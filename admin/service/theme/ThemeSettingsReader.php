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
 *       "product_img": {"width": 1000, "height": 1000, "note": "补充提示", "tip": "手工文案"}
 *   }
 * 其中 width/height 为裁剪工具预填的固定尺寸；tip 存在时覆盖自动生成的文字说明。
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
        'case' => 'cases_img',
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
     *                     item = ['width' => int, 'height' => int, 'note' => string, 'tip' => string]
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
            $items[$key] = array(
                'width' => $this->positiveInt(isset($value['width']) ? $value['width'] : 0),
                'height' => $this->positiveInt(isset($value['height']) ? $value['height'] : 0),
                'note' => isset($value['note']) ? (string) $value['note'] : '',
                'tip' => isset($value['tip']) ? (string) $value['tip'] : '',
            );
        }

        return $items;
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
