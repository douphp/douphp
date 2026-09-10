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

namespace Dou\Admin\Model\Data;

use Dou\Core\Orm\Model;

use Dou\Core\Facade\DB;
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 data 数据模型。
 */
class Data extends Model
{
    /** @var string */
    protected $table = 'data';

    /** @var array */
    protected $fillable = array(
        'parent_code',
        'theme',
        'data_group',
        'data_item',
        'name',
        'code',
        'text',
        'image',
        'link',
        'is_class',
        'sort',
        'module',
        'item_id',
        'class',
        'is_locked',
    );

    /**
     * @param string $group
     * @return array
     */
    public static function findByGroup($group)
    {
        return static::where('data_group', (string) $group)
            ->order('id ASC')
            ->get();
    }

    /**
     * @param int $id
     * @param string $fields
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findById($id, $fields = '*')
    {
        return static::field($fields)->where('id', (int) $id)->first();
    }

    /**
     * @param string $code
     * @param string $theme
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findByCodeAndTheme($code, $theme)
    {
        return static::where('code', (string) $code)
            ->where('theme', (string) $theme)
            ->first();
    }

    /**
     * @param string $code
     * @param string $theme
     * @param int $excludeId
     * @return bool
     */
    public static function existsByCodeAndTheme($code, $theme, $excludeId = 0)
    {
        $query = static::where('code', (string) $code)
            ->where('theme', (string) $theme);

        if ((int) $excludeId > 0) {
            $query->where('id', '!=', (int) $excludeId);
        }

        return (bool) $query->exists();
    }

    /**
     * 跨主题检查 code 是否存在（用于生成全新唯一 code 时的冲突检测）。
     *
     * @param string $code
     * @return bool
     */
    public static function existsByCode($code)
    {
        return (bool) static::where('code', (string) $code)->exists();
    }

    /**
     * 取指定主题下所有已存在的 code 集合（key 为 code，便于 O(1) 查重）。
     *
     * 供 TemplateDataScanner 同步时做差集：已存在的 code 完全跳过，仅补缺失项。
     *
     * 注意：这里用 DB::table() 直查返回纯数组，而非 ORM 的 ->get()（返回 Collection）。
     * 因为 (array) 强转 Collection 对象拿不到内部 items（protected 属性），
     * 会导致 foreach 遍历到错误的 key，$row['code'] 触发 Undefined index。
     * 参考 core/service/data/DataService.php::fetchTree() 的写法。
     *
     * @param string $theme
     * @return array<string, int> code => id
     */
    public static function findAllCodesByTheme($theme)
    {
        $rows = DB::table('data')
            ->where('theme', (string) $theme)
            ->field('id, code')
            ->select();

        $map = array();
        foreach ((array) $rows as $row) {
            $map[(string) $row['code']] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * 批量取指定主题下多个 code 的 data_group（用于 parent 继承 group 时查父级 group）。
     *
     * 供 TemplateDataScanner::inheritGroupFromParent() 使用：当子项声明的 parent_code
     * 是已存在的 DB 记录（不在本次扫描结果里）时，通过本方法批量查父级 group，
     * 避免逐条查询。
     *
     * @param array  $codes  父级 code 列表
     * @param string $theme
     * @return array<string, string> code => data_group
     */
    public static function findGroupsByCodesAndTheme(array $codes, $theme)
    {
        if (empty($codes)) {
            return array();
        }

        $rows = DB::table('data')
            ->where('theme', (string) $theme)
            ->whereIn('code', array_values($codes))
            ->field('code, data_group')
            ->select();

        $map = array();
        foreach ((array) $rows as $row) {
            $map[(string) $row['code']] = (string) $row['data_group'];
        }

        return $map;
    }

    /**
     * 批量把指定主题下多个 code 的 is_class 标记为 1（表示是分组/容器）。
     *
     * 供 TemplateDataScanner::markParentAsClass() 使用：当子项通过 |parent:"xxx" 引用
     * 一个已存在的 DB 记录作为父级时，该父级应标记为分组。
     *
     * 只更新 is_class=0 的记录，避免对已标记为分组的记录重复 UPDATE。
     *
     * @param array  $codes  父级 code 列表
     * @param string $theme
     * @return int 受影响行数
     */
    public static function markAsClassByCodes(array $codes, $theme)
    {
        if (empty($codes)) {
            return 0;
        }

        return DB::table('data')
            ->where('theme', (string) $theme)
            ->whereIn('code', array_values($codes))
            ->where('is_class', 0)
            ->update(array('is_class' => 1));
    }

    /**
     * @param array $data
     * @return int|string 新插入行主键
     */
    public static function insertData(array $data)
    {
        $model = static::create($data);

        return $model ? $model->getKey() : 0;
    }

    /**
     * 按主键更新（调用方传入显式键数组，均在 $fillable 内、无需 cast，走直发 SQL）。
     *
     * @param int $id
     * @param array $data
     * @return int 受影响行数
     */
    public static function updateById($id, array $data)
    {
        return static::whereKey((int) $id)
            ->update($data);
    }

    /**
     * @param int $id
     * @return int
     */
    public static function deleteById($id)
    {
        return DB::table(static::tableName())->where('id', (int) $id)->delete();
    }

    /**
     * @param string $module
     * @return string
     */
    public static function findLastItemIdByModule($module)
    {
        $itemId = DB::table(static::tableName())
            ->where('module', (string) $module)
            ->order('id DESC')
            ->value('item_id');

        return $itemId ? (string) $itemId : '';
    }

    /**
     * @param string $module
     * @param string|int $itemId
     * @return array
     */
    public static function findByModuleAndItemId($module, $itemId)
    {
        return static::where('module', (string) $module)
            ->where('item_id', $itemId)
            ->order('id ASC')
            ->get();
    }

    /**
     * @param int $id
     * @return int
     */
    public static function toggleLockById($id)
    {
        $isLocked = (int) DB::table(static::tableName())->where('id', (int) $id)->value('is_locked');
        $isLocked = $isLocked ? 0 : 1;
        static::where('id', (int) $id)->update(array('is_locked' => $isLocked));

        return $isLocked;
    }

    /**
     * @return void
     */
    public static function clearBannerParentAndClass()
    {
        static::where('data_group', 'banner')
            ->update(array('parent_code' => '', 'is_class' => 0));
    }
}
