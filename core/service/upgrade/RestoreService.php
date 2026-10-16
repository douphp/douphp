<?php

/**
 * DouPHP®
 * ------------------------------------------------------------------------------------
 * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)
 *
 * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-10-16
 */

namespace Dou\Core\Service\Upgrade;

use Dou\Core\Facade\DB;
use Dou\Core\Facade\Zip;
use Dou\Core\Support\FileHelper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 升级还原点还原引擎（scope 参数化的还原动作位）。
 *
 * 还原分五个阶段（均可断点续传，DouMsg 链 / 步骤机按次调用 step()）：
 *   files  按条目区间解压 files.zip 覆盖站点（sys 与 module 通用）
 *   mirror 系统域镜像还原：删除快照外的新增文件（升级引入的新文件回滚时移除）
 *   added  模块域：删除升级包将新增、而快照时尚不存在的文件
 *   db     按 db/ 分卷顺序导入数据库（DB::fnExecute 为全库唯一 SQL 导入入口）
 *   cache  清模板缓存
 * begin() 前先做清单哈希验证（files.zip / 各 db 分卷的 sha256 与 manifest 比对，
 * 任何不符即拒绝还原）；整个还原过程由 {@see MaintenanceModeService} 保持维护模式，
 * 完成 / 中断恢复原值，避免站点长期停留在关闭状态。
 *
 * 独立救援脚本（rescue.php）不走本类：它必须能在框架代码损坏时运行，逻辑自带。
 *
 * 兼容 PHP 5.6+。
 */
class RestoreService
{
    /** @var string 站点根（结尾带 /） */
    private $rootPath;

    /** @var string storage/snapshot/ 绝对路径（结尾带 /） */
    private $snapshotRoot;

    /** @var SnapshotRepository */
    private $repository;

    /** @var MaintenanceModeService */
    private $maintenance;

    /**
     * @param string $rootPath 站点根目录
     */
    public function __construct($rootPath)
    {
        $this->rootPath = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';
        $this->snapshotRoot = $this->rootPath . 'storage/snapshot/';
        $this->repository = new SnapshotRepository($this->rootPath);
        $this->maintenance = new MaintenanceModeService($this->rootPath);
    }

    /**
     * 开始还原：校验 → 哈希验证 → 维护模式 → 写还原状态。
     *
     * @param string $dirName 快照目录名
     * @return array array('ok' => bool, 'resumed' => bool, 'error' => string)
     */
    public function begin($dirName)
    {
        $row = $this->repository->row($dirName);
        if ($row === null) {
            return array('ok' => false, 'resumed' => false, 'error' => $this->t('snapshot_error_not_found', '还原点不存在'));
        }

        $other = $this->activeDir();
        if ($other !== null && $other !== $dirName) {
            return array('ok' => false, 'resumed' => false, 'error' => $this->t('snapshot_error_restore_busy', '已有未完成的还原任务，请先完成或等待其结束'));
        }

        $dir = $this->repository->dirPath($dirName);
        if (is_file($dir . 'restore_state.json')) {
            return array('ok' => true, 'resumed' => true, 'error' => '');
        }

        $verifyError = $this->verifyManifest($row);
        if ($verifyError !== '') {
            return array('ok' => false, 'resumed' => false, 'error' => $verifyError);
        }

        $maintenance = $this->maintenance->enable('restore:' . $dirName);
        if (empty($maintenance['ok'])) {
            return array('ok' => false, 'resumed' => false, 'error' => (string) $maintenance['error']);
        }

        $manifest = $row['manifest'];
        $state = array(
            'dir' => $dirName,
            'scope' => isset($manifest['scope']) ? (string) $manifest['scope'] : '',
            'file_mode' => isset($manifest['file_mode']) ? (string) $manifest['file_mode'] : 'full',
            'phase' => 'files',
            'started_at' => time(),
            'file_offset' => 0,
            'stray_files' => null,
            'added_pending' => null,
            'db_index' => 0,
            'failed_statements' => 0,
        );
        if (@file_put_contents($dir . 'restore_state.json', json_encode($state), LOCK_EX) === false) {
            return array('ok' => false, 'resumed' => false, 'error' => $this->t('snapshot_error_state_write', '还原状态写入失败（storage/snapshot 不可写）'));
        }
        $this->appendLog($dir, sprintf('开始还原：%s（%s）', $dirName, isset($manifest['title']) ? $manifest['title'] : ''));

        return array('ok' => true, 'resumed' => false, 'error' => '');
    }

