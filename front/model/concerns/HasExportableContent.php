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

namespace Dou\Front\Model\Concerns;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\CategoryIds;
use Dou\Core\Support\Str;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 内容（含分类）模块导出 trait：为 Sitemap / Llms 等动态聚合提供
 * 列表行（listForExport）与分类树（categoriesForExport）。
 *
 * 输出契约 title / name / description / created_at / url：多语言经 langBox 覆写、
 * description 缺省取正文 320 字摘要、url 走 route($module . '.show')。
 * status 过滤由 scopePublished 守护；分类过滤走 CategoryIds::subtree 取自身+子分类。
 *
 * 使用方须实现 public static function categoryClass()，返回对应 *Category 类名。
 *
 * @method static string tableName()
 * @method static \Dou\Core\Orm\Builder query()
 * @method static string categoryClass()
 */
trait HasExportableContent
{
    /**
     * 导出用内容列表。
     *
     * @param string|int $catId 'ALL' 或 category_id（含其子分类）
     * @param int $limit 0 表示不限制条数
     * @return array<int, array<string, mixed>>
     */
    public static function listForExport($catId = 'ALL', $limit = 0)
    {
        $module = static::tableName();
        if (!Config::get('features.' . $module, false)) {
            return array();
        }

        $query = static::query();
        if (method_exists(get_called_class(), 'scopePublished')) {
            $query->published();
        }

        if ($catId !== 'ALL' && (int) $catId > 0) {
            $catIds = CategoryIds::subtree($module . '_category', (int) $catId);
            if (!empty($catIds)) {
                $query->where('category_id', 'IN', $catIds);
            }
        }

        $query->order('id DESC');
        if ((int) $limit > 0) {
            $query->limit((int) $limit);
        }

        $rows = array();
        foreach ($query->get() as $model) {
            $rows[] = static::toExportRow($model->getAttributes());
        }

        return $rows;
    }

    /**
     * 导出用分类（无层级缩进，name 多语言）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categoriesForExport()
    {
        $calledClass = get_called_class();
        if (!is_callable(array($calledClass, 'categoryClass'))) {
            throw new \RuntimeException($calledClass . ' must implement categoryClass() for HasExportableContent.');
        }

        $cls = static::categoryClass();

        return $cls::flat();
    }

    /**
     * 把原始行裁剪为导出契约。
     *
     * @param array $raw 模型原始属性
     * @return array<string, mixed>
     */
    protected static function toExportRow(array $raw)
    {
        $module = static::tableName();
        $row = language()->langBox($raw, $module, 'name, title, content, description');

        $id = isset($row['id']) ? (int) $row['id'] : 0;
        $addTime = Util::toTimestamp(isset($row['created_at']) ? $row['created_at'] : null);
        $rawDescription = isset($row['description']) ? $row['description'] : '';
        $rawContent = isset($row['content']) ? $row['content'] : '';

        return array(
            'title' => isset($row['title']) ? $row['title'] : '',
            'name' => isset($row['name']) ? $row['name'] : (isset($row['title']) ? $row['title'] : ''),
            'description' => $rawDescription ? $rawDescription : Str::excerpt($rawContent, 320),
            'created_at' => $addTime !== null ? date('Y-m-d', $addTime) : '',
            'url' => route($module . '.show', array('id' => $id)),
        );
    }
}
