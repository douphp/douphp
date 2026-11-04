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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 分类展示信息装配（终结型静态门面 + cate_info accessor）。
 *
 * 收口三种调用口径：
 * 1. categoryInfo()         — 控制器 / Service 按 catId 取行装配（含兜底、归档覆写、url、icon）；
 * 2. categoryInfoFromRow()  — 已有分类行时复用，避免重复查询；
 * 3. getCateInfoAttribute() — 模型 $appends 白名单触发的列表字段，读已加载的 category 关联。
 *
 * 数据源走 Model::findCategoryById()（基类按 {table}_category 约定取行），
 * 路由名固定 {table}.category（Route::column 展开时统一声明）。
 *
 * 返回键集：分类行全部字段（icon 已转真实 URL，模块特有列如 item_category.type 原样透传）
 * + 恒定键 category_id / parent_id / name / keywords / description / icon / url。
 * 分类缺失（$catId <= 0 的日期归档页 / 分类行已删）时返回完整兜底数组而非 false/null，
 * url 恒有值 —— accessor 与静态入口同一结构，API 契约与模板判空行为均保持稳定。
 *
 * 列表场景如需零额外查询，调用方须在 toArray() 之前执行 warmupCategoryIcons()。
 */
trait HasCategoryInfoQuery
{
    /**
     * 使用本 trait 的模型须继承 Dou\Core\Orm\Model（按 {table}_category 约定取行）。
     * 抽象声明仅用于声明依赖契约，实际实现由基类 Model 提供。
     *
     * @param int|string $catId
     * @return mixed 分类行（Model 实例或数组），无记录时为 null
     */
    abstract public static function findCategoryById($catId);

    /**
     * 使用本 trait 的模型须继承 Dou\Core\Orm\Model（提供表名，用于推导语言域与路由名）。
     *
     * @return string
     */
    abstract public static function tableName();

    /**
     * 分类展示信息（按 ID 取行）。
     *
     * @param int|string $catId
     * @param array $archive Util::parseArchive() 结果；非空时以归档标签覆盖 name
     * @return array
     */
    public static function categoryInfo($catId, $archive = array())
    {
        $catId = (int) $catId;
        $row = $catId > 0 ? static::findCategoryById($catId) : null;

        return static::categoryInfoFromRow($row, $catId, $archive);
    }

    /**
     * 分类展示信息（已有分类行时复用，避免重复查询）。
     *
     * @param array|false|null $row 分类行（Model::getAttributes() 或 DB::find() 原始数组）
     * @param int|string $catId
     * @param array $archive
     * @return array
     */
    public static function categoryInfoFromRow($row, $catId, $archive = array())
    {
        $catId = (int) $catId;
        $defaults = array(
            'category_id' => $catId,
            'parent_id' => 0,
            'name' => '',
            'keywords' => '',
            'description' => '',
            'icon' => '',
        );

        if (is_array($row) && !empty($row)) {
            $language = language();
            if ($language !== null) {
                // 左优先合并：分类行（含模块特有列）覆盖默认值，缺失键由 defaults 补齐
                $row = $language->langBox($row, static::tableName() . '_category', 'name, keywords, description');
            }
            $cateInfo = $row + $defaults;
            $cateInfo['category_id'] = $catId;
            $cateInfo['parent_id'] = (int) $cateInfo['parent_id'];
            $cateInfo['icon'] = attachment()->url($cateInfo['icon']);
        } else {
            $cateInfo = $defaults;
        }

        $cateInfo['url'] = route(static::tableName() . '.category', array('category_id' => $catId));

        if (!empty($archive['label'])) {
            $cateInfo['name'] = $archive['label'];
        }

        return $cateInfo;
    }

    /**
     * 栏目名：优先分类名，回退语言包文案。
     *
     * @param array $cateInfo
     * @param string $labelKey 回退语言键；默认 {table}_category
     *                         （api 端页面标题用 {table}，显式传入即可）
     * @return string
     */
    public static function categoryLabel(array $cateInfo, $labelKey = '')
    {
        if (isset($cateInfo['name']) && $cateInfo['name'] !== '') {
            return $cateInfo['name'];
        }

        return lang($labelKey !== '' ? $labelKey : static::tableName() . '_category');
    }

    /**
     * 批量预热分类 icon 的附件 URL（列表 toArray() 之前调用）。
     *
     * 收集已加载 category 关联里的 icon 号一次性 urlBatch()，
     * 使随后 accessor 内的 attachment()->url() 全部命中请求级缓存，零额外查询。
     * 使用方须先 with('category') 预加载关系。
     *
     * @param mixed $models hydrated Model 集合 / 数组
     * @return void
     */
    public static function warmupCategoryIcons($models)
    {
        if (!is_array($models) && !($models instanceof \Traversable)) {
            return;
        }

        $iconNumbers = array();
        foreach ($models as $model) {
            if (!is_object($model) || !method_exists($model, 'getAttribute')) {
                continue;
            }
            $category = $model->getAttribute('category');
            if (!is_object($category) || !method_exists($category, 'getAttribute')) {
                continue;
            }
            $icon = $category->getAttribute('icon');
            if (is_string($icon) && $icon !== '') {
                $iconNumbers[] = $icon;
            }
        }

        if (!empty($iconNumbers)) {
            attachment()->urlBatch($iconNumbers);
        }
    }

    /**
     * cate_info accessor：由已加载的 category 关联派生。
     *
     * 关联未加载 / 无分类时返回完整兜底数组（与 categoryInfo() 同结构），
     * 保证 toArray() 输出的 cate_info 恒为数组，API 侧契约稳定。
     * 使用方须 with('category') 预加载并把 'cate_info' 列入 $appends。
     *
     * @return array
     */
    public function getCateInfoAttribute()
    {
        $category = $this->getAttribute('category');
        if (!$category) {
            return static::categoryInfoFromRow(null, 0);
        }

        return static::categoryInfoFromRow(
            $category->getAttributes(),
            (int) $category->getAttribute('id')
        );
    }
}