    /**
     * 推进一个还原单元（内部按 max_seconds 预算连续推进多个阶段）。
     *
     * @param array $options file_batch（默认 500 条/批）、delete_batch（默认 300）、max_seconds（默认 8 秒，0=不限）
     * @return array array('ok' => bool, 'done' => bool, 'phase' => string, 'logs' => array, 'error' => string)
     */
    public function step(array $options = array())
    {
        $fileBatch = isset($options['file_batch']) ? max(20, (int) $options['file_batch']) : 500;
        $deleteBatch = isset($options['delete_batch']) ? max(20, (int) $options['delete_batch']) : 300;
        $maxSeconds = isset($options['max_seconds']) ? (int) $options['max_seconds'] : 8;
        $startTime = microtime(true);
        $logs = array();

        $dirName = $this->activeDir();
        if ($dirName === null) {
            return $this->result(false, false, '', $logs, $this->t('snapshot_error_no_restore', '没有进行中的还原任务'));
        }
        $dir = $this->repository->dirPath($dirName);
        $row = $this->repository->row($dirName);
        if ($row === null) {
            return $this->result(false, false, '', $logs, $this->t('snapshot_error_not_found', '还原点不存在'));
        }
        $manifest = $row['manifest'];
        $state = $this->readState($dir);
        if ($state === null) {
            return $this->result(false, false, '', $logs, $this->t('snapshot_error_no_restore', '没有进行中的还原任务'));
        }

        while (true) {
            $phase = isset($state['phase']) ? (string) $state['phase'] : 'files';

            if ($phase === 'files') {
                $error = $this->doFilesStep($dir, $manifest, $state, $fileBatch, $logs);
                if ($error !== '') {
                    return $this->result(false, false, $phase, $logs, $error);
                }
                if ($state['phase'] === 'files') {
                    // 时间预算内未解完：保存后续跑
                    $this->saveState($dir, $state);
                    break;
                }
                $this->saveState($dir, $state);
            } elseif ($phase === 'mirror') {
                $error = $this->doMirrorStep($dir, $manifest, $state, $deleteBatch, $logs);
                if ($error !== '') {
                    return $this->result(false, false, $phase, $logs, $error);
                }
                if ($state['phase'] === 'mirror') {
                    $this->saveState($dir, $state);
                    break;
                }
                $this->saveState($dir, $state);
            } elseif ($phase === 'added') {
                $error = $this->doAddedStep($dir, $manifest, $state, $deleteBatch, $logs);
                if ($error !== '') {
                    return $this->result(false, false, $phase, $logs, $error);
                }
                if ($state['phase'] === 'added') {
                    $this->saveState($dir, $state);
                    break;
                }
                $this->saveState($dir, $state);
            } elseif ($phase === 'db') {
                try {
                    $done = $this->doDbStep($dir, $manifest, $state, $logs);
                } catch (\Exception $e) {
                    return $this->result(false, false, $phase, $logs, $e->getMessage());
                } catch (\Throwable $e) {
                    return $this->result(false, false, $phase, $logs, $e->getMessage());
                }
                $this->saveState($dir, $state);
                if (!$done) {
                    break;
                }
            } elseif ($phase === 'cache') {
                $cacheClear = new CacheClearService();
                $cacheClear->clearSiteCache($this->rootPath);
                $logs[] = $this->t('snapshot_log_cache_cleared', '模板缓存已清理');
                $state['phase'] = 'done';
                $this->saveState($dir, $state);
            } elseif ($phase === 'done') {
                $this->finishRestore($dir, $manifest, $state, $logs);

                return $this->result(true, true, 'done', $logs, '');
            } else {
                return $this->result(false, false, (string) $phase, $logs, $this->t('snapshot_error_state', '还原状态异常'));
            }

            if ($maxSeconds > 0 && (microtime(true) - $startTime) >= $maxSeconds) {
                break;
            }
        }

        return $this->result(true, false, (string) $state['phase'], $logs, '');
    }

    /**
     * 当前还原中的快照目录名（无则 null）。
     *
     * @return string|null
     */
    public function activeDir()
    {
        foreach ((array) glob($this->snapshotRoot . '*/restore_state.json') as $stateFile) {
            $dirName = basename(dirname($stateFile));
            if ($this->repository->isValidName($dirName)) {
                return $dirName;
            }
        }

        return null;
    }

