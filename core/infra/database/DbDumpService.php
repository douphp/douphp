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

namespace Dou\Core\Infra\Database;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Check;
use Dou\Core\Support\DbVersion;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 数据库导出引擎（分卷 / 快照双通道共用单一来源）。
 *
 * 2026-10 从 admin/service/backup/BackupService 抽出：备份模块（分卷备份）与
 * 升级快照（storage/snapshot）此前是两套潜在实现，抽取后由本类统一提供
 * 「单表分块导出 + 字节预算控制 + 续传游标」能力，两侧只做编排。
 *
 * 预算语义：每追加一行前检查预算，放不下时该行留给下一块；只有单行本身
 * 超过整块预算时才强制写入（否则会死循环），保证导出总能向前推进。
 * 表头同样受预算约束：本块放不下就整表留给下一块。
 *
 * 兼容 PHP 5.6+。
 */
class DbDumpService
{
    /**
     * 分卷安全余量上限（字节）。
     *
     * 实际预算 = min(分卷设定, upload_max_filesize, post_max_size) − 余量；
     * 余量用于覆盖 dump 头注释与行边界误差，确保生成文件小于设定/限制值
     * （很多服务器限制上传 2M，分卷文件超一点点就无法回传导入）。
     */
    const VOLUME_SAFETY_MARGIN = 16384;

    /**
     * 单表分块导出（续传依赖返回的 next_startrow / complete）。
     *
     * @param string $table 表名（已含前缀）
     * @param int $startfrom 起始行偏移
     * @param int $currsize 本块已写入字节数（预算判定用）
     * @param int $budgetBytes 本块字节预算；<=0 时按 2048KB 分卷推算
     * @return array array('sql' => string, 'next_startrow' => int, 'complete' => bool)
     *               sql 为空串表示本块剩余空间连表头都放不下，整表留给下一块
     * @throws DomainException 表名不合法时抛出
     */
    public function dumpTable($table, $startfrom = 0, $currsize = 0, $budgetBytes = 0)
    {
        if (!Check::tableName($table)) {
            throw new DomainException(lang('illegal'));
        }

        if ($budgetBytes <= 0) {
            $budgetBytes = self::volumeBudgetBytes(2048);
        }
        $budget = $budgetBytes;

        $tabledump = '';
        $offset = 100;
        if (!$startfrom) {
            $tabledump = "DROP TABLE IF EXISTS `$table`;\n";
            $createtable = DB::query("SHOW CREATE TABLE $table");
            $create = DB::fetchArray($createtable, MYSQLI_NUM);
            $tabledump .= $create[1] . ";\n\n";
            if (DbVersion::isAtLeast(DB::version(), '4.1') && DB::charset()) {
                $tabledump = preg_replace("/(DEFAULT)*\s*CHARSET=[a-zA-Z0-9]+/", "DEFAULT CHARSET=" . DB::charset(), $tabledump);
            }
            // 导出侧字符集归一（表级+列级）：utf8mb4_0900_* 与 utf8mb3 是 MySQL 8.0+ 服务器
            // SHOW CREATE TABLE 的输出格式，低版本（5.7 等）导入会失败；统一归一为全版本可导形式。
            // 此处仅作用于建表 DDL（数据行尚未拼接），可直接全文替换；
            // 0900 系列含 utf8mb4_0900_ai_ci 与 8.0.30+ 的 utf8mb4_zh_0900_as_cs 等命名变体。
            $tabledump = preg_replace('/utf8mb4_[a-z0-9_]*0900[a-z0-9_]*/i', 'utf8mb4_unicode_ci', $tabledump);
            $tabledump = str_replace('utf8mb3', 'utf8', $tabledump);
            // 本块剩余空间连表头都放不下：整表留给下一块，避免文件被表头推过预算
            if ($currsize > 0 && $currsize + strlen($tabledump) > $budget) {
                return array('sql' => '', 'next_startrow' => 0, 'complete' => false);
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
                // 逐行控制在预算内：该行放不下就留给下一块（首行例外，避免死循环）
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
        $tabledump .= "\n";

        return array('sql' => $tabledump, 'next_startrow' => $startfrom, 'complete' => $complete);
    }

    /**
     * 组装 dump 头注释（备份文件 / 快照 db 文件共用格式）。
     *
     * @param string $title 站点 URL 或快照标题
     * @param string $appVersion DouPHP 版本串（可空）
     * @return string
     */
    public static function headerComment($title, $appVersion = '')
    {
        return "-- DouPHP v2.x SQL Dump Program\n-- " . $title . "\n-- \n-- DATE : " . date('Y-m-d H:i:s')
            . "\n-- MYSQL SERVER VERSION : " . DB::version()
            . "\n-- PHP VERSION : " . PHP_VERSION
            . "\n-- DouPHP VERSION : " . $appVersion . "\n\n";
    }

    /**
     * 计算单卷可写字节预算。
     *
     * 预算 = min(分卷设定, upload_max_filesize, post_max_size) − 安全余量（设定值的 1/64，
     * 夹在 1K~16K）。生成文件后要能下载回传或在受限服务器导入，所以按上传限制收敛。
     *
     * @param int $volSizeKb 分卷设定（KB）
     * @return int 字节预算
     */
    public static function volumeBudgetBytes($volSizeKb)
    {
        $target = intval($volSizeKb) * 1024;
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
    public static function parseIniSizeToBytes($value)
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
}
