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

namespace Dou\Core\Service\Snapshot;

use Dou\Core\Facade\DB;
use Dou\Core\Facade\Zip;
use Dou\Core\Infra\Database\DbDumpService;
use Dou\Core\Support\FileHelper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 升级还原点（快照）创建引擎：scope 参数化，供云渠道（后台步骤机）、向导渠道与后台还原点页共用。
 *
 * 目录：storage/snapshot/{scope}-{scope_id}-{YmdHis}/
 *   - progress.json     创建进度（分阶段续传游标）
 *   - files.zip         文件集（条目名 = 相对站点根路径）
 *   - db/xx-{table}.sql 数据库分块导出（与备份模块同一引擎 {@see DbDumpService}）
 *   - manifest.json     完成清单（含 files.zip 与各 db 文件的 sha256）
 *   - rescue.php        独立救援脚本（仅 system 域；不含敏感信息，令牌另行签发）
 *   - rescue.token      急救令牌的 sha256 与有效期（明文令牌不落盘）
 *   - restore.log       还原过程日志（RestoreService 追加）
 * 活跃指针：storage/snapshot/active.json（同一时间只允许一个创建中的还原点）。
 *
 * 创建分四个阶段（均可断点续传）：
 *   collect  收集文件清单与表清单（一次调用）
 *   files    分批打包文件（每批 file_batch 个文件）
 *   db       按字节预算分块导出数据库（每调用 db_bytes 字节）
 *   finalize 写 manifest / 救援脚本 / 清除活跃指针 / 留存策略清理
 *
 * 文件集模型（three 参数位之一）：
 *   - system：全站代码（排除动态目录：images / storage / .git / .svn / .dou / node_modules）
 *   - module / theme / plugin：按升级包解压目录反推「将被覆盖的文件」（备份集）+
 *     「将被新增的文件」（还原时删除）；theme / plugin 经 item_prefix 映射到
 *     theme/<slug>/ / plugin/<slug>/ 目标目录
 *   - none  ：仅数据库（向导渠道：文件已由用户手工上传，只有库需要备份）
 *
 * 兼容 PHP 5.6+。
 */
class SnapshotService
{
    /** @var string 系统域 */
    const SCOPE_SYSTEM = 'system';

    /** @var string 模块域 */
    const SCOPE_MODULE = 'module';

    /** @var string 主题域 */
    const SCOPE_THEME = 'theme';

    /** @var string 插件域 */
    const SCOPE_PLUGIN = 'plugin';

    /** @var string 站点根（结尾带 /） */
    private $rootPath;

    /** @var string storage/snapshot/ 绝对路径（结尾带 /） */
    private $snapshotRoot;

    /** @var DbDumpService */
    private $dbDump;

    /** @var SnapshotRepository */
    private $repository;

    /** @var int 活跃快照超过该时长视为陈旧（创建中断），自动中止 */
    const STALE_SECONDS = 14400;

    /**
     * 系统域排除的顶层目录（动态数据 / 版本库 / 开发目录；快照不备份、镜像还原不清理）。
     *
     * @var array
     */
    protected static $systemExcludeDirs = array('images', 'storage', '.git', '.svn', '.dou', 'node_modules');

    /**
     * @param string $rootPath 站点根目录
     */
    public function __construct($rootPath)
    {
        $this->rootPath = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';
        $this->snapshotRoot = $this->rootPath . 'storage/snapshot/';
        $this->dbDump = new DbDumpService();
        $this->repository = new SnapshotRepository($this->rootPath);
        if (!is_dir($this->snapshotRoot)) {
            @mkdir($this->snapshotRoot, 0777, true);
        }
    }

    /**
     * @return string storage/snapshot/ 绝对路径（结尾带 /）
     */
    public function baseDir()
    {
        return $this->snapshotRoot;
    }

    /**
     * @return SnapshotRepository
     */
    public function repository()
    {
        return $this->repository;
    }

    /**
     * 开始创建还原点（同 install_id 的活跃快照自动续建，其余活跃快照中止重建）。
     *
     * @param string $scope system|module|theme|plugin
     * @param string $scopeId 域内标识（cloud_id / wizard 等）
     * @param array $options file_mode(full|list|none)、item_dir（list 模式解压目录）、
     *                       item_prefix（list 模式目标目录前缀，如 theme/m149/）、
     *                       db(bool)、channel、title、install_id
     * @return array array('ok' => bool, 'dir' => string, 'resumed' => bool, 'error' => string)
     */
    public function begin($scope, $scopeId, array $options = array())
    {
        if (!in_array($scope, array(self::SCOPE_SYSTEM, self::SCOPE_MODULE, self::SCOPE_THEME, self::SCOPE_PLUGIN), true)) {
            $scope = self::SCOPE_SYSTEM;
        }
        $fileMode = isset($options['file_mode']) ? (string) $options['file_mode'] : 'full';
        if (!in_array($fileMode, array('full', 'list', 'none'), true)) {
            $fileMode = 'full';
        }
        $installId = isset($options['install_id']) ? (string) $options['install_id'] : '';

        $active = $this->active();
        if ($active !== null) {
            $sameInstall = $installId !== ''
                && isset($active['install_id']) && (string) $active['install_id'] === $installId;
            if ($sameInstall) {
                return array('ok' => true, 'dir' => $active['dir'], 'resumed' => true, 'error' => '');
            }
            $this->abort();
        }

        // manifest / 活跃指针保留原始 scope_id（查找键，含点号等合法字符）；
        // 目录名另做文件安全净化（去点号等），避免「按原始 id 查不到快照」
        $scopeId = trim((string) $scopeId);
        if (strlen($scopeId) > 80) {
            $scopeId = substr($scopeId, 0, 80);
        }
        $dirName = $scope . '-' . $this->sanitizeScopeId($scopeId) . '-' . date('YmdHis');
        $dir = $this->snapshotRoot . $dirName . '/';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            return array('ok' => false, 'dir' => '', 'resumed' => false, 'error' => $this->t('snapshot_error_dir', '还原点目录无法创建（storage/snapshot 不可写）'));
        }
        // 数据库目录按需创建：theme / plugin 与仅文件快照不导出数据库，避免留下空 db/ 目录
        if (!empty($options['db'])) {
            @mkdir($dir . 'db/', 0777, true);
        }

