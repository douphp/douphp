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

namespace Dou\Front\Service\Sort;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台列表「排序选项」ViewModel。
 *
 * 由 Controller / Service 构造注入；只返回 `field`（用于模板）+ `sql`（用于查询）数组，
 * 不做 Smarty assign，也不持有运行时状态。
 */
class ListSortOptionBuilder extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 构建排序选项与对应 SQL 片段。
     *
     * @param string $fields 可点击排序字段，逗号分隔（如 `sales, price, created_at, sort`）
     * @param string $options 支持「方向切换」的字段，逗号分隔（如 `price`）
     * @param string $default_sort 默认 SQL 排序片段（如 `p.sort ASC, p.id DESC`）
     * @param string $by 当前选中字段
     * @param string $sort 当前方向 asc/desc
     * @param string $page_url 翻页 URL 前缀
     * @param string $table_alias 表别名（如 `p`），用于 SQL 拼接
     * @return array `field` => [...], `sql` => string
     */
    public function buildSortOptions($fields, $options, $default_sort, $by = '', $sort = '', $page_url = '', $table_alias = '')
    {
        $by = Check::basicString($by) ? trim($by) : '';
        $sort = Check::letter($sort) ? trim($sort) : '';

        $field_array = explode(',', str_replace(' ', '', 'default, ' . $fields));
        if (!Config::get('features.order', false)) {
            $key = array_search('sales', $field_array);
            unset($field_array[$key]);
        }

        $options_array = explode(',', str_replace(' ', '', $options));
        $data = array('field' => array(), 'sql' => '');
        foreach ($field_array as $field) {
            if ($field == $by && $sort && in_array($field, $options_array)) {
                $new_s = $sort == 'asc' ? 'desc' : 'asc';
            } else {
                $new_s = $field == 'sort' ? 'asc' : 'desc';
            }

            $data['field'][] = array(
                'name' => lang('sort_' . $field),
                'active' => $field == $by || (!$by && $field == 'default') ? true : false,
                'url' => Util::normalizeQueryString($page_url . ($field != 'default' ? "&by=$field" : '') . (in_array($field, $options_array) ? '&sort=' . $new_s : '')),
                'param' => array('by' => $field, 'sort' => $new_s),
                'icon' => in_array($field, $options_array) ? ($field == $by ? ($sort == 'desc' ? 'down' : 'up') : 'down') : '',
            );
        }

        // 如果有表别名，添加前缀
        $prefix = $table_alias ? $table_alias . '.' : '';

        if ($by && $sort && $by != 'default') {
            $data['sql'] = $prefix . "$by " . strtoupper($sort) . ', ' . $prefix . 'id DESC';
        } else {
            $data['sql'] = $default_sort;
        }

        return $data;
    }
}
