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
 * Release Date: 2026-09-08
 */

namespace Dou\Admin\Service\Backup;

use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Facade\Zip;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Infra\Database\DbDumpService;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\DbVersion;
use Dou\Core\Support\FileHelper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台数据备份/恢复业务（供 BackupController 调用）。
 *
 * 职责：组织表清单与备份文件列表、执行分卷备份/导入 SQL、删除与下载备份。
 */
class BackupService extends BaseService
{
    /** @var int 最近一次 sql_dumptable 结束时的偏移（供备份续传 URL 使用） */
    protected $lastDumpStartrow = 0;

    /** @var bool 最近一次 sqlDumptable 是否已把该表整表导完（供分卷续传判定下一卷起点） */
    protected $lastTableComplete = false;

    /** @var DbDumpService 共享导出引擎（core，快照通道同源） */
    protected $dbDump;

    /**
     * 备份包解压条目白名单。
     *
     * 备份包由 {@see packBackup()} 生成，SQL 直接放在包根目录，资源保留 `images/**` 路径，
     * 还原时先解压到隔离目录。
     * 这里限定只放行这两类条目，杜绝包内夹带 `admin/x.php` 之类的
     * 合法相对路径覆盖站内文件；`images/` 下再排除可执行与配置扩展名。
     *
     * @var array
     */
    protected static $restoreAllowRules = array(
        '*.sql',
        'images/**',
    );