    /**
     * 放弃还原（清理状态 + 恢复维护模式原值；不清除已还原内容）。
     *
     * @param string $dirName
     * @return void
     */
    public function abandon($dirName)
    {
        if (!$this->repository->isValidName($dirName)) {
            return;
        }
        $dir = $this->repository->dirPath($dirName);
        $this->appendLog($dir, '还原任务被放弃');
        @unlink($dir . 'restore_state.json');
        $this->maintenance->restore();
    }

    /**
     * files 阶段：按条目区间解压覆盖。
     *
     * @param string $dir
     * @param array $manifest
     * @param array $state
     * @param int $fileBatch
     * @param array $logs
     * @return string 错误（空串=成功）
     */
    protected function doFilesStep($dir, array $manifest, array &$state, $fileBatch, array &$logs)
    {
        $zipFile = $dir . (isset($manifest['files_zip']) && $manifest['files_zip'] !== '' ? $manifest['files_zip'] : 'files.zip');
        if (!is_file($zipFile)) {
            $logs[] = $this->t('snapshot_log_restore_no_files', '本还原点不含文件包，跳过文件还原');
            $state['phase'] = $this->phaseAfterFiles($state);

            return '';
        }

        $range = Zip::extractRange($zipFile, $this->rootPath, (int) $state['file_offset'], $fileBatch);
        if (empty($range['ok'])) {
            return $this->t('snapshot_error_extract', '文件还原失败（files.zip 无法解压）')
                . ($range['error'] !== '' ? '：' . $range['error'] : '');
        }
        if ((int) $range['count'] === 0) {
            $logs[] = sprintf($this->t('snapshot_log_restore_files_done', '文件还原完成（%d 项）'), (int) $state['file_offset']);
            $state['phase'] = $this->phaseAfterFiles($state);

            return '';
        }
        $state['file_offset'] = (int) $state['file_offset'] + (int) $range['count'];

        return '';
    }

    /**
     * files 之后的阶段。
     *
     * 只有「全量文件」的系统域快照才做镜像清理（删除快照外文件）；仅数据库快照
     * （file_mode=none，如向导渠道）与模块域一律不得走 mirror，否则会把快照
     * 未收录的文件误判为「新增」而全量删除。
     *
     * @param array $state
     * @return string
     */
    protected function phaseAfterFiles(array $state)
    {
        $scope = isset($state['scope']) ? (string) $state['scope'] : '';
        $fileMode = isset($state['file_mode']) ? (string) $state['file_mode'] : 'full';
        if ($scope === SnapshotService::SCOPE_SYSTEM && $fileMode === 'full') {
            return 'mirror';
        }
        if ($scope === SnapshotService::SCOPE_MODULE) {
            return 'added';
        }

        return 'db';
    }

    /**
     * mirror 阶段（仅系统域）：删除快照外的新增文件，并清空空目录。
     *
     * @param string $dir
     * @param array $manifest
     * @param array $state
     * @param int $deleteBatch
     * @param array $logs
     * @return string 错误（空串=成功）
     */
    protected function doMirrorStep($dir, array $manifest, array &$state, $deleteBatch, array &$logs)
    {
        if (empty($state['stray_files'])) {
            // 首次进入：计算快照外文件清单（一次性），随状态持久化
            $known = array();
            foreach ((array) (isset($manifest['files']) ? $manifest['files'] : array()) as $rel) {
                $known[str_replace('\\', '/', (string) $rel)] = true;
            }
            $strays = array();
            $dirs = array();
            $this->walkRoot('', true, $known, $strays, $dirs);
            $state['stray_files'] = $strays;
            $state['dir_cleanup'] = $dirs;
            $logs[] = sprintf($this->t('snapshot_log_restore_mirror_scan', '检测到快照外文件 %d 个，开始清理'), count($strays));
        }

        $strays = (array) $state['stray_files'];
        if ($strays) {
            $deleted = 0;
            $slice = array_splice($strays, 0, $deleteBatch);
            foreach ($slice as $rel) {
                $abs = $this->rootPath . $rel;
                if (is_file($abs) && @unlink($abs)) {
                    $deleted++;
                }
            }
            $state['stray_files'] = $strays;
            $logs[] = sprintf($this->t('snapshot_log_restore_mirror_delete', '清理快照外文件 %d 个'), $deleted);
        }

        if (empty($state['stray_files'])) {
            $dirs = (array) (isset($state['dir_cleanup']) ? $state['dir_cleanup'] : array());
            $absDirs = array();
            foreach ($dirs as $relDir) {
                $absDirs[] = $this->rootPath . rtrim($relDir, '/');
            }
            FileHelper::removeEmptyDirs($absDirs);
            $logs[] = $this->t('snapshot_log_restore_mirror_done', '镜像清理完成');
            $state['phase'] = $this->phaseAfterMirror();
            $state['stray_files'] = null;
            $state['dir_cleanup'] = null;
        }

        return '';
    }

