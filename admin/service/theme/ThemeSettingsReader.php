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
 * 读取主题 `ext/setting.json` 并标准化为结构数组。
 *
 * 文件格式（JSON 对象，键为声明项）：
 *   {
 *       "product_img": {"width": 1000, "height": 1000, "tip": "提示文案"},
 *       "banner_img": {"width": {"min": 1920}, "height": 400, "tip": "宽度不小于 1920"}
 *   }
 * width/height 支持纯数字（精确值）或 {"min": 55, "max": 200}（区间，可只写一侧）；
 * 标准化后取代表值供裁剪预填与限宽使用：有 max 取 max，否则取 min。
 * tip 为唯一提示文案，原样输出；缺省时不显示。
 *
 * 用于后台填充 setting.theme.*_img_size 等模块缩略图尺寸提示与裁剪预设。
 */
class ThemeSettingsReader extends BaseService
{
    /**
     * 后台上传模块 → 主题图片尺寸配置键映射（上传限宽配套用）。
     */
    const MODULE_IMAGE_KEYS = array(
        'product' => 'product_img',
        'article' => 'article_img',
        'cases' => 'cases_img',
        'doc' => 'doc_img',
        'professional' => 'professional_img',
        'show' => 'banner_img',
        'page' => 'banner_img',
    );

    /**
     */
    public function __construct()
    {
    }

    /**
     * 读取主题 `ext/setting.json`。
     *
     * @param string $theme 空则取当前站点主题
     * @return array|false 文件不存在或 JSON 非法时返回 false；否则返回 ['theme' => [key => item]]，
     *                     item = ['width' => int, 'height' => int, 'width_min' => int, 'width_max' => int,
     *                             'height_min' => int, 'height_max' => int, 'tip' => string]
     */
    public function read($theme = '')
    {
        $theme = $theme ? $theme : Config::get('site.site_theme', '');
        $settingFile = ROOT_PATH . 'theme/' . $theme . '/ext/setting.json';
        if (!is_file($settingFile)) {
            return false;
        }

        $content = @file_get_contents($settingFile);
        if ($content === false || trim($content) === '') {
            return false;
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return false;
        }

        return array('theme' => $this->normalizeItems($decoded));
    }

    /**
     * 后台模块主图的主题配置宽度（0 表示未配置），供上传限宽配套取 max(site.image_width, 本值)。
     *
     * @param string $module 上传模块名（product / article / cases ...）
     * @return int
     */
    public function imageWidthForModule($module)
    {
        $module = (string) $module;
        // PHP 5.6 不允许 isset() 直接作用于类常量下标，先取到局部变量再判断
        $imageKeys = self::MODULE_IMAGE_KEYS;
        if (!isset($imageKeys[$module])) {
            return 0;
        }

        $setting = $this->read();
        if (!is_array($setting) || !isset($setting['theme'][$imageKeys[$module]])) {
            return 0;
        }

        return (int) $setting['theme'][$imageKeys[$module]]['width'];
    }

    /**
     * 新标准格式数组 → 标准化 item 表。
     *
     * @param array $raw 主题文件 return 的数组
     * @return array
     */
    private function normalizeItems(array $raw)
    {
        $items = array();
        foreach ($raw as $key => $value) {
            $key = $this->normalizeKey($key);
            if ($key === '' || !is_array($value)) {
                continue;
            }
            $width = $this->normalizeDimension(isset($value['width']) ? $value['width'] : 0);
            $height = $this->normalizeDimension(isset($value['height']) ? $value['height'] : 0);
            $items[$key] = array(
                'width' => $width['value'],
                'height' => $height['value'],
                'width_min' => $width['min'],
                'width_max' => $width['max'],
                'height_min' => $height['min'],
                'height_max' => $height['max'],
                'tip' => isset($value['tip']) ? (string) $value['tip'] : '',
            );
        }

        return $items;
    }

    /**
     * 宽/高声明标准化。
     *
     * 纯数字为精确值；{"min": N, "max": N} 为区间，缺省一侧表示不限。
     * value 为代表值：有 max 取 max，否则取 min（供裁剪预填与限宽使用）。
     *
     * @param mixed $value
     * @return array ['value' => int, 'min' => int, 'max' => int]
     */
    private function normalizeDimension($value)
    {
        if (is_array($value)) {
            $min = $this->positiveInt(isset($value['min']) ? $value['min'] : 0);
            $max = $this->positiveInt(isset($value['max']) ? $value['max'] : 0);

            return array(
                'value' => $max > 0 ? $max : $min,
                'min' => $min,
                'max' => $max,
            );
        }

        $exact = $this->positiveInt($value);

        return array('value' => $exact, 'min' => $exact, 'max' => $exact);
    }

    /**
     * 键名标准化：去掉旧格式遗留的 _size 后缀（product_img_size → product_img）。
     *
     * @param string $key
     * @return string
     */
    private function normalizeKey($key)
    {
        $key = trim((string) $key);
        if (substr($key, -5) === '_size') {
            $key = substr($key, 0, -5);
        }

        return $key;
    }

    /**
     * @param mixed $value
     * @return int 非正数返回 0
     */
    private function positiveInt($value)
    {
        $value = (int) $value;

        return $value > 0 ? $value : 0;
    }
}
