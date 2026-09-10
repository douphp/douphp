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

namespace Dou\Core\Infra\Database;

use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\SiteDebugExceptionRenderer;
use Dou\Core\Infra\Log\Log;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}
class Connection
{
    /** @var string 数据库主机 */
    private $dbhost;
    /** @var string 数据库用户名 */
    private $dbuser;
    /** @var string 数据库用户名密码 */
    private $dbpass;
    /** @var string 数据库名 */
    private $dbname;
    /** @var \mysqli|false 数据库连接标识 */
    private $dou_link;
    /** @var string 数据库前缀 */
    private $prefix;
    /** @var string 数据库编码，GBK,UTF8,gb2312 */
    private $charset;
    /** @var string sql执行语句 */
    private $sql;
    /** @var string 数据库错误提示 */
    private $error_msg;
    /** @var string 最后执行的完整SQL语句（用于调试） */
    private $last_sql = '';
    /** @var array 最后执行的绑定参数（用于调试） */
    private $last_params = [];
    /** @var bool 调试模式开关 */
    private $debug_mode = false;

    // 链式查询属性
    /** @var string 当前操作的表名 */
    private $current_table = '';
    /** @var array WHERE条件数组 */
    private $where = [];
    /** @var string 查询字段 */
    private $fields = '*';
    /** @var string 排序 */
    private $order_by = '';
    /** @var string 限制条数 */
    private $limit = '';
    /** @var array 要插入/更新的数据 */
    private $data = [];
    /** @var array 绑定参数 */
    private $params = [];
    /** @var string 参数类型字符串 */
    private $types = '';
    /** @var array 存储JOIN信息 */
    private $joins = [];
    /** @var string 分组字段 */
    private $group_by = '';
    /** @var array HAVING条件数组 */
    private $having = [];
    /** @var bool 是否去重 */
    private $distinct = false;
    /** @var bool 是否只获取SQL */
    private $fetch_sql = false;
    /** @var string 最后构建的SQL */
    private $last_build_sql = '';
    /** @var Connection|null 父实例引用（用于克隆对象回写SQL） */
    private $parent_instance = null;
    /** @var array UNION查询数组 */
    private $unions = [];
    /** @var string 锁类型 */
    private $lock = '';
    /** @var int|null 偏移量（独立存储） */
    private $offset_value = null;

    /**
     * 执行__construct操作。
     *
     * @param mixed $dbhost 参数dbhost。
     * @param mixed $dbuser 参数dbuser。
     * @param mixed $dbpass 参数dbpass。
     * @param string $dbname 参数dbname。
     * @param string $prefix 参数prefix。
     * @param string $charset 参数charset。
     * @param bool $skip_connect 参数skip_connect。
     * @return void 返回结果。
     */
    public function __construct($dbhost, $dbuser, $dbpass, $dbname = '', $prefix = '', $charset = 'utf-8', $skip_connect = false)
    {
        // 检查 PHP 版本
        if (version_compare(PHP_VERSION, '5.6.0', '<')) {
            die('DouPHP Error: PHP 5.6.0 or higher is required. Current version: ' . PHP_VERSION);
        }

        // 字符集
        $charset = strtolower(trim($charset));

        $this->dbhost = $dbhost;
        $this->dbuser = $dbuser;
        $this->dbpass = $dbpass;
        $this->dbname = $dbname;
        $this->prefix = $prefix;
        $this->charset = $charset === 'utf-8' || $charset === 'utf8' ? 'utf8mb4' : $charset;

        // 只有在非克隆模式下才连接数据库
        if (!$skip_connect) {
            $this->connect();
        }
    }

    /**
     * 执行connect操作。
     *
     * @return bool 返回结果。
     */
    public function connect()
    {
        // 检查 MySQLi 扩展是否已启用（兼容 PHP 5.6+）
        if (!extension_loaded('mysqli') && !function_exists('mysqli_connect')) {
            $error_msg = 'MySQLi extension is not enabled. Please enable mysqli extension in php.ini.';
            if ($this->debug_mode) {
                $this->debugLog('Extension Error', $error_msg);
            }
            $this->error($error_msg);
            return false;
        }

        // 处理数据库主机地址（支持 IPv6 和端口）。
        // 裸 IPv6（如 ::1、2001:db8::1）含多个冒号，旧式 explode(':') 会把地址切碎并把端口错配成片段，
        // 故按三种形态显式解析：[IPv6]:port / host:port（单冒号）/ 裸主机或裸 IPv6（无端口）。
        $host = $this->dbhost;
        if (preg_match('/^\[(.+)\]:(\d+)$/', $host, $hostMatch)) {
            $dbhost = $hostMatch[1];
            $dbport = (int) $hostMatch[2];
        } elseif (substr_count($host, ':') === 1) {
            list($dbhost, $dbport) = explode(':', $host, 2);
        } else {
            $dbhost = $host;
            $dbport = 3306;
        }

        // 尝试建立数据库连接
        if (!$this->dou_link = @mysqli_connect($dbhost, $this->dbuser, $this->dbpass, null, $dbport)) {
            $error_msg = 'Can not connect to mysql server: ' . mysqli_connect_error();
            if ($this->debug_mode) {
                $this->debugLog('Connection Error', $error_msg);
            }
            $this->error($error_msg);
            return false;
        }

        // 设置字符集（支持降级处理）
        if ($this->charset) {
            // 处理 utf8mb4 兼容性（PHP 5.5+ 支持 utf8mb4，但需检查支持情况）
            if ($this->charset === 'utf8mb4') {
                // 先尝试 utf8mb4，失败则降级到 utf8
                if (!@mysqli_set_charset($this->dou_link, 'utf8mb4')) {
                    @mysqli_set_charset($this->dou_link, 'utf8');
                    $this->charset = 'utf8';
                    if ($this->debug_mode) {
                        $this->debugLog('Charset Warning', 'utf8mb4 not supported, fallback to utf8');
                    }
                }
            } else {
                // 其他字符集设置
                @mysqli_set_charset($this->dou_link, $this->charset);
            }
        }

        // 设置 SQL 模式
        $this->query("SET sql_mode=''");

        // 选择数据库
        if (mysqli_select_db($this->dou_link, $this->dbname) === false) {
            $error_msg = "NO THIS DBNAME: " . $this->dbname;
            if ($this->debug_mode) {
                $this->debugLog('Database Error', $error_msg);
            }
            $this->error($error_msg);
            return false;
        }

        return true; // 连接成功
    }

    /**
     * 执行charset操作。
     *
     * @return mixed 返回结果。
     */
    public function charset()
    {
        return $this->charset;
    }

    /**
     * 执行query操作。
     *
     * @param mixed $sql 参数sql。
     * @return mixed 返回结果。
     */
    public function query($sql)
    {
        $this->sql = $sql;

        if ($this->debug_mode) {
            $this->debugLog('Query', $sql);
        }

        $query = mysqli_query($this->dou_link, $this->sql);

        if (!$query && $this->debug_mode) {
            $this->debugLog('Query Error', mysqli_error($this->dou_link));
        }

        return $query;
    }

    /**
     * 执行tableName操作。
     *
     * @param mixed $table 参数table。
     * @return string 返回结果。
     */
    public function tableName($table)
    {
        return '`' . $this->prefix . $table . '`';
    }

    /**
     * 获取数据库表前缀。
     *
     * @return string
     */
    public function getPrefix()
    {
        return (string) $this->prefix;
    }

    /**
     * 执行tableExist操作。
     *
     * @param mixed $table 参数table。
     * @return mixed 返回结果。
     */
    public function tableExist($table)
    {
        $result = mysqli_query($this->dou_link, "SHOW TABLES LIKE '" . trim($this->tableName($table), '`') . "'");
        $exists = $result && mysqli_num_rows($result) > 0;
        if ($result) {
            mysqli_free_result($result);
        }
        return $exists;
    }

    /**
     * 执行numRows操作。
     *
     * @param mixed $query 参数query。
     * @return mixed 返回结果。
     */
    public function numRows($query)
    {
        if ($query) {
            return mysqli_num_rows($query);
        }
    }

    /**
     * 执行multiQuery操作。
     *
     * @param mixed $sql 参数sql。
     * @return mixed 返回结果。
     */
    public function multiQuery($sql)
    {
        $this->sql = $sql;
        $query = mysqli_multi_query($this->dou_link, $this->sql);
        return $query;
    }

    /**
     * 执行affectedRows操作。
     *
     * @return mixed 返回结果。
     */
    public function affectedRows()
    {
        return mysqli_affected_rows($this->dou_link);
    }

    /**
     * 执行numFields操作。
     *
     * @param mixed $query 参数query。
     * @return mixed 返回结果。
     */
    public function numFields($query)
    {
        if ($query) {
            return mysqli_num_fields($query);
        }
    }

    /**
     * 执行freeResult操作。
     *
     * @param mixed $query 参数query。
     * @return mixed 返回结果。
     */
    public function freeResult($query)
    {
        if ($query) {
            return mysqli_free_result($query);
        }
    }

    /**
     * 执行insertId操作。
     *
     * @return mixed 返回结果。
     */
    public function insertId()
    {
        return mysqli_insert_id($this->dou_link);
    }

    /**
     * 执行fetchRow操作。
     *
     * @param mixed $query 参数query。
     * @return mixed 返回结果。
     */
    public function fetchRow($query)
    {
        if ($query) {
            return mysqli_fetch_row($query);
        }
    }

    /**
     * 执行fetchAssoc操作。
     *
     * @param mixed $query 参数query。
     * @return mixed 返回结果。
     */
    public function fetchAssoc($query)
    {
        if ($query) {
            return mysqli_fetch_assoc($query);
        }
    }

