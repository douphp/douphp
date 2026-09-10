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

namespace Dou\Core\Facade;

use Dou\Core\Foundation\Facade\StaticFacade;
use Dou\Core\Infra\Database\Connection;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * DB 静态门面：底层为 {@see Connection} 容器单例。
 *
 * 用法：
 *   use Dou\Core\Facade\DB;
 *   $row = DB::table('user')->where('id', $id)->find();
 *   DB::table('product')->data(array('is_open' => 0))->where('id', $id)->update();
 *   DB::beginTransaction(); DB::commit(); DB::rollback();
 *
 * 需要原始 Connection 实例（极少数把 db 作为变量/参数传出的场景）：$conn = DB::getFacadeRoot();
 * 测试期用 {@see StaticFacade::swap()} / {@see StaticFacade::clearResolvedInstance()} 替换为 mock。
 *
 * @method static mixed query(string $sql)
 * @method static Connection table(string $table)
 * @method static Connection field(string $field)
 * @method static Connection where(string $field, $op = null, $value = null)
 * @method static Connection whereOr(string $field, $op = null, $value = null)
 * @method static Connection whereIn(string $field, array $values)
 * @method static Connection whereNotIn(string $field, array $values)
 * @method static Connection whereRaw(string $raw, array $binds = [])
 * @method static Connection join(string $table, string $condition)
 * @method static Connection innerJoin(string $table, string $condition)
 * @method static Connection rightJoin(string $table, string $condition)
 * @method static Connection order(string $order)
 * @method static Connection groupBy(string $group)
 * @method static Connection having(string $field, $op = null, $value = null)
 * @method static Connection limit(int $offset, $length = null)
 * @method static Connection offset(int $offset)
 * @method static Connection distinct(bool $distinct = true)
 * @method static Connection data(array $data)
 * @method static Connection lock(string $lockType = 'FOR UPDATE')
 * @method static Connection union($query, bool $all = false)
 * @method static array|false find()
 * @method static array select()
 * @method static mixed value(string $field)
 * @method static array column(string $field, $key = null)
 * @method static int count()
 * @method static int|float sum(string $field)
 * @method static int|float avg(string $field)
 * @method static int|float max(string $field)
 * @method static int|float min(string $field)
 * @method static bool exists()
 * @method static int|string insert($data = null)
 * @method static int update($data = null)
 * @method static int delete()
 * @method static int insertAll(array $dataList)
 * @method static Connection inc(string $field, int $step = 1)
 * @method static Connection dec(string $field, int $step = 1)
 * @method static Connection exp(string $field, string $operator, $value, bool $raw = false)
 * @method static Connection setField(string $field, $value, bool $raw = false)
 * @method static array paginate(int $pageSize = 10, int $page = 1, string $pageUrl = '', string $get = '', bool $closeRewrite = false, $recordCountReduce = '')
 * @method static array|false getRow(string $table, string $field, $where = '')
 * @method static mixed getValue(string $table, string $field, $where = '')
 * @method static array getOne(string $sql, bool $limited = false)
 * @method static bool rowExist(string $table, $where = '')
 * @method static int rowNumber(string $table, $where = '')
 * @method static bool valueExist(string $table, string $field, $value, string $and = '')
 * @method static bool tableExist(string $table)
 * @method static bool fieldExist(string $table, string $field)
 * @method static bool hasUniqueIndex(string $table, string $field)
 * @method static string tableName(string $table)
 * @method static string getPrefix()
 * @method static string escapeString(string $string)
 * @method static int affectedRows()
 * @method static int|string insertId()
 * @method static array|null fetchAssoc($query)
 * @method static array|null fetchArray($query, int $resulttype = MYSQLI_BOTH)
 * @method static array|null fetchRow($query)
 * @method static int numRows($query)
 * @method static int autoId(string $table)
 * @method static string version()
 * @method static void beginTransaction()
 * @method static void commit()
 * @method static void rollback()
 * @method static mixed stmtExecute(string $sql, array $params = [], string $types = '')
 * @method static Connection fetchSql(bool $fetch = true)
 * @method static string lastSql()
 * @method static string rawSql()
 * @method static void setDebug(bool $enable = true)
 * @method static array toSqlBindings()
 */
class DB extends StaticFacade
{
    /**
     * 容器中以 Connection FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return Connection::class;
    }
}
