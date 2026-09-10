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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 路由表导出器（按端导出 name => pattern 映射）
 *
 * 供小程序命名路由机制使用：后台同步时把 api 端 declared 条目导成 name → pattern 表，
 * 写入各 slug 的 utils/routes.generated.ts，小程序 route(name, params) 据此按名取址。
 *
 * 只取 declared 条目（pattern 已无元变量、可直接填占位符），按端命名空间过滤，
 * 剥去 api 端冗余的 `api.` 前缀（api-only 上下文前缀无歧义）。
 */
class RouteTableExporter
{
    /**
     * 导出指定端的命名路由表。
     *
     * @param string $end 端命名空间，'Api' | 'Front' | 'Admin'（见 RouteEntry::endNamespace）
     * @return array name(剥 api. 前缀) => pattern，按 key 升序
     */
    public static function exportForEnd($end = 'Api')
    {
        $table = array();
        foreach (RouteManifest::getEntries() as $entry) {
            if ($entry->route_type !== 'declared') {
                continue;
            }
            if ($entry->endNamespace() !== $end) {
                continue;
            }
            if ($entry->name === null || $entry->name === '') {
                continue;
            }
            $key = (strpos($entry->name, 'api.') === 0) ? substr($entry->name, 4) : $entry->name;
            $table[$key] = $entry->pattern;
        }
        ksort($table);
        return $table;
    }
}