    /**
     * 执行fetchArray操作。
     *
     * @param mixed $query 参数query。
     * @param mixed $resulttype 参数resulttype。
     * @return mixed 返回结果。
     */
    public function fetchArray($query, $resulttype = MYSQLI_BOTH)
    {
        if ($query) {
            return mysqli_fetch_array($query, $resulttype);
        }
    }

    /**
     * 执行autoId操作。
     *
     * @param mixed $table 参数table。
     * @return mixed 返回结果。
     */
    public function autoId($table)
    {
        $tableName = trim($this->tableName($table), '`');
        $this->query("ANALYZE TABLE " . $this->tableName($table));
        return $this->getOne("SELECT auto_increment FROM information_schema.`TABLES` WHERE TABLE_SCHEMA='" . $this->escapeString($this->dbname) . "' AND TABLE_NAME = '$tableName'");
    }

    /**
     * 执行version操作。
     *
     * @return mixed 返回结果。
     */
    public function version()
    {
        return $version = mysqli_get_server_info($this->dou_link);
    }

    /**
     * 执行close操作。
     *
     * @return mixed 返回结果。
     */
    public function close()
    {
        return mysqli_close($this->dou_link);
    }

    /** @var int 事务嵌套深度 */
    protected $transDepth = 0;

    /** @var bool 是否在事务中 */
    protected $inTransaction = false;

    /**
     * 执行beginTransaction操作（支持嵌套，使用 SAVEPOINT）。
     *
     * @return void
     */
    public function beginTransaction()
    {
        if (!$this->dou_link) {
            $this->error('Database connection not established');
        }
        if ($this->transDepth === 0) {
            mysqli_autocommit($this->dou_link, false);
            $this->inTransaction = true;
        } else {
            $savepoint = 'sp' . $this->transDepth;
            $this->query("SAVEPOINT `$savepoint`");
        }
        $this->transDepth++;
    }

    /**
     * 执行commit操作（嵌套时仅释放 savepoint，最终层才提交）。
     *
     * @return void
     */
    public function commit()
    {
        if (!$this->dou_link) {
            $this->error('Database connection not established');
        }
        if ($this->transDepth <= 0) {
            return;
        }
        $this->transDepth--;
        if ($this->transDepth === 0) {
            // 最外层提交
            $this->query("RELEASE SAVEPOINT sp0"); // 清理全部 savepoint
            if (!mysqli_commit($this->dou_link)) {
                $this->error('Failed to commit transaction: ' . mysqli_error($this->dou_link));
            }
            mysqli_autocommit($this->dou_link, true);
            $this->inTransaction = false;
        }
    }

    /**
     * 执行rollback操作（嵌套时回滚到 savepoint）。
     *
     * @return void
     */
    public function rollback()
    {
        if (!$this->dou_link) {
            $this->error('Database connection not established');
        }
        if ($this->transDepth <= 0) {
            return;
        }
        if ($this->transDepth === 1) {
            // 最外层回滚
            if (!mysqli_rollback($this->dou_link)) {
                $this->error('Failed to rollback transaction: ' . mysqli_error($this->dou_link));
            }
            mysqli_autocommit($this->dou_link, true);
            $this->transDepth = 0;
            $this->inTransaction = false;
        } else {
            // 内层回滚到前一个保存点
            $this->transDepth--;
            $savepoint = 'sp' . $this->transDepth;
            $this->query("ROLLBACK TO SAVEPOINT `$savepoint`");
        }
    }

    /**
     * 执行selectAll操作。
     *
     * @param mixed $table 参数table。
     * @return mixed 返回结果。
     */
    public function selectAll($table)
    {
        return $this->query("SELECT * FROM " . $this->tableName($table));
    }

    /**
     * 执行fieldExist操作。
     *
     * @param mixed $table 参数table。
     * @param mixed $field 参数field。
     * @return bool 返回结果。
     */
    public function fieldExist($table, $field)
    {
        $array = array();
        $sql = "SHOW COLUMNS FROM " . $this->tableName($table);
        $query = $this->query($sql);
        if ($query !== false) {
            while ($row = $this->fetchArray($query)) {
                $array[] = $row['Field'];
            }
            mysqli_free_result($query);

            if (in_array($field, $array)) {
                return true;
            }
        }
    }

    /**
     * 执行hasUniqueIndex操作。
     *
     * @param mixed $table 参数table。
     * @param mixed $field 参数field。
     * @return mixed 返回结果。
     */
    public function hasUniqueIndex($table, $field)
    {
        $table_name = trim($this->tableName($table), '`');
        $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table_name' AND COLUMN_NAME = '" . $this->escapeString($field) . "' AND NON_UNIQUE = 0";

        $count = $this->getOne($sql);

        // 转换并判断
        return intval($count) > 0;
    }

    /**
     * 执行valueExist操作。
     *
     * @param mixed $table 参数table。
     * @param mixed $field 参数field。
     * @param mixed $value 参数value。
     * @param string $and 参数and。
     * @return bool 返回结果。
     */
    public function valueExist($table, $field, $value, $and = '')
    {
        $and = $and ? ' AND ' . $and : '';
        $sql = "SELECT * FROM " . $this->tableName($table) . " WHERE $field = '" . $this->escapeString($value) . "'" . $and;
        $number = $this->numRows($this->query($sql));

        if ($number > 0) {
            return true;
        }
    }

    /**
     * 执行getRow操作。
     *
     * @param mixed $table 参数table。
     * @param mixed $field 参数field。
     * @param string $where 参数where。
     * @return mixed 返回结果。
     */
    public function getRow($table, $field, $where = '')
    {
        $field = $this->isSafeFieldName($field) ? $field : '*';
        $sql = "SELECT $field FROM " . $this->tableName($table) . " WHERE " . $where;
        $query = $this->query($sql);
        if ($query !== false) {
            $result = $this->fetchAssoc($query);
            mysqli_free_result($query);
            return $result;
        } else {
            return false;
        }
    }

    // 验证是否有符合条件的记录
    /**
     * 执行rowExist操作。
     *
     * @param mixed $table 参数table。
     * @param string $where 参数where。
     * @return bool 返回结果。
     */
    public function rowExist($table, $where = '')
    {
        $where = $where ? " WHERE $where" : '';
        $sql = "SELECT * FROM " . $this->tableName($table) . $where;
        $number = $this->numRows($this->query($sql));

        if ($number > 0) {
            return true;
        }
        return false;
    }

    // 统计数量
    /**
     * 执行rowNumber操作。
     *
     * @param mixed $table 参数table。
     * @param string $where 参数where。
     * @return mixed 返回结果。
     */
    public function rowNumber($table, $where = '')
    {
        $where = $where ? " WHERE $where" : '';
        $result = $this->query("SELECT COUNT(*) FROM " . $this->tableName($table) . $where);
        if ($result === false) {
            return 0;
        }
        $row = $this->fetchRow($result);
        mysqli_free_result($result);
        $number = $row[0];

        return $number;
    }

    // 获取一条数据的一个值 ①
    /**
     * 执行getValue操作。
     *
     * @param mixed $table 参数table。
     * @param mixed $field 参数field。
     * @param string $where 参数where。
     * @return mixed 返回结果。
     */
    public function getValue($table, $field, $where = '')
    {
        $field = $this->isSafeFieldName($field) ? $field : '*';
        $value = $this->getOne("SELECT $field FROM " . $this->tableName($table) . " WHERE $where");
        if ($value !== false) {
            return $value;
        } else {
            return false;
        }
    }

    // 读取一条数据的一个值 ②
    /**
     * 执行getOne操作。
     *
     * @param mixed $sql 参数sql。
     * @param bool $limited 参数limited。
     * @return mixed 返回结果。
     */
    public function getOne($sql, $limited = false)
    {
        if ($limited == true) {
            $sql = trim($sql . ' LIMIT 1');
        }

        $query = $this->query($sql);
        if ($query !== false) {
            $row = $this->fetchRow($query);

            if ($row !== false && $row !== null && is_array($row)) {
                return $row[0];
            } else {
                return '';
            }
        } else {
            return false;
        }
    }

    // 转义特殊字符
    /**
     * 执行escapeString操作。
     *
     * @param mixed $string 参数string。
     * @return mixed 返回结果。
     */
    public function escapeString($string)
    {
        if ($string === null) {
            $string = '';
        }

        return mysqli_real_escape_string($this->dou_link, $string);
    }

    // 返回错误信息
    /**
     * 执行error操作。
     *
     * @param string $msg 参数msg。
     * @return void 返回结果。
     */
    public function error($msg = '')
    {
        $detail = $msg ? "DouPHP Error: $msg" : 'MySQL server error report: ' . $this->error_msg;
        // 非 debug 不回显库名/SQL/连接细节，仅给通用提示，避免信息泄漏；debug 下回显完整细节便于排查。
        if (SiteDebugExceptionRenderer::isSiteDebugEnabled()) {
            exit($detail);
        }
        exit('Database error.');
    }

    // 循环读取结果集并储存至数组
    /**
     * 执行fnQuery操作。
     *
     * @param mixed $sql 参数sql。
     * @return mixed 返回结果。
     */
    public function fnQuery($sql)
    {
        $data = array();
        $query = $this->query($sql);
        if ($query !== false) {
            while ($row = $this->fetchAssoc($query)) {
                $data[] = $row;
            }
            mysqli_free_result($query);
        }
        return $data;
    }

    // 数据库导入
    /**
     * 执行fnExecute操作。
     *
     * @param mixed $sql 参数sql。
     * @return bool 返回结果。
     */
    public function fnExecute($sql)
    {
        // 禁用外键约束
        $this->query("SET FOREIGN_KEY_CHECKS = 0");

        $sqls = $this->fnSplit($sql);
        if (is_array($sqls)) {
            foreach ((array) $sqls as $sqlItem) {
                if (trim($sqlItem) != '') {
                    $this->query($sqlItem);
                }
            }
        } else {
            $this->query($sqls);
        }

        // 重新启用外键约束
        $this->query("SET FOREIGN_KEY_CHECKS = 1");

        return true;
    }