        $progress = array(
            'scope' => $scope,
            'scope_id' => $scopeId,
            'channel' => isset($options['channel']) ? (string) $options['channel'] : '',
            'title' => isset($options['title']) ? (string) $options['title'] : '',
            'install_id' => $installId,
            'created_at' => time(),
            'file_mode' => $fileMode,
            'item_dir' => isset($options['item_dir']) ? (string) $options['item_dir'] : '',
            'item_prefix' => isset($options['item_prefix']) ? (string) $options['item_prefix'] : '',
            'db' => !empty($options['db']),
            'files' => array(),
            'file_total' => 0,
            'file_index' => 0,
            'added' => array(),
            'tables' => array(),
            'table_index' => 0,
            'startfrom' => 0,
            'db_bytes' => 0,
            'phase' => 'collect',
        );
        if (@file_put_contents($dir . 'progress.json', json_encode($progress), LOCK_EX) === false) {
            return array('ok' => false, 'dir' => '', 'resumed' => false, 'error' => $this->t('snapshot_error_dir', '还原点目录无法创建（storage/snapshot 不可写）'));
        }

        $this->writeActive(array(
            'dir' => $dirName,
            'scope' => $scope,
            'scope_id' => $scopeId,
            'channel' => $progress['channel'],
            'install_id' => $installId,
            'started_at' => time(),
        ));

