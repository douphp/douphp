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

namespace Dou\Core\Web\Routing;

use Dou\Core\Facade\DB;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 路由 ID 合法性解析器
 *
 * 提供分类/单页/内容三类 ID 的独立解析方法。
 * -1: 非法或未命中
 *  0: 分类列表（仅 *_category 且 id=0/无参数）
 * >0: 合法内容 ID
 */
class RouteIdValidator
{
    /**
     * 获取分类模块的合法分类 ID
     *
     * @param string $module 分类模块名（如 article_category）
     * @param string $categoryId 数字分类 ID（可选，0 表示全部分类）
     * @param string $slug 分类唯一标识（可选）
     * @param string $year 风格7 归档年（可选；非空时校验落在 1970-2100，否则视为非法 URL 返 -1）
     * @param string $month 风格7 归档月（可选；与 year 配合校验落在 1-12）
     * @return int 分类 ID，0 表示全部分类，-1 表示无效
     */
    public function category($module, $categoryId = '', $slug = '', $year = '', $month = '')
    {
        $categoryId = (string) $categoryId;
        $slug = (string) $slug;
        $year = (string) $year;
        $month = (string) $month;

        // 风格7：URL 里的 year/month 必须落在 Util::parseArchive 的有效范围（年 1970-2100、月 1-12）；
        // 超出即非法 URL，与 slug 错走同一通道。route pattern 已保证格式（年=4 位、月=2 位）。
        if ($year !== '' && Util::parseArchive($year, $month) === array()) {
            return -1;
        }

        // 格式校验
        if (($categoryId !== '' && !Check::number($categoryId)) ||
            ($slug !== '' && !Check::slug($slug))
        ) {
            return -1;
        }

        // 允许 category_id=0 表示全部分类
        if ($categoryId === '0') {
            return 0;
        }

        // 优先使用 slug 查询
        if ($slug !== '') {
            $found = DB::table($module)->where('slug', $slug)->value('id');
            return $found ? (int) $found : -1;
        }

        // 使用 category_id 查询
        if ($categoryId !== '') {
            $found = DB::table($module)->where('id', $categoryId)->value('id');
            return $found ? (int) $found : -1;
        }

        // 没有参数，返回 0（分类列表页）
        return 0;
    }

    /**
     * 获取普通内容模块（如 article、product）的合法 ID
     *
     * @param string $module 模块名（如 article、product）
     * @param string $id 数字 ID（可选）
     * @param string $categorySlug 分类别名（可选，用于验证）
     * @param string $slug 友好 URL（可选）
     * @param string $year 风格7 详情年（可选；非空时与记录 created_at 校验）
     * @param string $month 风格7 详情月（可选；非空时与记录 created_at 校验）
     * @return int 内容 ID，-1 表示无效或不存在
     */
    public function column($module, $id = '', $categorySlug = '', $slug = '', $year = '', $month = '')
    {
        $id = (string) $id;
        $categorySlug = (string) $categorySlug;
        $slug = (string) $slug;
        $year = (string) $year;
        $month = (string) $month;

        // 格式校验
        if (($id !== '' && !Check::number($id)) ||
            ($categorySlug !== '' && !Check::slug($categorySlug)) ||
            ($slug !== '' && !Check::slug($slug))
        ) {
            return -1;
        }

        $final_id = null;

        // 优先通过 slug 获取 ID
        if ($slug !== '') {
            $slug_id = DB::table($module)->where('slug', $slug)->value('id');
            if (!$slug_id) {
                return -1;
            }
            $final_id = (int) $slug_id;
        }

        // 通过 id 获取并检查一致性
        if ($id !== '') {
            $id_exists = DB::table($module)->where('id', $id)->value('id');
            if (!$id_exists) {
                return -1;
            }
            $id_exists = (int) $id_exists;
            if ($final_id !== null && $final_id != $id_exists) {
                return -1; // slug 与 id 不匹配
            }
            $final_id = $id_exists;
        }

        if ($final_id === null) {
            return -1; // 既没有 id 也没有 slug
        }

        // 如果提供了 categorySlug，验证分类别名
        if ($categorySlug !== '') {
            $categoryId = DB::table($module)->where('id', $final_id)->value('category_id');
            if (!$categoryId) {
                return -1;
            }
            $cat_unique = DB::table($module . '_category')
                ->where('id', $categoryId)
                ->value('slug');
            if ($cat_unique != $categorySlug) {
                return -1;
            }
        } elseif (!defined('IS_API') && RouteRules::columnDetailPatternUsesCategorySlug()) {
            // 详情 pattern 含分类别名段时：请求未带 category_slug 仅允许 category_id=0 的内容（无分类）。
            $categoryId = (int) DB::table($module)->where('id', $final_id)->value('category_id');
            if ($categoryId > 0) {
                return -1;
            }
        }

        // 风格7：URL 里的 year/month 必须与记录真实 created_at 对应；created_at=0 放行（兼容缺时间戳的旧记录）。
        // 与 slug / categorySlug 同级校验：不一致即非法 URL，由控制器抛 DomainException 走统一提示页。
        if ($year !== '' || $month !== '') {
            $addTime = (int) DB::table($module)->where('id', $final_id)->value('created_at');
            if ($addTime > 0) {
                if ($year !== '' && (int) date('Y', $addTime) !== (int) $year) {
                    return -1;
                }
                if ($month !== '' && (int) date('m', $addTime) !== (int) $month) {
                    return -1;
                }
            }
        }

        return $final_id;
    }

    /**
     * 获取只存在 ID 的模块合法 ID（如单表模块，无 slug/slug）
     *
     * @param string $module 模块名（如 some_single_table）
     * @param string $id 数字 ID（必填或可选，若空则返回 -1）
     * @return int 内容 ID，-1 表示无效或不存在
     */
    public function single($module, $id = '')
    {
        $id = (string) $id;
        if ($id !== '' && Check::number($id)) {
            $found = DB::table($module)->where('id', $id)->value('id');
            return $found ? (int) $found : -1;
        }
        return -1;
    }

    /**
     * 获取单页面 ID
     *
     * @param string $id 数字 ID（可选）
     * @param string $slug 页面唯一标识（可选）
     * @return int 页面 ID，-1 表示无效或不存在
     */
    public function page($id = '', $slug = '')
    {
        $id = (string) $id;
        $slug = (string) $slug;

        // 优先使用数字 ID
        if ($id !== '' && Check::number($id)) {
            $found = DB::table('page')->where('id', $id)->value('id');
            return $found ? (int) $found : -1;
        }

        // 使用 slug（页面唯一标识）
        if ($slug !== '' && Check::slug($slug)) {
            $found = DB::table('page')->where('slug', $slug)->value('id');
            return $found ? (int) $found : -1;
        }

        return -1;
    }
}
