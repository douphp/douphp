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
 * Release Date: 2026-11-25
 */

namespace Dou\Core\Infra\Database;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 在执行备份 SQL 前核对向量能力和同名表覆盖风险。
 */
class DbDumpRestoreCheck
{
    /** @var string */
    const VECTOR_MARKER = '-- DouPHP VECTOR TABLE: ';

    /**
     * 检查完整的 SQL 分卷清单，任何分卷未通过都不能开始还原。
     *
     * @param array $files 按还原顺序排列的 SQL 文件绝对路径
     * @param callable $query 当前还原连接的查询方法
     * @param bool $protectExisting 是否拦截用普通表结构覆盖同名向量表
     * @return bool 是否含向量表
     * @throws \RuntimeException 分卷读取失败或目标库不兼容
     */
    public static function inspect(array $files, $query, $protectExisting = false)
    {
        $vectorTables = array();
        $plainTables = array();
        foreach ($files as $file) {
            self::inspectFile($file, $vectorTables, $plainTables);
        }

        if ($vectorTables) {
            self::requireVectorSupport($query);
        }
        if ($protectExisting && $plainTables) {
            self::protectVectorTables($plainTables, $query);
        }

        return !empty($vectorTables);
    }

    /**
     * 为独立救援脚本提供同一份预检逻辑，救援时可在站点引导损坏的情况下运行。
     *
     * @return string 不含命名空间及 PHP 开始标签的类源码
     */
    public static function standaloneSource()
    {
        $class = new \ReflectionClass(__CLASS__);
        $lines = file($class->getFileName());
        if ($lines === false) {
            throw new \RuntimeException('无法生成数据库还原预检代码。');
        }

        return implode('', array_slice($lines, $class->getStartLine() - 1, $class->getEndLine() - $class->getStartLine() + 1));
    }

    /**
     * 逐行扫描 DouPHP 生成的表定义，兼容未写向量标记的旧备份。
     *
     * @param string $file SQL 文件路径
     * @param array $vectorTables 记录备份中的向量表
     * @param array $plainTables 记录备份中的普通表
     * @return void
     */
    private static function inspectFile($file, array &$vectorTables, array &$plainTables)
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('无法读取数据库分卷：' . basename($file));
        }

        $table = '';
        $ddl = '';
        try {
            while (($line = fgets($handle)) !== false) {
                if ($table === '') {
                    if (strpos($line, self::VECTOR_MARKER) === 0) {
                        $vectorTables[trim(substr($line, strlen(self::VECTOR_MARKER)))] = true;
                    }
                    if (!preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z0-9_]+)`?\s*\(/i', $line, $match)) {
                        continue;
                    }
                    $table = $match[1];
                }

                $ddl .= $line;
                if (substr(rtrim($line), -1) === ';') {
                    self::recordTable($table, $ddl, $vectorTables, $plainTables);
                    $table = '';
                    $ddl = '';
                }
            }
            if (!feof($handle)) {
                throw new \RuntimeException('读取数据库分卷失败：' . basename($file));
            }
            if ($table !== '') {
                self::recordTable($table, $ddl, $vectorTables, $plainTables);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * 识别列类型和向量索引，忽略字段默认值及注释里的文字。
     *
     * @param string $table 备份中的完整表名
     * @param string $ddl 建表语句
     * @param array $vectorTables 记录备份中的向量表
     * @param array $plainTables 记录备份中的普通表
     * @return void
     */
    private static function recordTable($table, $ddl, array &$vectorTables, array &$plainTables)
    {
        $quotedStrings = <<<'REGEX'
~'(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.|"")*"~s
REGEX;
        $ddl = preg_replace($quotedStrings, "''", $ddl);
        if ($ddl === null) {
            throw new \RuntimeException('无法检查备份表结构：' . $table);
        }
        if (preg_match('/[(,]\s*(?:(?:`(?:[^`]|``)+`|[a-z0-9_]+)\s+VECTOR\s*\(\s*\d+\s*\)|VECTOR\s+(?:INDEX|KEY)\b)/i', $ddl)) {
            $vectorTables[$table] = true;
        } else {
            $plainTables[$table] = true;
        }
    }

    /**
     * 确认目标已开启 AliSQL 向量支持且恢复连接使用正确的隔离级别。
     *
     * @param callable $query 当前还原连接的查询方法
     * @return void
     */
    public static function requireVectorSupport($query)
    {
        $rows = self::queryRows($query, "SHOW GLOBAL VARIABLES LIKE 'vidx_disabled'");
        if (empty($rows[0]['Value']) || strtoupper($rows[0]['Value']) !== 'OFF') {
            throw new \RuntimeException('备份包含向量表。请在支持向量的阿里云 RDS MySQL 实例上开启向量存储后恢复。');
        }

        $rows = self::queryRows($query, "SELECT @@SESSION.transaction_isolation AS isolation_level, VEC_TOTEXT(VEC_FROMTEXT('[1,2]')) AS vector_value");
        $vector = isset($rows[0]['vector_value']) ? json_decode($rows[0]['vector_value'], true) : null;
        if (!is_array($vector) || !isset($vector[0], $vector[1]) || count($vector) !== 2 || (float) $vector[0] !== 1.0 || (float) $vector[1] !== 2.0) {
            throw new \RuntimeException('目标数据库无法转换备份中的向量值，请核对 RDS 的内核小版本及向量存储配置。');
        }
        if (empty($rows[0]['isolation_level']) || strtoupper($rows[0]['isolation_level']) !== 'READ-COMMITTED') {
            throw new \RuntimeException('恢复向量表要求当前数据库会话使用 READ COMMITTED 隔离级别。');
        }
    }

    /**
     * 拦截会以普通结构重建当前向量表的手工备份。
     *
     * @param array $plainTables 备份中的普通表名集合
     * @param callable $query 当前还原连接的查询方法
     * @return void
     */
    private static function protectVectorTables(array $plainTables, $query)
    {
        $rows = self::queryRows($query, "SELECT DISTINCT TABLE_NAME AS table_name, @@lower_case_table_names AS name_case"
            . " FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_SCHEMA = BINARY DATABASE() AND DATA_TYPE = 'vector'");
        if ($rows === false) {
            throw new \RuntimeException('无法检查目标数据库的向量表，已停止恢复。');
        }
        $lowercaseTables = array_change_key_case($plainTables, CASE_LOWER);
        foreach ($rows as $row) {
            $name = $row['table_name'];
            $willReplace = (int) $row['name_case'] === 0 ? isset($plainTables[$name]) : isset($lowercaseTables[strtolower($name)]);
            if ($willReplace) {
                throw new \RuntimeException('备份会覆盖目标库的向量表 ' . $name . '。请从源库导出不包含该表的备份后再恢复。');
            }
        }
    }

    /**
     * 取查询结果并释放结果集。
     *
     * @param callable $query 当前还原连接的查询方法
     * @param string $sql 预检 SQL
     * @return array|false 查询结果行，失败返回 false
     */
    private static function queryRows($query, $sql)
    {
        $result = call_user_func($query, $sql);
        if (is_array($result)) {
            return $result;
        }
        if (!$result instanceof \mysqli_result) {
            return false;
        }

        $rows = array();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }
}
