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

namespace Dou\Admin\Service\Theme;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 读取主题 inc/..setting.php 键值（每行 key:value 格式）。
 *
 * 用于后台填充 setting.theme.*_img_size 等模块缩略图尺寸提示。
 */
class ThemeSettingsReader extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 读取主题 inc/..setting.php。
     *
     * @param string $theme 空则取当前站点主题
     * @return array|false 文件不存在返回 false；存在返回 [theme => [key => value, ...]]
     */
    public function read($theme = '')
    {
        $themeSetting = array();
        $theme = $theme ? $theme : Config::get('site.site_theme', '');
        $settingFile = ROOT_PATH . 'theme/' . $theme . '/inc/..setting.php';
        if (!file_exists($settingFile)) {
            return false;
        }

        $content = file($settingFile);
        foreach ((array) $content as $line) {
            $line = trim($line);
            if (strpos($line, ':') !== false) {
                $arr = explode(':', $line);
                $themeSetting['theme'][$arr[0]] = $arr[1];
            }
        }

        return $themeSetting;
    }
}