    /** @var array images/ 下禁止落盘的扩展名（大小写不敏感） */
    protected static $restoreDeniedExtensions = array('php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'htaccess');

    /**
     */
    public function __construct()
    {
        $backupDir = STORAGE_PATH . 'backup/';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0777, true);
        }
        $this->dbDump = new DbDumpService();
    }

    /**
     * 备份页：按表前缀列出库表及占用大小，供选择备份。
     *
     * @param string $prefix 表名前缀（与站点表前缀一致）
     * @return array array('table_list' => array, 'totalsize' => int)
     */
    public function buildTableList($prefix)
    {
        $table_list = array();
        $totalsize = 0;
        $query = DB::query("SHOW TABLE STATUS LIKE '" . $prefix . "%'");
        while ($table = DB::fetchArray($query)) {
            $table['checked'] = ($table['Engine'] === 'MyISAM') ? ' ' : 'disabled';
            $totalsize += $table['Data_length'] + $table['Index_length'];
            if ($table['Data_length'] > 10240) {
                $table['Data_length'] = ceil($table['Data_length'] / 1024) . 'KB';
            }
            $table_list[] = $table;
        }
        $totalsize = ceil($totalsize / 1024);

        return array('table_list' => $table_list, 'totalsize' => $totalsize);
    }

    /**
     * 恢复页：转换旧 SQL 后，按时间倒序列出 ZIP 备份。
     *
     * @return array 备份文件行数据列表
     */
    public function buildRestoreFileList()
    {
        $this->convertLegacyBackups();

        return (array) FileHelper::buildFileListByTime((array) glob(STORAGE_PATH . 'backup/*.zip'));
    }

    /**
     * 在文件锁内转换旧 SQL，避免多个还原页重复打包同一套文件。
     *
     * @return void
     */
    protected function convertLegacyBackups()
    {
        $lock = @fopen(STORAGE_PATH . 'state/backup_convert.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) {
                fclose($lock);
            }
            throw new DomainException(lang('backup_zip_lock_failed'), route('admin.backup'));
        }
        try {
            $backup_filename = '';
            if (is_file(STORAGE_PATH . 'backup/tables.php')) {
                include STORAGE_PATH . 'backup/tables.php';
            }
            $sets = array();
            foreach ((array) glob(STORAGE_PATH . 'backup/*.sql') as $file) {
                $name = basename($file);
                $stem = FileHelper::filename($name);
                $volume = preg_match('/^(.+)_([0-9]+)\.sql$/', $name, $match);
                if ($volume) {
                    $stem = $match[1];
                }
                if (!$this->isBackupFile($name) || $stem === $backup_filename) {
                    continue;
                }
                // 独立 SQL 与同名分卷必须分组，不能混装到一个归档中。
                $key = ($volume ? 'volume:' : 'single:') . $stem;
                if (!isset($sets[$key])) {
                    $sets[$key] = array('stem' => $stem, 'files' => array());
                }
                $sets[$key]['files'][] = $file;
            }
            foreach ($sets as $set) {
                $mtime = 0;
                foreach ($set['files'] as $file) {
                    $mtime = max($mtime, filemtime($file));
                }
                $suffix = 0;
                do {
                    $zipfile = STORAGE_PATH . 'backup/' . $set['stem']
                        . ($suffix ? '_legacy_' . $suffix : '') . '.zip';
                    if (!file_exists($zipfile) || $this->zipMatchesSql($zipfile, $set['files'], true)) {
                        break;
                    }
                    $suffix++;
                } while (true);
                if (!file_exists($zipfile)) {
                    $this->packBackup($zipfile, $set['files'], false, $mtime);
                }
                $this->deleteSqlFiles($set['files'], $zipfile);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 执行分卷 SQL 备份（可含打包资源目录）；多轮 dou_msg.htm 跳转续传直至完成（由 Controller 调 `respondDeleteResult` 走二次确认分支）。
     *
     * @param string $prefix 表名前缀
     * @param array $req 请求参数（键名：act、tables、vol_size 等）
     * @return array message、back_url、timeout、confirm_url
     * @throws DomainException 参数 / 写入 / 选择不合法时抛出
     */
    public function runBackup($prefix, array $req)
    {
        $lock = @fopen(STORAGE_PATH . 'state/backup_convert.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) {
                fclose($lock);
            }
            throw new DomainException(lang('backup_zip_lock_failed'), route('admin.backup'));
        }
        try {
            return $this->runBackupLocked($prefix, $req);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 在转换锁内导出分卷并生成 ZIP，避免工作 SQL 被还原页提前打包。
     *
     * @param string $prefix 表名前缀
     * @param array $req 备份请求参数
     * @return array 消息及续传地址
     */
    protected function runBackupLocked($prefix, array $req)
    {
        $act = Check::rec(Arr::get($req, 'act', '')) ? $req['act'] : 'select';
        $restore_filename = Arr::get($req, 'restore_filename', '');
        $back = !empty($req['back']) ? $req['back'] : route('admin.backup.restore');

        if ($restore_filename !== '') {
            $package = $this->inspectRestorePackage($restore_filename);
            $asset = $package['asset'] ? 'yes' : 'no';
        } else {
            $asset = Check::rec(Arr::get($req, 'asset', '')) ? $req['asset'] : 'no';
        }

        $volid = isset($req['volid']) ? $req['volid'] : 1;
        // 分卷大小（KB）：下限 64K，防止误输入 0/非数字导致空转分卷
        $vol_size = isset($req['vol_size']) && intval($req['vol_size']) > 0 ? intval($req['vol_size']) : 2048;
        $vol_size = max(64, $vol_size);

        if ($act === 'all') {
            $totalsize = 0;
            $tables = array();
            $query = DB::query("SHOW TABLE STATUS LIKE '" . $prefix . "%'");
            while ($table = DB::fetchArray($query)) {
                $totalsize += $table['Data_length'] + $table['Index_length'];
                $tables[] = $table['Name'];
            }
            $totalsize = ceil($totalsize / 1024);
            $reqFilename = (string) Arr::get($req, 'filename', '');
            if ($reqFilename !== ''
                && strpos($reqFilename, 'AUTO') === 0
                && $this->isBackupFile($reqFilename . '.sql')) {
                $filename = $reqFilename;
            } else {
                $filename = 'AUTO' . date('Ymd') . 'T' . date('His');
            }
        } else {
            $tables = isset($req['tables']) ? $req['tables'] : null;
            $totalsize = Arr::get($req, 'totalsize', 0);
            $filename = Arr::get($req, 'filename', '');
        }

        if (!$this->isBackupFile($filename . '.sql')) {
            throw new DomainException(lang('backup_filename_not_valid'), route('admin.backup'));
        }

        $showname = $filename . '.zip';

        if ($volid == 1) {
            // 首卷必须由请求显式给出表清单：未勾选任何表时直接回落到备份页提示，
            // 不能落进「读缓存续传」分支——缓存只在本分支校验通过后才写出，此时必然不存在
            if (!is_array($tables) || !$tables) {
                throw new DomainException(lang('backup_no_select'), route('admin.backup'));
            }
            // 新备份独占该文件名：先把同名旧备份（无后缀单卷 / 全部分卷 / zip 包）整体清掉。
            // 页面上的文件名是打开备份页时生成的，用户沿用旧页面重复提交会与既有备份同名；
            // 数据变少后本次写出的卷数更少，旧的高号分卷若残留，恢复链会按卷号连续取文件，
            // 把旧备份的后续卷一并导入，造成新旧数据混装。
            $this->purgeBackupSet($filename);
            $cache_file = STORAGE_PATH . 'backup/tables.php';
            $content = "<?php\r\n\$data = " . var_export($tables, true)
                . ";\r\n\$backup_filename = " . var_export($filename, true) . ";\r\n?>";
            if (file_put_contents($cache_file, $content, LOCK_EX) === false) {
                throw new DomainException(lang('backup_no_save'), route('admin.backup'));
            }
        } else {
            // 续传卷依赖首卷写下的表清单缓存：缓存缺失或损坏（被清理、手工删除、旧分卷 URL）
            // 时按「没有选择数据表」提示回落，避免 include 打入不存在的文件抛出致命错误
            $cache_file = STORAGE_PATH . 'backup/tables.php';
            if (!is_file($cache_file)) {
                throw new DomainException(lang('backup_no_select'), route('admin.backup'));
            }
            $data = null;
            $backup_filename = '';
            include $cache_file;
            $tables = is_array($data) ? $data : array();
            if (!$tables || $backup_filename !== $filename) {
                throw new DomainException(lang('backup_no_select'), route('admin.backup'));
            }
        }

        if (DbVersion::isAtLeast(DB::version(), '4.1') && DB::charset()) {
            DB::query("SET NAMES '" . DB::charset() . "';\n\n");
        }

        $sqldump = '';
        // tableid 即「下一卷应从哪张表开始」的下标，直接取用不再 -1。
        // 旧逻辑读取时做 -1，本意是续传被预算截断的表；但整库导完（$i==tablenumber）后
        // 它会回退重导最后一张表——若该表为空（只有 DDL、startfrom 恒为 0），每卷都非空、
        // 永远收敛不了，于是疯狂产出 ~1.4K 的空卷。改为按「上一张表是否整表导完」精确推进。
        $tableid = isset($req['tableid']) ? intval($req['tableid']) : 0;
        $startfrom = isset($req['startfrom']) ? intval($req['startfrom']) : 0;
        $tablenumber = count((array) $tables);
        // 单卷实际字节预算：min(设定值, 上传限制) 再预留安全余量
        $budget = DbDumpService::volumeBudgetBytes($vol_size);

        // 下一卷续传起点：默认「已全部导完」，for 正常跑完（$i 达到 $tablenumber）时保持此值，
        // 下一轮 for 不执行、$sqldump 为空即收尾，不会再回退重导最后一张表
        $nextTableid = $tablenumber;
        $nextStartfrom = 0;
        for ($i = $tableid; $i < $tablenumber; $i++) {
            // 本卷预算已满：当前表整体留到下一卷从头导
            if (strlen($sqldump) >= $budget) {
                $nextTableid = $i;
                $nextStartfrom = 0;
                break;
            }
            $before = strlen($sqldump);
            $sqldump .= $this->sqlDumptable($tables[$i], $vol_size, $startfrom, $before);
            $startfrom = 0;
            // 返回空串表示本卷剩余空间连新表表头都放不下，整表留给下一卷
            if (strlen($sqldump) === $before) {
                $nextTableid = $i;
                $nextStartfrom = 0;
                break;
            }
            if (!$this->lastTableComplete) {
                // 本表被预算截断（未导完）：下一卷从本表 lastDumpStartrow 续传
                $nextTableid = $i;
                $nextStartfrom = $this->lastDumpStartrow;
                break;
            }
            // 本表已整表导完：下一卷从下一张表开始
            $nextTableid = $i + 1;
            $nextStartfrom = 0;
        }

        if (trim($sqldump)) {
            $sqldump = DbDumpService::headerComment(ROOT_URL, Config::get('site.douphp_version', '')) . $sqldump;

            // 是否还有下一卷：for 正常跑完时 $nextTableid 会推进到表总数，被字节预算截断时
            // 停在当前表下标。卷后缀只由这个事实决定，不再用库大小（Data_length +
            // Index_length，含索引与 InnoDB 空间碎片）估算——数据删减后物理大小仍可能高于
            // 分卷设定，会把单卷备份硬命名成 _1；而估算偏小又会漏掉后续卷的 _1 起点，
            // 恢复链从无后缀文件开始就找不到续卷。
            $hasMore = $nextTableid < $tablenumber;
            // 主干以数字结尾时保留显式卷号，避免自定义名称被还原器误判为缺卷。
            $sql_filename = ((int) $volid > 1 || $hasMore || preg_match('/_[0-9]+$/', $filename))
                ? $filename . '_' . $volid . '.sql'
                : $filename . '.sql';
            $volid++;

            $bakfile = STORAGE_PATH . 'backup/' . $sql_filename;
            if (!is_writable(STORAGE_PATH . 'backup/')) {
                throw new DomainException(lang('backup_no_save'), route('admin.backup'));
            }
            if (file_put_contents($bakfile, $sqldump) === false) {
                throw new DomainException(lang('backup_no_save'), route('admin.backup'));
            }
            @chmod($bakfile, 0777);

            // 提示只面向最终产物：工作分卷随后会被打包并删除，不暴露 xxx_1.sql 这类中间文件名；
            // $volid 本轮已自增，实际写入的是上一号；$nextTableid 即已导完的表数
            $token_val = csrf()->token();
            return array(
                'message' => sprintf(
                    lang('backup_volume_progress'),
                    (int) $volid - 1,
                    (int) $nextTableid,
                    (int) $tablenumber,
                    $showname
                ),
                'back_url' => route('admin.backup.backup', array('act' => $act, 'asset' => $asset, 'vol_size' => $vol_size, 'totalsize' => $totalsize, 'filename' => $filename, 'token' => $token_val, 'tableid' => $nextTableid, 'volid' => $volid, 'startfrom' => $nextStartfrom, 'showname' => $showname, 'restore_filename' => $restore_filename, 'back' => $back)),
                'timeout' => '1',
                'confirm_url' => '',
            );
        } else {
            $targetSql = STORAGE_PATH . 'backup/' . $filename . '.sql';

            // 收尾时 $volid 已是「下一个待写卷号」：本次写出的卷号必然都小于它，
            // 不小于它的同名分卷只可能来自更早的备份，必须清掉，否则恢复链按卷号
            // 连续取文件时会把旧卷一起导入（首轮清理失败时的兜底）。
            foreach ($this->globVolumeFiles($filename, 'sql') as $volFile) {
                if (preg_match('/_([0-9]+)\.sql$/', basename($volFile), $volMatch)
                    && (int) $volMatch[1] >= (int) $volid) {
                    @unlink($volFile);
                }
            }
            $volFiles = $this->globVolumeFiles($filename, 'sql');

            if ((int) $volid === 2 && file_exists($targetSql)) {
                // 本次写出的是无后缀单卷（首轮已导完全部表）：残余的 _序号 卷都是旧备份
                foreach ($volFiles as $volFile) {
                    @unlink($volFile);
                }
                $volFiles = array();
            } elseif ((int) $volid === 2 && count($volFiles) === 1
                && preg_match('/_1\.sql$/', basename($volFiles[0]))
                && !preg_match('/_[0-9]+$/', $filename)) {
                // 本次只有一卷（_1）：去掉后缀；同名 .sql 已存在则先删再 rename，与不分卷路径覆盖语义一致
                if (file_exists($targetSql)) {
                    @unlink($targetSql);
                }
                @rename($volFiles[0], $targetSql);
                $volFiles = array();
            } elseif ((int) $volid > 2 && file_exists($targetSql)) {
                // 本次是多卷备份：无后缀同名文件属于更早的备份，删掉避免列表出现两条同名记录
                @unlink($targetSql);
            }
            $sqlFiles = $volFiles ? $volFiles : array($targetSql);
            $zipfile = STORAGE_PATH . 'backup/' . $filename . '.zip';
            $this->packBackup($zipfile, $sqlFiles, $asset === 'yes');
            $this->deleteSqlFiles($sqlFiles, $zipfile);
            @unlink(STORAGE_PATH . 'backup/tables.php');

            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::BACKUP, 1, (string) $showname);

            if ($restore_filename) {
                $token_val = csrf()->token();
                return array(
                    'message' => str_replace('d%', $showname, lang('backup_success_restore_auto')),
                    'back_url' => route('admin.backup.import', array(), array(
                        'query' => array(
                            'sql_filename' => $restore_filename,
                            'token' => $token_val,
                        ),
                    )),
                    'timeout' => '1',
                    'confirm_url' => '',
                );
            }

            // 收尾提示给出最终包名、大小、分卷数与是否含资源，便于用户核对产物
            return array(
                'message' => sprintf(
                    lang('backup_zip_success'),
                    $showname,
                    $this->formatSize(is_file($zipfile) ? (int) filesize($zipfile) : 0),
                    count($sqlFiles),
                    $asset === 'yes' ? lang('backup_zip_with_asset') : ''
                ),
                'back_url' => $back,
                'timeout' => '3',
                'confirm_url' => '',
            );
        }
    }

    /**
     * 从 ZIP 备份导入 SQL，续传只使用会话内已校验的卷清单。
     *
     * @param array $req ZIP 文件名 sql_filename、token、volid 等
     * @return array message、back_url、timeout、confirm_url
     * @throws DomainException 文件名不合法时抛出
     */
    public function runImport(array $req)
    {
        try {
            $filename = Arr::get($req, 'sql_filename', '');
            if (!$this->isBackupFile($filename) || strtolower(FileHelper::extension($filename)) !== 'zip') {
                throw new DomainException(lang('backup_zip_only'), route('admin.backup.restore'));
            }
            if (!is_file(STORAGE_PATH . 'backup/' . $filename)) {
                throw new DomainException(str_replace('d%', $filename, lang('backup_no_file')), route('admin.backup.restore'));
            }
            $state = Session::arr('backup_restore');
            if (!isset($req['volid'])) {
                $this->clearRestore();
                $package = $this->inspectRestorePackage($filename);
                $directory = STORAGE_PATH . 'cache/backup_restore_' . uniqid('', true) . '/';
                $state = array(
                    'filename' => $filename,
                    'directory' => $directory,
                    'files' => $package['files'],
                    'next' => 1,
                    'failed' => 0,
                );
                Session::set('backup_restore', $state);
                if (!Zip::extract(STORAGE_PATH . 'backup/' . $filename, $directory,
                    self::$restoreAllowRules, self::$restoreDeniedExtensions)) {
                    throw new DomainException(lang('backup_zip_extract_failed'), route('admin.backup.restore'));
                }
                foreach ($package['files'] as $file) {
                    if (!is_readable($directory . $file)) {
                        throw new DomainException(lang('backup_zip_sql_invalid'), route('admin.backup.restore'));
                    }
                }
                foreach ($package['entries'] as $entry) {
                    if (strpos($entry, 'images/') === 0 && substr($entry, -1) !== '/') {
                        if (!FileHelper::replace($directory . $entry, ROOT_PATH . $entry)) {
                            throw new DomainException(lang('backup_zip_asset_failed'), route('admin.backup.restore'));
                        }
                    }
                }
                return array(
                    'message' => $filename . lang('backup_file_unzip_success'),
                    'back_url' => route('admin.backup.import', array('sql_filename' => $filename, 'volid' => 1, 'token' => Arr::get($req, 'token', ''))),
                    'timeout' => '1',
                    'confirm_url' => '',
                );
            }
            $volid = (int) $req['volid'];
            if (!$state || $state['filename'] !== $filename || $volid !== $state['next']
                || !isset($state['files'][$volid - 1])) {
                throw new DomainException(lang('backup_zip_state_invalid'), route('admin.backup.restore'));
            }
            $file = $state['files'][$volid - 1];
            $sql = @file_get_contents($state['directory'] . $file);
            if ($sql === false) {
                throw new DomainException(lang('backup_zip_sql_invalid'), route('admin.backup.restore'));
            }
            $failed = 0;
            if (DB::fnExecute($sql) === false) {
                $failed = max(1, (int) DB::getImportFailedCount());
            }
            $state['failed'] += $failed;
            $state['next']++;
            Session::set('backup_restore', $state);
            if ($volid < count($state['files'])) {
                return array(
                    'message' => str_replace('d%', basename($file), lang('backup_restore_now'))
                        . ($failed ? $this->importFailedNotice($failed) : ''),
                    'back_url' => route('admin.backup.import', array('sql_filename' => $filename, 'volid' => $state['next'], 'token' => Arr::get($req, 'token', ''))),
                    'timeout' => $failed ? '5' : '1',
                    'confirm_url' => '',
                );
            }
            $this->clearRestore();
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::RESTORE, 1, $filename);
            return array(
                'message' => lang('backup_restore_success') . ($state['failed'] ? $this->importFailedNotice($state['failed']) : ''),
                'back_url' => route('admin.backup.restore'),
                'timeout' => $state['failed'] ? '5' : '3',
                'confirm_url' => '',
            );
        } catch (\Exception $e) {
            $this->clearRestore();
            throw $e;
        } catch (\Throwable $e) {
            $this->clearRestore();
            throw $e;
        }
    }

    /**
     * 校验还原包的路径和 SQL 分卷，读取是否包含资源。
     *
     * @param string $filename 备份目录中的 ZIP 文件名
     * @return array SQL 卷清单、全部条目及资源标记
     */
    protected function inspectRestorePackage($filename)
    {
        if (!$this->isBackupFile($filename) || strtolower(FileHelper::extension($filename)) !== 'zip') {
            throw new DomainException(lang('backup_zip_only'), route('admin.backup.restore'));
        }
        $entries = Zip::entries(STORAGE_PATH . 'backup/' . $filename,
            self::$restoreAllowRules, self::$restoreDeniedExtensions);
        if (!$entries) {
            throw new DomainException(lang('backup_zip_invalid'), route('admin.backup.restore'));
        }
        $entries = array_map(function ($entry) {
            return str_replace('\\', '/', $entry);
        }, $entries);
        if (count($entries) !== count(array_unique($entries))) {
            throw new DomainException(lang('backup_zip_invalid'), route('admin.backup.restore'));
        }
        $files = array();
        $asset = false;
        $stem = null;
        $single = false;
        foreach ($entries as $entry) {
            if (strpos($entry, 'images/') === 0) {
                $asset = true;
            }
            if (substr($entry, -1) === '/') {
                continue;
            }
            if (preg_match('#^([^/]+\.sql)$#D', $entry, $match)) {
                $name = $match[1];
                if (!$this->isBackupFile($name)) {
                    throw new DomainException(lang('backup_zip_sql_invalid'), route('admin.backup.restore'));
                }
                if (preg_match('/^(.+)_([0-9]+)\.sql$/D', $name, $volume)) {
                    $number = (int) $volume[2];
                    if ($single || $number < 1 || isset($files[$number])
                        || ($stem !== null && $stem !== $volume[1])) {
                        throw new DomainException(lang('backup_zip_sql_invalid'), route('admin.backup.restore'));
                    }
                    $stem = $volume[1];
                    $files[$number] = $entry;
                } else {
                    if ($files) {
                        throw new DomainException(lang('backup_zip_sql_invalid'), route('admin.backup.restore'));
                    }
                    $single = true;
                    $files[1] = $entry;
                }
            }
        }
        ksort($files, SORT_NUMERIC);
        if (!$files || array_keys($files) !== range(1, count($files))) {
            throw new DomainException(lang('backup_zip_sql_invalid'), route('admin.backup.restore'));
        }

        return array('files' => array_values($files), 'entries' => $entries, 'asset' => $asset);
    }

    /**
     * 核对包内 SQL 文件名及内容，转换重试时仅复用没有资源的同内容归档。
     *
     * @param string $zipfile 归档绝对路径
     * @param array $files SQL 源文件绝对路径列表
     * @param bool $sqlOnly 是否要求归档仅含这些 SQL
     * @return bool 是否与源文件完全一致
     */
    protected function zipMatchesSql($zipfile, array $files, $sqlOnly = false)
    {
        $entries = Zip::entries($zipfile);
        if (!$entries) {
            return false;
        }
        $expected = array();
        foreach ($files as $file) {
            $entry = basename($file);
            $expected[] = $entry;
            $content = Zip::read($zipfile, $entry);
            if ($content === false || !is_file($file) || hash('sha256', $content) !== hash_file('sha256', $file)) {
                return false;
            }
        }
        $actual = array();
        foreach ($entries as $entry) {
            if (strpos($entry, '/') === false || $sqlOnly) {
                $actual[] = $entry;
            }
        }
        sort($expected);
        sort($actual);

        return $actual === $expected;
    }

    /**
     * 写临时 ZIP 并核验 SQL 内容，成功后才发布为可见的备份包。
     *
     * @param string $zipfile 最终归档绝对路径
     * @param array $files SQL 源文件绝对路径列表
     * @param bool $asset 是否加入资源目录
     * @param int $mtime 旧备份的修改时间，0 表示使用当前时间
     * @return void
     */
    protected function packBackup($zipfile, array $files, $asset, $mtime = 0)
    {
        $temporary = $zipfile . '.tmp_' . uniqid('', true);
        $withAssets = $asset && is_dir(ROOT_PATH . 'images');
        try {
            // 资源保留站点相对路径，SQL 单独追加以去掉备份目录层级。
            $result = Zip::create($temporary, $withAssets ? array(ROOT_PATH . 'images') : $files,
                $withAssets ? ROOT_PATH : STORAGE_PATH . 'backup/');
            if (!empty($result['ok']) && $withAssets) {
                $result = Zip::append($temporary, $files, STORAGE_PATH . 'backup/');
            }
            if (empty($result['ok']) || !$this->zipMatchesSql($temporary, $files)) {
                throw new DomainException(str_replace('d%', basename($zipfile), lang('backup_zip_create_failed')), route('admin.backup.restore'));
            }
            if ($mtime && !@touch($temporary, $mtime)) {
                throw new DomainException(lang('backup_no_save'), route('admin.backup.restore'));
            }
            if (!@rename($temporary, $zipfile)) {
                throw new DomainException(lang('backup_no_save'), route('admin.backup.restore'));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * 删除已核验的源 SQL，部分删除失败时从 ZIP 补回，保留完整重试入口。
     *
     * @param array $files SQL 源文件绝对路径列表
     * @param string $zipfile 已核验的归档绝对路径
     * @return void
     */
    protected function deleteSqlFiles(array $files, $zipfile)
    {
        $deleted = array();
        foreach ($files as $file) {
            $mtime = filemtime($file);
            if (!@unlink($file)) {
                foreach ($deleted as $source => $time) {
                    $content = Zip::read($zipfile, basename($source));
                    if ($content !== false && @file_put_contents($source, $content) !== false) {
                        @touch($source, $time);
                    }
                }
                throw new DomainException(str_replace('d%', basename($file), lang('backup_zip_cleanup_failed')), route('admin.backup.restore'));
            }
            $deleted[$file] = $mtime;
        }
    }

    /**
     * 清理当前还原的隔离目录和会话状态。
     *
     * @return void
     */
    protected function clearRestore()
    {
        $state = Session::arr('backup_restore');
        if (!empty($state['directory'])
            && preg_match('#^' . preg_quote(STORAGE_PATH . 'cache/backup_restore_', '#') . '[a-z0-9.]+/$#D', $state['directory'])) {
            FileHelper::delDir($state['directory']);
        }
        Session::del('backup_restore');
    }

    /**
     * 导入后存在失败语句时的提示后缀。
     *
     * fnExecute 返回 false 表示即便临时放宽 sql_mode 仍有语句执行失败，
     * 此时不能静默展示“正在恢复/恢复成功”，须追加失败条数提醒用户核查。
     *
     * @param int $failed 执行失败的语句数
     * @return string
     */
    protected function importFailedNotice($failed)
    {
        return preg_replace('/d%/Ums', $failed, lang('backup_restore_partial'));
    }

    /**
     * 字节数格式化（提示文案用）。
     *
     * @param int $bytes
     * @return string
     */
    private function formatSize($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * 删除 ZIP 备份；未确认时由 Controller 调 `respondDeleteResult` 走 dou_msg.htm 二次确认。
     *
     * @param array $req
     * @param array $post 含 confirm 时表示已确认删除
     * @return array message、back_url、timeout、confirm_url
     * @throws DomainException 文件名不合法 / 备份文件不存在 / 无写权限时抛出
     */
    public function runDelete(array $req, array $post)
    {
        if (!$this->isBackupFile(Arr::get($req, 'sql_filename', ''))
            || strtolower(FileHelper::extension($req['sql_filename'])) !== 'zip') {
            throw new DomainException(lang('backup_filename_not_valid'), route('admin.backup'));
        }
        $sql_filename = $req['sql_filename'];
        $targets = is_file(STORAGE_PATH . 'backup/' . $sql_filename) ? array($sql_filename) : array();

        if (!$targets) {
            throw new DomainException(preg_replace('/d%/Ums', $sql_filename, lang('backup_no_file')), route('admin.backup.restore'));
        }

        if (isset($post['confirm'])) {
            $deleted = array();
            foreach ($targets as $target) {
                if (@unlink(STORAGE_PATH . 'backup/' . $target)) {
                    $deleted[] = $target;
                }
            }
            if (!$deleted) {
                throw new DomainException(preg_replace('/d%/Ums', implode(', ', $targets), lang('backup_delete_fail')), route('admin.backup.restore'));
            }
            $summary = implode(', ', $deleted);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $summary, 'backup');

            return array(
                'message' => preg_replace('/d%/Ums', $summary, lang('backup_delete_success')),
                'back_url' => route('admin.backup.restore'),
                'timeout' => '3',
                'confirm_url' => '',
            );
        }

        return array(
            'message' => preg_replace('/d%/Ums', $sql_filename, lang('del_check')),
            'back_url' => route('admin.backup.restore'),
            'timeout' => '30',
            'confirm_url' => route('admin.backup.destroy', array(), array('query' => array('sql_filename' => $sql_filename))),
        );
    }

    /**
     * 清理同名旧备份（无后缀单卷 / 全部分卷 / zip 包），让本次备份独占该文件名。
     *
     * 备份文件名既可由页面输入框指定，也会在用户沿用旧备份页表单重复提交时保持不变；
     * 数据变少后本次写出的卷数可能少于旧备份，旧的高号分卷若不清掉，恢复链会按卷号
     * 连续取文件，把旧备份的后续卷一并导入（表现为「恢复后出现早已删除的数据」）。
     *
     * @param string $filename 不含扩展名的备份文件名
     * @return void
     */
    protected function purgeBackupSet($filename)
    {
        $filename = (string) $filename;
        if ($filename === '') {
            return;
        }

        foreach (array($filename . '.sql', $filename . '.zip') as $name) {
            $file = STORAGE_PATH . 'backup/' . $name;
            if (is_file($file)) {
                @unlink($file);
            }
        }
        foreach ($this->globVolumeFiles($filename, 'sql') as $volume) {
            @unlink($volume);
        }
    }

    /**
     * 流式输出备份文件下载（直接写响应头与二进制内容）。
     *
     * @param array $req sql_filename
     * @return void
     */
    public function streamDownload(array $req)
    {
        if (!$this->isBackupFile(Arr::get($req, 'sql_filename', ''))
            || strtolower(FileHelper::extension($req['sql_filename'])) !== 'zip') {
            throw new DomainException(lang('backup_filename_not_valid'), route('admin.backup'));
        }
        $sql_filename = $req['sql_filename'];

        ob_clean();
        $fp = @fopen(STORAGE_PATH . 'backup/' . $sql_filename, 'r');
        if ($fp) {
            header('Content-type: application/zip');
            header('Content-Disposition: attachment; filename=' . $sql_filename);
            header('Accept-Ranges: bytes');
            header('Content-Length:' . filesize(STORAGE_PATH . 'backup/' . $sql_filename));
            header('Content-transfer-encoding: binary');
            while (!@feof($fp)) {
                echo fread($fp, 10240);
            }
        }
    }

    /**
     * 单表 SQL 分卷导出片段（续传依赖 lastDumpStartrow）。
     *
     * 引擎已抽取到 core 层 {@see DbDumpService::dumpTable()}（快照通道同源），
     * 本方法仅做「游标回填 + 缓存文件清理」的兼容包装。
     *
     * @param mixed $table
     * @param mixed $vol_size
     * @param int $startfrom
     * @param int $currsize
     * @return string
     * @throws DomainException 表名不合法时抛出
     */
    protected function sqlDumptable($table, $vol_size, $startfrom = 0, $currsize = 0)
    {
        try {
            $chunk = $this->dbDump->dumpTable($table, $startfrom, $currsize, DbDumpService::volumeBudgetBytes($vol_size));
        } catch (DomainException $e) {
            // 表名非法属「缓存被篡改/损坏」路径：清掉 tables.php 缓存再向上抛
            @unlink(STORAGE_PATH . 'backup/tables.php');
            throw $e;
        }

        $this->lastDumpStartrow = $chunk['next_startrow'];
        $this->lastTableComplete = $chunk['complete'];

        return $chunk['sql'];
    }

    /**
     * 按「文件名_数字.扩展名」严格列出分卷文件。
     *
     * 不能用宽松的 `文件名_*.扩展名` 通配，否则同前缀的普通备份
     * （如 dou_ai_install.sql 之于 dou_ai）会被误当成分卷，导致
     * 分卷统计错误、收尾去 _1 失败，甚至被打包/删除。
     *
     * @param string $filename 不含扩展名的文件名
     * @param string $ext 扩展名（如 sql）
     * @return array 匹配到的分卷文件完整路径列表
     */
    protected function globVolumeFiles($filename, $ext)
    {
        $pattern = '/^' . preg_quote($filename, '/') . '_[0-9]+\.' . preg_quote($ext, '/') . '$/';
        $files = array();
        foreach ((array) glob(STORAGE_PATH . 'backup/' . $filename . '_*.' . $ext) as $file) {
            if (preg_match($pattern, basename($file))) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * 校验是否为合法备份文件名（storage/backup/ 下的纯文件名，扩展名 sql/zip）。
     *
     * 作为 runBackup/runImport/runDelete/streamDownload 的统一闸门：
     * 拒绝任何目录分隔符 `/ \`、盘符 `:`、空字节与 `..` 穿越片段，
     * 确保后续 `STORAGE_PATH . 'backup/' . $filename` 不会逃逸到备份目录之外。
     *
     * @param mixed $filename
     * @return bool
     */
    protected function isBackupFile($filename)
    {
        $filename = is_string($filename) ? $filename : '';
        if ($filename === '') {
            return false;
        }
        if (strpbrk($filename, "/\\\0") !== false
            || strpos($filename, ':') !== false
            || strpos($filename, '..') !== false) {
            return false;
        }

        $ext = strtolower(FileHelper::extension($filename));

        return ($ext === 'sql' || $ext === 'zip');
    }
}
