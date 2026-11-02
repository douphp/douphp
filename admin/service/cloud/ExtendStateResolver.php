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

namespace Dou\Admin\Service\Cloud;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 云端扩展列表安装状态推导（模块 / 插件 / 模板 / 小程序共用）。
 *
 * 云端 `/extend/list` 只在 items[].action.kind 内表达本站安装情况，本类把它收敛为
 * 模板可直接使用的三态标识，供卡片做徽标、边框色、按钮高亮等差异化展示。
 */
class ExtendStateResolver
{
    /**
     * 推导单条扩展在本站的安装状态。
     *
     * 依据云端 action.kind：installed 为已装且最新，update / buy_then_update 为已装待更新；
     * vip_renew / vip_buy 同一 kind 兼用于「续费后更新」与「续费后安装」，据文案是否含「更新」区分。
     *
     * @param array<string, mixed> $item 云端扩展列表单条
     * @return string installed|update|install
     */
    public static function resolve(array $item)
    {
        $kind = isset($item['action']['kind']) ? (string) $item['action']['kind'] : '';
        $label = isset($item['action']['label']) ? (string) $item['action']['label'] : '';

        if ($kind === 'installed') {
            return 'installed';
        }
        if ($kind === 'update' || $kind === 'buy_then_update') {
            return 'update';
        }
        if ($kind === 'vip_renew' || $kind === 'vip_buy') {
            return (strpos($label, '更新') !== false) ? 'update' : 'install';
        }

        return 'install';
    }
}
