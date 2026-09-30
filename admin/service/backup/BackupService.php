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
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
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
     * 分卷安全余量上限（字节）。
     *
     * 实际预算 = min(分卷设定, upload_max_filesize, post_max_size) − 余量；
     * 余量用于覆盖 dump 头注释与行边界误差，确保生成文件小于设定/限制值
     * （很多服务器限制上传 2M，分卷文件超一点点就无法回传导入）。
     */
    const VOLUME_SAFETY_MARGIN = 16384;

    /**
     */
    public function __construct()
    {
        $backupDir = STORAGE_PATH . 'backup/';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0777, true);
        }
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

        if ($volid == 1 && $tables) {
            if (!is_array($tables)) {
                throw new DomainException(lang('backup_no_select'), route('admin.backup'));
            }
            $cache_file = STORAGE_PATH . 'backup/tables.php';
            $content = "<?php\r\n\$data = " . var_export($tables, true) . ";\r\n?>";
            file_put_contents($cache_file, $content, LOCK_EX);
        } else {
            include STORAGE_PATH . 'backup/tables.php';
            $tables = $data;
            if (!$tables) {
                throw new DomainException(lang('backup_no_select'), route('admin.backup'));
            }
        }

        if (DB::version() > '4.1' && DB::charset()) {
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
        $budget = $this->volumeBudgetBytes($vol_size);

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
            $sqldump = "-- DouPHP v2.x SQL Dump Program\n-- " . ROOT_URL . "\n-- \n-- DATE : " . date('Y-m-d H:i:s')
                . "\n-- MYSQL SERVER VERSION : " . DB::version()
                . "\n-- PHP VERSION : " . PHP_VERSION
                . "\n-- DouPHP VERSION : " . Config::get('site.douphp_version', '') . "\n\n" . $sqldump;

            $needsVolumeSuffix = ((int) $totalsize >= (int) $vol_size) || (int) $volid > 1;
            $sql_filename = $needsVolumeSuffix
                ? $filename . '_' . $volid . '.sql'
                : $filename . '.sql';
            $action_filename = $sql_filename;
            $volid++;

            $bakfile = STORAGE_PATH . 'backup/' . $sql_filename;
            if (!is_writable(STORAGE_PATH . 'backup/')) {
                throw new DomainException(lang('backup_no_save'), route('admin.backup'));
            }
            file_put_contents($bakfile, $sqldump);
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
            $volFiles = $this->globVolumeFiles($filename, 'sql');
            $targetSql = STORAGE_PATH . 'backup/' . $filename . '.sql';
            // 仅一卷时去掉 _1；同名 .sql 已存在则先删再 rename，与不分卷路径覆盖语义一致
            if (count($volFiles) === 1
                && preg_match('/_1\.sql$/', basename($volFiles[0]))) {
                if (file_exists($targetSql)) {
                    @unlink($targetSql);
                }
                @rename($volFiles[0], $targetSql);
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
                $zipErrorInfo = '';
                $response = Zip::create($zipfile, $filelist, ROOT_PATH, $zipErrorInfo);
                if ($response == 0) {
                    die('Error : ' . $zipErrorInfo);
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
            : preg_replace('/_([0-9])+/Ums', '', $sql_filename);

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

        preg_match('/(.*)_([0-9])+\.sql$/', $sql_filename, $match);
        if ($match) {
            $volid = !empty($req['volid']) ? $req['volid'] : 1;
            $sql_filename = $match[1] . '_' . $volid . '.sql';
        }

        $restore_now = preg_replace('/d%/Ums', $sql_filename, lang('backup_restore_now'));
        $file_path = STORAGE_PATH . 'backup/' . $sql_filename;

        if ($match) {
            if (file_exists($file_path)) {
                if ((int) $volid === 1) {
                    // 分卷链起点：清空上一轮恢复遗留的失败累计
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
     * 预算控制：每追加一行前检查预算，放不下时该行留给下一卷；只有单行本身
     * 超过整卷预算时才强制写入（否则会死循环），保证备份总能向前推进。
     * 表头同样受预算约束：本卷放不下就整表留给下一卷。
     *
     * @param mixed $table
     * @param mixed $vol_size
     * @param int $startfrom
     * @param int $currsize
     * @return string
     */
    protected function sqlDumptable($table, $vol_size, $startfrom = 0, $currsize = 0)
    {
        // 验证数据表名是否合法
        if (!Check::tableName($table)) {
            @unlink(STORAGE_PATH . 'backup/tables.php');
            throw new DomainException(lang('illegal'), route('admin.backup'));
        }

        $this->lastDumpStartrow = 0;
        $this->lastTableComplete = false;

        $budget = $this->volumeBudgetBytes($vol_size);

        if (!isset($tabledump)) {
            $tabledump = '';
        }
        $offset = 100;
        if (!$startfrom) {
            $tabledump = "DROP TABLE IF EXISTS `$table`;\n";
            $createtable = DB::query("SHOW CREATE TABLE $table");
            $create = DB::fetchArray($createtable, MYSQLI_NUM);
            $tabledump .= $create[1] . ";\n\n";
            if (DB::version() > '4.1' && DB::charset()) {
                $tabledump = preg_replace("/(DEFAULT)*\s*CHARSET=[a-zA-Z0-9]+/", "DEFAULT CHARSET=" . DB::charset(), $tabledump);
            }
            // 本卷剩余空间连表头都放不下：整表留给下一卷，避免文件被表头推过预算
            if ($currsize > 0 && $currsize + strlen($tabledump) > $budget) {
                return '';
            }
        }
        $tabledumped = 0;
        $numrows = $offset;
        $complete = false;
        // !$tabledumped 兜底：预算再紧也至少尝试读一批数据，保证表有数据时能推进
        while ($numrows == $offset && ($currsize + strlen($tabledump) < $budget || !$tabledumped)) {
            $tabledumped = 1;
            $rows = DB::query("SELECT * FROM $table LIMIT $startfrom, $offset");
            $numfields = DB::numFields($rows);
            $numrows = DB::numRows($rows);
            $fieldTypes = self::fetchFieldTypes($rows);
            $written = 0;
            while ($row = DB::fetchArray($rows, MYSQLI_NUM)) {
                $comma = "";
                $rowdump = "INSERT INTO $table VALUES(";
                for ($i = 0; $i < $numfields; $i++) {
                    $rowdump .= $comma . self::formatDumpValue($row[$i], isset($fieldTypes[$i]) ? $fieldTypes[$i] : null);
                    $comma = ",";
                }
                $rowdump .= ");\n";
                // 逐行控制在预算内：该行放不下就留给下一卷（首行例外，避免死循环）
                if ($written > 0 && $currsize + strlen($tabledump) + strlen($rowdump) > $budget) {
                    break;
                }
                $tabledump .= $rowdump;
                $written++;
            }
            $startfrom += $written;
            if ($written < $numrows) {
                break;
            }
            // 本批不足一批（含空表返回 0 行）说明已读到表尾，整表导完
            if ($numrows < $offset) {
                $complete = true;
            }
        }
        $this->lastDumpStartrow = $startfrom;
        $this->lastTableComplete = $complete;
        $tabledump .= "\n";
        return $tabledump;
    }

    /**
     * 计算单卷可写字节预算。
     *
     * 预算 = min(分卷设定, upload_max_filesize, post_max_size) − 安全余量（设定值的 1/64，
     * 夹在 1K~16K）。生成文件后要能下载回传或在受限服务器导入，所以按上传限制收敛；
     * 余量用于覆盖 dump 头注释与单行边界误差，保证实际文件小于设定/限制值。
     *
     * @param int $vol_size 分卷设定（KB）
     * @return int 字节预算
     */
    protected function volumeBudgetBytes($vol_size)
    {
        $target = intval($vol_size) * 1024;
        $limits = array(
            self::parseIniSizeToBytes(@ini_get('upload_max_filesize')),
            self::parseIniSizeToBytes(@ini_get('post_max_size')),
        );
        foreach ($limits as $limit) {
            if ($limit > 0 && $target > $limit) {
                $target = $limit;
            }
        }

        $margin = max(1024, min(self::VOLUME_SAFETY_MARGIN, intval($target / 64)));

        return max(1024, $target - $margin);
    }

    /**
     * 解析 PHP ini 简写大小（如 2M/512K/1G/纯字节）为字节数。
     *
     * 直接 intval('512K') 会得到 512 被误当 512M、intval('2M') 与设定值相等时
     * 又不触发收缩，导致上传限制保护失效；这里按单位换算并忽略 -1 等负值。
     *
     * @param string|null $value ini 原始值
     * @return int 字节数；无法解析或表示“无限制”时返回 0
     */
    protected static function parseIniSizeToBytes($value)
    {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^(-?[0-9.]+)\s*([KMG]?)B?$/i', $value, $match)) {
            return 0;
        }

        $unit = strtoupper($match[2]);
        $multiplier = 1;
        if ($unit === 'K') {
            $multiplier = 1024;
        } elseif ($unit === 'M') {
            $multiplier = 1048576;
        } elseif ($unit === 'G') {
            $multiplier = 1073741824;
        }
        $bytes = (float) $match[1] * $multiplier;

        return $bytes > 0 ? (int) $bytes : 0;
    }

    /**
     * 取结果集各字段的 mysqli 类型（下标与 SELECT 列顺序一致）。
     *
     * @param mixed $result mysqli 结果集
     * @return array 字段下标 => mysqli 类型常量
     */
    protected static function fetchFieldTypes($result)
    {
        $types = array();
        foreach ((array) @mysqli_fetch_fields($result) as $index => $field) {
            $types[$index] = $field->type;
        }

        return $types;
    }

    /**
     * 把字段值格式化为 SQL 字面量。
     *
     * 两点必须处理，否则备份文件在新版 MySQL/MariaDB 严格模式（STRICT_TRANS_TABLES）
     * 下无法导入：
     *  ① NULL 必须原样导出为裸 NULL：导出成 '' 会被 DATETIME 等列拒绝（#1292）；
     *  ② 零日期（'0000-00-00 00:00:00'）是旧版非严格模式遗留的哨兵值，语义即“无值”，
     *     新版默认 sql_mode 含 NO_ZERO_DATE，原样导出同样会被拒绝。
     * 零日期只对日期时间列转换，避免误改字符串列中恰好同形的文本。
     *
     * @param mixed $value 字段值
     * @param int|null $type mysqli 字段类型（未知时传 null）
     * @return string SQL 字面量
     */
    protected static function formatDumpValue($value, $type)
    {
        if ($value === null) {
            return 'NULL';
        }

        if (self::isZeroDateValue($value, $type)) {
            return 'NULL';
        }

        return "'" . DB::escapeString($value) . "'";
    }

    /**
     * 判断是否为日期时间列上的零日期值。
     *
     * @param mixed $value 字段值
     * @param int|null $type mysqli 字段类型
     * @return bool
     */
    protected static function isZeroDateValue($value, $type)
    {
        $datetimeTypes = array(MYSQLI_TYPE_DATE, MYSQLI_TYPE_DATETIME, MYSQLI_TYPE_NEWDATE, MYSQLI_TYPE_TIMESTAMP);
        if (!in_array($type, $datetimeTypes, true)) {
            return false;
        }

        return $value === '0000-00-00 00:00:00' || $value === '0000-00-00';
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