    // 数据分离（处理SQL导入文件）
    /**
     * 执行fnSplit操作。
     *
     * @param mixed $sql 参数sql。
     * @return mixed 返回结果。
     */
    public function fnSplit($sql)
    {
        // 统一将表定义中的字符集替换为 utf8mb4
        // 支持 TYPE/ENGINE 和多种编码格式
        $sql = preg_replace(
            '/(TYPE|ENGINE)\s*=\s*(InnoDB|MyISAM)(\s+DEFAULT\s+(CHARSET|CHARACTER\s+SET)\s*=\s*[a-zA-Z0-9_]+)?(\s+COLLATE\s*=?\s*[a-zA-Z0-9_]+)?/i',
            '$1=$2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $sql
        );

        $sql = str_replace("\r", "\n", $sql);
        $ret = [];
        $num = 0;
        $queriesarray = explode(";\n", trim($sql));
        unset($sql);
        foreach ($queriesarray as $query) {
            $ret[$num] = '';
            $queries = explode("\n", trim($query));
            $queries = array_filter($queries);
            // 按换行拼接，保留 token 边界，避免多行 INSERT...SELECT 的最后一个值与下一行 FROM 直接粘连成非法 token
            foreach ((array) $queries as $queryLine) {
                $str1 = substr($queryLine, 0, 1);
                if ($str1 != '#' && $str1 != '-') {
                    if ($ret[$num] !== '') {
                        $ret[$num] .= "\n";
                    }
                    $ret[$num] .= $queryLine;
                }
            }
            $num++;
        }
        return ($ret);
    }

    /**
     * 执行reset操作。
     *
     * @return mixed 返回结果。
     */
    private function reset()
    {
        $this->current_table = '';
        $this->where = [];
        $this->fields = '*';
        $this->order_by = '';
        $this->limit = '';
        $this->offset_value = null;
        $this->data = [];
        $this->params = [];
        $this->types = '';
        $this->joins = [];
        $this->group_by = '';
        $this->having = [];
        $this->distinct = false;
        $this->fetch_sql = false;
        $this->unions = [];
        $this->lock = '';
        return $this;
    }

