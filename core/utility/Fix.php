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

namespace Dou\Core\Utility;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 补丁
 *
 */
class Fix
{
    /**
     * 为沿用旧式 URL 形态的模板构建映射补丁。
     *
     * 当前模块名由 shell 层从 Request::routeModule() 取好显式传入，core 层无 HTTP 感知。
     *
     * @param string $currentModule 当前请求的 route module（如 'user'、'order'）；为空则不追加模块特定 sub/act 映射
     * @return array
     */
    public function buildLegacyUrlMap($currentModule = '')
    {
        $url = $this->buildBaseUrlMap();
        $url = $this->appendControllerRouteMap($url, (string) $currentModule);

        return $url;
    }

    /**
     * 构建 douUrl 基础映射。
     *
     * @return array
     */
    private function buildBaseUrlMap()
    {
        $url = array();
        $columnModule = (array) Config::get('module.column_module', array());
        $singleModule = (array) Config::get('module.single_module', array());

        foreach ($columnModule as $moduleId) {
            $url[$moduleId] = route($moduleId . '.category');
        }

        foreach ($singleModule as $moduleId) {
            $url[$moduleId] = route($moduleId);
        }

        $url['cart'] = route('order.cart');

        foreach (explode('|', 'login|register|logout|edit|password|sns|order|order_list|vip_log|contact') as $value) {
            $url[$value] = route('user.' . $value);
        }

        $url['order_insert'] = route('order.cart.store');
        $url['order_cashier'] = route('order.cashier');

        $url['root_url'] = ROOT_URL;
        $url['home_url'] = HOME_URL;
        $url['favorites_store'] = route('favorites.user.store');
        $url['coupon_claim'] = route('coupon.claim');
        $url['consultation_insert'] = route('consultation.store');
        $url['captcha'] = route('captcha');
        $url['search'] = route('search');

        $data = DB::fnQuery("SELECT id, slug FROM " . DB::tableName('page') . " ORDER BY id ASC");
        if (is_array($data)) {
            $page = array();
            foreach ($data as $value) {
                $page[$value['slug']] = route('page.show', ['id' => $value['id']]);
            }
            $url['page'] = $page;
        }

        return $url;
    }

    /**
     * 追加当前模块的 sub/act URL 映射。
     *
     * @param array $url
     * @param string $currentModule shell 层传入的当前模块名（如 'user'）；空则不追加模块特定映射
     * @return array
     */
    private function appendControllerRouteMap(array $url, $currentModule = '')
    {
        $routeMap = array(
            'user' => 'login|login_post|login_phone|login_phone_post|register|register_post'
                . '|edit|edit_post|password|password_post'
                . '|verification|verification_post'
                . '|password_reset|password_reset_post'
                . '|telphone|telphone_post|email|email_post'
                . '|logout|order_list|order|order_cancel'
                . '|sns|sns_link|area|filebox|filedel|promotion_qrcode|contact',
            'order' => 'cart|insert|checkout|update|del|success|cashier|charge|pay_evidence|cod'
                . '|change_shipping|use_coupon|contact_list|contact_select',
            'share' => 'user|apply|apply_post|filebox|filedel',
            'favorites' => 'user',
            'money' => 'user|work|use',
            'vip' => 'success|user',
            'dh' => 'addcart|change|del|cart|success',
            'book' => 'list|class|detail|date|time|schedule|contact|booking|cancel|user|view|work',
            'withdraw' => 'user',
            'sn' => 'search',
            'point' => 'detail|user',
            'landing' => 'submit',
            'form' => 'submit',
            'distribution' => 'user|apply|apply_post|people',
            'coupon' => 'claim|user',
            'comment' => 'user',
            'ai' => 'chat|stream|user|package|history|quota_check|new_chat|get_sessions|get_records|save_record|switch_chat',
            'aftersale' => 'user',
            'vote' => 'detail|item|rank|view|submit|qrcode',
        );

        $currentModule = (string) $currentModule;
        if ($currentModule !== '' && isset($routeMap[$currentModule])) {
            $url = $this->appendSubRoutes($url, $currentModule, $routeMap[$currentModule]);
        }

        if ($currentModule === 'user') {
            $url = $this->appendActRoutes($url, 'user', 'contact', 'create|store|edit|update|destroy|set|list_json|info');
        }

        return $url;
    }

    /**
     * 兼容 douSubUrl 结构（sub + 顶层同名 key）。
     *
     * @param array $url
     * @param string $module
     * @param string $subValues
     * @return array
     */
    private function appendSubRoutes(array $url, $module, $subValues)
    {
        if (!isset($url['sub'])) {
            $url['sub'] = array();
        }

        foreach (explode('|', $subValues) as $sub) {
            $routeUrl = route($module . '.' . $sub);

            if (!isset($url['sub'][$sub])) {
                $url['sub'][$sub] = $routeUrl;
            }

            if (!isset($url[$sub])) {
                $url[$sub] = $routeUrl;
            }
        }

        return $url;
    }

    /**
     * 兼容 douActUrl 结构（act）。
     *
     * @param array $url
     * @param string $module
     * @param string $rec
     * @param string $actValues
     * @return array
     */
    private function appendActRoutes(array $url, $module, $rec, $actValues)
    {
        if (!isset($url['act'])) {
            $url['act'] = array();
        }

        foreach (explode('|', $actValues) as $act) {
            $url['act'][$act] = route($module . '.' . $rec . '.' . $act);
        }

        return $url;
    }
}
