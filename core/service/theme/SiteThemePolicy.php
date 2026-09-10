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

namespace Dou\Core\Service\Theme;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 站点主题策略（Domain 读模型）。
 *
 * 判定当前生效的前台主题目录名，以及商业主题（m\d{3}）在未授权 + 正式域名下
 * 是否需要禁用并回退 default。
 */
class SiteThemePolicy extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 当前生效的前台主题目录名。
     *
     * 未授权 + m\d{3} 商业主题 + 正式域名时回退到 default。
     *
     * @return string
     */
    public function effectiveTheme()
    {
        $siteTheme = Config::get('site.site_theme', '');

        if ($this->themeBlocked()) {
            $siteTheme = 'default';
        }

        return $siteTheme;
    }

    /**
     * 是否禁止当前商业主题（未授权 + 商业主题 + 正式域名）。
     *
     * @return bool
     */
    public function themeBlocked()
    {
        if (Config::get('app.licensed', false)) {
            return false;
        }

        $siteTheme = Config::get('site.site_theme', '');
        if (!preg_match('/^m\d{3}$/', $siteTheme)) {
            return false;
        }

        if (!Check::domain(ROOT_URL)) {
            return false;
        }

        return true;
    }
}