    /**
     * 执行table操作。
     *
     * @param mixed $table 参数table。
     * @return mixed 返回结果。
     */
    public function table($table)
    {
        // 使用 clone 克隆当前实例（避免调用构造函数）
        $new_instance = clone $this;

        // 记录父实例引用，便于执行后回写 SQL
        $new_instance->parent_instance = $this;

        // 调用私有的 reset() 方法重置链式状态
        $new_instance->reset();

        // 设置表名
        $table = trim($table);
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*)$/i', $table, $matches)) {
            $new_instance->current_table = '`' . $this->prefix . $matches[1] . '` AS `' . $matches[2] . '`';
        } else {
            $new_instance->current_table = '`' . $this->prefix . $table . '`';
        }

        return $new_instance;
    }

    /**
     * 执行join操作。
     *
     * @param mixed $table 参数table。
     * @param mixed $condition 参数condition。
     * @return mixed 返回结果。
     */
    public function join($table, $condition)
    {
        return $this->addJoin('LEFT JOIN', $table, $condition);
    }

    /**
     * 执行innerJoin操作。
     *
     * @param mixed $table 参数table。
     * @param mixed $condition 参数condition。
     * @return mixed 返回结果。
     */
    public function innerJoin($table, $condition)
    {
        return $this->addJoin('INNER JOIN', $table, $condition);
    }

    /**
     * 执行rightJoin操作。
     *
     * @param mixed $table 参数table。
     * @param mixed $condition 参数condition。
     * @return mixed 返回结果。
     */
    public function rightJoin($table, $condition)
    {
        return $this->addJoin('RIGHT JOIN', $table, $condition);
    }

    /**
     * 执行addJoin操作。
     *
     * @param mixed $type 参数type。
     * @param mixed $table 参数table。
     * @param mixed $condition 参数condition。
     * @return mixed 返回结果。
     */
    private function addJoin($type, $table, $condition)
    {
        // 过滤条件中的字段名，防止 SQL 注入
        // 只允许 table.field = table.field 格式
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*\s*=\s*[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/', trim($condition))) {
            return $this; // 条件格式不合法，忽略
        }

        $table = trim($table);

        // 检查是否有别名
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*)$/i', $table, $matches)) {
            $table_sql = '`' . $this->prefix . $matches[1] . '` AS `' . $matches[2] . '`';
        } else {
            $table_sql = '`' . $this->prefix . $table . '`';
        }

        $this->joins[] = [
            'type' => $type,
            'table' => $table_sql,
            'condition' => $condition
        ];
        return $this;
    }

    /**
     * 执行buildJoin操作。
     *
     * @return mixed 返回结果。
     */
    private function buildJoin()
    {
        if (empty($this->joins)) {
            return '';
        }

        $sql = '';
        foreach ($this->joins as $join) {
            $sql .= ' ' . $join['type'] . ' ' . $join['table'] . ' ON ' . $join['condition'];
        }
        return $sql;
    }

    /**
     * 执行field操作。
     *
     * @param mixed $field 参数field。
     * @return mixed 返回结果。
     */
    public function field($field)
    {
        if (is_array($field)) {
            // 数组形式：过滤每个字段名
            $safe_fields = [];
            foreach ($field as $f) {
                $safe_fields[] = $this->filterField($f);
            }
            $this->fields = implode(', ', $safe_fields);
        } else {
            // 字符串形式：检查是否包含逗号（多字段）
            if (strpos($field, ',') !== false) {
                // 包含逗号，拆分并过滤每个字段
                $field_parts = explode(',', $field);
                $safe_fields = [];
                foreach ($field_parts as $f) {
                    $safe_fields[] = $this->filterField($f);
                }
                $this->fields = implode(', ', $safe_fields);
            } else {
                // 单个字段
                $this->fields = $this->filterField($field);
            }
        }
        return $this;
    }

    /**
     * 执行order操作。
     *
     * @param mixed $order 参数order。
     * @return mixed 返回结果。
     */
    public function order($order)
    {
        // 过滤排序字符串，防止SQL注入
        if (empty($order)) {
            $this->order_by = '';
            return $this;
        }

        // 移除首尾空格
        $order = trim($order);

        // 拆分多个排序条件（用逗号分隔）
        $order_parts = explode(',', $order);
        $safe_orders = [];

        foreach ($order_parts as $part) {
            $part = trim($part);

            // 受控函数排序白名单：仅放行无参、无用户输入的安全表达式（当前仅 RAND() 随机排序）。
            // 否则像 'RAND()' 这类函数片段不匹配下方字段正则会被静默丢弃，导致“随机列表”实际不随机。
            if (preg_match('/^rand\(\)$/i', $part)) {
                $safe_orders[] = 'RAND()';
                continue;
            }

            // 调用方常传入 MySQL 反引号包裹的标识符（如 `group` DESC、`is_default` DESC）。
            // 先剥除反引号再校验标识符，通过后再统一重新包裹，避免保留字/习惯写法被静默丢弃。
            $normalized = str_replace('`', '', $part);

            // 匹配 "字段名 ASC/DESC" 或 "字段名"
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_\.]*)\s*(asc|desc)?$/i', $normalized, $matches)) {
                $field = $matches[1];
                $direction = isset($matches[2]) ? strtoupper($matches[2]) : 'ASC';

                // 处理 table.field 格式
                if (strpos($field, '.') !== false) {
                    $parts = explode('.', $field);
                    $safe_orders[] = '`' . $parts[0] . '`.`' . $parts[1] . '` ' . $direction;
                } else {
                    $safe_orders[] = '`' . $field . '` ' . $direction;
                }
            } elseif ($part !== '') {
                // 既非安全字段也不在受控白名单：丢弃并 debug 记录，避免函数表达式等被静默丢失难以排查。
                // 非 debug 下 minLevel 为 WARNING，本条不落盘（生产无噪声）；debug 下可见。
                Log::debug('order() 丢弃不安全排序片段', array('channel' => 'system', 'segment' => $part));
            }
        }

        // 如果没有合法的排序条件，返回空字符串
        $this->order_by = !empty($safe_orders) ? implode(', ', $safe_orders) : '';
        return $this;
    }

    /**
     * 执行filterField操作。
     *
     * @param mixed $field 参数field。
     * @return mixed 返回结果。
     */
    private function filterField($field)
    {
        // 移除首尾空格
        $field = trim($field);

        // 如果是 * 或包含函数调用(如 COUNT(*))，直接返回
        if ($field === '*' || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*\(.*\)(\s+as\s+\w+)?$/i', $field)) {
            return $field;
        }

        // 处理字面量 AS 别名（如 "'product' AS module"、"0 AS price"、"NULL AS price"）
        if (preg_match('/^(\'[^\']*\'|"[^"]*"|-?\d+(?:\.\d+)?|NULL)\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*)$/i', $field, $matches)) {
            return $matches[1] . ' AS `' . $matches[2] . '`';
        }

        // 处理 table.* 格式（如 p.*）
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\\.\*$/', $field, $matches)) {
            return '`' . $matches[1] . '`.*';
        }

        // 处理 "field AS alias" 或 "table.field AS alias" 格式
        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $field, $matches)) {
            $field_name = trim($matches[1]);
            $alias = trim($matches[2]);
            // 验证别名
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
                // 支持 table.field 格式的字段名
                if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\.([a-zA-Z_][a-zA-Z0-9_]*)$/', $field_name, $fp)) {
                    return '`' . $fp[1] . '`.`' . $fp[2] . '` AS `' . $alias . '`';
                } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field_name)) {
                    return '`' . $field_name . '` AS `' . $alias . '`';
                }
            }
        }

        // 只允许字母、数字、下划线、点号（用于表名.字段名）
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_\.]*$/', $field)) {
            // 处理 table.field 格式
            if (strpos($field, '.') !== false) {
                $parts = explode('.', $field);
                return '`' . $parts[0] . '`.`' . $parts[1] . '`';
            }
            return '`' . $field . '`';
        }

        // 不符合规则的字段名，返回 *（安全默认值）
        return '*';
    }

    /**
     * 执行limit操作。
     *
     * @param mixed $offset 参数offset。
     * @param mixed|null $length 参数length。
     * @return mixed 返回结果。
     */
    public function limit($offset, $length = null)
    {
        if ($length === null) {
            $this->limit = intval($offset);
        } else {
            $this->limit = intval($offset) . ', ' . intval($length);
        }
        return $this;
    }

    /**
     * 执行offset操作。
     *
     * @param mixed $offset 参数offset。
     * @return mixed 返回结果。
     */
    public function offset($offset)
    {
        $this->offset_value = intval($offset);
        return $this;
    }

    /**
     * 执行data操作。
     *
     * @param mixed $data 参数data。
     * @return mixed 返回结果。
     */
    public function data($data)
    {
        $this->data = $data;
        return $this;
    }

    /**
     * 执行where操作。
     *
     * @param mixed $field 参数field。
     * @param mixed|null $op 参数op。
     * @param mixed|null $value 参数value。
     * @return mixed 返回结果。
     */
    public function where($field, $op = null, $value = null)
    {
        $this->parseWhere($field, $op, $value, 'AND');
        return $this;
    }

    /**
     * 执行whereOr操作。
     *
     * @param mixed $field 参数field。
     * @param mixed|null $op 参数op。
     * @param mixed|null $value 参数value。
     * @return mixed 返回结果。
     */
    public function whereOr($field, $op = null, $value = null)
    {
        $this->parseWhere($field, $op, $value, 'OR');
        return $this;
    }

    /**
     * 执行whereIn操作。
     *
     * @param mixed $field 参数field。
     * @param mixed $values 参数values。
     * @return mixed 返回结果。
     */
    public function whereIn($field, $values)
    {
        return $this->where($field, 'IN', $values);
    }

    /**
     * 执行whereNotIn操作。
     *
     * @param mixed $field 参数field。
     * @param mixed $values 参数values。
     * @return mixed 返回结果。
     */
    public function whereNotIn($field, $values)
    {
        return $this->where($field, 'NOT IN', $values);
    }

    /**
     * 执行whereOrIn操作。
     *
     * @param mixed $field 参数field。
     * @param mixed $values 参数values。
     * @return mixed 返回结果。
     */
    public function whereOrIn($field, $values)
    {
        return $this->whereOr($field, 'IN', $values);
    }

    /**
     * 执行whereOrNotIn操作。
     *
     * @param mixed $field 参数field。
     * @param mixed $values 参数values。
     * @return mixed 返回结果。
     */
    public function whereOrNotIn($field, $values)
    {
        return $this->whereOr($field, 'NOT IN', $values);
    }

    /**
     * 执行whereRaw操作。
     *
     * @param mixed $raw 参数raw。
     * @param array $binds 参数binds。
     * @return mixed 返回结果。
     */
    public function whereRaw($raw, $binds = [])
    {
        return $this->parseWhereRaw($raw, $binds, 'AND');
    }

    /**
     * 执行whereOrRaw操作。
     *
     * @param mixed $raw 参数raw。
     * @param array $binds 参数binds。
     * @return mixed 返回结果。
     */
    public function whereOrRaw($raw, $binds = [])
    {
        return $this->parseWhereRaw($raw, $binds, 'OR');
    }

    /**
     * 执行parseWhereRaw操作。
     *
     * @param mixed $raw 参数raw。
     * @param mixed $binds 参数binds。
     * @param mixed $logic 参数logic。
     * @return mixed 返回结果。
     */
    private function parseWhereRaw($raw, $binds, $logic)
    {
        if (empty($raw)) {
            return $this;
        }

        $this->where[] = [
            'type' => 'raw',
            'logic' => $logic,
            'raw' => $raw
        ];

        // 绑定参数
        if (!empty($binds)) {
            foreach ($binds as $bind) {
                $this->params[] = $bind;
                $this->types .= $this->getParamType($bind);
            }
        }

        return $this;
    }

    /**
     * 执行parseWhere操作。
     *
     * @param mixed $field 参数field。
     * @param mixed $op 参数op。
     * @param mixed $value 参数value。
     * @param mixed $logic 参数logic。
     * @return void 返回结果。
     */
    private function parseWhere($field, $op, $value, $logic)
    {
        // 闭包查询支持
        if ($field instanceof \Closure) {
            $query = new QueryBuilder($this);
            $field($query);
            $sub_where = $query->getWhere();
            $sub_bind = $query->getBind();
            $sub_types = $query->getBindTypes();

            if (!empty($sub_where)) {
                $this->where[] = [
                    'type' => 'group',
                    'logic' => $logic,
                    'conditions' => $sub_where
                ];
                $this->params = array_merge($this->params, $sub_bind);
                $this->types .= $sub_types;
            }
            return;
        }

        // 普通条件
        if ($value === null && $op !== null) {
            $op_upper = strtoupper($op);
            // IS NULL / IS NOT NULL 是合法的三参数调用，保留 op
            if ($op_upper === 'IS NULL' || $op_upper === 'IS NOT NULL') {
                $op = $op_upper;
            } elseif ($op_upper === 'IS' || $op_upper === 'IS NOT') {
                // 处理 ->where('field', 'IS NOT', null) 和 ->where('field', 'IS', null) 形式
                $op = $op_upper . ' NULL';
            } elseif (in_array($op_upper, ['NOT IN', 'IN', 'NOT LIKE', 'LIKE'])) {
                // 三参数调用，value 不是有效数组（含空数组）时：
                //   IN 空集合 → 恒假 1=0（匹配空集，避免退化为无过滤的全表返回）；
                //   NOT IN 空集合 → 恒真 1=1（匹配全集，语义正确）；
                //   LIKE / NOT LIKE 的非数组值无意义，按既有语义忽略。
                if (!is_array($value) || empty($value)) {
                    if ($op_upper === 'IN') {
                        $this->where[] = ['type' => 'raw', 'logic' => $logic, 'raw' => '1 = 0'];
                    } elseif ($op_upper === 'NOT IN') {
                        $this->where[] = ['type' => 'raw', 'logic' => $logic, 'raw' => '1 = 1'];
                    }
                    return;
                }
                $op = $op_upper;
            } elseif (in_array($op_upper, ['>', '<', '>=', '<=', '<>', '!=', '='])) {
                // 三参数调用且 value 为 null：归一相等/不等比较，避免 `col = NULL` 恒为未知（假）漏掉 NULL 行。
                //   = → IS NULL；!= / <> → IS NOT NULL。
                //   其余比较运算符（>,<,>=,<=）与 NULL 比较本就恒为未知，保持原样（按 SQL 语义）。
                if ($op_upper === '=') {
                    $op = 'IS NULL';
                } elseif ($op_upper === '!=' || $op_upper === '<>') {
                    $op = 'IS NOT NULL';
                }
            } else {
                // 两个参数: where('field', 'value') - 第二个参数是值
                $value = $op;
                $op = '=';
            }
        } elseif ($value === null && $op === null) {
            // where('field') 形式，对应 IS NULL
            $op = 'IS NULL';
        }

        $op = strtoupper($op);

        // IN/NOT IN 查询：空数组退化处理。
        //   IN 空集合 → 恒假 1=0（匹配空集）；NOT IN 空集合 → 恒真 1=1（匹配全集）。
        //   空 IN 若丢弃条件会让 `whereIn('id', [])` 退化成无过滤全表返回，
        //   在权限 / 可见范围 / 购物车归属等场景下造成越权读取。
        if (($op === 'IN' || $op === 'NOT IN') && is_array($value) && empty($value)) {
            if ($op === 'IN') {
                $this->where[] = ['type' => 'raw', 'logic' => $logic, 'raw' => '1 = 0'];
            } else {
                $this->where[] = ['type' => 'raw', 'logic' => $logic, 'raw' => '1 = 1'];
            }
            return;
        }

        $this->where[] = [
            'type' => 'condition',
            'logic' => $logic,
            'field' => $field,
            'op' => $op,
            'value' => $value
        ];

        // 添加绑定参数
        if ($op === 'IN' || $op === 'NOT IN') {
            // IN 查询：将数组中的每个值都添加到参数列表
            if (is_array($value)) {
                foreach ($value as $item) {
                    $this->params[] = $item;
                    $this->types .= $this->getParamType($item);
                }
            }
        } elseif ($op === 'IS NULL' || $op === 'IS NOT NULL') {
            // IS NULL / IS NOT NULL 不需要绑定参数
        } else {
            // 普通查询：添加单个值
            $this->params[] = $value;
            $this->types .= $this->getParamType($value);
        }
    }

    /**
     * 执行getParamType操作。
     *
     * @param mixed $value 参数value。
     * @return string 返回结果。
     */
    private function getParamType($value)
    {
        if (is_int($value)) {
            return 'i';
        } elseif (is_float($value)) {
            return 'd';
        } else {
            // null 也用 's' 类型，mysqli_stmt_bind_param 会自动处理 NULL 值
            return 's';
        }
    }

    /**
     * 执行buildWhere操作。
     *
     * @return mixed 返回结果。
     */
    private function buildWhere()
    {
        if (empty($this->where)) {
            return '';
        }

        $sql = ' WHERE ';
        $first = true;

        foreach ($this->where as $condition) {
            if (!$first) {
                $sql .= ' ' . $condition['logic'] . ' ';
            }
            $first = false;

            if ($condition['type'] === 'group') {
                $sql .= '(' . $this->buildGroupWhere($condition['conditions']) . ')';
            } elseif ($condition['type'] === 'raw') {
                $sql .= $condition['raw'];
            } else {
                $op = $condition['op'];
                // 安全过滤字段名
                $field = $this->safeFieldName($condition['field']);

                if ($op === 'LIKE') {
                    $sql .= $field . ' LIKE ?';
                } elseif ($op === 'IN' || $op === 'NOT IN') {
                    $count = is_array($condition['value']) ? count($condition['value']) : 1;
                    $placeholders = implode(', ', array_fill(0, $count, '?'));
                    $sql .= $field . ' ' . $op . ' (' . $placeholders . ')';
                } elseif ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                    $sql .= $field . ' ' . $op;
                } else {
                    $sql .= $field . ' ' . $op . ' ?';
                }
            }
        }

        return $sql;
    }

    /**
     * 执行buildGroupWhere操作。
     *
     * @param mixed $conditions 参数conditions。
     * @return mixed 返回结果。
     */
    private function buildGroupWhere($conditions)
    {
        $sql = '';
        $first = true;

        foreach ($conditions as $condition) {
            if (!$first) {
                $sql .= ' ' . $condition['logic'] . ' ';
            }
            $first = false;

            if ($condition['type'] === 'group') {
                $sql .= '(' . $this->buildGroupWhere($condition['conditions']) . ')';
            } else {
                $op = $condition['op'];
                // 安全过滤字段名
                $field = $this->safeFieldName($condition['field']);

                if ($op === 'LIKE') {
                    $sql .= $field . ' LIKE ?';
                } elseif ($op === 'IN' || $op === 'NOT IN') {
                    $count = is_array($condition['value']) ? count($condition['value']) : 1;
                    $placeholders = implode(', ', array_fill(0, $count, '?'));
                    $sql .= $field . ' ' . $op . ' (' . $placeholders . ')';
                } elseif ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                    $sql .= $field . ' ' . $op;
                } else {
                    $sql .= $field . ' ' . $op . ' ?';
                }
            }
        }

        return $sql;
    }

    /**
     * 执行safeFieldName操作。
     *
     * @param mixed $field 参数field。
     * @return string 返回结果。
     */
    private function safeFieldName($field)
    {
        // 只允许字母、数字、下划线、点号
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_\.]*$/', $field)) {
            // 处理 table.field 格式
            if (strpos($field, '.') !== false) {
                $parts = explode('.', $field);
                return '`' . $parts[0] . '`.`' . $parts[1] . '`';
            }
            return '`' . $field . '`';
        }

        // 不合法的字段名，返回安全默认值
        return '`id`';
    }

    /**
     * 执行stmtExecute操作。
     *
     * @param mixed $sql 参数sql。
     * @param array $params 参数params。
     * @param string $types 参数types。
     * @return mixed 返回结果。
     */
    public function stmtExecute($sql, $params = [], $types = '')
    {
        $this->sql = $sql;
        $this->last_sql = $sql;
        $this->last_params = $params;

        if ($this->debug_mode) {
            $this->debugLog('Prepared SQL', $sql);
            $this->debugLog('Bind Params', json_encode($params, JSON_UNESCAPED_UNICODE));
            $this->debugLog('Bind Types', $types);
        }

        $stmt = mysqli_prepare($this->dou_link, $sql);

        if (!$stmt) {
            if ($this->debug_mode) {
                $this->debugLog('Prepare Error', mysqli_error($this->dou_link));
            }
            return false;
        }

        if (!empty($params)) {
            // 使用参数展开运算符（PHP 5.6+）
            if (!mysqli_stmt_bind_param($stmt, $types, ...$params)) {
                if ($this->debug_mode) {
                    $this->debugLog('Bind Error', mysqli_stmt_error($stmt));
                }
                mysqli_stmt_close($stmt);
                return false;
            }
        }

        if (!mysqli_stmt_execute($stmt)) {
            if ($this->debug_mode) {
                $this->debugLog('Execute Error', mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
            return false;
        }

        return $stmt;
    }

    /**
     * 执行syncSqlToParent操作。
     *
     * @return void 返回结果。
     */
    private function syncSqlToParent()
    {
        if ($this->parent_instance !== null) {
            $this->parent_instance->last_sql = $this->last_sql;
            $this->parent_instance->last_params = $this->last_params;
            $this->parent_instance->last_build_sql = $this->last_build_sql;
        }
    }

    /**
     * 执行insert操作。
     *
     * @param mixed|null $data 参数data。
     * @return mixed 返回结果。
     */
    public function insert($data = null)
    {
        if ($data !== null) {
            $this->data = $data;
        }

        if (empty($this->current_table) || empty($this->data)) {
            return false;
        }

        $fields = [];
        $placeholders = [];
        $params = [];
        $types = '';

        foreach ($this->data as $field => $value) {
            $fields[] = '`' . $field . '`';
            $placeholders[] = '?';
            $params[] = $value;
            $types .= $this->getParamType($value);
        }

        $sql = 'INSERT INTO ' . $this->current_table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';

        // 记录SQL用于调试
        $this->last_sql = $sql;
        $this->last_params = $params;

        // 保存构建的SQL
        $this->last_build_sql = $sql;

        // 如果是只获取SQL，不执行
        if ($this->fetch_sql) {
            $result = $sql;
            $this->reset();
            return $result;
        }

        $stmt = $this->stmtExecute($sql, $params, $types);

        if ($stmt !== false) {
            $insertId = mysqli_insert_id($this->dou_link);
            mysqli_stmt_close($stmt);
            $this->syncSqlToParent();
            $this->reset();
            return $insertId;
        }

        $this->syncSqlToParent();
        $this->reset();
        return false;
    }

    /**
     * 执行setField操作。
     *
     * @param mixed $field 参数field。
     * @param mixed $value 参数value。
     * @param bool $raw 参数raw。
     * @param array $binds 原生表达式（$raw=true）中 `?` 占位符的绑定值（按出现顺序），
     *                     用于 `SET col = replace(col, ?, ?)` 之类需参数化的原生 SET 表达式；
     *                     非 raw 或无占位符时传空数组即可（inc/dec/exp 等内部调用沿用默认空）。
     * @return mixed 返回结果。
     */
    public function setField($field, $value, $raw = false, $binds = array())
    {
        $params = array();
        $types = '';
        if (empty($this->current_table)) {
            return false;
        }

        // 安全过滤字段名
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            return false;
        }

        // 原生 SET 表达式中的占位符绑定（位于 WHERE 绑定之前，与 SQL 中出现顺序一致）
        $rawParams = array();
        $rawTypes = '';
        if ($raw && is_array($binds) && !empty($binds)) {
            foreach ($binds as $bind) {
                $rawParams[] = $bind;
                $rawTypes .= $this->getParamType($bind);
            }
        }

        if ($raw) {
            // 原生表达式，直接使用（占位符值经 $binds 参数化绑定）
            $sql = 'UPDATE ' . $this->current_table . ' SET `' . $field . '` = ' . $value;
            $sql .= $this->buildWhere();

            // 记录SQL用于调试
            $this->last_sql = $sql;
            $this->last_params = array_merge($rawParams, $this->params);

            // 保存构建的SQL
            $this->last_build_sql = $sql;
        } else {
            // 普通值，使用参数绑定
            $sql = 'UPDATE ' . $this->current_table . ' SET `' . $field . '` = ?';
            $sql .= $this->buildWhere();

            // 合并参数
            $params = array_merge([$value], $this->params);
            $types = $this->getParamType($value) . $this->types;

            // 记录SQL用于调试
            $this->last_sql = $sql;
            $this->last_params = $params;

            // 保存构建的SQL
            $this->last_build_sql = $sql;
        }

        // 如果是只获取SQL，不执行
        if ($this->fetch_sql) {
            $result = $sql;
            $this->reset();
            return $result;
        }

        // 根据模式执行不同的SQL
        if ($raw) {
            $stmt = $this->stmtExecute($sql, array_merge($rawParams, $this->params), $rawTypes . $this->types);
        } else {
            $stmt = $this->stmtExecute($sql, $params, $types);
        }

        if ($stmt !== false) {
            $affectedRows = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            $this->syncSqlToParent();
            $this->reset();
            return $affectedRows;
        }

        $this->syncSqlToParent();
        $this->reset();
        return false;
    }

    /**
     * 执行update操作。
     *
     * @param mixed|null $data 参数data。
     * @return mixed 返回结果。
     */
    public function update($data = null)
    {
        if ($data !== null) {
            $this->data = $data;
        }

        if (empty($this->current_table) || empty($this->data)) {
            return false;
        }

        $sets = [];
        $params = [];
        $types = '';

        foreach ($this->data as $field => $value) {
            $sets[] = '`' . $field . '` = ?';
            $params[] = $value;
            $types .= $this->getParamType($value);
        }

        $sql = 'UPDATE ' . $this->current_table . ' SET ' . implode(', ', $sets);
        $sql .= $this->buildWhere();

        // 合并WHERE绑定参数
        $params = array_merge($params, $this->params);
        $types .= $this->types;

        // 记录SQL用于调试
        $this->last_sql = $sql;
        $this->last_params = $params;

        // 保存构建的SQL
        $this->last_build_sql = $sql;

        // 如果是只获取SQL，不执行
        if ($this->fetch_sql) {
            $result = $sql;
            $this->reset();
            return $result;
        }

        $stmt = $this->stmtExecute($sql, $params, $types);

        if ($stmt !== false) {
            $affectedRows = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            $this->syncSqlToParent();
            $this->reset();
            return $affectedRows;
        }

        $this->syncSqlToParent();
        $this->reset();
        return false;
    }

    /**
     * 执行delete操作。
     *
     * @return mixed 返回结果。
     */
    public function delete()
    {
        if (empty($this->current_table)) {
            return false;
        }

        $sql = 'DELETE FROM ' . $this->current_table;
        $sql .= $this->buildWhere();

        // 记录SQL用于调试
        $this->last_sql = $sql;
        $this->last_params = $this->params;

        // 保存构建的SQL
        $this->last_build_sql = $sql;

        // 如果是只获取SQL，不执行
        if ($this->fetch_sql) {
            $result = $sql;
            $this->reset();
            return $result;
        }

        $stmt = $this->stmtExecute($sql, $this->params, $this->types);

        if ($stmt !== false) {
            $affectedRows = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            $this->syncSqlToParent();
            $this->reset();
            return $affectedRows;
        }

        $this->syncSqlToParent();
        $this->reset();
        return false;
    }

    /**
     * 执行buildLimit操作。
     *
     * @return mixed 返回结果。
     */
    private function buildLimit()
    {
        $limit_sql = '';

        if ($this->offset_value !== null && $this->limit) {
            // 如果同时设置了offset和limit
            if (strpos($this->limit, ',') !== false) {
                // 如果limit已经是 "offset, length" 格式，直接使用
                $limit_sql = ' LIMIT ' . $this->limit;
            } else {
                // 否则组合成 "offset, limit" 格式
                $limit_sql = ' LIMIT ' . $this->offset_value . ', ' . $this->limit;
            }
        } elseif ($this->offset_value !== null) {
            // 如果只设置了offset
            $limit_sql = ' LIMIT ' . $this->offset_value . ', 18446744073709551615';
        } elseif ($this->limit) {
            // 如果只设置了limit
            $limit_sql = ' LIMIT ' . $this->limit;
        }

        return $limit_sql;
    }

    /**
     * 执行select操作。
     *
     * @return mixed 返回结果。
     */
    public function select()
    {
        if (empty($this->current_table)) {
            return false;
        }

        $payload = $this->buildSelectPayload();
        if (!is_array($payload)) {
            return false;
        }
        $sql = $payload['sql'];
        $bindParams = $payload['params'];
        $bindTypes = $payload['types'];

        // 保存构建的SQL
        $this->last_build_sql = $sql;

        // 如果是只获取SQL，不执行
        if ($this->fetch_sql) {
            $result = $sql;
            $this->reset();
            return $result;
        }

        // 记录最后执行的SQL和参数
        $this->last_sql = $sql;
        $this->last_params = $bindParams;

        $stmt = $this->stmtExecute($sql, $bindParams, $bindTypes);

        if ($stmt !== false) {
            $data = [];

            // 判断是否支持 mysqli_stmt_get_result
            if (function_exists('mysqli_stmt_get_result')) {
                // 方法1：使用 get_result（需要mysqlnd）
                $result = mysqli_stmt_get_result($stmt);
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $data[] = $row;
                    }
                    mysqli_free_result($result);
                }
            } else {
                die('mysqli_stmt_get_result function not found! Please install mysqlnd driver.');
            }

            mysqli_stmt_close($stmt);
            $this->syncSqlToParent();
            $this->reset();
            return $data;
        }

        $this->syncSqlToParent();
        $this->reset();
        return false;
    }

    /**
     * 执行buildSelectPayload操作。
     *
     * @return array 返回结果。
     */
    private function buildSelectPayload()
    {
        // 处理DISTINCT
        $fields = $this->distinct ? 'DISTINCT ' . $this->fields : $this->fields;

        // 构建基础查询SQL
        $baseSql = 'SELECT ' . $fields . ' FROM ' . $this->current_table;
        $baseSql .= $this->buildJoin();
        $baseSql .= $this->buildWhere();

        // 分组
        if ($this->group_by) {
            $baseSql .= ' GROUP BY ' . $this->group_by;

            // HAVING条件
            if (!empty($this->having)) {
                $baseSql .= $this->buildHaving();
            }
        }

        $sql = $baseSql;
        $bindParams = $this->params;
        $bindTypes = $this->types;

        // UNION 查询
        if (!empty($this->unions)) {
            $sql = '(' . $baseSql . ')';

            foreach ($this->unions as $union) {
                $unionSql = isset($union['query']) ? $union['query'] : '';
                if ($unionSql === '') {
                    continue;
                }

                $sql .= ' UNION ' . (!empty($union['all']) ? 'ALL ' : '') . '(' . $unionSql . ')';

                if (!empty($union['params']) && is_array($union['params'])) {
                    $bindParams = array_merge($bindParams, $union['params']);
                }
                if (!empty($union['types']) && is_string($union['types'])) {
                    $bindTypes .= $union['types'];
                }
            }
        }

        // 排序（放在UNION之后）
        if ($this->order_by) {
            $sql .= ' ORDER BY ' . $this->order_by;
        }

        // 限制条数（放在UNION之后）
        $sql .= $this->buildLimit();

        // 锁机制（放在UNION之后）
        if ($this->lock) {
            $sql .= ' ' . $this->lock;
        }

        return array(
            'sql' => $sql,
            'params' => $bindParams,
            'types' => $bindTypes,
        );
    }

    /**
     * 执行toSqlBindings操作。
     *
     * @return mixed 返回结果。
     */
    public function toSqlBindings()
    {
        if (empty($this->current_table)) {
            return array(
                'sql' => '',
                'params' => array(),
                'types' => '',
            );
        }

        $clone = clone $this;
        return $clone->buildSelectPayload();
    }

    /**
     * 执行find操作。
     *
     * @return mixed 返回结果。
     */
    public function find()
    {
        $this->limit = 1;
        $result = $this->select();

        if ($this->fetch_sql) {
            return $result;
        }

        if (is_array($result) && !empty($result)) {
            return $result[0];
        }

        return null;
    }

    /**
     * 执行value操作。
     *
     * @param mixed $field 参数field。
     * @return mixed 返回结果。
     */
    /**
     * 校验字段名是否为安全的简单标识符（字母/数字/下划线/点号）。
     *
     * @param string $field
     * @return bool
     */
    private function isSafeFieldName($field)
    {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_\.]*$/', $field);
    }

    public function value($field)
    {
        $field = $this->isSafeFieldName($field) ? $field : '*';
        $this->fields = $field;
        $result = $this->find();

        if ($result && !is_string($result) && isset($result[$field])) {
            return $result[$field];
        }

        return null;
    }

    /**
     * 执行column操作。
     *
     * @param mixed $field 参数field。
     * @param mixed|null $key 参数key。
     * @return mixed 返回结果。
     */
    public function column($field, $key = null)
    {
        // 校验字段名安全
        $field = $this->isSafeFieldName($field) ? $field : '*';
        // 保存原字段设置，避免污染
        $origin_fields = $this->fields;
        $this->fields = $field;
        if ($key !== null) {
            // 同时查询键字段和值字段
            $key = $this->isSafeFieldName($key) ? $key : 'id';
            $this->fields = $key . ', ' . $field;
        }

        // 执行查询
        $result = $this->select();

        // 恢复字段设置（可选，因为 select 内部会 reset，但为了兼容后续链式，手动恢复）
        $this->fields = $origin_fields;

        if (!is_array($result)) {
            return [];
        }

        // 提取字段值
        $list = [];
        if ($key === null) {
            foreach ($result as $row) {
                $list[] = $row[$field];
            }
        } else {
            foreach ($result as $row) {
                $keyValue = isset($row[$key]) ? $row[$key] : null;
                if ($keyValue !== null) {
                    $list[$keyValue] = $row[$field];
                } else {
                    // 键为 null 时直接追加（数值索引）
                    $list[] = $row[$field];
                }
            }
        }

        return $list;
    }

    /**
     * 执行exists操作。
     *
     * @return mixed 返回结果。
     */
    public function exists()
    {
        // 性能优化：只需要知道是否存在，不需要统计总数
        // 使用 LIMIT 1 让数据库找到第一条记录后立即停止
        $original_limit = $this->limit;
        $this->limit = 1;

        $result = $this->find();

        // 恢复链式调用前的 limit
        $this->limit = $original_limit;

        return $result !== null && !is_string($result);
    }

    /**
     * 执行count操作。
     *
     * 含 UNION 时各分支列结构一致，不能将 COUNT(*) 与各分支 SELECT 直接 UNION；
     * 改为对整段 UNION 包一层子查询再 COUNT。
     *
     * @return int
     */
    public function count()
    {
        if (!empty($this->unions)) {
            $inner = $this->toSqlBindings();
            $sql = isset($inner['sql']) ? $inner['sql'] : '';
            if ($sql === '') {
                return 0;
            }
            $params = isset($inner['params']) && is_array($inner['params']) ? $inner['params'] : array();
            $types = isset($inner['types']) ? $inner['types'] : '';
            $wrapped = 'SELECT COUNT(*) AS cnt FROM (' . $sql . ') AS dou_union_count';
            $stmt = $this->stmtExecute($wrapped, $params, $types);
            if (!$stmt || !($stmt instanceof \mysqli_stmt)) {
                return 0;
            }
            $cnt = 0;
            if (function_exists('mysqli_stmt_get_result')) {
                $res = mysqli_stmt_get_result($stmt);
                if ($res) {
                    $row = mysqli_fetch_assoc($res);
                    if ($row && isset($row['cnt'])) {
                        $cnt = (int) $row['cnt'];
                    }
                    mysqli_free_result($res);
                }
            }
            mysqli_stmt_close($stmt);

            return $cnt;
        }

        // group_by / distinct：用子查询包裹后 COUNT 实际行数（组数 / 去重行数）。
        // 否则 find() 只取首组的 COUNT(*)（带 GROUP BY 时）或生成 SELECT DISTINCT COUNT(*)（带 distinct 时），结果都错。
        if ($this->group_by !== '' || $this->distinct) {
            $clone = clone $this;
            // count 关心总行数：清掉分页 limit/offset 与排序，避免子查询截断或无谓 ORDER BY。
            $clone->limit = '';
            $clone->offset_value = null;
            $clone->order_by = '';
            $inner = $clone->buildSelectPayload();
            $sql = isset($inner['sql']) ? $inner['sql'] : '';
            if ($sql === '') {
                return 0;
            }
            $params = isset($inner['params']) && is_array($inner['params']) ? $inner['params'] : array();
            $types = isset($inner['types']) ? $inner['types'] : '';
            $wrapped = 'SELECT COUNT(*) AS cnt FROM (' . $sql . ') AS dou_count';
            $stmt = $this->stmtExecute($wrapped, $params, $types);
            if (!$stmt || !($stmt instanceof \mysqli_stmt)) {
                return 0;
            }
            $cnt = 0;
            if (function_exists('mysqli_stmt_get_result')) {
                $res = mysqli_stmt_get_result($stmt);
                if ($res) {
                    $row = mysqli_fetch_assoc($res);
                    if ($row && isset($row['cnt'])) {
                        $cnt = (int) $row['cnt'];
                    }
                    mysqli_free_result($res);
                }
            }
            mysqli_stmt_close($stmt);

            return $cnt;
        }

        $this->fields = 'COUNT(*) as cnt';
        $result = $this->find();

        if ($result && !is_string($result) && isset($result['cnt'])) {
            return intval($result['cnt']);
        }

        return 0;
    }

    /**
     * 执行sum操作。
     *
     * @param mixed $field 参数field。
     * @return mixed 返回结果。
     */
    public function sum($field)
    {
        $field = $this->isSafeFieldName($field) ? $field : '*';
        $this->fields = 'SUM(' . $field . ') as sum_value';
        $result = $this->find();

        if ($result && !is_string($result) && isset($result['sum_value'])) {
            return floatval($result['sum_value']);
        }

        return 0;
    }

    /**
     * 执行avg操作。
     *
     * @param mixed $field 参数field。
     * @return mixed 返回结果。
     */
    public function avg($field)
    {
        $field = $this->isSafeFieldName($field) ? $field : '*';
        $this->fields = 'AVG(' . $field . ') as avg_value';
        $result = $this->find();

        if ($result && !is_string($result) && isset($result['avg_value'])) {
            return floatval($result['avg_value']);
        }

        return 0;
    }

    /**
     * 执行max操作。
     *
     * @param mixed $field 参数field。
     * @return mixed 返回结果。
     */
    public function max($field)
    {
        $field = $this->isSafeFieldName($field) ? $field : '*';
        $this->fields = 'MAX(' . $field . ') as max_value';
        $result = $this->find();

        if ($result && !is_string($result) && isset($result['max_value'])) {
            return $result['max_value'];
        }

        return null;
    }

    /**
     * 执行min操作。
     *
     * @param mixed $field 参数field。
     * @return mixed 返回结果。
     */
    public function min($field)
    {
        $field = $this->isSafeFieldName($field) ? $field : '*';
        $this->fields = 'MIN(' . $field . ') as min_value';
        $result = $this->find();

        if ($result && !is_string($result) && isset($result['min_value'])) {
            return $result['min_value'];
        }

        return null;
    }

    /**
     * 执行groupBy操作。
     *
     * @param mixed $group 参数group。
     * @return mixed 返回结果。
     */
    public function groupBy($group)
    {
        // 安全过滤分组字段
        $group = trim($group);
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_\.]*$/', $group)) {
            // 处理 table.field 格式
            if (strpos($group, '.') !== false) {
                $parts = explode('.', $group);
                $this->group_by = '`' . $parts[0] . '`.`' . $parts[1] . '`';
            } else {
                $this->group_by = '`' . $group . '`';
            }
        }
        return $this;
    }

    /**
     * 执行having操作。
     *
     * @param mixed $field 参数field。
     * @param mixed|null $op 参数op。
     * @param mixed|null $value 参数value。
     * @return mixed 返回结果。
     */
    public function having($field, $op = null, $value = null)
    {
        $this->parseHaving($field, $op, $value, 'AND');
        return $this;
    }

    /**
     * 执行havingOr操作。
     *
     * @param mixed $field 参数field。
     * @param mixed|null $op 参数op。
     * @param mixed|null $value 参数value。
     * @return mixed 返回结果。
     */
    public function havingOr($field, $op = null, $value = null)
    {
        $this->parseHaving($field, $op, $value, 'OR');
        return $this;
    }

    /**
     * 执行parseHaving操作。
     *
     * @param mixed $field 参数field。
     * @param mixed $op 参数op。
     * @param mixed $value 参数value。
     * @param mixed $logic 参数logic。
     * @return void 返回结果。
     */
    private function parseHaving($field, $op, $value, $logic)
    {
        if ($value === null) {
            $value = $op;
            $op = '=';
        }

        $op = strtoupper($op);

        $this->having[] = [
            'logic' => $logic,
            'field' => $field,
            'op' => $op,
            'value' => $value
        ];

        // 添加绑定参数
        if ($op === 'IN' || $op === 'NOT IN') {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $this->params[] = $item;
                    $this->types .= $this->getParamType($item);
                }
            }
        } else {
            $this->params[] = $value;
            $this->types .= $this->getParamType($value);
        }
    }

    /**
     * 执行buildHaving操作。
     *
     * @return mixed 返回结果。
     */
    private function buildHaving()
    {
        if (empty($this->having)) {
            return '';
        }

        $sql = ' HAVING ';
        $first = true;

        foreach ($this->having as $condition) {
            if (!$first) {
                $sql .= ' ' . $condition['logic'] . ' ';
            }
            $first = false;

            $op = $condition['op'];
            // 修复：HAVING条件可能包含聚合函数，使用专门的过滤方法
            $field = $this->filterHavingField($condition['field']);

            if ($op === 'IN' || $op === 'NOT IN') {
                $count = is_array($condition['value']) ? count($condition['value']) : 1;
                $placeholders = implode(', ', array_fill(0, $count, '?'));
                $sql .= $field . ' ' . $op . ' (' . $placeholders . ')';
            } else {
                $sql .= $field . ' ' . $op . ' ?';
            }
        }

        return $sql;
    }

    /**
     * 执行filterHavingField操作。
     *
     * @param mixed $field 参数field。
     * @return mixed 返回结果。
     */
    private function filterHavingField($field)
    {
        $field = trim($field);

        // 允许聚合函数：COUNT(*), SUM(amount), AVG(price), MAX(id), MIN(score)
        // 允许格式：函数名(字段名) 或 函数名(表名.字段名) 或 普通字段名
        // 使用更安全的正则，允许字母、数字、下划线、点号、星号、括号、逗号、空格
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\s*\([a-zA-Z0-9_\.\*\s,]+\)$/i', $field)) {
            // 聚合函数格式，保持原样返回
            return $field;
        }

        // 普通字段名，使用原有安全过滤
        return $this->safeFieldName($field);
    }

    /**
     * 执行distinct操作。
     *
     * @param bool $distinct 参数distinct。
     * @return mixed 返回结果。
     */
    public function distinct($distinct = true)
    {
        $this->distinct = $distinct;
        return $this;
    }

    /**
     * 执行inc操作。
     *
     * @param mixed $field 参数field。
     * @param int $step 参数step。
     * @return mixed 返回结果。
     */
    public function inc($field, $step = 1)
    {
        return $this->setField($field, $field . ' + ' . intval($step), true);
    }

    /**
     * 执行dec操作。
     *
     * @param mixed $field 参数field。
     * @param int $step 参数step。
     * @return mixed 返回结果。
     */
    public function dec($field, $step = 1)
    {
        return $this->setField($field, $field . ' - ' . intval($step), true);
    }

    /**
     * 执行exp操作。
     *
     * @param mixed $field 参数field。
     * @param mixed $operator 参数operator。
     * @param mixed $value 参数value。
     * @param bool $raw 参数raw。
     * @return mixed 返回结果。
     */
    public function exp($field, $operator, $value, $raw = false)
    {
        $operators = ['+', '-', '*', '/'];
        if (!in_array($operator, $operators)) {
            return false;
        }

        if ($raw) {
            // 原生表达式，直接使用
            $expression = $field . ' ' . $operator . ' ' . $value;
            return $this->setField($field, $expression, true);
        } else {
            // 非原生表达式，使用参数绑定
            // 构建安全的表达式：字段名 运算符 ?
            $expression = $field . ' ' . $operator . ' ?';

            // 这里需要特殊处理，因为set_field不支持带占位符的表达式
            // 我们直接构建一个特殊的set_field调用
            if (empty($this->current_table)) {
                return false;
            }

            // 安全过滤字段名
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                return false;
            }

            // 构建SQL
            $sql = 'UPDATE ' . $this->current_table . ' SET `' . $field . '` = `' . $field . '` ' . $operator . ' ?';
            $sql .= $this->buildWhere();

            // 合并参数
            $params = array_merge([$value], $this->params);
            $types = $this->getParamType($value) . $this->types;

            // 记录SQL
            $this->last_sql = $sql;
            $this->last_params = $params;
            $this->last_build_sql = $sql;

            // 如果是只获取SQL，不执行
            if ($this->fetch_sql) {
                $result = $sql;
                $this->reset();
                return $result;
            }

            $stmt = $this->stmtExecute($sql, $params, $types);

            if ($stmt !== false) {
                $affectedRows = mysqli_stmt_affected_rows($stmt);
                mysqli_stmt_close($stmt);
                $this->reset();
                return $affectedRows;
            }

            $this->reset();
            return false;
        }
    }

    /**
     * 执行insertAll操作。
     *
     * @param mixed $data_list 参数data_list。
     * @return mixed 返回结果。
     */
    public function insertAll($data_list)
    {
        if (empty($this->current_table) || empty($data_list) || !is_array($data_list)) {
            return false;
        }

        // 获取第一个数据的字段作为所有数据的字段
        $first_data = current($data_list);
        $fields = array_keys($first_data);
        $field_names = array_map(function ($field) {
            return '`' . $field . '`';
        }, $fields);

        // 构建占位符和参数
        $placeholders = [];
        $params = [];
        $types = '';

        foreach ($data_list as $data) {
            $row_placeholders = [];
            foreach ($fields as $field) {
                $value = isset($data[$field]) ? $data[$field] : null;
                $row_placeholders[] = '?';
                $params[] = $value;
                $types .= $this->getParamType($value);
            }
            $placeholders[] = '(' . implode(', ', $row_placeholders) . ')';
        }

        $sql = 'INSERT INTO ' . $this->current_table . ' (' . implode(', ', $field_names) . ') VALUES ' . implode(', ', $placeholders);

        // 记录SQL用于调试
        $this->last_sql = $sql;
        $this->last_params = $params;
        $this->last_build_sql = $sql;

        // 如果是只获取SQL模式
        if ($this->fetch_sql) {
            $result = $sql;
            $this->reset();
            return $result;
        }

        $stmt = $this->stmtExecute($sql, $params, $types);

        if ($stmt !== false) {
            $affectedRows = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            $this->reset();
            return $affectedRows;
        }

        $this->reset();
        return false;
    }

    /**
     * 执行union操作。
     *
     * @param mixed $query 参数query。
     * @param bool $all 参数all。
     * @return mixed 返回结果。
     */
    public function union($query, $all = false)
    {
        if ($query instanceof Connection) {
            // 读取链式实例构建结果（含绑定参数），避免丢失占位符绑定
            $payload = $query->toSqlBindings();
            $query = isset($payload['sql']) ? $payload['sql'] : '';
            $params = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : array();
            $types = isset($payload['types']) && is_string($payload['types']) ? $payload['types'] : '';
        } else {
            $params = array();
            $types = '';
        }
        $this->unions[] = [
            'query' => $query,
            'all' => $all,
            'params' => $params,
            'types' => $types,
        ];
        return $this;
    }

    /**
     * 执行lock操作。
     *
     * @param string $lock_type 参数lock_type。
     * @return mixed 返回结果。
     */
    public function lock($lock_type = 'FOR UPDATE')
    {
        $lock_type = strtoupper(trim($lock_type));

        // 允许多种写法
        if ($lock_type === 'UPDATE' || $lock_type === 'EXCLUSIVE') {
            $this->lock = 'FOR UPDATE';
        } elseif ($lock_type === 'SHARED' || $lock_type === 'SHARE') {
            $this->lock = 'LOCK IN SHARE MODE';
        } elseif ($lock_type === 'FOR UPDATE' || $lock_type === 'LOCK IN SHARE MODE') {
            $this->lock = $lock_type;
        } else {
            $this->lock = 'FOR UPDATE'; // 默认排他锁
        }

        return $this;
    }

    /**
     * 执行paginate操作。
     *
     * @param int $page_size 参数page_size。
     * @param int $page 参数page。
     * @param string $page_url 参数page_url。
     * @param string $get 参数get。
     * @param bool $close_rewrite 参数close_rewrite。
     * @param string $record_count_reduce 参数record_count_reduce。
     * @return array 返回结果。
     */
    public function paginate($page_size = 10, $page = 1, $page_url = '', $get = '', $close_rewrite = false, $record_count_reduce = '')
    {
        if (empty($this->current_table)) {
            return ['list' => [], 'pager' => []];
        }
        $page_size = max(1, (int) $page_size);
        $page = max(1, (int) $page);

        // 1. 克隆当前查询对象用于 count（避免影响原查询）
        $count_query = clone $this;

        // 2. 使用克隆对象获取总数（不影响原查询对象）
        $record_count = $count_query->count();

        // 3. 如果有记录数减少
        if ($record_count_reduce) {
            $record_count -= $record_count_reduce;
        }

        // 4. 计算分页信息
        $page_info = $this->calculatePagerInfo($record_count, $page_size, $page, $page_url, $get, $close_rewrite);

        // 5. 将分页信息赋值给 SESSION
        Session::set('page', $page);

        // 6. 设置 limit 并执行查询（使用原查询对象）
        $start = ($page - 1) * $page_size;
        $this->limit($start, $page_size);
        $list = $this->select();

        // 7. 返回查询数据（select 内部已经调用 reset）
        return [
            'list' => $list ?: [],
            'pager' => $page_info
        ];
    }

    /**
     * 执行calculatePagerInfo操作。
     *
     * @param mixed $record_count 参数record_count。
     * @param mixed $page_size 参数page_size。
     * @param mixed $page 参数page。
     * @param mixed $page_url 参数page_url。
     * @param mixed $get 参数get。
     * @param mixed $close_rewrite 参数close_rewrite。
     * @return array 返回结果。
     */
    private function calculatePagerInfo($record_count, $page_size, $page, $page_url, $get, $close_rewrite)
    {
        $rewrite_enabled = !empty(Config::get('site.rewrite', false));

        // 调整分页链接样式
        if (!defined('IS_ADMIN') && $rewrite_enabled && !$close_rewrite) {
            $get_page = '/o';
            if ($get) {
                $get = preg_replace('/&/', '?', $get, 1);
                $get = '/' . $get;
            }
        } else {
            $get_page = strpos($page_url ? $page_url : '', '?') !== false ? '&page=' : '?page=';
        }

        $page_count = ceil($record_count / $page_size);
        $page_count = $page_count > 0 ? $page_count : 1;
        $first = $page_url . $get_page . '1' . $get;
        $previous = $page_url . $get_page . ($page > 1 ? $page - 1 : 1) . $get;
        $next = $page_url . $get_page . ($page_count > $page ? $page + 1 : $page_count) . $get;
        $last = $page_url . $get_page . $page_count . $get;

        // 当分页总数超过6页时，起始分页由计算得出
        if ($page_count > 6) {
            if ($page_count - $page < 6) {
                $page_start = $page_count - 5;
            } else {
                $page_start = $page;
            }
        } else {
            $page_start = 1;
        }

        // 页码循环显示
        $box = [];
        for ($p = $page_start; $p <= $page_start + 5 && $p <= $page_count; $p++) {
            $box[] = [
                "page" => $p,
                "url" => $page_url . $get_page . $p . $get,
                "cur" => $p == $page
            ];
        }

        return [
            "record_count" => $record_count,
            "page_size" => $page_size,
            "page" => $page,
            "page_count" => $page_count,
            "box" => $box,
            "previous" => $previous,
            "next" => $next,
            "first" => $first,
            "last" => $last
        ];
    }

    /**
     * 执行debug操作。
     *
     * @return mixed 返回结果。
     */
    public function debug()
    {
        return $this->lastSql();
    }

    /**
     * 执行lastSql操作。
     *
     * @return mixed 返回结果。
     */
    public function lastSql()
    {
        // 优先使用 last_sql（最后执行的SQL）
        $sql = !empty($this->last_sql) ? $this->last_sql : $this->last_build_sql;
        $params = $this->last_params;

        if (empty($sql)) {
            return 'No SQL executed yet.';
        }

        if (empty($params)) {
            return $sql;
        }

        // 将 ? 占位符替换为实际参数值
        foreach ($params as $param) {
            $pos = strpos($sql, '?');
            if ($pos !== false) {
                // 对字符串类型添加引号，数字类型直接替换
                if (is_string($param)) {
                    $value = "'" . addslashes($param) . "'";
                } elseif (is_null($param)) {
                    $value = 'NULL';
                } else {
                    $value = $param;
                }
                $sql = substr_replace($sql, $value, $pos, 1);
            }
        }

        return $sql;
    }

    /**
     * 执行fetchSql操作。
     *
     * @param bool $fetch 参数fetch。
     * @return mixed 返回结果。
     */
    public function fetchSql($fetch = true)
    {
        $this->fetch_sql = $fetch;
        return $this;
    }

    /**
     * 执行rawSql操作。
     *
     * @return mixed 返回结果。
     */
    public function rawSql()
    {
        return $this->last_build_sql;
    }

    /**
     * 执行setDebug操作。
     *
     * @param bool $enable 参数enable。
     * @return mixed 返回结果。
     */
    public function setDebug($enable = true)
    {
        $this->debug_mode = $enable;
        if ($enable) {
            $this->debugLog('Debug Mode', 'Enabled');
        }
        return $this;
    }

    /**
     * 执行debugLog操作。
     *
     * @param mixed $type 参数type。
     * @param mixed $message 参数message。
     * @return void 返回结果。
     */
    private function debugLog($type, $message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        $caller = 'Unknown';
        if (isset($trace[2])) {
            $class = isset($trace[2]['class']) ? $trace[2]['class'] : '';
            $type_char = isset($trace[2]['type']) ? $trace[2]['type'] : '';
            $function = isset($trace[2]['function']) ? $trace[2]['function'] : '';
            $caller = $class . $type_char . $function;
            if (empty($caller)) {
                $caller = 'Unknown';
            }
        }

        // 确保 header 只在未发送时调用
        if (!headers_sent()) {
            header('Content-type: text/html; charset=' . DOU_CHARSET);
        }

        // 构建输出内容
        $output = "<div style='margin:5px 0;padding:10px;background:#f8f9fa;border-left:4px solid #007bff;font-family:monospace;font-size:12px;'>";
        $output .= "<strong style='color:#007bff;'>[{$timestamp}] [{$type}]</strong>";
        $output .= " <span style='color:#6c757d;'>({$caller})</span><br>";
        $output .= "<pre style='margin:5px 0 0 0;white-space:pre-wrap;word-wrap:break-word;'>{$message}</pre>";
        $output .= "</div>";

        echo $output;
    }
}
