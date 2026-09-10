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

namespace Dou\Core\Support;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 命名转换纯工具
 *
 * 与 Arr / Str / Num / Check 同层的无状态静态工具；不依赖 Config / 容器 / 请求上下文。
 * 收编原 RouteResolver / PrettyRouteMatcher 中重复的 studly / baseModule 实现，作为路由
 * 入站解析与出站生成共用的命名转换单一来源。
 */
class Naming
{
    /**
     * snake_case 段 → Studly 类名片段（如 ai_model → AiModel）
     *
     * @param string $name
     * @return string
     */
    public static function studly($name)
    {
        $parts = explode('_', strtolower((string) $name));
        $out = '';
        foreach ($parts as $p) {
            if ($p !== '') {
                $out .= ucfirst($p);
            }
        }
        return $out;
    }

    /**
     * 去掉 _category 后缀得到主模块名（如 article_category → article）
     *
     * @param string $module
     * @return string
     */
    public static function baseModule($module)
    {
        $module = (string) $module;
        return (substr($module, -9) === '_category') ? substr($module, 0, -9) : $module;
    }

    /**
     * 数据库模块名 → URL 展示模块名。
     *
     * DouPHP 历史约定：article 模块对外 URL 段固定为 `news`，其它模块原样。
     *
     * @param string $module 数据库模块名（如 article / product / news_category 的 base）
     * @return string
     */
    public static function urlModule($module)
    {
        return ($module === 'article') ? 'news' : (string) $module;
    }

    /**
     * URL 展示模块名 → 数据库模块名（与 {@see urlModule} 互逆）。
     *
     * @param string $urlName URL 第一段
     * @return string
     */
    public static function dbModule($urlName)
    {
        return ($urlName === 'news') ? 'article' : (string) $urlName;
    }
}
