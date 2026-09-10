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
     * 恢复页：列出 storage/backup 下可用备份（按时间排序，合并分卷展示）。
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
        foreach ((array) $backup_file_list as $row) {
            if ($row['number'] <= 1 || !$row['number']) {
                if ($row['number'] == 1) {
                    $showname = preg_replace('/_([0-9])+/Ums', '', $row['showname']);
                    $vol_file_list = $this->globVolumeFiles(FileHelper::filename($showname), $row['ext']);
                    $row['showname'] = $showname;
                    $row['vol_number'] = count($vol_file_list);
                } else {
                    $row['vol_number'] = 1;
                }
                $file_list[] = $row;
            }
        }

        return $file_list;
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
        $vol_size = !empty($req['vol_size']) ? $req['vol_size'] : 2048;

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
        $tableid = isset($req['tableid']) ? $req['tableid'] - 1 : 0;
        $startfrom = isset($req['startfrom']) ? intval($req['startfrom']) : 0;
        $tablenumber = count((array) $tables);

        for ($i = $tableid; $i < $tablenumber && strlen($sqldump) < $vol_size * 1024; $i++) {
            $sqldump .= $this->sqlDumptable($tables[$i], $vol_size, $startfrom, strlen($sqldump));
            $startfrom = 0;
        }

        if (trim($sqldump)) {
            $sqldump = "-- DouPHP v1.x SQL Dump Program\n-- " . ROOT_URL . "\n-- \n-- DATE : " . date('Y-m-d H:i:s')
                . "\n-- MYSQL SERVER VERSION : " . DB::version()
                . "\n-- PHP VERSION : " . PHP_VERSION
                . "\n-- DouPHP VERSION : " . Config::get('site.douphp_version', '') . "\n\n" . $sqldump;

            $tableid = $i;
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
                'back_url' => route('admin.backup.backup', array('act' => $act, 'asset' => $asset, 'vol_size' => $vol_size, 'totalsize' => $totalsize, 'filename' => $filename, 'token' => $token_val, 'tableid' => $tableid, 'volid' => $volid, 'startfrom' => intval($this->lastDumpStartrow), 'showname' => $showname, 'restore_filename' => $restore_filename, 'back' => $back)),
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
            if (Zip::extract(STORAGE_PATH . 'backup/' . $sql_filename, ROOT_PATH)) {
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
                DB::fnExecute(file_get_contents($file_path));
                $volid++;
                return array(
                    'message' => $restore_now,
                    'back_url' => route('admin.backup.import', array('sql_filename' => $sql_filename, 'showname' => $showname, 'volid' => $volid, 'token' => (Arr::get($req, 'token', '')))),
                    'timeout' => '1',
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
                audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::RESTORE, 1, (string) $showname);
                return array(
                    'message' => lang('backup_restore_success'),
                    'back_url' => route('admin.backup.restore'),
                    'timeout' => '3',
                    'confirm_url' => '',
                );
            }
        }

        DB::fnExecute(file_get_contents($file_path));
        if (FileHelper::extension($showname) === 'zip') {
            @unlink(STORAGE_PATH . 'backup/' . FileHelper::filename($showname) . '.sql');
        }
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::RESTORE, 1, (string) $showname);
        return array(
            'message' => lang('backup_restore_success'),
            'back_url' => route('admin.backup.restore'),
            'timeout' => '3',
            'confirm_url' => '',
        );
    }

    /**
     * 删除备份文件（含同前缀分卷）；未确认时由 Controller 调 `respondDeleteResult` 走 dou_msg.htm 二次确认。
     *
     * @param array $req
     * @param array $post 含 confirm 时表示已确认删除
     * @return array message、back_url、timeout、confirm_url
     * @throws DomainException 文件名不合法时抛出
     */
    public function runDelete(array $req, array $post)
    {
        if (!$this->isBackupFile(Arr::get($req, 'sql_filename', ''))) {
            throw new DomainException(lang('backup_filename_not_valid'), route('admin.backup'));
        }
        $sql_filename = $req['sql_filename'];

        if (isset($post['confirm'])) {
            if (file_exists(STORAGE_PATH . 'backup/' . $sql_filename)) {
                @unlink(STORAGE_PATH . 'backup/' . $sql_filename);
            }
            preg_match('/(.*)_([0-9])+\.sql$/', $sql_filename, $match);
            if ($match) {
                $sqlfiles = $this->globVolumeFiles($match[1], 'sql');
                $sql_filename .= ' ' . lang('backup_vol_include') . ' : ';
                foreach ($sqlfiles as $sqlfile) {
                    if (file_exists(STORAGE_PATH . 'backup/' . basename($sqlfile))) {
                        @unlink(STORAGE_PATH . 'backup/' . basename($sqlfile));
                    }
                    $sql_filename .= basename($sqlfile) . ',';
                }
            }
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $sql_filename, 'backup');
            return array(
                'message' => preg_replace('/d%/Ums', $sql_filename, lang('backup_del_success')),
                'back_url' => route('admin.backup.restore'),
                'timeout' => '3',
                'confirm_url' => '',
            );
        }

        $del_check = preg_replace('/d%/Ums', $sql_filename, lang('del_check'));
        return array(
            'message' => $del_check,
            'back_url' => route('admin.backup.restore'),
            'timeout' => '30',
            'confirm_url' => route('admin.backup.destroy', array(), array('query' => array('sql_filename' => $sql_filename))),
        );
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

        $allow_max_size = intval(@ini_get('upload_max_filesize')); // 单位M
        if ($allow_max_size > 0 && $vol_size > ($allow_max_size * 1024)) {
            $vol_size = $allow_max_size * 1024; // 单位K
        }

        if ($vol_size > 0) {
            $vol_size = $vol_size * 1024;
        }

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
        }
        $tabledumped = 0;
        $numrows = $offset;
        while ($currsize + strlen($tabledump) < $vol_size && $numrows == $offset) {
            $tabledumped = 1;
            $rows = DB::query("SELECT * FROM $table LIMIT $startfrom, $offset");
            $numfields = DB::numFields($rows);
            $numrows = DB::numRows($rows);
            while ($row = DB::fetchArray($rows, MYSQLI_NUM)) {
                $comma = "";
                $tabledump .= "INSERT INTO $table VALUES(";
                for ($i = 0; $i < $numfields; $i++) {
                    $tabledump .= $comma . "'" . DB::escapeString($row[$i]) . "'";
                    $comma = ",";
                }
                $tabledump .= ");\n";
            }
            $startfrom += $offset;
        }
        $this->lastDumpStartrow = $startfrom;
        $tabledump .= "\n";
        return $tabledump;
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
