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
 * Release Date: 2026-10-13
 */

namespace Dou\Core\Infra\Database;

use Dou\Core\Facade\DB;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表结构标准同步引擎（升级体系核心组件，单一权威源）。
 *
 * 职责：
 *   1. 解析「标准结构文件」——phpMyAdmin dump 风格（CREATE TABLE + 外置 ALTER ADD KEY /
 *      ALTER MODIFY AUTO_INCREMENT）与 SHOW CREATE TABLE 风格（键内联）均可；只解析，
 *      绝不执行其中的 DROP / INSERT 等语句；
 *   2. 经 INFORMATION_SCHEMA 内省现网表，按列（类型 / 可空 / 默认值 / 注释 / AUTO_INCREMENT /
 *      字符集）、索引（增删改）、表选项（引擎 / 字符集）逐项 diff 并执行对齐；
 *   3. 提供规范化迁移 API（表/字段改名、时间戳转换、值迁移等），供各版本 upgrade_*.php
 *      补丁记录历史变迁，替代手写 ALTER。
 *
 * 关键时序约束（调用方必须遵守）：
 *   - 字段/表改名必须发生在标准同步（syncStandards）之前，否则旧列会被当作
 *     「标准外多余列」删除，数据丢失；
 *   - 标准文件只作为结构标准来源，其 DROP TABLE / INSERT 等内容一律忽略。
 *
 * 比对归一化（防 MySQL 版本差异导致反复 ALTER 不收敛）：
 *   - 整型显示宽度（int(10) / int(11) / smallint(6)）比较时统一剥离；
 *   - utf8mb3 与 utf8 别名归一；
 *   - 标准未写 DEFAULT 的列，现网隐式默认（''、'0'、NULL）视为合规。
 *
 * 数据库访问统一走 DB:: 门面；单条语句失败记录日志并继续（对齐旧升级程序的容忍度），
 * config 表重建等关键步骤失败抛 RuntimeException（升级链路可捕获上报）。
 *
 * 兼容性：PHP 5.6 ~ 8.x，UTF-8 无 BOM，4 空格缩进。
 */
class SchemaSync
{
    /** @var array 标准文件清单：array(array('file' => 绝对路径, 'create' => bool 表缺失时是否补建)) */
    private $standards = array();

    /** @var string 执行日志文件路径（空串不落盘） */
    private $logFile = '';

    /** @var bool 演练模式：只收集语句不执行（devtools check 用） */
    private $dryRun = false;

    /** @var array 解析后的表模型：裸表名(小写) => 模型 */
    private $models = array();

    /** @var bool 标准文件是否已解析 */
    private $parsed = false;

    /** @var array 执行过的语句 */
    private $statements = array();

    /** @var array 日志行 */
    private $logs = array();

    /** @var array 错误信息 */
    private $errors = array();

    /**
     * @param array $options 可选：
     *                       - standards : array，元素为「标准文件绝对路径」或 array('file' => 路径, 'create' => bool)。
     *                       缺省 create=false（仅对齐已存在的表，不补建）；
     *                       - log_file  : string，日志落盘路径；
     *                       - dry_run   : bool，只收集不执行。
     */
    public function __construct(array $options = array())
    {
        if (isset($options['standards']) && is_array($options['standards'])) {
            foreach ($options['standards'] as $item) {
                if (is_string($item) && $item !== '') {
                    $this->standards[] = array('file' => $item, 'create' => false);
                } elseif (is_array($item) && !empty($item['file'])) {
                    $this->standards[] = array(
                        'file' => (string) $item['file'],
                        'create' => !empty($item['create']),
                    );
                }
            }
        }
        if (!empty($options['log_file'])) {
            $this->logFile = (string) $options['log_file'];
        }
        if (!empty($options['dry_run'])) {
            $this->dryRun = true;
        }
        $this->log('SchemaSync 启动（dry_run=' . ($this->dryRun ? 'true' : 'false') . '，标准文件 ' . count($this->standards) . ' 份）');
    }

    // =================================================================
    // 日志与执行
    // =================================================================