    /**
     * mirror 之后的阶段。
     *
     * @return string
     */
    protected function phaseAfterMirror()
    {
        return 'db';
    }

    /**
     * added 阶段（仅模块域）：删除升级包将新增、快照时尚不存在的文件。
     *
     * @param string $dir
     * @param array $manifest
     * @param array $state
     * @param int $deleteBatch
     * @param array $logs
     * @return string 错误（空串=成功）
     */
    protected function doAddedStep($dir, array $manifest, array &$state, $deleteBatch, array &$logs)
    {
        if (empty($state['added_pending'])) {
            $pending = array();
            foreach ((array) (isset($manifest['added']) ? $manifest['added'] : array()) as $rel) {
                if (is_string($rel) && $rel !== '') {
                    $pending[] = str_replace('\\', '/', $rel);
                }
            }
            $state['added_pending'] = $pending;
        }

        $pending = (array) $state['added_pending'];
        if ($pending) {
            $slice = array_splice($pending, 0, $deleteBatch);
            $deleted = 0;
            $dirs = array();
            foreach ($slice as $rel) {
                $abs = $this->rootPath . $rel;
                if (is_dir($abs)) {
                    $dirs[] = $abs;
                } elseif (is_file($abs) && @unlink($abs)) {
                    $deleted++;
                }
            }
            FileHelper::removeEmptyDirs($dirs);
            $state['added_pending'] = $pending;
            if ($deleted > 0) {
                $logs[] = sprintf($this->t('snapshot_log_restore_added_delete', '清理升级新增文件 %d 个'), $deleted);
            }
        }

        if (empty($state['added_pending'])) {
            $state['phase'] = $this->phaseAfterMirror();
            $state['added_pending'] = null;
        }

        return '';
    }

    /**
     * db 阶段：逐个分卷导入（每个分卷一次调用，便于断点与观察）。
     *
     * @param string $dir
     * @param array $manifest
     * @param array $state
     * @param array $logs
     * @return bool 是否全部导入完成
     */
    protected function doDbStep($dir, array $manifest, array &$state, array &$logs)
    {
        $dbFiles = (array) (isset($manifest['db_files']) ? $manifest['db_files'] : array());
        $total = count($dbFiles);
        if ($total === 0) {
            $logs[] = $this->t('snapshot_log_restore_no_db', '本还原点不含数据库备份，跳过数据库还原');
            $state['phase'] = 'cache';

            return true;
        }

        $index = (int) $state['db_index'];
        if ($index >= $total) {
            $state['phase'] = 'cache';

            return true;
        }

        $name = isset($dbFiles[$index]['name']) ? (string) $dbFiles[$index]['name'] : '';
        $file = $dir . 'db/' . basename($name);
        if (!is_file($file)) {
            throw new \RuntimeException($this->t('snapshot_error_db_missing', '数据库分卷缺失：') . $name);
        }
        $sql = (string) file_get_contents($file);
        if (DB::fnExecute($sql) === false) {
            $failed = (int) DB::getImportFailedCount();
            $state['failed_statements'] = (int) $state['failed_statements'] + $failed;
            $logs[] = sprintf($this->t('snapshot_log_restore_db_failed', '分卷 %1$s 有 %2$d 条语句失败'), $name, $failed);
        }
        $state['db_index'] = $index + 1;
        $logs[] = sprintf($this->t('snapshot_log_restore_db', '正在还原数据库（%d/%d）'), (int) $state['db_index'], $total);
        if ((int) $state['db_index'] >= $total) {
            $state['phase'] = 'cache';

            return true;
        }

        return false;
    }

    /**
     * 还原收尾：manifest 记 restored_at + 日志 + 状态清理 + 维护模式恢复。
     *
     * @param string $dir
     * @param array $manifest
     * @param array $state
     * @param array $logs
     * @return void
     */
    protected function finishRestore($dir, array $manifest, array $state, array &$logs)
    {
        $manifest['restored_at'] = time();
        @file_put_contents($dir . 'manifest.json', json_encode($manifest), LOCK_EX);

        $failed = (int) $state['failed_statements'];
        $summary = $failed > 0
            ? sprintf($this->t('snapshot_log_restore_done_partial', '还原完成，但有 %d 条 SQL 语句失败，请核查'), $failed)
            : $this->t('snapshot_log_restore_done', '还原完成');
        $logs[] = $summary;
        $this->appendLog($dir, $summary);

        @unlink($dir . 'restore_state.json');
        $this->maintenance->restore();
    }

