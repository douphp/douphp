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

namespace Dou\Admin\Service\Product;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 商品导出 / 导入列声明。
 *
 * 只描述「哪些列、表头文案、按列顺序取哪个键」，读写能力由
 * {@see \Dou\Vendor\Excel\Excel} 承担，入库由 ProductService 自己完成。
 *
 * 导出覆盖列表可见列与 SEO / 详情文本；会员价 level_price、自定义字段 defined、
 * 多语言 metadata、促销起止时间属于序列化结构，不入表。
 * 导入仅覆盖标量字段，不支持图库、自定义字段、会员价与多语言 metadata。
 */
class ProductExcelDefinition
{
    /**
     * 导出列：键 => 语言文案键（顺序即 Excel 列顺序）。
     *
     * 其中 category_name / status_format / image 由 ProductService 预处理好后塞入行数组。
     *
     * 列顺序变更会影响已导出的历史文件对比，新增列只能追加到末尾。
     *
     * @return array
     */
    public static function exportLabels()
    {
        return array(
            'id' => 'record_id',
            'title' => 'product_title',
            'category_name' => 'product_category',
            'price' => 'product_price',
            'promote_price' => 'price_promote',
            'stock' => 'product_stock',
            'point' => 'product_point',
            'model' => 'product_model',
            'sort' => 'sort',
            'status_format' => 'status',
            'created_at' => 'created_at',
            'image' => 'thumb',
            'sales' => 'product_sales',
            'slug' => 'slug',
            'keywords' => 'keywords',
            'description' => 'description',
            'content' => 'product_content',
        );
    }

    /**
     * 导出表头行。
     *
     * @return array
     */
    public static function head()
    {
        $head = array();
        foreach (self::exportLabels() as $langKey) {
            $head[] = lang($langKey);
        }

        return $head;
    }

    /**
     * 导出单行（$product 需已补齐 category_name / status_format / image 描述符）。
     *
     * @param array $product
     * @return array
     */
    public static function row(array $product)
    {
        $line = array();
        foreach (array_keys(self::exportLabels()) as $field) {
            $line[] = isset($product[$field]) ? $product[$field] : '';
        }

        return $line;
    }

    /**
     * 导入列：字段名 => 语言文案键（顺序即模板列顺序）。
     *
     * 注意：顺序变更会让历史模板错位，新增列只能追加到末尾。
     *
     * @return array
     */
    public static function importLabels()
    {
        return array(
            'title' => 'product_title',
            'category' => 'product_category',
            'price' => 'product_price',
            'promote_price' => 'price_promote',
            'stock' => 'product_stock',
            'point' => 'product_point',
            'model' => 'product_model',
            'sort' => 'sort',
            'status' => 'status',
            'slug' => 'slug',
        );
    }

    /**
     * 导入列名顺序。
     *
     * @return array
     */
    public static function importFields()
    {
        return array_keys(self::importLabels());
    }

    /**
     * 导入表头行（第一行按表头跳过，不参与入库）。
     *
     * @return array
     */
    public static function importHead()
    {
        $head = array();
        foreach (self::importLabels() as $langKey) {
            $head[] = lang($langKey);
        }

        return $head;
    }
}
