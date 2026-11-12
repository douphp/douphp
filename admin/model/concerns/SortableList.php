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

namespace Dou\Admin\Model\Concerns;

use Dou\Core\Orm\Builder;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台列表排序 concern。
 *
 * 只接受调用方显式传入的字段白名单，公共层不维护任何模块清单。
 * 未指定排序或字段不在白名单内时，回退到按添加顺序（id DESC）排序。
 */
trait SortableList
{
    /**
     * 解析列表排序 SQL。
     *
     * @param string $field
     * @param string $order
     * @param array $allowed
     * @param string $default
     * @return string
     */
    public static function resolveListOrder($field, $order, array $allowed, $default = 'id DESC')
    {
        $field = is_string($field) ? trim($field) : '';
        $order = is_string($order) ? strtolower(trim($order)) : '';

        if ($field === '' || !in_array($field, $allowed, true)) {
            return $default;
        }

        $sql = $field . ' ' . ($order === 'desc' ? 'DESC' : 'ASC');
        if ($field !== 'id') {
            $sql .= ', id DESC';
        }

        return $sql;
    }

    /**
     * 列表排序作用域。
     *
     * @param Builder $query
     * @param string $field
     * @param string $order
     * @param array $allowed
     * @return Builder
     */
    public function scopeApplyListOrder(Builder $query, $field = '', $order = '', array $allowed = array())
    {
        return $query->order(self::resolveListOrder($field, $order, $allowed));
    }
}