    /**
     * 还原前的清单哈希验证（files.zip + 各 db 分卷）。
     *
     * @param array $row repository->row() 行
     * @return string 错误（空串=通过）
     */
    protected function verifyManifest(array $row)
    {
        $dir = $row['path'];
        $manifest = $row['manifest'];

        if (!empty($manifest['files_zip']) && !empty($manifest['files_zip_sha256'])) {
            $zipFile = $dir . 'files.zip';
            if (!is_file($zipFile)) {
                return $this->t('snapshot_error_verify_missing', '还原点校验失败：files.zip 缺失');
            }
            if (!hash_equals((string) $manifest['files_zip_sha256'], (string) @hash_file('sha256', $zipFile))) {
                return $this->t('snapshot_error_verify_hash', '还原点校验失败：files.zip 校验值不一致（文件可能被改动）');
            }
        }

        foreach ((array) (isset($manifest['db_files']) ? $manifest['db_files'] : array()) as $dbFile) {
            $name = isset($dbFile['name']) ? (string) $dbFile['name'] : '';
            $file = $dir . 'db/' . basename($name);
            if (!is_file($file)) {
                return $this->t('snapshot_error_verify_missing', '还原点校验失败：数据库分卷缺失：') . $name;
            }
            if (!empty($dbFile['sha256']) && !hash_equals((string) $dbFile['sha256'], (string) @hash_file('sha256', $file))) {
                return $this->t('snapshot_error_verify_hash', '还原点校验失败：数据库分卷校验值不一致：') . $name;
            }
        }

        return '';
    }

    /**
     * 站内根遍历（系统域排除规则与 SnapshotService 保持一致），收集快照外文件与全部目录。
     *
     * @param string $relPrefix
     * @param bool $topLevel
     * @param array $known manifest 文件集（rel => true）
     * @param array $strays 出参
     * @param array $dirs 出参
     * @return void
     */
    protected function walkRoot($relPrefix, $topLevel, array $known, array &$strays, array &$dirs)
    {
        $absDir = $this->rootPath . $relPrefix;
        $handle = @opendir($absDir);
        if (!$handle) {
            return;
        }
        while (($item = @readdir($handle)) !== false) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $abs = $absDir . $item;
            $rel = $relPrefix . $item;
            if (is_link($abs)) {
                continue;
            }
            if (is_dir($abs)) {
                if ($topLevel && in_array($item, array('images', 'storage', '.git', '.svn', '.dou', 'node_modules'), true)) {
                    continue;
                }
                $dirs[] = $rel;
                $this->walkRoot($rel . '/', false, $known, $strays, $dirs);
            } elseif (is_file($abs)) {
                if (!isset($known[$rel])) {
                    $strays[] = $rel;
                }
            }
        }
        closedir($handle);
    }

    /**
     * @param string $dir
     * @return array|null
     */
    protected function readState($dir)
    {
        $file = rtrim($dir, '/') . '/restore_state.json';
        if (!is_file($file)) {
            return null;
        }
        $state = json_decode((string) @file_get_contents($file), true);

        return is_array($state) ? $state : null;
    }

    /**
     * @param string $dir
     * @param array $state
     * @return void
     */
    protected function saveState($dir, array $state)
    {
        @file_put_contents(rtrim($dir, '/') . '/restore_state.json', json_encode($state), LOCK_EX);
    }

    /**
     * @param string $dir
     * @param string $line
     * @return void
     */
    protected function appendLog($dir, $line)
    {
        $file = rtrim($dir, '/') . '/restore.log';
        $entry = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(array("\r", "\n"), ' ', (string) $line) . "\n";
        @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * step 结果封装。
     *
     * @param bool $ok
     * @param bool $done
     * @param string $phase
     * @param array $logs
     * @param string $error
     * @return array
     */
    protected function result($ok, $done, $phase, array $logs, $error)
    {
        return array(
            'ok' => $ok,
            'done' => $done,
            'phase' => $phase,
            'logs' => $logs,
            'error' => $error,
        );
    }

    /**
     * 语言取值（核心层可能运行在向导渠道，lang() 并非必然存在）。
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    protected function t($key, $default)
    {
        if (function_exists('lang')) {
            $value = lang($key, $default);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $default;
    }
}