    /**
     * 记录一行日志（内存 + 可选落盘）。
     *
     * @param string $msg
     * @return void
     */
    public function log($msg)
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
        $this->logs[] = $line;
        if ($this->logFile !== '') {
            @file_put_contents($this->logFile, $line . "\n", FILE_APPEND);
        }
    }

    /**
     * 记录一条错误。
     *
     * @param string $msg
     * @return void
     */
    private function error($msg)
    {
        $this->errors[] = $msg;
        $this->log('[ERROR] ' . $msg);
    }

    /**
     * 执行一条 SQL（dry_run 时只收集）。
     *
     * @param string $sql
     * @return bool 是否成功
     */
    private function exec($sql)
    {
        $sql = trim($sql);
        if ($sql === '') {
            return true;
        }
        $this->statements[] = $sql;
        if ($this->dryRun) {
            $this->log('[DRY-RUN] ' . $sql);
            return true;
        }
        $result = DB::query($sql);
        if ($result === false) {
            // 附加库端原始错误（如 5.6 的 "Specified key was too long"）：同一条 SQL 在不同
            // MySQL 版本上的失败原因不同，没有原始错误文本将无法从日志分辨根因。
            $dbError = trim((string) DB::getLastError());
            $this->error('SQL 执行失败：' . $sql . ($dbError !== '' ? '（MySQL: ' . $dbError . '）' : ''));
            return false;
        }
        $this->log('[OK] ' . $sql);
        return true;
    }

    /**
     * 全部执行日志。
     *
     * @return array
     */
    public function getLogs()
    {
        return $this->logs;
    }

    /**
     * 全部执行过的（或 dry-run 将执行的）语句。
     *
     * @return array
     */
    public function getStatements()
    {
        return $this->statements;
    }

    /**
     * 错误清单。
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * 是否有错误。
     *
     * @return bool
     */
    public function hasErrors()
    {
        return !empty($this->errors);
    }

    // =================================================================
    // 标准文件解析
    // =================================================================

    /**
     * 解析全部标准文件（懒加载，只解析一次）。
     *
     * @return void
     */
    public function parseStandards()
    {
        if ($this->parsed) {
            return;
        }
        $this->parsed = true;
        foreach ($this->standards as $entry) {
            if (!is_file($entry['file'])) {
                $this->error('标准结构文件不存在：' . $entry['file']);
                continue;
            }
            $content = (string) file_get_contents($entry['file']);
            $this->parseStandardContent($content, $entry['file'], $entry['create']);
        }
        $this->log('标准文件解析完成，共 ' . count($this->models) . ' 张表：' . implode(', ', array_keys($this->models)));
    }

    /**
     * 解析单份标准文件内容。
     *
     * @param string $content
     * @param string $source 来源路径（日志用）
     * @param bool $create 本文件内表缺失时是否允许补建
     * @return void
     */
    private function parseStandardContent($content, $source, $create)
    {
        $statements = $this->splitSqlStatements($content);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || strpos($stmt, '/*') === 0 || strpos($stmt, '--') === 0) {
                continue;
            }
            if (preg_match('/^CREATE\s+TABLE\s+/i', $stmt)) {
                $model = $this->parseCreateTable($stmt);
                if ($model !== null) {
                    $model['create'] = $model['create'] || $create;
                    $bare = strtolower($model['name']);
                    if (isset($this->models[$bare])) {
                        $this->log('重复定义的表（后出现的标准生效）：' . $model['name'] . '（来源 ' . $source . '）');
                    }
                    $this->models[$bare] = $model;
                }
                continue;
            }
            if (preg_match('/^ALTER\s+TABLE\s+/i', $stmt)) {
                $this->parseAlterTable($stmt);
                continue;
            }
            // 其余（SET / INSERT / DROP / START TRANSACTION 等）一律忽略
        }
    }

    /**
     * 解析 CREATE TABLE 语句为表模型。
     *
     * @param string $stmt
     * @return array|null array(name, columns, indexes, engine, charset, collate, auto_increment, create)
     */
    private function parseCreateTable($stmt)
    {
        if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`\s*\(/is', $stmt, $m, PREG_OFFSET_CAPTURE)) {
            $this->log('忽略无法识别的 CREATE TABLE 语句：' . substr($stmt, 0, 80));
            return null;
        }
        $name = $m[1][0];
        $openPos = $m[0][1] + strlen($m[0][0]) - 1;
        $group = $this->extractParenGroup($stmt, $openPos);
        if ($group === null) {
            $this->error('CREATE TABLE 括号不配对：' . $name);
            return null;
        }
        $body = $group[0];
        $tail = substr($stmt, $group[1] + 1);

        $model = array(
            'name' => $this->stripTablePrefix($name),
            'columns' => array(),
            'indexes' => array(),
            'engine' => 'InnoDB',
            'charset' => '',
            'collate' => '',
            'create' => false,
        );

        foreach (explode("\n", $body) as $line) {
            $line = rtrim(trim($line), ',');
            if ($line === '') {
                continue;
            }
            if ($line[0] === '`') {
                $col = $this->parseColumnLine($line);
                if ($col !== null) {
                    $model['columns'][] = $col;
                }
                continue;
            }
            if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|FULLTEXT\s+KEY|SPATIAL\s+KEY|KEY|INDEX|CONSTRAINT)\b/i', $line, $km)) {
                if (strtoupper($km[1]) === 'CONSTRAINT') {
                    $this->log('忽略外键约束（标准不支持）：' . $name);
                    continue;
                }
                $idx = $this->parseIndexLine($line);
                if ($idx !== null) {
                    $model['indexes'][strtolower($idx['expose']) === 'primary' ? 'primary' : strtolower($idx['name'])] = $idx;
                }
                continue;
            }
        }

        // 表选项：ENGINE / CHARSET / COLLATE
        if (preg_match('/\bENGINE\s*=\s*(\w+)/i', $tail, $em)) {
            $model['engine'] = $em[1];
        }
        if (preg_match('/\bCHARSET\s*=\s*(\w+)/i', $tail, $cm)) {
            $model['charset'] = strtolower($cm[1]);
        } elseif (preg_match('/\bCHARACTER\s+SET\s*=\s*(\w+)/i', $tail, $cm)) {
            $model['charset'] = strtolower($cm[1]);
        }
        if (preg_match('/\bCOLLATE\s*=\s*(\w+)/i', $tail, $om)) {
            $model['collate'] = strtolower($om[1]);
        }
        if ($model['charset'] === '' && $model['collate'] !== '') {
            $model['charset'] = $this->charsetOfCollation($model['collate']);
        }

        return $model;
    }

    /**
     * 解析一行列定义（形如 `col` varchar(255) NOT NULL DEFAULT '' COMMENT 'x'）。
     *
     * @param string $line
     * @return array|null array(name, def)
     */
    private function parseColumnLine($line)
    {
        if (!preg_match('/^`([^`]+)`\s+(.+)$/s', $line, $m)) {
            return null;
        }
        $def = rtrim(trim($m[2]), ',');
        return array('name' => $m[1], 'def' => $def);
    }

    /**
     * 解析索引行（内联 KEY 行或 ALTER ADD 子句的结构体）。
     *
     * @param string $line
     * @return array|null array(expose, name, type, columns)
     */
    private function parseIndexLine($line)
    {
        if (!preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|FULLTEXT\s+KEY|SPATIAL\s+KEY|KEY|INDEX)\s*(?:`([^`]+)`)?\s*\(/i', $line, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $typeRaw = strtoupper(preg_replace('/\s+/', ' ', $m[1][0]));
        $name = isset($m[2]) && $m[2][0] !== '' ? $m[2][0] : '';
        $openPos = $m[0][1] + strlen($m[0][0]) - 1;
        $group = $this->extractParenGroup($line, $openPos);
        if ($group === null) {
            return null;
        }

        $type = 'KEY';
        if (strpos($typeRaw, 'PRIMARY') === 0) {
            $type = 'PRIMARY';
            $name = 'PRIMARY';
        } elseif (strpos($typeRaw, 'UNIQUE') === 0) {
            $type = 'UNIQUE';
        } elseif (strpos($typeRaw, 'FULLTEXT') === 0) {
            $type = 'FULLTEXT';
        } elseif (strpos($typeRaw, 'SPATIAL') === 0) {
            $type = 'SPATIAL';
        }

        $columns = array();
        foreach ($this->splitTopLevel($group[0]) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (preg_match('/^`([^`]+)`(?:\s*\(\s*(\d+)\s*\))?/', $token, $cm)) {
                $columns[] = array('col' => $cm[1], 'sub' => isset($cm[2]) ? $cm[2] : '');
            }
        }

        return array('expose' => $name === 'PRIMARY' ? 'PRIMARY' : $name, 'name' => $name, 'type' => $type, 'columns' => $columns);
    }

    /**
     * 解析 ALTER TABLE 语句：只关心 ADD 索引、ADD 列、MODIFY 列（AUTO_INCREMENT 合入列定义）。
     *
     * @param string $stmt
     * @return void
     */
    private function parseAlterTable($stmt)
    {
        if (!preg_match('/^ALTER\s+TABLE\s+`([^`]+)`\s+(.+)$/is', $stmt, $m)) {
            return;
        }
        $bare = strtolower($this->stripTablePrefix($m[1]));
        if (!isset($this->models[$bare])) {
            return;
        }
        $body = $m[2];
        foreach ($this->splitTopLevel($body) as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }
            if (preg_match('/^ADD\s+(PRIMARY\s+KEY|UNIQUE\s+KEY|FULLTEXT\s+KEY|SPATIAL\s+KEY|KEY|INDEX)\b/i', $clause)) {
                $idx = $this->parseIndexLine(preg_replace('/^ADD\s+/i', '', $clause));
                if ($idx !== null) {
                    $this->models[$bare]['indexes'][strtolower($idx['name']) === 'primary' ? 'primary' : strtolower($idx['name'])] = $idx;
                }
                continue;
            }
            if (preg_match('/^ADD\s+(?:COLUMN\s+)?(`[^`]+`\s+.+)$/is', $clause, $am)) {
                // 少见格式：外置 ADD 列。位置语义由「追加到模型尾部」近似（标准文件不依赖此格式）
                $col = $this->parseColumnLine(trim($am[1]));
                if ($col !== null) {
                    $this->models[$bare]['columns'][] = $col;
                }
                continue;
            }
            if (preg_match('/^MODIFY\s+(?:COLUMN\s+)?`([^`]+)`\s+(.+)$/is', $clause, $mm)) {
                $colName = $mm[1];
                $def = rtrim(trim($mm[2]), ',');
                if (stripos($def, 'AUTO_INCREMENT') !== false) {
                    $this->mergeColumnDefinition($bare, $colName, $def);
                }
                continue;
            }
            // CHANGE / DROP / 其它：标准文件不使用，忽略
        }
    }

    /**
     * 用 ALTER MODIFY 的完整定义覆盖模型中的列定义（phpMyAdmin dump 的自增列写法）。
     *
     * @param string $bare
     * @param string $colName
     * @param string $def
     * @return void
     */
    private function mergeColumnDefinition($bare, $colName, $def)
    {
        foreach ($this->models[$bare]['columns'] as $i => $col) {
            if (strcasecmp($col['name'], $colName) === 0) {
                $this->models[$bare]['columns'][$i]['def'] = $def;
                return;
            }
        }
    }

    // =================================================================
    // SQL 词法辅助
    // =================================================================

    /**
     * 把 SQL 文本切成语句（分号分隔，引号 / 反引号 / 注释感知）。
     *
     * @param string $content
     * @return array
     */
    private function splitSqlStatements($content)
    {
        $out = array();
        $buffer = '';
        $len = strlen($content);
        $inQuote = false;
        $quoteChar = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $content[$i];
            // 行注释
            if (!$inQuote && ($ch === '-' || $ch === '#') && $i + 1 < $len && ($ch === '#' || $content[$i + 1] === '-')) {
                while ($i < $len && $content[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($inQuote) {
                $buffer .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $buffer .= $content[$i + 1];
                    $i++;
                    continue;
                }
                if ($ch === $quoteChar) {
                    // 连续两个引号视为转义
                    if ($i + 1 < $len && $content[$i + 1] === $quoteChar) {
                        $buffer .= $content[$i + 1];
                        $i++;
                        continue;
                    }
                    $inQuote = false;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inQuote = true;
                $quoteChar = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ';') {
                $out[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') {
            $out[] = $buffer;
        }
        return $out;
    }

    /**
     * 按顶层逗号切分（引号 / 括号感知）。
     *
     * @param string $str
     * @return array
     */
    private function splitTopLevel($str)
    {
        $out = array();
        $buffer = '';
        $len = strlen($str);
        $inQuote = false;
        $quoteChar = '';
        $depth = 0;
        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];
            if ($inQuote) {
                $buffer .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $buffer .= $str[$i + 1];
                    $i++;
                    continue;
                }
                if ($ch === $quoteChar) {
                    $inQuote = false;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inQuote = true;
                $quoteChar = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $out[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') {
            $out[] = $buffer;
        }
        return $out;
    }

    /**
     * 提取从 $openPos 处 '(' 开始的成对括号内容（引号感知）。
     *
     * @param string $str
     * @param int $openPos
     * @return array|null array(内容, 收尾 ')' 的下标)
     */
    private function extractParenGroup($str, $openPos)
    {
        if (!isset($str[$openPos]) || $str[$openPos] !== '(') {
            return null;
        }
        $depth = 0;
        $len = strlen($str);
        $inQuote = false;
        $quoteChar = '';
        for ($i = $openPos; $i < $len; $i++) {
            $ch = $str[$i];
            if ($inQuote) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;
                    continue;
                }
                if ($ch === $quoteChar) {
                    $inQuote = false;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inQuote = true;
                $quoteChar = $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return array(substr($str, $openPos + 1, $i - $openPos - 1), $i);
                }
            }
        }
        return null;
    }

    /**
     * 剥离表前缀（标准文件统一使用 dou_ 前缀，运行时按站点前缀换算）。
     *
     * @param string $name
     * @return string
     */
    private function stripTablePrefix($name)
    {
        if (strncasecmp($name, 'dou_', 4) === 0) {
            return substr($name, 4);
        }
        return $name;
    }

    /**
     * 由排序规则反推字符集名（utf8mb4_unicode_ci -> utf8mb4）。
     *
     * @param string $collation
     * @return string
     */
    private function charsetOfCollation($collation)
    {
        $collation = $this->normalizeCollation($collation);
        $pos = strpos($collation, '_');
        return $pos === false ? $collation : substr($collation, 0, $pos);
    }

    /**
     * 排序规则别名归一（MySQL 8 的 utf8mb3 与历史 utf8 等价）。
     *
     * @param string $collation
     * @return string
     */
    private function normalizeCollation($collation)
    {
        return strtolower(str_replace('utf8mb3', 'utf8', (string) $collation));
    }

    // =================================================================
    // 内省
    // =================================================================

    /**
     * 读取现网表列（裸名 => 行）。
     *
     * @param string $bare
     * @return array
     */
    private function introspectColumns($bare)
    {
        $table = DB::escapeString($this->actualTableName($bare));
        $rows = DB::fnQuery("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME, COLUMN_COMMENT, ORDINAL_POSITION"
            . " FROM INFORMATION_SCHEMA.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'"
            . " ORDER BY ORDINAL_POSITION ASC");
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $out[strtolower($row['COLUMN_NAME'])] = $row;
            }
        }
        return $out;
    }

    /**
     * 读取现网表索引（小写索引名 => array(unique, columns)）。
     *
     * @param string $bare
     * @return array
     */
    private function introspectIndexes($bare)
    {
        $table = DB::escapeString($this->actualTableName($bare));
        $rows = DB::fnQuery("SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART"
            . " FROM INFORMATION_SCHEMA.STATISTICS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'"
            . " ORDER BY INDEX_NAME ASC, SEQ_IN_INDEX ASC");
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $name = strtolower($row['INDEX_NAME']);
                if (!isset($out[$name])) {
                    $out[$name] = array('name' => $row['INDEX_NAME'], 'unique' => ((int) $row['NON_UNIQUE'] === 0), 'columns' => array());
                }
                $out[$name]['columns'][] = array(
                    'col' => strtolower($row['COLUMN_NAME']),
                    'sub' => is_null($row['SUB_PART']) ? '' : (string) $row['SUB_PART'],
                );
            }
        }
        return $out;
    }

    /**
     * 读取现网表选项（ENGINE / TABLE_COLLATION）。
     *
     * @param string $bare
     * @return array|null
     */
    private function introspectTableOptions($bare)
    {
        $table = DB::escapeString($this->actualTableName($bare));
        $rows = DB::fnQuery("SELECT ENGINE, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "' AND TABLE_TYPE = 'BASE TABLE'");
        if (!is_array($rows) || empty($rows)) {
            return null;
        }
        return $rows[0];
    }

    /**
     * 裸表名 -> 实际表名（含站点前缀，不含反引号）。
     *
     * @param string $bare
     * @return string
     */
    private function actualTableName($bare)
    {
        return trim(DB::tableName($bare), '`');
    }

    /**
     * 列定义体（不含列名，形如 varchar(255) COLLATE .. NOT NULL DEFAULT .. AUTO_INCREMENT COMMENT '..'）。
     * 语法顺序按 MySQL 列定义文法：type [COLLATE] [NULL] [DEFAULT] [ON UPDATE] [AUTO_INCREMENT] [COMMENT]。
     *
     * @param array $row INFORMATION_SCHEMA.COLUMNS 行
     * @return string
     */
    private function columnDefinitionBody($row)
    {
        $sql = $row['COLUMN_TYPE'];
        if (!is_null($row['COLLATION_NAME']) && $row['COLLATION_NAME'] !== '') {
            $sql .= ' COLLATE ' . $row['COLLATION_NAME'];
        }
        $sql .= ($row['IS_NULLABLE'] === 'NO') ? ' NOT NULL' : ' NULL';
        if (!is_null($row['COLUMN_DEFAULT'])) {
            $default = (string) $row['COLUMN_DEFAULT'];
            if (preg_match('/^CURRENT_TIMESTAMP(\(\d*\))?$/i', $default)) {
                $sql .= ' DEFAULT ' . $default;
            } else {
                $sql .= " DEFAULT '" . DB::escapeString($default) . "'";
            }
        } elseif ($row['IS_NULLABLE'] === 'YES') {
            $sql .= ' DEFAULT NULL';
        }
        if (stripos($row['EXTRA'], 'on update CURRENT_TIMESTAMP') !== false) {
            $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
        }
        if (stripos($row['EXTRA'], 'auto_increment') !== false) {
            $sql .= ' AUTO_INCREMENT';
        }
        if (isset($row['COLUMN_COMMENT']) && $row['COLUMN_COMMENT'] !== '') {
            $sql .= " COMMENT '" . DB::escapeString($row['COLUMN_COMMENT']) . "'";
        }
        return $sql;
    }

    /**
     * 带列名的现网列定义（用于 CHANGE 改名：`旧` `新` <定义体>）。
     *
     * @param array $row
     * @param string|null $newName 新列名（null 用 origin 名）
     * @return string
     */
    private function currentColumnDefinition($row, $newName = null)
    {
        $name = is_null($newName) ? $row['COLUMN_NAME'] : $newName;
        return '`' . $name . '` ' . $this->columnDefinitionBody($row);
    }

    // =================================================================
    // 列签名与比对
    // =================================================================

    /**
     * 由标准列定义文本生成比对签名。
     *
     * @param string $def
     * @return array
     */
    private function standardColumnSignature($def)
    {
        $sig = array(
            'type' => '',
            'unsigned' => (bool) preg_match('/\bunsigned\b/i', $def),
            'nullable' => preg_match('/\bNOT\s+NULL\b/i', $def) ? 'NO' : 'YES',
            'has_default' => false,
            'default' => null,
            'auto' => (bool) preg_match('/\bAUTO_INCREMENT\b/i', $def),
            'comment' => null,
            'charset' => '',
            'collate' => '',
        );

        if (preg_match('/^([a-zA-Z]+)\s*/', $def, $m)) {
            $type = $m[1];
            $rest = substr($def, strlen($m[0]));
            if (preg_match('/^\s*\(/', $rest)) {
                $group = $this->extractParenGroup($rest, strpos($rest, '('));
                if ($group !== null) {
                    $type .= '(' . $group[0] . ')';
                }
            }
            $sig['type'] = $this->normalizeType($type);
        }

        if (preg_match('/\bDEFAULT\s+(\'(?:[^\'\\\\]|\\\\.)*\'|\((?:[^)]*)\)|CURRENT_TIMESTAMP(?:\(\d*\))?|NULL|[^\s,]+)/i', $def, $dm)) {
            $sig['has_default'] = true;
            $sig['default'] = $this->normalizeDefaultToken($dm[1]);
        }
        if (preg_match('/\bCOMMENT\s+\'((?:[^\'\\\\]|\\\\.)*)\'/i', $def, $cm)) {
            $sig['comment'] = $this->unescapeSqlString($cm[1]);
        }
        if (preg_match('/\bCOLLATE\s+([A-Za-z0-9_]+)/i', $def, $om)) {
            $sig['collate'] = $this->normalizeCollation($om[1]);
        }
        if (preg_match('/\bCHARACTER\s+SET\s+([A-Za-z0-9_]+)/i', $def, $sm)) {
            $sig['charset'] = strtolower($sm[1]);
        } elseif ($sig['collate'] !== '') {
            $sig['charset'] = $this->charsetOfCollation($sig['collate']);
        }
        return $sig;
    }

    /**
     * 由 INFORMATION_SCHEMA 行生成比对签名。
     *
     * @param array $row
     * @return array
     */
    private function currentColumnSignature($row)
    {
        $sig = array(
            'type' => $this->normalizeType($row['COLUMN_TYPE']),
            'unsigned' => (strpos(strtolower($row['COLUMN_TYPE']), 'unsigned') !== false),
            'nullable' => $row['IS_NULLABLE'] === 'NO' ? 'NO' : 'YES',
            'has_default' => !is_null($row['COLUMN_DEFAULT']),
            'default' => is_null($row['COLUMN_DEFAULT']) ? null : $this->normalizeDefaultToken($row['COLUMN_DEFAULT']),
            'auto' => (stripos($row['EXTRA'], 'auto_increment') !== false),
            'comment' => isset($row['COLUMN_COMMENT']) ? (string) $row['COLUMN_COMMENT'] : '',
            'charset' => '',
            'collate' => '',
        );
        if (!is_null($row['COLLATION_NAME']) && $row['COLLATION_NAME'] !== '') {
            $sig['collate'] = $this->normalizeCollation($row['COLLATION_NAME']);
            $sig['charset'] = $this->charsetOfCollation($row['COLLATION_NAME']);
        }
        return $sig;
    }

    /**
     * 类型归一：小写、压缩空白、整型剥离显示宽度。
     *
     * @param string $type
     * @return string
     */
    private function normalizeType($type)
    {
        $type = strtolower(trim(preg_replace('/\s+/', ' ', (string) $type)));
        // 剥离 unsigned / signed / zerofill（单独比对 unsigned）
        $type = preg_replace('/\s+(unsigned|signed|zerofill)\b/', '', $type);
        // 逗号后空格归一（decimal(10, 2) -> decimal(10,2)）
        $type = preg_replace('/\s*,\s*/', ',', $type);
        // 整型显示宽度剥离：int(10) -> int；tinyint(1)/(3) 视为等价（防 MySQL 8 无法保留宽度导致反复 ALTER）
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)$/', $type)) {
            $type = preg_replace('/\(\d+\)$/', '', $type);
        }
        return $type;
    }

    /**
     * 默认值 token 归一：去引号、NULL 哨兵、大小写统一。
     *
     * @param string $token
     * @return string
     */
    private function normalizeDefaultToken($token)
    {
        $token = trim((string) $token);
        if (strlen($token) >= 2 && $token[0] === "'" && substr($token, -1) === "'") {
            $token = $this->unescapeSqlString(substr($token, 1, -1));
        }
        if (strcasecmp($token, 'NULL') === 0) {
            return '__NULL__';
        }
        if (strcasecmp($token, 'CURRENT_TIMESTAMP') === 0 || preg_match('/^CURRENT_TIMESTAMP\(\d*\)$/i', $token)) {
            return strtoupper($token);
        }
        return $token;
    }

    /**
     * SQL 字符串转义还原（\' -> '、\\ -> \）。
     *
     * @param string $str
     * @return string
     */
    private function unescapeSqlString($str)
    {
        return str_replace(array("\\'", '\\\\'), array("'", '\\'), (string) $str);
    }

    /**
     * 列签名比对：一致返回 true。
     *
     * @param array $std 标准签名
     * @param array $cur 现网签名
     * @return bool
     */
    private function columnSignaturesMatch($std, $cur)
    {
        if ($std['type'] !== $cur['type']) {
            return false;
        }
        if ($std['unsigned'] !== $cur['unsigned']) {
            return false;
        }
        if ($std['nullable'] !== $cur['nullable']) {
            return false;
        }
        if ($std['auto'] !== $cur['auto']) {
            return false;
        }
        // 标准未写 DEFAULT：现网隐式默认（''、'0'、NULL）视为合规，不反复 MODIFY
        if ($std['has_default']) {
            $stdDefault = is_null($std['default']) ? '__NULL__' : $std['default'];
            $curDefault = is_null($cur['default']) ? '__NULL__' : $cur['default'];
            if ($std['nullable'] === 'YES' && $curDefault === '__NULL__') {
                $curDefault = $stdDefault; // 可空列「无默认」与「DEFAULT NULL」等价
            }
            if ((string) $stdDefault !== (string) $curDefault) {
                return false;
            }
        }
        if ((string) $std['comment'] !== (string) $cur['comment']) {
            return false;
        }
        // 仅当标准显式声明字符集/排序规则时比对（避免推导出的默认值引发误 MODIFY）
        if ($std['collate'] !== '' && $std['collate'] !== $cur['collate']) {
            return false;
        }
        if ($std['charset'] !== '' && $std['charset'] !== $cur['charset']) {
            return false;
        }
        return true;
    }

    // =================================================================
    // 结构同步（标准驱动）
    // =================================================================

    /**
     * 按全部标准文件同步现网结构（补建缺失表、增删改列、增删改索引、对齐表选项）。
     *
     * @return array array('synced' => n, 'created' => n, 'skipped' => n, 'failed' => n, 'statements' => n)
     */
    public function syncStandards()
    {
        $this->parseStandards();
        $stats = array('synced' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0);
        foreach ($this->models as $model) {
            $result = $this->syncTable($model);
            if ($result === 'created') {
                $stats['created']++;
            } elseif ($result === 'synced') {
                $stats['synced']++;
            } else {
                $stats['skipped']++;
            }
        }
        $stats['failed'] = count($this->errors);
        $stats['statements'] = count($this->statements);
        $this->log('结构同步完成：对齐 ' . $stats['synced'] . ' 表，补建 ' . $stats['created'] . ' 表，跳过 ' . $stats['skipped'] . ' 表，语句 ' . $stats['statements'] . ' 条，失败 ' . $stats['failed'] . ' 条');
        return $stats;
    }

    /**
     * 同步单表。
     *
     * @param array $model
     * @return string 'created' | 'synced' | 'skipped'
     */
    private function syncTable($model)
    {
        $bare = $model['name'];
        $actual = DB::tableName($bare);

        if (!DB::tableExist($bare)) {
            if (empty($model['create'])) {
                $this->log('跳过缺失的表（create=false）：' . $bare);
                return 'skipped';
            }
            $this->log('补建缺失的表：' . $bare);
            if (!$this->exec($this->createTableSql($model))) {
                return 'skipped';
            }
            return 'created';
        }

        // 1) 表选项（引擎 / 字符集）：先对齐，后续列比对基于转换后的状态
        $this->syncTableOptions($model, $actual);

        // 2) 内省现网
        $curColumns = $this->introspectColumns($bare);
        $curIndexes = $this->introspectIndexes($bare);

        // 2.5) 主键过渡：MySQL 硬性要求 AUTO_INCREMENT 列必须是键列，若把「删旧主键 / 补自增列 /
        //      建新主键」拆成多条 ALTER，中途必现 1075（there can be only one auto column and it
        //      must be defined as a key），并留下「既无主键、又缺 id 列」的半程结构。
        //      因此合并为一条原子 ALTER；成功后重新内省，后续步骤据实对齐、不再重复这些动作。
        $pkSql = $this->primaryKeyTransitionSql($model, $actual, $curColumns, $curIndexes);
        if ($pkSql !== '' && $this->exec($pkSql)) {
            $curColumns = $this->introspectColumns($bare);
            $curIndexes = $this->introspectIndexes($bare);
        }

        // 标准列（保持顺序）
        $stdColumns = array();
        foreach ($model['columns'] as $col) {
            $stdColumns[strtolower($col['name'])] = $col;
        }

        // 3) 多余索引 / 定义不符索引：先删（避免后续删列时索引悬挂）
        $indexOps = array('drop' => array(), 'add' => array());
        $this->diffIndexes($model, $curIndexes, $indexOps);
        foreach ($indexOps['drop'] as $sql) {
            $this->exec($sql);
        }

        // 4) 多余列删除
        foreach (array_keys($curColumns) as $colName) {
            if (!isset($stdColumns[$colName])) {
                $this->exec('ALTER TABLE ' . $actual . ' DROP `' . $curColumns[$colName]['COLUMN_NAME'] . '`');
            }
        }

        // 5) 缺失列补建（按标准顺序，用 AFTER 定位；仅当存在前置标准列时）
        $known = array();
        foreach ($curColumns as $colName => $row) {
            $known[$colName] = true;
        }
        $prevStdName = '';
        foreach ($model['columns'] as $col) {
            $lower = strtolower($col['name']);
            if (!isset($known[$lower])) {
                $position = '';
                if ($prevStdName === '') {
                    $position = ' FIRST';
                } elseif (isset($known[strtolower($prevStdName)])) {
                    $position = ' AFTER `' . $prevStdName . '`';
                }
                $this->exec('ALTER TABLE ' . $actual . ' ADD `' . $col['name'] . '` ' . $col['def'] . $position);
                $known[$lower] = true;
                $curColumns[$lower] = null;
            }
            $prevStdName = $col['name'];
        }

        // 6) 定义不符列 MODIFY（以标准原文定义为准）
        foreach ($model['columns'] as $col) {
            $lower = strtolower($col['name']);
            if (!isset($curColumns[$lower]) || $curColumns[$lower] === null) {
                continue; // 刚补建的列无需再改
            }
            $stdSig = $this->standardColumnSignature($col['def']);
            $curSig = $this->currentColumnSignature($curColumns[$lower]);
            if (!$this->columnSignaturesMatch($stdSig, $curSig)) {
                $this->exec('ALTER TABLE ' . $actual . ' MODIFY `' . $col['name'] . '` ' . $col['def']);
            }
        }

        // 7) 缺失索引补建（在列就绪之后）
        foreach ($indexOps['add'] as $sql) {
            $this->exec($sql);
        }

        return 'synced';
    }

    /**
     * 构造主键过渡的合并 ALTER 片段；主键已对齐或标准无主键时返回空串。
     *
     * 覆盖三种旧站形态：主键在业务列上而标准 id 缺失；主键列名/列序与标准不同；
     * 自增列只有 UNIQUE 键而无 PRIMARY（此时 UNIQUE 与建主键合并，避免自增列短暂失去键）。
     *
     * @param array $model
     * @param string $actual
     * @param array $curColumns 现网列（小写名 => 行）
     * @param array $curIndexes 现网索引（小写名 => array(name,unique,columns))
     * @return string
     */
    private function primaryKeyTransitionSql($model, $actual, array $curColumns, array $curIndexes)
    {
        $stdPk = null;
        foreach ($model['indexes'] as $idx) {
            if (isset($idx['type']) && $idx['type'] === 'PRIMARY') {
                $stdPk = $idx;
                break;
            }
        }
        if ($stdPk === null) {
            return '';
        }

        $stdCols = $this->normalizeIndexColumns($stdPk['columns']);
        $stdNames = array();
        foreach ($stdCols as $col) {
            $stdNames[] = $col['col'];
        }
        if (empty($stdNames)) {
            return '';
        }

        $curPkNames = null;
        if (isset($curIndexes['primary'])) {
            $curPkNames = array();
            foreach ($this->normalizeIndexColumns($curIndexes['primary']['columns']) as $col) {
                $curPkNames[] = $col['col'];
            }
            if ($curPkNames === $stdNames) {
                return ''; // 主键形状已一致（列类型差异交由 MODIFY 步骤处理）
            }
        }

        $actions = array();
        if ($curPkNames !== null) {
            $actions[] = 'DROP PRIMARY KEY';
        }
        // 旧主键列本身不在标准中（如 page_id 未走改名链）：同一条语句里一并删除，
        // 否则旧自增列会在「主键已删、新列未建」的中间态触发 1075。
        // 该列本就会由「多余列删除」步骤 DROP，此处仅提前到原子语句内，数据结局不变。
        $stdColumnNames = array();
        foreach ($model['columns'] as $col) {
            $stdColumnNames[strtolower($col['name'])] = true;
        }
        if ($curPkNames !== null) {
            foreach ($curPkNames as $colName) {
                if (!isset($stdColumnNames[$colName]) && isset($curColumns[$colName])) {
                    $actions[] = 'DROP `' . $curColumns[$colName]['COLUMN_NAME'] . '`';
                }
            }
        }
        // 自增列被建成 UNIQUE 键（无主键）：与建新主键合并执行
        foreach ($curIndexes as $key => $cur) {
            if ($key === 'primary' || empty($cur['unique'])) {
                continue;
            }
            $names = array();
            foreach ($this->normalizeIndexColumns($cur['columns']) as $col) {
                $names[] = $col['col'];
            }
            if ($names === $stdNames) {
                $actions[] = 'DROP INDEX `' . $cur['name'] . '`';
            }
        }
        // 标准主键列在当前表缺失：同一条语句内补建，保证自增列与主键同时到位
        foreach ($stdNames as $colName) {
            if (isset($curColumns[$colName])) {
                continue;
            }
            $def = null;
            foreach ($model['columns'] as $col) {
                if (strtolower($col['name']) === $colName) {
                    $def = $col['def'];
                    break;
                }
            }
            if ($def === null) {
                $this->error('标准主键列不在标准列清单中，无法构造主键过渡：' . $model['name'] . '.' . $colName);
                return '';
            }
            $actions[] = 'ADD `' . $colName . '` ' . $def;
        }
        $actions[] = 'ADD PRIMARY KEY (' . $this->indexColumnsSql($stdCols) . ')';

        $this->log('主键过渡：' . $model['name'] . '（' . implode('; ', $actions) . '）');
        return 'ALTER TABLE ' . $actual . ' ' . implode(', ', $actions);
    }

    /**
     * 表选项对齐（ENGINE / 字符集）。
     *
     * @param array $model
     * @param string $actual
     * @return void
     */
    private function syncTableOptions($model, $actual)
    {
        $options = $this->introspectTableOptions($model['name']);
        if ($options === null) {
            return;
        }
        $curEngine = isset($options['ENGINE']) ? strtolower((string) $options['ENGINE']) : '';
        $curCollate = $this->normalizeCollation(isset($options['TABLE_COLLATION']) ? $options['TABLE_COLLATION'] : '');
        $stdEngine = strtolower($model['engine']);
        $stdCollate = $this->normalizeCollation($model['collate']);

        $parts = array();
        if ($curEngine !== '' && $curEngine !== $stdEngine) {
            $parts[] = 'ENGINE=' . $model['engine'];
        }
        $convert = false;
        if ($stdCollate !== '' && $curCollate !== $stdCollate) {
            $convert = true;
        } elseif ($stdCollate === '' && $model['charset'] !== '' && $this->charsetOfCollation($curCollate) !== $model['charset']) {
            $convert = true;
        }
        if ($convert && $model['charset'] !== '') {
            $convertSql = 'CONVERT TO CHARACTER SET ' . $model['charset'];
            if ($model['collate'] !== '') {
                $convertSql .= ' COLLATE ' . $model['collate'];
            }
            $parts[] = $convertSql;
        }
        if (empty($parts)) {
            return;
        }
        $this->log('对齐表选项：' . $model['name'] . '（当前 ' . $curEngine . '/' . $curCollate . '）');
        $this->exec('ALTER TABLE ' . $actual . ' ' . implode(', ', $parts));
    }

    /**
     * 索引 diff：填充 drop / add 语句清单。
     *
     * @param array $model
     * @param array $curIndexes 现网索引（小写名 => array(unique,columns)）
     * @param array $ops 按引用：array('drop' => array(), 'add' => array())
     * @return void
     */
    private function diffIndexes($model, $curIndexes, array &$ops)
    {
        $actual = DB::tableName($model['name']);
        $stdIndexes = array();
        foreach ($model['indexes'] as $key => $idx) {
            $stdIndexes[$key] = array(
                'unique' => ($idx['type'] === 'PRIMARY' || $idx['type'] === 'UNIQUE'),
                'columns' => $this->normalizeIndexColumns($idx['columns']),
                'name' => ($idx['type'] === 'PRIMARY') ? 'PRIMARY' : $idx['name'],
                'type' => $idx['type'],
            );
        }

        // 多余索引 与 定义不符索引 -> drop
        foreach ($curIndexes as $key => $cur) {
            if (!isset($stdIndexes[$key])) {
                $ops['drop'][] = ($key === 'primary')
                    ? 'ALTER TABLE ' . $actual . ' DROP PRIMARY KEY'
                    : 'ALTER TABLE ' . $actual . ' DROP INDEX `' . $curIndexes[$key]['name'] . '`';
                continue;
            }
            $std = $stdIndexes[$key];
            if ($std['unique'] !== $cur['unique'] || $std['columns'] !== $this->normalizeIndexColumns($cur['columns'])) {
                $ops['drop'][] = ($key === 'primary')
                    ? 'ALTER TABLE ' . $actual . ' DROP PRIMARY KEY'
                    : 'ALTER TABLE ' . $actual . ' DROP INDEX `' . $curIndexes[$key]['name'] . '`';
                $stdIndexes[$key]['needs_add'] = true;
            }
        }

        // 缺失索引 与 重建索引 -> add
        foreach ($stdIndexes as $key => $std) {
            $needsAdd = !isset($curIndexes[$key]) || !empty($std['needs_add']);
            if (!$needsAdd) {
                continue;
            }
            $colText = $this->indexColumnsSql($std['columns']);
            if ($std['type'] === 'PRIMARY') {
                $ops['add'][] = 'ALTER TABLE ' . $actual . ' ADD PRIMARY KEY (' . $colText . ')';
            } elseif ($std['type'] === 'UNIQUE') {
                $ops['add'][] = 'ALTER TABLE ' . $actual . ' ADD UNIQUE KEY `' . $std['name'] . '` (' . $colText . ')';
            } elseif ($std['type'] === 'FULLTEXT') {
                $ops['add'][] = 'ALTER TABLE ' . $actual . ' ADD FULLTEXT KEY `' . $std['name'] . '` (' . $colText . ')';
            } else {
                $ops['add'][] = 'ALTER TABLE ' . $actual . ' ADD KEY `' . $std['name'] . '` (' . $colText . ')';
            }
        }
    }

    /**
     * 索引列归一（比较用）：列名小写，保持顺序。
     *
     * @param array $columns
     * @return array
     */
    private function normalizeIndexColumns(array $columns)
    {
        $out = array();
        foreach ($columns as $col) {
            $out[] = array(
                'col' => strtolower(isset($col['col']) ? $col['col'] : (isset($col['COLUMN_NAME']) ? $col['COLUMN_NAME'] : '')),
                'sub' => isset($col['sub']) ? (string) $col['sub'] : '',
            );
        }
        return $out;
    }

    /**
     * 索引列 SQL 片段。
     *
     * @param array $columns 归一后的列数组
     * @return string
     */
    private function indexColumnsSql(array $columns)
    {
        $parts = array();
        foreach ($columns as $col) {
            $parts[] = '`' . $col['col'] . '`' . ($col['sub'] !== '' ? '(' . $col['sub'] . ')' : '');
        }
        return implode(',', $parts);
    }

    /**
     * 由表模型生成 CREATE TABLE 语句（运行时前缀）。
     *
     * @param array $model
     * @return string
     */
    private function createTableSql($model)
    {
        $lines = array();
        foreach ($model['columns'] as $col) {
            $lines[] = '  `' . $col['name'] . '` ' . $col['def'];
        }
        // 索引：PRIMARY 在前，其余按解析顺序
        $indexLines = array();
        foreach ($model['indexes'] as $idx) {
            $colText = $this->indexColumnsSql($this->normalizeIndexColumns($idx['columns']));
            if ($idx['type'] === 'PRIMARY') {
                array_unshift($indexLines, '  PRIMARY KEY (' . $colText . ')');
            } elseif ($idx['type'] === 'UNIQUE') {
                $indexLines[] = '  UNIQUE KEY `' . $idx['name'] . '` (' . $colText . ')';
            } elseif ($idx['type'] === 'FULLTEXT') {
                $indexLines[] = '  FULLTEXT KEY `' . $idx['name'] . '` (' . $colText . ')';
            } else {
                $indexLines[] = '  KEY `' . $idx['name'] . '` (' . $colText . ')';
            }
        }
        $body = implode(",\n", array_merge($lines, $indexLines));
        $tail = 'ENGINE=' . $model['engine'];
        if ($model['charset'] !== '') {
            $tail .= ' DEFAULT CHARSET=' . $model['charset'];
        }
        if ($model['collate'] !== '') {
            $tail .= ' COLLATE=' . $model['collate'];
        }
        return "CREATE TABLE " . DB::tableName($model['name']) . " (\n" . $body . "\n) " . $tail;
    }

    // =================================================================
    // 规范化迁移 API（供各版本 upgrade_*.php 补丁调用，全部幂等）
    // =================================================================

    /**
     * 取表模型（裸表名）。
     *
     * @param string $bare
     * @return array|null
     */
    public function getTableModel($bare)
    {
        $this->parseStandards();
        $key = strtolower($this->stripTablePrefix($bare));
        return isset($this->models[$key]) ? $this->models[$key] : null;
    }

    /**
     * 全部表模型（小写裸表名 => 模型）。
     *
     * @return array
     */
    public function getTableModels()
    {
        $this->parseStandards();
        return $this->models;
    }

    /**
     * 表缺失时按标准补建（幂等）。
     *
     * @param string $bare
     * @return bool 表最终是否存在
     */
    public function ensureTable($bare)
    {
        if (DB::tableExist($bare)) {
            return true;
        }
        $model = $this->getTableModel($bare);
        if ($model === null) {
            $this->error('无标准定义，无法补建表：' . $bare);
            return false;
        }
        $this->log('按标准补建表：' . $bare);
        return $this->exec($this->createTableSql($model));
    }

    /**
     * 表改名（保留全部数据；目标已存在或源不存在时跳过）。
     *
     * @param string $old 旧裸表名
     * @param string $new 新裸表名
     * @return bool 是否执行了改名
     */
    public function renameTable($old, $new)
    {
        if (!DB::tableExist($old) || DB::tableExist($new)) {
            return false;
        }
        $this->log('表改名：' . $old . ' -> ' . $new);
        return $this->exec('RENAME TABLE ' . DB::tableName($old) . ' TO ' . DB::tableName($new));
    }

    /**
     * 列改名（保留数据；以现网定义 CHANGE，属性由最终结构同步校准）。
     *
     * @param string $table 裸表名
     * @param string $old 旧列名
     * @param string $new 新列名
     * @return bool 是否执行了改名
     */
    public function renameColumn($table, $old, $new)
    {
        if (!DB::tableExist($table) || !DB::fieldExist($table, $old) || DB::fieldExist($table, $new)) {
            return false;
        }
        $columns = $this->introspectColumns($table);
        $key = strtolower($old);
        if (!isset($columns[$key])) {
            return false;
        }
        $this->log('列改名：' . $table . '.' . $old . ' -> ' . $new);
        return $this->exec('ALTER TABLE ' . DB::tableName($table) . ' CHANGE `' . $old . '` `' . $new . '` '
            . $this->columnDefinitionBody($columns[$key]));
    }

    /**
     * 按标准补列（列已存在时跳过）——用于「新增列 + 历史行回填」场景。
     *
     * @param string $table 裸表名
     * @param string $column 列名
     * @return bool 列最终是否存在
     */
    public function addColumn($table, $column)
    {
        if (!DB::tableExist($table)) {
            return $this->ensureTable($table);
        }
        if (DB::fieldExist($table, $column)) {
            return true;
        }
        $model = $this->getTableModel($table);
        if ($model === null) {
            $this->error('无标准定义，无法补列：' . $table . '.' . $column);
            return false;
        }
        $prev = '';
        foreach ($model['columns'] as $col) {
            if (strcasecmp($col['name'], $column) === 0) {
                $position = '';
                if ($prev === '') {
                    $position = ' FIRST';
                } elseif (DB::fieldExist($table, $prev)) {
                    $position = ' AFTER `' . $prev . '`';
                }
                $this->log('按标准补列：' . $table . '.' . $column);
                $this->exec('ALTER TABLE ' . DB::tableName($table) . ' ADD `' . $col['name'] . '` ' . $col['def'] . $position);
                return true;
            }
            $prev = $col['name'];
        }
        $this->error('标准中不存在该列：' . $table . '.' . $column);
        return false;
    }

    /**
     * INT unix 时间戳列迁为 DATETIME（含值转换、旧列索引清理、零日期清洗）。
     * 旧列与新列同名时走临时列中转（类型已是 datetime/timestamp 时直接跳过）。
     *
     * @param string $table
     * @param string $oldCol
     * @param string $newCol
     * @return void
     */
    public function unixToDatetime($table, $oldCol, $newCol)
    {
        if (!DB::tableExist($table) || !DB::fieldExist($table, $oldCol)) {
            if (DB::tableExist($table) && DB::fieldExist($table, $newCol)) {
                $this->nullifyZeroDates($table, array($newCol));
            }
            return;
        }
        $actual = DB::tableName($table);
        $escapedOld = DB::escapeString($oldCol);

        if (strcasecmp($oldCol, $newCol) === 0) {
            // 同名列：加临时列中转
            $dataType = strtolower((string) DB::getOne("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . DB::escapeString($this->actualTableName($table)) . "'"
                . " AND COLUMN_NAME = '" . $escapedOld . "'"));
            if ($dataType === 'datetime' || $dataType === 'timestamp') {
                $this->nullifyZeroDates($table, array($newCol));
                return;
            }
            $tmp = $newCol . '_dt_tmp';
            if (!DB::fieldExist($table, $tmp)) {
                $this->exec('ALTER TABLE ' . $actual . ' ADD `' . $tmp . '` DATETIME NULL DEFAULT NULL AFTER `' . $oldCol . '`');
            }
            if (!DB::fieldExist($table, $tmp)) {
                $this->error('unixToDatetime 失败：临时列不存在 ' . $table . '.' . $tmp);
                return;
            }
            // 值转换未成功时绝不删除源列（否则整列时间数据丢失）
            if (!$this->exec('UPDATE ' . $actual . ' SET `' . $tmp . '` = IF(`' . $oldCol . '` > 0, FROM_UNIXTIME(`' . $oldCol . '`), NULL) WHERE `' . $oldCol . '` IS NOT NULL')) {
                $this->error('unixToDatetime 中止：值转换失败，保留源列 ' . $table . '.' . $oldCol);
                return;
            }
            $this->dropIndexIfExists($table, $oldCol);
            $this->exec('ALTER TABLE ' . $actual . ' DROP `' . $oldCol . '`');
            if (!DB::fieldExist($table, $newCol) && DB::fieldExist($table, $tmp)) {
                $this->exec('ALTER TABLE ' . $actual . ' CHANGE `' . $tmp . '` `' . $newCol . '` DATETIME NULL DEFAULT NULL');
            }
            $this->nullifyZeroDates($table, array($newCol));
            return;
        }

        if (!DB::fieldExist($table, $newCol)) {
            $this->exec('ALTER TABLE ' . $actual . ' ADD `' . $newCol . '` DATETIME NULL DEFAULT NULL AFTER `' . $oldCol . '`');
        }
        // 新列未就绪时不动旧列，避免数据丢失
        if (!DB::fieldExist($table, $newCol)) {
            $this->error('unixToDatetime 失败：新列不存在 ' . $table . '.' . $newCol);
            return;
        }
        // 值转换未成功时绝不删除源列（否则整列时间数据丢失）
        if (!$this->exec('UPDATE ' . $actual . ' SET `' . $newCol . '` = IF(`' . $oldCol . '` > 0, FROM_UNIXTIME(`' . $oldCol . '`), NULL) WHERE `' . $oldCol . '` IS NOT NULL')) {
            $this->error('unixToDatetime 中止：值转换失败，保留源列 ' . $table . '.' . $oldCol);
            return;
        }
        $this->dropIndexIfExists($table, $oldCol);
        if (DB::fieldExist($table, $oldCol)) {
            $this->exec('ALTER TABLE ' . $actual . ' DROP `' . $oldCol . '`');
        }
        $this->nullifyZeroDates($table, array($newCol));
    }

    /**
     * 删除指定列上的同名索引（存在才删）。
     *
     * @param string $table
     * @param string $indexName
     * @return void
     */
    private function dropIndexIfExists($table, $indexName)
    {
        $indexes = $this->introspectIndexes($table);
        $key = strtolower($indexName);
        if (!isset($indexes[$key]) || $key === 'primary') {
            return;
        }
        $this->exec('ALTER TABLE ' . DB::tableName($table) . ' DROP INDEX `' . $indexName . '`');
    }

    /**
     * 清洗零日期（'0000-00-00 ...'）为 NULL（幂等）。
     * 严格模式下不能直接用零日期字面量比较，须 CAST 成字符串再比对。
     *
     * @param string $table
     * @param array $fields
     * @return void
     */
    public function nullifyZeroDates($table, array $fields)
    {
        if (!DB::tableExist($table)) {
            return;
        }
        foreach ($fields as $field) {
            if (!DB::fieldExist($table, $field)) {
                continue;
            }
            $this->exec("UPDATE " . DB::tableName($table) . " SET `" . $field . "` = NULL"
                . " WHERE `" . $field . "` IS NOT NULL"
                . " AND CAST(`" . $field . "` AS CHAR) IN ('0000-00-00 00:00:00', '0000-00-00')");
        }
    }

    /**
     * 值替换：col = 'to' WHERE col = 'from'（可附加条件）。
     *
     * @param string $table
     * @param string $column
     * @param string $from
     * @param string $to
     * @param string $extraWhere 附加 WHERE 片段（不含 WHERE/AND 前缀也可，自动补 AND），如 "`name` = 'login_mode'"
     * @return void
     */
    public function swapValue($table, $column, $from, $to, $extraWhere = '')
    {
        if (!DB::tableExist($table) || !DB::fieldExist($table, $column)) {
            return;
        }
        $where = "`" . $column . "` = '" . DB::escapeString($from) . "'";
        $extraWhere = trim((string) $extraWhere);
        if ($extraWhere !== '') {
            $extraWhere = preg_replace('/^\s*AND\s+/i', '', $extraWhere);
            $where .= ' AND ' . $extraWhere;
        }
        $this->exec("UPDATE " . DB::tableName($table) . " SET `" . $column . "` = '" . DB::escapeString($to) . "' WHERE " . $where);
    }

    /**
     * 字符串替换：col = REPLACE(col, search, replace)，仅命中含 search 的行。
     *
     * @param string $table
     * @param string $column
     * @param string $search
     * @param string $replace
     * @return void
     */
    public function replaceValue($table, $column, $search, $replace)
    {
        if (!DB::tableExist($table) || !DB::fieldExist($table, $column)) {
            return;
        }
        $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $search) . '%';
        $this->exec("UPDATE " . DB::tableName($table) . " SET `" . $column . "` = REPLACE(`" . $column . "`, '"
            . DB::escapeString($search) . "', '" . DB::escapeString($replace) . "')"
            . " WHERE `" . $column . "` LIKE '" . DB::escapeString($like) . "'");
    }

    /**
     * 全表引擎与字符集转换：MyISAM 或非 utf8mb4 -> InnoDB + utf8mb4_unicode_ci（1.9 遗留步骤，幂等）。
     *
     * @return int 转换的表数量
     */
    public function convertAllTablesToInnoDbUtf8mb4()
    {
        $rows = DB::fnQuery("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
            . " AND (ENGINE = 'MyISAM' OR TABLE_COLLATION NOT LIKE 'utf8mb4%')");
        $count = 0;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $this->exec('ALTER TABLE `' . $row['TABLE_NAME'] . '` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $count++;
            }
        }
        $this->log('全表引擎/字符集转换完成：' . $count . ' 张表');
        return $count;
    }

    // =================================================================
    // config 表重建（读旧值 -> 完全重建 -> 回灌 + 保留旧站独有行）
    // =================================================================

    /**
     * 按标准结构完全重建指定表并回灌数据（config 表专用策略）。
     *
     * 流程：SELECT * 全行快照 -> DROP -> 按标准 CREATE -> 导入默认行（$defaultsFile）
     *       -> 回灌：同名行恢复 value 值；旧站独有行按快照完整插回（保留自定义配置）。
     *
     * @param string $table 裸表名（config）
     * @param string $standardFile 结构标准文件（其中须含该表 CREATE TABLE）
     * @param string $defaultsFile 默认行数据文件（INSERT 语句）
     * @return array array('rows_before' => n, 'defaults' => n, 'restored' => n, 'kept' => n)
     */
    public function rebuildTableFromStandard($table, $standardFile, $defaultsFile)
    {
        // 标准模型：用独立实例解析该表（避免污染当前实例标准集）
        $probe = new self(array('standards' => array($standardFile), 'dry_run' => $this->dryRun));
        $probe->parseStandards();
        $model = $probe->getTableModel($table);
        if ($model === null) {
            throw new \RuntimeException('config 重建失败：标准文件中未找到表定义 ' . $table . '（' . $standardFile . '）');
        }

        $actual = DB::tableName($table);
        $oldRows = array();
        if (DB::tableExist($table)) {
            // fnQuery 失败与「空表」都返回空数组，无法区分；因此先取行数做快照完整性校验。
            // 快照不完整就中止重建（fail loud），否则 DROP 之后旧值彻底丢失且无从恢复。
            $expectRaw = DB::getOne("SELECT COUNT(*) FROM " . $actual);
            if ($expectRaw === false || $expectRaw === null) {
                throw new \RuntimeException('config 重建中止：无法读取 ' . $actual . ' 的行数，为避免配置数据丢失已放弃重建');
            }
            $rows = DB::fnQuery("SELECT * FROM " . $actual);
            $oldRows = is_array($rows) ? $rows : array();
            if (count($oldRows) !== (int) $expectRaw) {
                throw new \RuntimeException('config 重建中止：旧值快照不完整（应 ' . (int) $expectRaw
                    . ' 行，实得 ' . count($oldRows) . ' 行），为避免配置数据丢失已放弃重建');
            }
        }
        $this->log('config 表重建：旧行 ' . count($oldRows) . ' 条');

        // 默认行预检（必须在 DROP 之前完成）：文件缺失、解析不出行、字段数与标准列序不符，
        // 都要在这里拦住——若等 DROP 之后才发现灌不进去，站点配置就真的没了。
        $columnNames = array();
        foreach ($model['columns'] as $col) {
            $columnNames[] = $col['name'];
        }
        if (!is_file($defaultsFile)) {
            throw new \RuntimeException('config 重建中止：默认行数据文件不存在（' . $defaultsFile . '），未改动现网结构');
        }
        $inserts = $this->parseInserts($defaultsFile, $table);
        if (empty($inserts)) {
            throw new \RuntimeException('config 重建中止：默认行数据文件未解析出任何行（' . $defaultsFile . '），未改动现网结构');
        }
        foreach ($inserts as $tokens) {
            if (count($tokens) !== count($columnNames)) {
                throw new \RuntimeException('config 重建中止：默认行字段数与标准列序不符（期望 ' . count($columnNames)
                    . '，实际 ' . count($tokens) . '）：' . implode(',', $tokens) . '，未改动现网结构');
            }
        }

        // 重建结构
        if (DB::tableExist($table) && !$this->exec('DROP TABLE ' . $actual)) {
            throw new \RuntimeException('config 重建失败：DROP TABLE ' . $actual);
        }
        if (!$this->exec($probe->createTableSql($model))) {
            throw new \RuntimeException('config 重建失败：CREATE TABLE ' . $actual);
        }

        // 导入默认行
        $defaultCount = 0;
        $defaultKeys = array();
        foreach ($inserts as $tokens) {
            $sql = 'INSERT INTO ' . $actual . ' (' . $this->quoteColumnList($columnNames) . ') VALUES (' . implode(', ', $tokens) . ')';
            if ($this->exec($sql)) {
                $defaultCount++;
                $defaultKeys[(string) trim($tokens[1], "'")] = true; // 第 2 列为 name
            }
        }
        if ($defaultCount === 0) {
            throw new \RuntimeException('config 重建失败：默认行 ' . count($inserts) . ' 条全部灌入失败');
        }
        if ($defaultCount !== count($inserts)) {
            $this->error('config 默认行部分灌入失败（成功 ' . $defaultCount . ' / 共 ' . count($inserts) . '）');
        }

        // 回灌旧值：同名恢复 value；旧站独有行完整插回
        $restored = 0;
        $kept = 0;
        foreach ($oldRows as $row) {
            $key = isset($row['name']) ? (string) $row['name'] : '';
            if ($key === '') {
                continue;
            }
            if (isset($defaultKeys[$key])) {
                if (array_key_exists('value', $row)) {
                    $this->exec('UPDATE ' . $actual . " SET `value` = '" . DB::escapeString((string) $row['value']) . "'"
                        . " WHERE `name` = '" . DB::escapeString($key) . "'");
                    $restored++;
                }
                continue;
            }
            // 旧站独有行：按快照完整插回（仅取标准表中存在的列）
            $cols = array();
            $vals = array();
            foreach ($columnNames as $colName) {
                if ($colName === 'id') {
                    continue; // 自增列不强制保留
                }
                if (array_key_exists($colName, $row)) {
                    $cols[] = $colName;
                    $vals[] = "'" . DB::escapeString((string) $row[$colName]) . "'";
                }
            }
            if (empty($cols)) {
                continue;
            }
            if ($this->exec('INSERT INTO ' . $actual . ' (' . $this->quoteColumnList($cols) . ') VALUES (' . implode(', ', $vals) . ')')) {
                $kept++;
            }
        }
        $this->log('config 表重建完成：默认行 ' . $defaultCount . '，回灌 ' . $restored . '，保留旧站独有行 ' . $kept);
        return array('rows_before' => count($oldRows), 'defaults' => $defaultCount, 'restored' => $restored, 'kept' => $kept);
    }

    /**
     * 读取数据文件中的 INSERT 语句（仅取目标表），返回按位置排列的值 token 列表。
     *
     * @param string $file
     * @param string $bareTable
     * @return array array(array(token, token, ...), ...)
     */
    private function parseInserts($file, $bareTable)
    {
        $content = (string) @file_get_contents($file);
        $out = array();
        foreach ($this->splitSqlStatements($content) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || !preg_match('/^INSERT\s+INTO\s+/i', $stmt)) {
                continue;
            }
            if (!preg_match('/^INSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?\s*(?:\(([^)]*)\))?\s*VALUES\s*(.+)$/is', $stmt, $m)) {
                continue;
            }
            if (strcasecmp($this->stripTablePrefix($m[1]), $this->stripTablePrefix($bareTable)) !== 0) {
                continue;
            }
            $valuesText = trim($m[3]);
            $group = $this->extractParenGroup($valuesText, strpos($valuesText, '('));
            if ($group === null) {
                continue;
            }
            $tokens = array();
            foreach ($this->splitTopLevel($group[0]) as $token) {
                $tokens[] = trim($token);
            }
            $out[] = $tokens;
        }
        return $out;
    }

    /**
     * 列名清单转反引号列表。
     *
     * @param array $names
     * @return string
     */
    private function quoteColumnList(array $names)
    {
        $out = array();
        foreach ($names as $name) {
            $out[] = '`' . $name . '`';
        }
        return implode(', ', $out);
    }
}
