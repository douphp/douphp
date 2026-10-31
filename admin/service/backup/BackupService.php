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
     * 备份包由 {@see runBackup()} 以 `Zip::create($zipfile, [分卷 sql..., ROOT_PATH.'images'], ROOT_PATH)`
     * 生成，包内条目形如 `storage/backup/xxx.sql` 与 `images/**`，还原时必须解压回站点根才能
     * 同时恢复 SQL 与附件。这里限定只放行这两类条目，杜绝包内夹带 `admin/x.php` 之类的
     * 合法相对路径覆盖站内文件；`images/` 下再排除可执行与配置扩展名。
     *
     * @var array
     */
    protected static $restoreAllowRules = array(
        'storage/backup/*.sql',
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
     * 恢复页：列出 storage/backup 下可用备份（按时间排序，分卷合并为一行）。
     *
     * 一套分卷只出一行：filename 取集合内序号最小的卷（删除 / 导入的真实入口），
     * showname 去掉 `_序号`，vol_number 为卷数。这样即便首卷缺失（备份中断、手工
     * 删过一套中的部分卷），其余分卷仍然可见、可删，不会变成列表里看不见也删不掉的孤儿文件。
     *
     * @return array 备份文件行数据列表
     */
    public function buildRestoreFileList()
    {
        $backup_file_list = array_merge(
            (array) glob(STORAGE_PATH . 'backup/*.sql'),
            (array) glob(STORAGE_PATH . 'backup/*.zip')
        );
        $backup_file_list = FileHelper::buildFileListByTime($backup_file_list);

        $file_list = array();
        $set_index = array();
        $set_min = array();
        foreach ((array) $backup_file_list as $row) {
            // 分卷按「_序号.sql」整体形态判定：buildFileListByTime 的 number 只截到末位数字
            // （X_10.sql 被读成 0），拿它判分卷会把 X_10.sql 当成一套独立备份
            if ($row['ext'] === 'sql' && preg_match('/^(.*)_([0-9]+)\.sql$/', $row['filename'], $match)) {
                $stem = $match[1];
                $volno = (int) $match[2];
                if (isset($set_index[$stem])) {
                    $idx = $set_index[$stem];
                    $file_list[$idx]['vol_number']++;
                    if ($volno < $set_min[$stem]) {
                        // 换代表行：展示口径（文件名 / 大小 / 时间）跟着序号最小的卷走，卷数累计保留
                        $set_min[$stem] = $volno;
                        $file_list[$idx] = $this->volumeHeadRow($row, $stem, $file_list[$idx]['vol_number']);
                    }
                    continue;
                }
                $set_index[$stem] = count($file_list);
                $set_min[$stem] = $volno;
                $file_list[] = $this->volumeHeadRow($row, $stem, 1);
                continue;
            }
            $row['vol_number'] = 1;
            $file_list[] = $row;
        }

        return $file_list;
    }

    /**
     * 把一套分卷的代表文件行整理成列表行（列名去 `_序号`、卷数、可操作标记）。
     *
     * `number` 归 1：列表一行就是一套备份的头，旧编译产物里「非首卷不给操作列」的
     * `number` 判定分支必须仍然成立，否则孤儿卷（如只剩 X_5.sql）会又看不见又删不掉。
     *
     * @param array $row buildFileListByTime 产出的单文件行
     * @param string $stem 分卷主干名（不含扩展名）
     * @param int $volNumber 已累计的卷数
     * @return array
     */
    protected function volumeHeadRow(array $row, $stem, $volNumber)
    {
        $row['showname'] = $stem . '.sql';
        $row['number'] = 1;
        $row['vol_number'] = (int) $volNumber;

        return $row;
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
        $act = Check::rec(Arr::get($req, 'act', '')) ? $req['act'] : 'select';
        $restore_filename = Arr::get($req, 'restore_filename', '');
        $back = !empty($req['back']) ? $req['back'] : route('admin.backup.restore');

        if (FileHelper::extension($restore_filename) === 'zip') {
            $asset = 'yes';
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

        $showname = $this->isBackupFile(Arr::get($req, 'showname', ''))
            ? $req['showname']
            : $filename . ($act === 'asset' ? '.zip' : '.sql');

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
            $content = "<?php\r\n\$data = " . var_export($tables, true) . ";\r\n?>";
            file_put_contents($cache_file, $content, LOCK_EX);
        } else {
            // 续传卷依赖首卷写下的表清单缓存：缓存缺失或损坏（被清理、手工删除、旧分卷 URL）
            // 时按「没有选择数据表」提示回落，避免 include 打入不存在的文件抛出致命错误
            $cache_file = STORAGE_PATH . 'backup/tables.php';
            if (!is_file($cache_file)) {
                throw new DomainException(lang('backup_no_select'), route('admin.backup'));
            }
            $data = null;
            include $cache_file;
            $tables = is_array($data) ? $data : array();
            if (!$tables) {
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
            $sql_filename = ((int) $volid > 1 || $hasMore)
                ? $filename . '_' . $volid . '.sql'
                : $filename . '.sql';
            $action_filename = $sql_filename;
            $volid++;

            $bakfile = STORAGE_PATH . 'backup/' . $sql_filename;
            if (!is_writable(STORAGE_PATH . 'backup/')) {
                throw new DomainException(lang('backup_no_save'), route('admin.backup'));
            }
            if (file_put_contents($bakfile, $sqldump) === false) {
                throw new DomainException(lang('backup_no_save'), route('admin.backup'));
            }
            @chmod($bakfile, 0777);

            lang_set('backup_file_success', preg_replace('/d%/Ums', $action_filename, lang('backup_file_success')));
            $token_val = csrf()->token();
            return array(
                'message' => lang('backup_file_success'),
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
                && preg_match('/_1\.sql$/', basename($volFiles[0]))) {
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
            @unlink(STORAGE_PATH . 'backup/tables.php');

            if ($asset === 'yes') {
                $zipfile = STORAGE_PATH . 'backup/' . $filename . '.zip';
                $vol_file_list = $this->globVolumeFiles($filename, 'sql');
                $filelist = array();
                if ($vol_file_list) {
                    foreach ($vol_file_list as $vol_file) {
                        $filelist[] = $vol_file;
                    }
                } else {
                    $filelist[] = STORAGE_PATH . 'backup/' . $filename . '.sql';
                }
                $filelist[] = ROOT_PATH . 'images';
                $zipResult = Zip::create($zipfile, $filelist, ROOT_PATH);
                if (empty($zipResult['ok'])) {
                    die('Error : ' . $zipResult['error']);
                } else {
                    if ($vol_file_list) {
                        foreach ($vol_file_list as $vol_file) {
                            @unlink($vol_file);
                        }
                    } else {
                        @unlink(STORAGE_PATH . 'backup/' . $filename . '.sql');
                    }
                }
            }

            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::BACKUP, 1, (string) $showname);

            if ($restore_filename) {
                $token_val = csrf()->token();
                return array(
                    'message' => lang('backup_success_restore_auto'),
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

            return array(
                'message' => $asset === 'yes' ? lang('backup_file_zip_success') : lang('backup_success'),
                'back_url' => $back,
                'timeout' => '3',
                'confirm_url' => '',
            );
        }
    }

    /**
     * 从备份文件导入 SQL（支持 zip、多分卷按 volid 顺序执行）。
     *
     * @param array $req sql_filename、showname、token、volid 等
     * @return array message、back_url、timeout、confirm_url
     * @throws DomainException 文件名不合法时抛出
     */
    public function runImport(array $req)
    {
        if (!$this->isBackupFile(Arr::get($req, 'sql_filename', ''))) {
            throw new DomainException(lang('backup_filename_not_valid'), route('admin.backup.restore'));
        }
        $sql_filename = $req['sql_filename'];

        $showname = $this->isBackupFile(Arr::get($req, 'showname', ''))
            ? $req['showname']
            : preg_replace('/_[0-9]+(\.sql|\.zip)$/i', '$1', $sql_filename);

        if (FileHelper::extension($sql_filename) === 'zip') {
            if (Zip::extract(STORAGE_PATH . 'backup/' . $sql_filename, ROOT_PATH, self::$restoreAllowRules, self::$restoreDeniedExtensions)) {
                $name = FileHelper::filename($sql_filename);
                $vol_file_list = $this->globVolumeFiles($name, 'sql');
                $sql_filename = $vol_file_list ? $name . '_1.sql' : $name . '.sql';
                return array(
                    'message' => $showname . lang('backup_file_unzip_success') . $sql_filename,
                    'back_url' => route('admin.backup.import', array('sql_filename' => $sql_filename, 'showname' => $showname, 'token' => (Arr::get($req, 'token', '')))),
                    'timeout' => '1',
                    'confirm_url' => '',
                );
            }
        }

        // 分卷名解析：数字组必须整段捕获（原写法 ([0-9])+ 只保留末位数字，X_12.sql
        // 会被拆成 X_1 + 12，拼出的 X_1_1.sql 不存在，恢复就此误判为完成）。
        // 未带 volid 时从文件名里的卷号续跑：首卷缺失时列表行可能是 _2，强行从 _1
        // 找起同样会「找不到就算完成」，结果一卷都没导入。
        $match = array();
        $volid = 0;
        preg_match('/^(.+)_([0-9]+)\.sql$/i', $sql_filename, $match);
        if ($match) {
            $volid = isset($req['volid']) && (int) $req['volid'] > 0
                ? (int) $req['volid']
                : (int) $match[2];
            $sql_filename = $match[1] . '_' . $volid . '.sql';
        }

        $restore_now = preg_replace('/d%/Ums', $sql_filename, lang('backup_restore_now'));
        $file_path = STORAGE_PATH . 'backup/' . $sql_filename;

        if ($match) {
            if (file_exists($file_path)) {
                // 分卷链起点（点开列表行的那一次请求不带 volid）：清空上一轮恢复遗留的失败累计。
                // 不能再用 volid === 1 判定：首卷丢失时列表行可能是 _2，从 _2 起恢复同样属于链起点。
                if (empty($req['volid'])) {
                    Session::del('backup_restore_failed');
                }
                $failed = 0;
                if (DB::fnExecute(file_get_contents($file_path)) === false) {
                    $failed = (int) DB::getImportFailedCount();
                    Session::increment('backup_restore_failed', $failed);
                }
                $volid++;
                return array(
                    'message' => $failed > 0 ? $restore_now . $this->importFailedNotice($failed) : $restore_now,
                    'back_url' => route('admin.backup.import', array('sql_filename' => $sql_filename, 'showname' => $showname, 'volid' => $volid, 'token' => (Arr::get($req, 'token', '')))),
                    'timeout' => $failed > 0 ? '5' : '1',
                    'confirm_url' => '',
                );
            } else {
                if (FileHelper::extension($showname) === 'zip') {
                    $name = FileHelper::filename($showname);
                    $vol_file_list = $this->globVolumeFiles($name, 'sql');
                    if ($vol_file_list) {
                        foreach ($vol_file_list as $vol_file) {
                            @unlink($vol_file);
                        }
                    } else {
                        @unlink(STORAGE_PATH . 'backup/' . $name . '.sql');
                    }
                }
                $failed = (int) Session::get('backup_restore_failed', 0);
                Session::del('backup_restore_failed');
                audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::RESTORE, 1, (string) $showname);
                return array(
                    'message' => $failed > 0 ? lang('backup_restore_success') . $this->importFailedNotice($failed) : lang('backup_restore_success'),
                    'back_url' => route('admin.backup.restore'),
                    'timeout' => $failed > 0 ? '5' : '3',
                    'confirm_url' => '',
                );
            }
        }

        $failed = 0;
        if (DB::fnExecute(file_get_contents($file_path)) === false) {
            $failed = (int) DB::getImportFailedCount();
        }
        if (FileHelper::extension($showname) === 'zip') {
            @unlink(STORAGE_PATH . 'backup/' . FileHelper::filename($showname) . '.sql');
        }
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::RESTORE, 1, (string) $showname);
        return array(
            'message' => $failed > 0 ? lang('backup_restore_success') . $this->importFailedNotice($failed) : lang('backup_restore_success'),
            'back_url' => route('admin.backup.restore'),
            'timeout' => $failed > 0 ? '5' : '3',
            'confirm_url' => '',
        );
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
     * 删除备份文件（分卷一并删除）；未确认时由 Controller 调 `respondDeleteResult` 走 dou_msg.htm 二次确认。
     *
     * 分卷备份在列表里只占一行（行 filename 即首卷 `X_1.sql`），删除必须连同同名其余分卷
     * 一起清掉，否则会留下列表里看不见、也就永远删不掉的孤儿分卷。
     *
     * @param array $req
     * @param array $post 含 confirm 时表示已确认删除
     * @return array message、back_url、timeout、confirm_url
     * @throws DomainException 文件名不合法 / 备份文件不存在 / 无写权限时抛出
     */
    public function runDelete(array $req, array $post)
    {
        if (!$this->isBackupFile(Arr::get($req, 'sql_filename', ''))) {
            throw new DomainException(lang('backup_filename_not_valid'), route('admin.backup'));
        }
        $sql_filename = $req['sql_filename'];
        $targets = $this->collectDeleteTargets($sql_filename);

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

        // 确认文案与结果提示同形：首卷名 + 「以及分卷 ： 其余卷」，让用户知道会一并删掉几个文件
        $others = array_slice($targets, 1);
        $label = $targets[0] . ($others ? ' ' . lang('backup_vol_include') . ' : ' . implode(', ', $others) : '');

        return array(
            'message' => preg_replace('/d%/Ums', $label, lang('del_check')),
            'back_url' => route('admin.backup.restore'),
            'timeout' => '30',
            'confirm_url' => route('admin.backup.destroy', array(), array('query' => array('sql_filename' => $sql_filename))),
        );
    }

    /**
     * 归集一次删除应落盘的文件名：点到的文件 + 它的其余分卷。
     *
     * 分卷名形如 `X_1.sql … X_N.sql`，主干取「去扩展名后再剥掉尾部 `_序号`」，因此点到的
     * 无论是哪一卷都能把整套分卷收齐。仅对 sql 生效：zip 备份包在打包时已吞掉同名分卷，
     * 删包不应连带删掉同名前缀的独立 sql 备份。
     *
     * @param string $sql_filename 相对 storage/backup 的文件名
     * @return string[] 磁盘上确实存在的文件名，点到的文件排在首位
     */
    protected function collectDeleteTargets($sql_filename)
    {
        $targets = file_exists(STORAGE_PATH . 'backup/' . $sql_filename) ? array($sql_filename) : array();

        if (FileHelper::extension($sql_filename) !== 'sql') {
            return $targets;
        }

        $stem = preg_replace('/_[0-9]+$/', '', FileHelper::filename($sql_filename));
        foreach ($this->globVolumeFiles($stem, 'sql') as $volume) {
            $volume = basename((string) $volume);
            if (!in_array($volume, $targets, true)) {
                $targets[] = $volume;
            }
        }

        return $targets;
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
        if (!$this->isBackupFile(Arr::get($req, 'sql_filename', ''))) {
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
