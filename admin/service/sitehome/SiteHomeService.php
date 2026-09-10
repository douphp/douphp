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

namespace Dou\Admin\Service\SiteHome;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 站点首页可视化（route=sitehome）
 */
class SiteHomeService extends BaseService
{
    /**
     * @param string $systemSign
     * @return string
     */
    public function buildSiteLogoUrl($systemSign)
    {
        if ($systemSign === 'api') {
            return ROOT_URL . MINIPROGRAM_DIR . '/' . Config::get('site.miniprogram_code', '') . '/images/' . Config::get('site.site_logo', '');
        }

        return ROOT_URL . 'theme/' . Config::get('site.site_theme', '') . '/images/' . Config::get('site.site_logo', '');
    }

    /**
     * @param string $systemSign
     * @return string
     */
    public function getShowListType($systemSign)
    {
        return $systemSign === 'api' ? 'miniprogram' : 'pc';
    }
}
