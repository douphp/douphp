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
 * 模块 / 菜单 slug → Bootstrap Icons 类名映射。
 *
 * 后台侧栏与前台 / 小程序会员中心动态菜单共用同一张表。
 */
class MenuIconMap
{
    /** @var string */
    const DEFAULT_ICON = 'bi-grid';

    /**
     * slug => bi-* 类名（与 bootstrap-icons.css / 小程序 iconfont.wxss 对应）。
     *
     * @var array<string, string>
     */
    private static $map = array(
        '_default' => 'bi-grid',
        'home' => 'bi-house',
        'setting' => 'bi-gear',
        'nav' => 'bi-diagram-3',
        'show' => 'bi-pie-chart',
        'page' => 'bi-filter-square',
        'product-cat' => 'bi-hdd-stack',
        'product' => 'bi-grid-3x3-gap',
        'article-cat' => 'bi-grid-1x2',
        'article' => 'bi-list',
        'ai' => 'bi-robot',
        'doc-cat' => 'bi-window-dock',
        'doc' => 'bi-filetype-doc',
        'manager' => 'bi-person-gear',
        'manager-log' => 'bi-grip-vertical',
        'backup' => 'bi-database-gear',
        'link' => 'bi-link',
        'guestbook' => 'bi-marker-tip',
        'health' => 'bi-heart-pulse',
        'user' => 'bi-people',
        'book' => 'bi-journal-bookmark',
        'order' => 'bi-bag',
        'plugin' => 'bi-plugin',
        'menu-page' => 'bi-hammer',
        'theme' => 'bi-palette',
        'cases-cat' => 'bi-x-diamond',
        'cases' => 'bi-hammer',
        'case-cat' => 'bi-x-diamond',
        'case' => 'bi-hammer',
        'download-cat' => 'bi-file-arrow-down',
        'download' => 'bi-file-earmark-arrow-down',
        'miniprogram' => 'bi-wechat',
        'weixin' => 'bi-wechat',
        'chat' => 'bi-chat',
        'work' => 'bi-person-workspace',
        'faq' => 'bi-question-square',
        'team' => 'bi-microsoft-teams',
        'aftersale' => 'bi-speedometer',
        'coupon' => 'bi-cash-coin',
        'tag' => 'bi-hand-thumbs-up',
        'vote' => 'bi-mouse2',
        'comment' => 'bi-chat-left-quote',
        'certificate' => 'bi-layers',
        'service' => 'bi-radar',
        'job' => 'bi-suit-club',
        'partner' => 'bi-lightbulb',
        'support' => 'bi-key',
        'attribute' => 'bi-info',
        'solution' => 'bi-disc',
        'language' => 'bi-globe',
        'sms' => 'bi-phone-vibrate',
        'data' => 'bi-grip-vertical',
        'video' => 'bi-play-circle',
        'form' => 'bi-file-medical',
        'landing' => 'bi-box-arrow-in-right',
        'consultation' => 'bi-keyboard',
        'professional' => 'bi-plus-square',
        'course' => 'bi-mortarboard',
        'box' => 'bi-box',
        'gallery' => 'bi-images',
        'onepic' => 'bi-file-image',
        'sn' => 'bi-123',
        'dh' => 'bi-bag-plus',
        'store' => 'bi-shop',
        'quotation' => 'bi-send-dash-fill',
        'equipment' => 'bi-wrench-adjustable-circle',
        'item' => 'bi-view-list',
        'favorites' => 'bi-bookmark-plus',
        'brand' => 'bi-lightbulb',
        'distribution' => 'bi-cup-hot',
        'vip' => 'bi-gem',
        'share' => 'bi-share',
        'money' => 'bi-wallet',
        'email' => 'bi-envelope',
        'dedetodouphp' => 'bi-egg-fill',
        'point' => 'bi-dice-1',
        'withdraw' => 'bi-cash',
        'area' => 'bi-pin-map',
        'fragment' => 'bi-three-dots',
        'excel' => 'bi-file-earmark-excel',
    );

    /**
     * 按菜单 slug 解析 Bootstrap Icons 类名。
     *
     * @param string $slug 模块名或菜单键（如 product、product-cat、menu-page）
     * @return string bi-* 类名
     */
    public static function resolve($slug)
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return self::DEFAULT_ICON;
        }
        if (isset(self::$map[$slug])) {
            return self::$map[$slug];
        }

        return self::DEFAULT_ICON;
    }

    /**
     * 返回完整 slug → bi-* 映射（供模板静态项与校验脚本使用）。
     *
     * @return array<string, string>
     */
    public static function all()
    {
        return self::$map;
    }
}