        return array('ok' => true, 'dir' => $dirName, 'resumed' => false, 'error' => '');
    }

    /**
     * 推进一个创建单元（内部按 max_seconds 预算连续推进多个阶段）。
     *
     * @param array $options file_batch（默认 600）、db_bytes（默认 3MB）、max_seconds（默认 10 秒，0=不限）
     * @return array array('ok' => bool, 'done' => bool, 'phase' => string, 'dir' => string,
     *               'progress' => string, 'logs' => array, 'error' => string)
     */
    public function step(array $options = array())
    {
        $fileBatch = isset($options['file_batch']) ? max(50, (int) $options['file_batch']) : 600;
        $dbBytes = isset($options['db_bytes']) ? max(262144, (int) $options['db_bytes']) : 3145728;
        $maxSeconds = isset($options['max_seconds']) ? (int) $options['max_seconds'] : 10;
        $startTime = microtime(true);
        $logs = array();

        $active = $this->active();
        if ($active === null) {
            return $this->result(false, false, '', '', $logs, $this->t('snapshot_error_no_active', '没有进行中的还原点创建任务'));
        }
        $dirName = $active['dir'];
        $dir = $this->snapshotRoot . $dirName . '/';
        $progress = $this->readProgress($dir);
        if ($progress === null) {
            return $this->result(false, false, '', $dirName, $logs, $this->t('snapshot_error_progress', '还原点进度文件缺失或损坏'));
        }

        $title = isset($progress['title']) && $progress['title'] !== '' ? $progress['title'] : $dirName;

        while (true) {
            $phase = $progress['phase'];

            if ($phase === 'collect') {
                $error = $this->doCollect($dir, $progress);
                if ($error !== '') {
                    return $this->result(false, false, $phase, $dirName, $logs, $error);
                }
                $logs[] = sprintf($this->t('snapshot_log_collected', '还原点已收集 %d 个文件'), (int) $progress['file_total']);
                $this->saveProgress($dir, $progress);
            } elseif ($phase === 'files') {
                $slice = array_slice($progress['files'], (int) $progress['file_index'], $fileBatch);
                if (!$slice) {
                    $progress['phase'] = $this->phaseAfterFiles($progress);
                    $this->saveProgress($dir, $progress);
                    continue;
                }
                $absolute = array();
                foreach ($slice as $rel) {
                    $absolute[] = $this->rootPath . $rel;
                }
                $appended = Zip::append($dir . 'files.zip', $absolute, $this->rootPath);
                if (empty($appended['ok'])) {
                    return $this->result(false, false, $phase, $dirName, $logs, $this->t('snapshot_error_zip', '文件打包失败：') . $appended['error']);
                }
                $progress['file_index'] = (int) $progress['file_index'] + count($slice);
                if ($progress['file_index'] >= (int) $progress['file_total']) {
                    $progress['phase'] = $this->phaseAfterFiles($progress);
                }
                $this->saveProgress($dir, $progress);
                $logs[] = sprintf($this->t('snapshot_log_files', '正在打包还原点文件（%d/%d）'), (int) $progress['file_index'], (int) $progress['file_total']);
            } elseif ($phase === 'db') {
                try {
                    $finished = $this->doDbStep($dir, $progress, $dbBytes, $title, $logs);
                } catch (\Exception $e) {
                    return $this->result(false, false, $phase, $dirName, $logs, $e->getMessage());
                } catch (\Throwable $e) {
                    return $this->result(false, false, $phase, $dirName, $logs, $e->getMessage());
                }
                if ($finished) {
                    $progress['phase'] = 'finalize';
                }
                $this->saveProgress($dir, $progress);
            } elseif ($phase === 'finalize') {
                $error = $this->doFinalize($dir, $progress, $logs);
                if ($error !== '') {
                    return $this->result(false, false, $phase, $dirName, $logs, $error);
                }
                $progress['phase'] = 'done';
                $this->saveProgress($dir, $progress);

                return $this->result(true, true, 'done', $dirName, $logs, '');
            } else {
                // done 或未知阶段：视为已完成
                return $this->result(true, true, (string) $phase, $dirName, $logs, '');
            }

            if ($maxSeconds > 0 && (microtime(true) - $startTime) >= $maxSeconds) {
                break;
            }
        }

        return $this->result(true, false, (string) $progress['phase'], $dirName, $logs, '');
    }

    /**
     * 中止活跃快照（删除残缺目录并清除活跃指针）。
     *
     * @return void
     */
    public function abort()
    {
        $active = $this->readActive();
        if ($active !== null && !empty($active['dir']) && $this->repository->isValidName($active['dir'])) {
            FileHelper::delDir($this->snapshotRoot . $active['dir'] . '/');
        }
        @unlink($this->snapshotRoot . 'active.json');
    }

    /**
     * 活跃快照状态（陈旧自动中止并返回 null）。
     *
     * @return array|null array('dir','scope','scope_id','install_id','channel','progress')
     */
    public function active()
    {
        $active = $this->readActive();
        if ($active === null) {
            return null;
        }
        $dirName = isset($active['dir']) ? (string) $active['dir'] : '';
        if (!$this->repository->isValidName($dirName) || !is_file($this->snapshotRoot . $dirName . '/progress.json')) {
            @unlink($this->snapshotRoot . 'active.json');
            return null;
        }
        $startedAt = isset($active['started_at']) ? (int) $active['started_at'] : 0;
        if ($startedAt > 0 && time() - $startedAt > self::STALE_SECONDS) {
            $this->abort();
            return null;
        }
        $active['progress'] = $this->readProgress($this->snapshotRoot . $dirName . '/');

        return $active;
    }

    /**
     * 签发急救令牌（仅 system 域；明文只返回给调用方展示，落盘仅存 sha256）。
     *
     * @param string $dirName 快照目录名
     * @return array array('ok' => bool, 'token' => string, 'url' => string, 'expires_at' => int, 'error' => string)
     */
    public function mintRescueToken($dirName)
    {
        $row = $this->repository->row($dirName);
        if ($row === null) {
            return array('ok' => false, 'token' => '', 'url' => '', 'expires_at' => 0, 'error' => $this->t('snapshot_error_not_found', '还原点不存在'));
        }
        if (!isset($row['manifest']['scope']) || $row['manifest']['scope'] !== self::SCOPE_SYSTEM) {
            return array('ok' => false, 'token' => '', 'url' => '', 'expires_at' => 0, 'error' => $this->t('snapshot_error_rescue_scope', '仅系统还原点提供救援脚本'));
        }
        $dir = $this->snapshotRoot . $dirName . '/';
        if (!is_file($dir . 'rescue.php')) {
            $this->writeRescueArtifacts($dir);
        }
        if (!is_file($dir . 'rescue.php')) {
            return array('ok' => false, 'token' => '', 'url' => '', 'expires_at' => 0, 'error' => $this->t('snapshot_error_rescue_write', '救援脚本写入失败'));
        }

        $token = class_exists('Dou\\Core\\Support\\Str')
            ? \Dou\Core\Support\Str::randomHex(16)
            : md5(uniqid((string) mt_rand(), true) . microtime(true));
        $expiresAt = time() + 86400;
        if (@file_put_contents($dir . 'rescue.token', json_encode(array(
            'token_sha256' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        )), LOCK_EX) === false) {
            return array('ok' => false, 'token' => '', 'url' => '', 'expires_at' => 0, 'error' => $this->t('snapshot_error_rescue_write', '救援脚本写入失败'));
        }

        return array(
            'ok' => true,
            'token' => $token,
            'url' => $this->rescueUrl($dirName, $token),
            'expires_at' => $expiresAt,
            'error' => '',
        );
    }

    /**
     * 救援脚本访问 URL。
     *
     * @param string $dirName
     * @param string $token
     * @return string
     */
    public function rescueUrl($dirName, $token)
    {
        $base = defined('ROOT_URL') ? rtrim(ROOT_URL, '/') . '/' : '/';

        return $base . 'storage/snapshot/' . $dirName . '/rescue.php?token=' . rawurlencode($token);
    }

    /**
     * 系统域文件清单：全站代码，排除动态目录 / 版本库 / 开发目录 / 符号链接。
     *
     * @param string $rootPath
     * @return array 相对站点根的路径列表（/ 分隔，字典序）
     */
    public static function systemFileList($rootPath)
    {
        $root = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';
        $list = array();
        self::walkDir($root, '', true, $list);
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * 模块 / 主题 / 插件域文件清单：按升级包解压目录反推将被覆盖 / 新增的文件。
     *
     * 模块包解压目录结构与站点根一致（空前缀）；主题 / 插件包解压目录内容即目标
     * 子目录内容，需以 itemPrefix 映射（theme/<slug>/、plugin/<slug>/），输出统一为
     * 站点相对路径，供备份（Zip::append 以 rootPath 为源）与还原阶段直接使用。
     *
     * @param string $rootPath
     * @param string $itemDir 解压目录（绝对路径，无结尾 /）
     * @param string $itemPrefix 目标目录前缀（'' 或 'theme/m149/'）
     * @return array array('files' => array, 'added' => array)
     */
    public static function moduleFileList($rootPath, $itemDir, $itemPrefix = '')
    {
        $root = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';
        $item = rtrim(str_replace('\\', '/', (string) $itemDir), '/') . '/';
        $prefix = trim(str_replace('\\', '/', (string) $itemPrefix), '/');
        $prefix = ($prefix === '') ? '' : $prefix . '/';
        $list = array();
        self::walkDir($item, '', false, $list);
        sort($list, SORT_STRING);

        $files = array();
        $added = array();
        foreach ($list as $rel) {
            $target = $prefix . $rel;
            if (is_file($root . $target)) {
                $files[] = $target;
            } else {
                $added[] = $target;
            }
        }

        return array('files' => $files, 'added' => $added);
    }

    /**
     * 递归收集目录下文件（相对路径）。
     *
     * @param string $absDir 绝对路径（结尾带 /）
     * @param string $relPrefix 相对前缀（'' 或 'a/b/'）
     * @param bool $applyExcludes 是否应用系统域顶层排除
     * @param array $list 出参
     * @return void
     */
    protected static function walkDir($absDir, $relPrefix, $applyExcludes, array &$list)
    {
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
                if ($applyExcludes && $relPrefix === '' && in_array($item, self::$systemExcludeDirs, true)) {
                    continue;
                }
                self::walkDir($abs . '/', $rel . '/', $applyExcludes, $list);
            } elseif (is_file($abs)) {
                $list[] = $rel;
            }
        }
        closedir($handle);
    }

    /**
     * collect 阶段：收集文件清单与表清单。
     *
     * @param string $dir
     * @param array $progress
     * @return string 错误信息（空串=成功）
     */
    protected function doCollect($dir, array &$progress)
    {
        $mode = isset($progress['file_mode']) ? $progress['file_mode'] : 'full';
        if ($mode === 'full') {
            $progress['files'] = self::systemFileList($this->rootPath);
        } elseif ($mode === 'list') {
            $itemDir = isset($progress['item_dir']) ? (string) $progress['item_dir'] : '';
            if ($itemDir === '' || !is_dir($itemDir)) {
                return $this->t('snapshot_error_item_dir', '升级包解压目录缺失，无法创建还原点');
            }
            $itemPrefix = isset($progress['item_prefix']) ? (string) $progress['item_prefix'] : '';
            $listed = self::moduleFileList($this->rootPath, $itemDir, $itemPrefix);
            $progress['files'] = $listed['files'];
            $progress['added'] = $listed['added'];
        } else {
            $progress['files'] = array();
            $progress['added'] = array();
        }
        $progress['file_total'] = count($progress['files']);
        $progress['file_index'] = 0;

        $progress['tables'] = array();
        if (!empty($progress['db'])) {
            $result = DB::query("SHOW TABLE STATUS LIKE '" . DB::getPrefix() . "%'");
            while ($table = DB::fetchArray($result)) {
                $progress['tables'][] = $table['Name'];
            }
        }
        $progress['table_index'] = 0;
        $progress['startfrom'] = 0;
        $progress['db_bytes'] = 0;
        // 有文件先走 files 分批打包；无文件（仅数据库）再直接进 db / finalize
        $progress['phase'] = ((int) $progress['file_total'] > 0) ? 'files' : $this->phaseAfterFiles($progress);

        // 同步写盘一次
        $this->saveProgress($dir, $progress);

        return '';
    }

    /**
     * 文件阶段结束后的下一阶段。
     *
     * @param array $progress
     * @return string
     */
    protected function phaseAfterFiles(array $progress)
    {
        if (!empty($progress['db']) && !empty($progress['tables'])) {
            return 'db';
        }

        return 'finalize';
    }

    /**
     * db 阶段单元：在字节预算内连续导表。
     *
     * @param string $dir
     * @param array $progress
     * @param int $dbBytes 本调用字节预算
     * @param string $title 快照标题（写进每个 db 文件头）
     * @param array $logs
     * @return bool 是否全部导完
     */
    protected function doDbStep($dir, array &$progress, $dbBytes, $title, array &$logs)
    {
        // db 目录由 begin() 按需创建；续建或目录缺失时在此兜底重建
        if (!is_dir($dir . 'db')) {
            @mkdir($dir . 'db', 0777, true);
        }

        $tables = (array) $progress['tables'];
        $total = count($tables);
        $bytesThisCall = 0;

        while ((int) $progress['table_index'] < $total) {
            if ($bytesThisCall >= $dbBytes) {
                break;
            }
            $table = (string) $tables[(int) $progress['table_index']];
            $file = $dir . 'db/' . str_pad((string) ((int) $progress['table_index'] + 1), 3, '0', STR_PAD_LEFT) . '-' . $table . '.sql';
            if (!is_file($file)) {
                $appVersion = (string) \Dou\Core\Foundation\Configuration\Config::get('site.douphp_version', '');
                $header = DbDumpService::headerComment($title, $appVersion);
                if (@file_put_contents($file, $header, LOCK_EX) === false) {
                    throw new \RuntimeException($this->t('snapshot_error_db_write', '数据库导出文件写入失败：') . $file);
                }
                $bytesThisCall += strlen($header);
                $progress['db_bytes'] = (int) $progress['db_bytes'] + strlen($header);
            }

            $chunk = $this->dbDump->dumpTable($table, (int) $progress['startfrom'], $bytesThisCall, $dbBytes);
            if ($chunk['sql'] !== '') {
                if (@file_put_contents($file, $chunk['sql'], FILE_APPEND | LOCK_EX) === false) {
                    throw new \RuntimeException($this->t('snapshot_error_db_write', '数据库导出文件写入失败：') . $file);
                }
                $bytesThisCall += strlen($chunk['sql']);
                $progress['db_bytes'] = (int) $progress['db_bytes'] + strlen($chunk['sql']);
            }
            $progress['startfrom'] = (int) $chunk['next_startrow'];

            if ($chunk['sql'] === '' && (int) $progress['startfrom'] === 0) {
                // 本调用剩余空间连表头都放不下：留给下一次调用
                break;
            }
            if (!empty($chunk['complete'])) {
                $progress['table_index'] = (int) $progress['table_index'] + 1;
                $progress['startfrom'] = 0;
                $logs[] = sprintf($this->t('snapshot_log_db', '正在导出数据库（%d/%d 表）'), (int) $progress['table_index'], $total);
            }
        }

        return (int) $progress['table_index'] >= $total;
    }

    /**
     * finalize 阶段：写 manifest、救援脚本，清除活跃指针，执行留存策略。
     *
     * @param string $dir
     * @param array $progress
     * @param array $logs
     * @return string 错误信息（空串=成功）
     */
    protected function doFinalize($dir, array $progress, array &$logs)
    {
        $manifest = array(
            'scope' => $progress['scope'],
            'scope_id' => $progress['scope_id'],
            'channel' => $progress['channel'],
            'title' => $progress['title'],
            'install_id' => $progress['install_id'],
            'created_at' => (int) $progress['created_at'],
            'file_mode' => $progress['file_mode'],
            'files' => array_values((array) $progress['files']),
            'files_count' => (int) $progress['file_total'],
            'files_zip' => '',
            'files_zip_size' => 0,
            'files_zip_sha256' => '',
            'added' => array_values((array) $progress['added']),
            'db' => !empty($progress['db']),
            'db_files' => array(),
            'db_bytes' => (int) $progress['db_bytes'],
            'douphp_version' => (string) \Dou\Core\Foundation\Configuration\Config::get('site.douphp_version', ''),
            'php_version' => PHP_VERSION,
        );

        $zipFile = $dir . 'files.zip';
        if (is_file($zipFile)) {
            $manifest['files_zip'] = 'files.zip';
            $manifest['files_zip_size'] = (int) @filesize($zipFile);
            $manifest['files_zip_sha256'] = (string) @hash_file('sha256', $zipFile);
        }

        $dbFiles = (array) glob($dir . 'db/*.sql');
        sort($dbFiles, SORT_STRING);
        foreach ($dbFiles as $file) {
            $manifest['db_files'][] = array(
                'name' => basename($file),
                'size' => (int) @filesize($file),
                'sha256' => (string) @hash_file('sha256', $file),
            );
        }

        $tmp = $dir . 'manifest.json.tmp';
        if (@file_put_contents($tmp, json_encode($manifest), LOCK_EX) === false || !@rename($tmp, $dir . 'manifest.json')) {
            return $this->t('snapshot_error_manifest', '还原点清单写入失败');
        }
        @chmod($dir . 'manifest.json', 0644);

        // 无数据库分卷（theme / plugin 或表清单为空）时清掉空 db/ 目录：
        // manifest 的 db_files 已按实际文件生成，删除后还原链路不受影响
        if (is_dir($dir . 'db') && count((array) glob($dir . 'db/*')) === 0) {
            @rmdir($dir . 'db');
        }

        if ($manifest['scope'] === self::SCOPE_SYSTEM) {
            $this->writeRescueArtifacts($dir);
        }

        @unlink($this->snapshotRoot . 'active.json');
        $deleted = $this->repository->prune();
        if ($deleted) {
            $logs[] = sprintf($this->t('snapshot_log_pruned', '已按留存策略清理 %d 个旧还原点'), count($deleted));
        }
        $logs[] = sprintf($this->t('snapshot_log_finalized', '还原点创建完成：%s'), basename(rtrim($dir, '/')));

        return '';
    }

    /**
     * 写救援脚本与目录防护 .htaccess（rescue.php 不含敏感信息，令牌另行签发）。
     *
     * @param string $dir 快照目录（结尾带 /）
     * @return void
     */
    protected function writeRescueArtifacts($dir)
    {
        if (!is_file($dir . 'rescue.php')) {
            @file_put_contents($dir . 'rescue.php', $this->rescueScriptSource(), LOCK_EX);
        }
        if (!is_file($this->snapshotRoot . '.htaccess')) {
            @file_put_contents($this->snapshotRoot . '.htaccess', $this->htaccessSource(), LOCK_EX);
        }
    }

    /**
     * storage/snapshot 目录防护（镜像根 .htaccess 的 <FilesMatch> + Order/Deny 风格）。
     *
     * @return string
     */
    protected function htaccessSource()
    {
        return "<FilesMatch \"\\.(token|json|sql|zip)$\">\n"
            . "    # Apache 2.4以下\n"
            . "    Order Deny,Allow\n"
            . "    Deny from all\n"
            . "\n"
            . "    # Apache 2.4以上\n"
            . "    # Require all denied\n"
            . "</FilesMatch>\n";
    }

    /**
     * 读 progress.json。
     *
     * @param string $dir
     * @return array|null
     */
    protected function readProgress($dir)
    {
        $file = rtrim($dir, '/') . '/progress.json';
        if (!is_file($file)) {
            return null;
        }
        $progress = json_decode((string) @file_get_contents($file), true);

        return is_array($progress) ? $progress : null;
    }

    /**
     * @param string $dir
     * @param array $progress
     * @return void
     */
    protected function saveProgress($dir, array $progress)
    {
        @file_put_contents(rtrim($dir, '/') . '/progress.json', json_encode($progress), LOCK_EX);
    }

    /**
     * @return array|null
     */
    protected function readActive()
    {
        $file = $this->snapshotRoot . 'active.json';
        if (!is_file($file)) {
            return null;
        }
        $active = json_decode((string) @file_get_contents($file), true);

        return is_array($active) ? $active : null;
    }

    /**
     * @param array $data
     * @return void
     */
    protected function writeActive(array $data)
    {
        @file_put_contents($this->snapshotRoot . 'active.json', json_encode($data), LOCK_EX);
    }

    /**
     * 标准化 scope_id（文件名安全；空值回退 pkg）。
     *
     * @param string $scopeId
     * @return string
     */
    protected function sanitizeScopeId($scopeId)
    {
        $scopeId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $scopeId);
        $scopeId = substr($scopeId, 0, 60);
        if ($scopeId === '') {
            $scopeId = 'pkg';
        }

        return $scopeId;
    }

    /**
     * step 结果封装。
     *
     * @param bool $ok
     * @param bool $done
     * @param string $phase
     * @param string $dirName
     * @param array $logs
     * @param string $error
     * @return array
     */
    protected function result($ok, $done, $phase, $dirName, array $logs, $error)
    {
        return array(
            'ok' => $ok,
            'done' => $done,
            'phase' => $phase,
            'dir' => $dirName,
            'progress' => '',
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

    /**
     * 独立救援脚本源码（不依赖站点框架；令牌校验 → 确认页 → 执行恢复 → 自删）。
     *
     * 恢复动作：files.zip 覆盖解压 → 镜像清理快照外新增文件（仅 file_mode=full）→
     * 按 db/ 分卷顺序导入数据库（引号感知切分，兼容转义引号与 SQL 字符串内换行）→
     * 清模板缓存 → 自删 rescue.php 与 rescue.token。
     *
     * @return string
     */
    protected function rescueScriptSource()
    {
        return <<<'RESCUE_SCRIPT'
<?php
/**
 * DouPHP 升级还原点 · 独立救援脚本
 *
 * 由 SnapshotService 生成并随快照目录存放，不依赖站点框架代码：
 * 升级中断导致后台无法进入时，用浏览器访问本脚本，把本次升级前的文件与数据库恢复回去。
 * 用法：GET 展示确认页；POST（confirm=1）执行恢复；成功后自删本脚本与令牌文件。
 * 本文件不含数据库凭据等敏感信息，令牌仅存 sha256。
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
@set_time_limit(0);
@ini_set('memory_limit', '512M');
// PHP 8.1+ 默认 mysqli 抛异常（MYSQLI_REPORT_ERROR|STRICT）：救援必须按返回值计失败并继续
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

$baseDir = rtrim(str_replace('\\', '/', dirname(__FILE__)), '/');
$rootDir = rtrim(str_replace('\\', '/', dirname(dirname(dirname($baseDir)))), '/') . '/';

function ds_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function ds_render($title, $bodyHtml)
{
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . ds_h($title) . '</title>'
        . '<style>body{font:14px/1.7 -apple-system,"Segoe UI","Microsoft YaHei",sans-serif;margin:0;background:#f5f6f8;color:#2b2f36}'
        . '.wrap{max-width:760px;margin:40px auto;background:#fff;border-radius:8px;padding:28px 32px;box-shadow:0 2px 12px rgba(0,0,0,.06)}'
        . 'h1{font-size:20px;margin:0 0 12px}.err{color:#c0392b}.ok{color:#1e8e3e}'
        . '.log{background:#0f172a;color:#d7e3f4;padding:14px 16px;border-radius:6px;font:12px/1.8 Consolas,Menlo,monospace;white-space:pre-wrap;word-break:break-all;max-height:420px;overflow:auto}'
        . 'a.btn,.btn{display:inline-block;margin-top:14px;padding:9px 22px;background:#2f6fed;color:#fff;border:0;border-radius:5px;text-decoration:none;cursor:pointer;font-size:14px}'
        . '.btn-danger{background:#c0392b}.muted{color:#8a9099;font-size:12px}</style></head><body><div class="wrap"><h1>' . ds_h($title) . '</h1>' . $bodyHtml . '</div></body></html>';
    exit;
}

function ds_out($log, $cls, $msg)
{
    $log .= '<div class="' . $cls . '">' . ds_h($msg) . "</div>\n";
    echo str_repeat(' ', 4096) . "\n";
    @ob_flush();
    @flush();

    return $log;
}

// ——— 令牌校验（一次性；sha256 比对；24 小时有效期） ———
$tokenFile = $baseDir . '/rescue.token';
$tokenHash = '';
$tokenExpires = 0;
if (is_file($tokenFile)) {
    $data = json_decode((string) @file_get_contents($tokenFile), true);
    if (is_array($data)) {
        $tokenHash = isset($data['token_sha256']) ? (string) $data['token_sha256'] : '';
        $tokenExpires = isset($data['expires_at']) ? (int) $data['expires_at'] : 0;
    }
}
$given = isset($_REQUEST['token']) ? (string) $_REQUEST['token'] : '';
if (!($tokenHash !== '' && $given !== '' && hash_equals($tokenHash, hash('sha256', $given)))) {
    http_response_code(403);
    ds_render('无效请求', '<p class="err">令牌无效或已失效，无法执行恢复。</p>');
}
if ($tokenExpires > 0 && time() > $tokenExpires) {
    ds_render('令牌已过期', '<p class="err">救援令牌已过期（有效期 24 小时）。</p>'
        . '<p>如确需恢复：可在服务器上把本目录 rescue.token 中的 expires_at 改为一个未来的 Unix 时间戳后重试；'
        . '或登录后台在「升级还原点」页重新生成急救链接。</p>');
}

$manifest = null;
if (is_file($baseDir . '/manifest.json')) {
    $manifest = json_decode((string) @file_get_contents($baseDir . '/manifest.json'), true);
}
if (!is_array($manifest)) {
    ds_render('还原点损坏', '<p class="err">manifest.json 缺失或损坏，无法执行恢复。</p>');
}

// ——— 确认页（GET） ———
if (empty($_POST['confirm'])) {
    $zipFile = $baseDir . '/files.zip';
    $zipInfo = is_file($zipFile) ? '有（' . number_format((float) @filesize($zipFile)) . ' 字节）' : '无';
    $dbCount = count((array) (isset($manifest['db_files']) ? $manifest['db_files'] : array()));
    $body = '<p>即将把本还原点备份的<b>文件</b>与<b>数据库</b>恢复回站点。</p>'
        . '<ul><li>还原点：' . ds_h(isset($manifest['title']) && $manifest['title'] !== '' ? $manifest['title'] : $baseDir) . '</li>'
        . '<li>创建时间：' . ds_h(!empty($manifest['created_at']) ? date('Y-m-d H:i:s', (int) $manifest['created_at']) : '-') . '</li>'
        . '<li>文件包：' . ds_h($zipInfo) . '</li>'
        . '<li>数据库分卷：' . $dbCount . ' 个</li></ul>'
        . '<p class="err">恢复为覆盖写操作，且不可自动撤销；如有条件请先单独备份当前数据库。</p>'
        . '<form method="post" onsubmit="this.querySelector(\'button\').disabled=true;this.querySelector(\'button\').textContent=\'正在恢复…\';return true;">'
        . '<input type="hidden" name="token" value="' . ds_h($given) . '">'
        . '<input type="hidden" name="confirm" value="1">'
        . '<button type="submit" class="btn btn-danger">开始恢复</button></form>';
    ds_render('DouPHP 救援恢复', $body);
}

// ——— 执行恢复（POST） ———
$log = '';

// 1. 文件恢复
$zipFile = $baseDir . '/files.zip';
if (is_file($zipFile)) {
    if (!class_exists('ZipArchive')) {
        ds_render('环境不支持', '<p class="err">当前 PHP 未启用 zip 扩展（ZipArchive），无法恢复文件。请启用扩展后重试。</p>');
    }
    $za = new ZipArchive();
    if (@$za->open($zipFile) !== true) {
        ds_render('打开文件包失败', '<p class="err">files.zip 无法打开。</p>');
    }
    $total = (int) $za->numFiles;
    $log = ds_out($log, 'muted', '开始恢复文件（共 ' . $total . ' 项）…');
    $failedFiles = 0;
    for ($i = 0; $i < $total; $i++) {
        $name = $za->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        $target = $rootDir . ltrim(str_replace('\\', '/', (string) $name), '/');
        $parent = dirname($target);
        if (!is_dir($parent)) {
            @mkdir($parent, 0777, true);
        }
        $stream = @$za->getStream($name);
        if (!$stream) {
            $failedFiles++;
            $log = ds_out($log, 'err', '提取失败：' . $name);
            continue;
        }
        $content = stream_get_contents($stream);
        fclose($stream);
        if (@file_put_contents($target, $content) === false) {
            @unlink($target);
            if (@file_put_contents($target, $content) === false) {
                $failedFiles++;
                $log = ds_out($log, 'err', '写入失败：' . $name);
                continue;
            }
        }
        if (($i + 1) % 500 === 0) {
            $log = ds_out($log, 'muted', '文件恢复 ' . ($i + 1) . '/' . $total . ' …');
        }
    }
    $za->close();
    if ($failedFiles > 0) {
        ds_render('恢复未完成', '<p class="err">有 ' . $failedFiles . ' 个文件恢复失败，已中止（数据库未改动）。请检查目录权限后重试。</p><div class="log">' . $log . '</div>');
    }
    $log = ds_out($log, 'ok', '文件恢复完成：' . $total . ' 项');
} else {
    $log = ds_out($log, 'muted', '本还原点不含文件包，跳过文件恢复。');
}

// 2. 镜像清理：删除快照外的新增文件（仅 file_mode=full 的系统级快照）
if ((isset($manifest['file_mode']) ? $manifest['file_mode'] : '') === 'full') {
    $log = ds_out($log, 'muted', '清理升级新增的文件…');
    $known = array();
    foreach ((array) (isset($manifest['files']) ? $manifest['files'] : array()) as $rel) {
        $known[str_replace('\\', '/', (string) $rel)] = true;
    }
    $exclude = array('images', 'storage', '.git', '.svn', '.dou', 'node_modules');
    $strays = array();
    $dirs = array();
    ds_walk($rootDir, '', $exclude, $known, $strays, $dirs);
    $deletedCount = 0;
    foreach ($strays as $rel) {
        if (@unlink($rootDir . $rel)) {
            $deletedCount++;
        }
    }
    // 仅删除空目录（rmdir），按深度倒序
    usort($dirs, function ($a, $b) {
        $da = substr_count($a, '/');
        $db = substr_count($b, '/');
        if ($da !== $db) {
            return $db - $da;
        }
        return strcmp($b, $a);
    });
    foreach ($dirs as $dAbs) {
        @rmdir($dAbs);
    }
    $log = ds_out($log, 'ok', '清理完成：删除 ' . $deletedCount . ' 个快照外文件');
} else {
    $log = ds_out($log, 'muted', '本还原点非全量文件模式，跳过镜像清理。');
}

// 3. 数据库恢复
$dbFiles = (array) (isset($manifest['db_files']) ? $manifest['db_files'] : array());
if ($dbFiles) {
    $cfgFile = $rootDir . 'config/config.php';
    if (!is_file($cfgFile)) {
        ds_render('缺少数据库配置', '<p class="err">config/config.php 不存在，无法恢复数据库。</p><div class="log">' . $log . '</div>');
    }
    $dbhost = '';
    $dbname = '';
    $dbuser = '';
    $dbpass = '';
    $prefix = '';
    include $cfgFile;

    $mysqli = @new mysqli($dbhost, $dbuser, $dbpass, $dbname);
    if ($mysqli->connect_errno) {
        ds_render('数据库连接失败', '<p class="err">连接数据库失败：' . ds_h($mysqli->connect_error) . '</p><div class="log">' . $log . '</div>');
    }
    if (!@$mysqli->set_charset('utf8mb4')) {
        @$mysqli->set_charset('utf8');
    }
    @$mysqli->query("SET SESSION sql_mode=''");

    $log = ds_out($log, 'muted', '开始恢复数据库（共 ' . count($dbFiles) . ' 个分卷）…');
    $failedSql = 0;
    $doneSql = 0;
    foreach ($dbFiles as $index => $dbFile) {
        $name = isset($dbFile['name']) ? (string) $dbFile['name'] : '';
        $path = $baseDir . '/db/' . basename($name);
        if (!is_file($path)) {
            $failedSql++;
            $log = ds_out($log, 'err', '分卷缺失：' . $name);
            continue;
        }
        foreach (ds_split_sql((string) file_get_contents($path)) as $stmt) {
            if (@$mysqli->query($stmt)) {
                $doneSql++;
            } else {
                $failedSql++;
                if ($failedSql <= 20) {
                    $log = ds_out($log, 'err', '语句失败：' . substr($stmt, 0, 120));
                }
            }
        }
        $log = ds_out($log, 'muted', '数据库分卷 ' . ($index + 1) . '/' . count($dbFiles) . ' 完成');
    }
    @$mysqli->close();
    if ($failedSql > 0) {
        $log = ds_out($log, 'err', '有 ' . $failedSql . ' 条语句执行失败（成功 ' . $doneSql . ' 条），请核查。');
    } else {
        $log = ds_out($log, 'ok', '数据库恢复完成（' . $doneSql . ' 条语句）');
    }
} else {
    $log = ds_out($log, 'muted', '本还原点不含数据库备份，跳过数据库恢复。');
}

// 4. 清模板缓存
$tplDir = $rootDir . 'storage/cache/template/';
if (is_dir($tplDir)) {
    ds_rmdir_children($tplDir);
    $log = ds_out($log, 'ok', '模板缓存已清理');
}

// 5. 一次性保障：令牌先作废（安全关键）；脚本自删在「执行中文件被占用」的环境
//    （典型为 Windows）可能失败，此时令牌已失效、残留脚本无令牌不可用，提示手动删除
@unlink($tokenFile);
$selfDeleted = @unlink(__FILE__);
$log = ds_out($log, 'ok', $selfDeleted
    ? '救援令牌已作废，脚本已自删。恢复完成，请立即访问站点核查。'
    : '救援令牌已作废（脚本未能自删：执行中的文件可能被系统占用，请手动删除本文件）。恢复完成，请立即访问站点核查。');

ds_render('恢复完成', '<p class="ok">恢复流程已执行完毕。</p><div class="log">' . $log . '</div>'
    . '<p class="muted">如站点仍异常，请检查目录权限或联系服务商。</p>');

/**
 * 递归收集快照外新增文件（相对路径）与全部目录（供空目录清理）。
 */
function ds_walk($absDir, $relPrefix, array $exclude, array $known, array &$strays, array &$dirs)
{
    $handle = @opendir($absDir);
    if (!$handle) {
        return;
    }
    while (($item = @readdir($handle)) !== false) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $abs = $absDir . '/' . $item;
        $rel = $relPrefix . $item;
        if (is_link($abs)) {
            continue;
        }
        if (is_dir($abs)) {
            if ($relPrefix === '' && in_array($item, $exclude, true)) {
                continue;
            }
            $dirs[] = $abs;
            ds_walk($abs, $rel . '/', $exclude, $known, $strays, $dirs);
        } elseif (is_file($abs)) {
            if (!isset($known[$rel])) {
                $strays[] = $rel;
            }
        }
    }
    closedir($handle);
}

/**
 * 引号感知的 SQL 语句切分（兼容反斜杠转义与 '' 双写引号；字符串内换行 / 分号安全）。
 */
function ds_split_sql($sql)
{
    $statements = array();
    $current = '';
    $length = strlen($sql);
    $inQuote = false;
    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i];
        if ($inQuote) {
            $current .= $ch;
            if ($ch === '\\' && $i + 1 < $length) {
                $current .= $sql[$i + 1];
                $i++;
                continue;
            }
            if ($ch === "'") {
                if ($i + 1 < $length && $sql[$i + 1] === "'") {
                    $current .= $sql[$i + 1];
                    $i++;
                    continue;
                }
                $inQuote = false;
            }
            continue;
        }
        if ($ch === "'") {
            $inQuote = true;
            $current .= $ch;
            continue;
        }
        if ($ch === ';') {
            $statement = trim($current);
            $current = '';
            $statement = trim(preg_replace('/^(?:--[^\n]*\n)+/', '', $statement));
            if ($statement !== '') {
                $statements[] = $statement;
            }
            continue;
        }
        $current .= $ch;
    }
    $statement = trim($current);
    $statement = trim(preg_replace('/^(?:--[^\n]*\n)+/', '', $statement));
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

/**
 * 清空目录内容（保留目录本身）。
 */
function ds_rmdir_children($dir)
{
    $handle = @opendir($dir);
    if (!$handle) {
        return;
    }
    while (($item = @readdir($handle)) !== false) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = rtrim($dir, '/') . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            ds_rmdir_children($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    closedir($handle);
}
RESCUE_SCRIPT;
    }
}
