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

namespace Dou\Core\Service\Config;

use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 站点配置装配器（Domain 读模型）。
 *
 * 输入：rootUrl、当前语言、是否为后台调用方。
 * 输出：站点配置数组（root_url、home_url、theme_url、weixin_img、site_logo、
 *      多语言字段覆盖；前台/API 场景额外解析 qq、net_safe_record_number）。
 *
 * 仅读库与字段拼装，不写入 Config；由 {@see \Dou\Core\Bootstrap\SiteBootstrap} 调用并写入。
 */
class SiteConfigAssembler extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 装配站点配置。
     *
     * 当前请求根 URL（DB.config.domain 为空时的 fallback）由 shell 层 Init 从
     * `Request::baseUrl()` 取好后显式传入，core 层无 HTTP 感知；当前语言从 Locale 单例读取。
     *
     * @param bool $publicFormatting 是否做对外站点的额外格式化（QQ、备案号）；后台传 false
     * @param string $rootUrl 当前请求根 URL，由 shell 层传入；DB.config.domain 为空时作 fallback
     * @return array
     */
    public function assemble($publicFormatting, $rootUrl = '')
    {
        $rootUrl = (string) $rootUrl;
        $currentLang = locale()->toArray();
        $config = array();

        $rows = DB::table('config')->select();
        foreach ((array) $rows as $row) {
            $config[$row['name']] = $row['value'];
        }

        $_ROOT_URL = !empty($config['domain']) ? $config['domain'] : $rootUrl;
        $_M_URL = $_ROOT_URL . M_DIR . '/';

        if ($publicFormatting) {
            if (!empty($config['qq'])) {
                $config['qq'] = Util::parseQqList($config['qq']);
            } else {
                $config['qq'] = array();
            }

            if (!empty($config['net_safe_record'])) {
                if (preg_match('/\d+/', $config['net_safe_record'], $arr)) {
                    $config['net_safe_record_number'] = $arr[0];
                }
            }
        }

        $fileUpdateTime = isset($config['file_update_time']) ? $config['file_update_time'] : 0;

        if (!empty($config['weixin_img'])) {
            $config['weixin_img'] = $_ROOT_URL . 'images/upload/' . $config['weixin_img'] . attachment()->cacheTag($fileUpdateTime);
        }

        $siteLogo = DB::table('config')->where('name', 'site_logo')->value('value');
        if (!empty($currentLang) && isset($currentLang['pack']) && $currentLang['pack'] !== '') {
            $langLogo = DB::table('language')->where('language_pack', $currentLang['pack'])->value('site_logo');
            if ($langLogo) {
                $siteLogo = $langLogo;
            }
        }
        $config['site_logo'] = ($siteLogo ? $siteLogo : '') . attachment()->cacheTag($fileUpdateTime);

        $config['root_url'] = $_ROOT_URL;
        $config['m_url'] = $_M_URL;
        $config['admin_url'] = $_ROOT_URL . ADMIN_DIR . '/';
        $siteTheme = isset($config['site_theme']) ? $config['site_theme'] : '';
        $config['theme_url'] = $_ROOT_URL . 'theme/' . $siteTheme . '/';

        if (!empty($currentLang)) {
            if (isset($currentLang['mode']) && $currentLang['mode'] === 'rewrite_open') {
                $config['home_url'] = $_ROOT_URL . $currentLang['sign'] . '/';
            } else {
                $config['home_url'] = $_ROOT_URL . '?lang=' . $currentLang['pack'];
            }

            $langFields = explode(',', 'site_name,site_title,site_keywords,site_description,address,tel,fax,email');
            $langConfig = DB::table('language')->where('language_pack', $currentLang['pack'])->find();
            if (is_array($langConfig)) {
                foreach ($langFields as $field) {
                    if (!empty($langConfig[$field])) {
                        $config[$field] = $langConfig[$field];
                    }
                }
            }
        } else {
            $config['home_url'] = $_ROOT_URL;
        }

        return $config;
    }

    /**
     * 读取 parameter 表为 name => value 形式（启动期 Config::set('param', ...) 使用）。
     *
     * @return array
     */
    public function parameter()
    {
        $parameter = array();
        $query = DB::query("SELECT name, value, `group` FROM " . DB::tableName('parameter'));
        while ($row = DB::fetchArray($query)) {
            $parameter[$row['name']] = $row['value'];
        }

        return $parameter;
    }
}
