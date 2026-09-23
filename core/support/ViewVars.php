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

use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Smarty assign 前补全缺省键，避免 PHP 8+ 未定义数组下标。
 *
 * 无状态纯静态调用，兼容 PHP 5.6+。
 */
class ViewVars
{
    /**
     * 仅补缺失键（已有键含 null 不覆盖）
     *
     * @param array $data
     * @param array $defaults
     * @return array
     */
    public static function fill(array $data, array $defaults)
    {
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $default;
            }
        }

        return $data;
    }

    /**
     * 站点参数：模板 {$param.xxx} 常用键占位
     *
     * @param array $param Config::get('param')
     * @return array
     */
    public static function param(array $param)
    {
        $defaults = array(
            'login_mode' => 'email',
            'sms_accessKeyId' => '',
            'comment_allow_no_order' => '',
            'book_tips' => '',
            'money_recharge_agreement' => '',
            'order_quick_buy' => '',
        );

        return self::fill($param, $defaults);
    }

    /**
     * 模块开关：供 Smarty $features.xxx 使用。
     *
     * @param array $features Config::get('features')
     * @return array
     */
    public static function features(array $features)
    {
        $keys = array(
            'language', 'item', 'link', 'data', 'box', 'fragment', 'sort', 'slug',
            'user', 'point', 'money', 'vip', 'attribute', 'brand', 'book',
            'favorites', 'comment', 'order', 'distribution', 'aftersale', 'work',
            'area', 'product', 'article', 'plugin', 'coupon', 'miniprogram', 'sms',
        );
        $allModule = (array) Config::get('module.all_module', array());
        if (!empty($allModule)) {
            $keys = array_merge($keys, $allModule);
        }
        $keys = array_unique($keys);
        $normalized = array();
        foreach ($keys as $key) {
            $normalized[$key] = !empty($features[$key]);
        }
        foreach ($features as $key => $value) {
            if (!array_key_exists($key, $normalized)) {
                $normalized[$key] = (bool) $value;
            }
        }

        return $normalized;
    }

    /**
     * 从主题尺寸 hint 抽出宽/高。
     * 「宽度>=1920、高度400」或文案中第一处 1000*1000 / 750x500 →「1920/400」。
     *
     * @param string $text
     * @return string 对不上则空串
     */
    public static function parseWidthHeightHint($text)
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }
        if (preg_match('/宽度[^\d]*(\d+).*高度[^\d]*(\d+)/u', $text, $m)) {
            $width = (int) $m[1];
            $height = (int) $m[2];
        } elseif (preg_match('/(\d+)\s*[*x×]\s*(\d+)/u', $text, $m)) {
            $width = (int) $m[1];
            $height = (int) $m[2];
        } else {
            return '';
        }
        if ($width <= 0 || $height <= 0) {
            return '';
        }

        return $width . '/' . $height;
    }

    /**
     * 后台 $setting.theme：消费 ThemeSettingsReader 标准化 item 表，
     * 派生旧契约键 {key}_size（文字说明）与 {key}_crop（裁剪预设比例 "宽/高"）。
     *
     * 文字说明：tip 优先原样输出；否则由 width/height 套语言包模板自动生成并追加 note。
     * 主题缺键时补空串，避免 PHP 8 未定义下标。
     *
     * @param array $theme 标准化 item 表（key => ['width','height','note','tip']）
     * @return array
     */
    public static function theme(array $theme)
    {
        $defaults = array(
            'article_img_size' => '',
            'doc_img_size' => '',
            'professional_img_size' => '',
            'case_img_size' => '',
            'cases_img_size' => '',
            'banner_img_size' => '',
            'product_img_size' => '',
        );

        $out = array();
        foreach ($theme as $key => $item) {
            if (!is_string($key) || $key === '' || !is_array($item)) {
                continue;
            }
            $width = isset($item['width']) ? (int) $item['width'] : 0;
            $height = isset($item['height']) ? (int) $item['height'] : 0;
            $note = isset($item['note']) ? (string) $item['note'] : '';
            $tip = isset($item['tip']) ? (string) $item['tip'] : '';

            $text = '';
            if ($tip !== '') {
                $text = $tip;
            } elseif ($width > 0 && $height > 0) {
                $text = str_replace(
                    array('{w}', '{h}'),
                    array((string) $width, (string) $height),
                    lang('theme_size_fixed', '推荐尺寸：{w}×{h}px')
                );
                if ($note !== '') {
                    $text .= lang('theme_size_note_sep', '，') . $note;
                }
            }

            $out[$key . '_size'] = $text;
            $out[$key . '_crop'] = ($width > 0 && $height > 0) ? $width . '/' . $height : '';
        }

        $out = self::fill($out, $defaults);
        foreach (array_keys($defaults) as $key) {
            $cropKey = substr($key, 0, -5) . '_crop';
            if (!array_key_exists($cropKey, $out)) {
                $out[$cropKey] = '';
            }
        }

        if ($out['case_img_size'] !== '' && $out['cases_img_size'] === '') {
            $out['cases_img_size'] = $out['case_img_size'];
            $out['cases_img_crop'] = isset($out['case_img_crop']) ? $out['case_img_crop'] : '';
        }

        return $out;
    }

    /**
     * 前台 $dou 命名空间：auth / user / vip 等子数组补全模板会访问的键。
     *
     * @param array $dou 原始装配数据
     * @return array
     */
    public static function dou(array $dou)
    {
        $user = (isset($dou['user']) && is_array($dou['user'])) ? $dou['user'] : array();
        $auth = (isset($dou['auth']) && is_array($dou['auth'])) ? $dou['auth'] : array();
        $vip = (isset($dou['vip']) && is_array($dou['vip'])) ? $dou['vip'] : array();
        $work = isset($dou['work']) ? $dou['work'] : array();
        $distribution = (isset($dou['distribution']) && is_array($dou['distribution'])) ? $dou['distribution'] : array();
        if (!is_array($work)) {
            $work = array();
        }

        return array(
            'user' => self::fill($user, array(
                'user_name' => '',
                'level_name' => '',
            )),
            'auth' => self::fill($auth, array(
                'is_login' => false,
                'is_vip' => false,
                'is_work' => false,
                'is_distribution' => false,
            )),
            'vip' => self::fill($vip, array(
                'start_at' => '',
                'end_at' => '',
                'status' => '',
                'text' => '',
            )),
            'work' => $work,
            'distribution' => $distribution,
        );
    }

    /**
     * 订单行/详情：模板 {$order.xxx} 常用键占位（可选支付/售后/优惠券未装时）。
     *
     * @param array $order
     * @return array
     */
    public static function orderForTemplate(array $order)
    {
        $defaults = array(
            'cashier_url' => '',
            'if_can_aftersale' => false,
            'coupon' => false,
            'aftersale_url' => '',
            'status_logs' => array(),
            'status_class' => 'info',
        );

        return self::fill($order, $defaults);
    }

    /**
     * 语言包安全取值（PHP 业务代码用，避免 Undefined array key）。
     *
     * @param array $lang
     * @param string $key
     * @param string $fallback
     * @return string
     */
    public static function langOr(array $lang, $key, $fallback = '')
    {
        return isset($lang[$key]) ? $lang[$key] : $fallback;
    }
}
