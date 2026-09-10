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

namespace Dou\Core\Service\Ai;

use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 图像尺寸归一化。
 *
 * 前端统一传入 WxH 尺寸，各图像模型按自己的协议消费。本类负责把 WxH 适配成
 * 具体模型要求的格式与合法取值，核心原则：尺寸绝不成为生成失败的原因，
 * 且模型无关 —— 新增模型只在 config/ai.php 的 image_size 登记策略，不改任何调用方逻辑。
 *
 * - range 策略（范围型模型）：单边 [min, max] 内任意组合、总像素封顶，
 *   钳制原则「能大则大」：合规原样保留；超上限等比缩小；低于下限等比放大；
 *   极端比例超出模型可达范围时钳到极限比例（保方向）
 * - presets 策略（枚举型模型）：预设值就近映射（宽高比为主、面积为辅）
 * - 未登记模型：返回空串，驱动侧丢弃 size，由上游使用默认尺寸，任务照常成功
 */
class ImageSizeNormalizer
{
    /**
     * 归一化尺寸。
     *
     * @param string $modelCode 模型 code（config['model_code']）
     * @param string $size 前端传入的尺寸（WxH，可为空）
     * @param string $providerCode 协议/提供商标识（config['provider_code']，用于协议级默认策略）
     * @return string 该模型要求的合法尺寸字符串；无策略可适配时返回空串（调用方应丢弃 size）
     */
    public static function normalize($modelCode, $size, $providerCode = '')
    {
        $size = trim((string) $size);
        if ($size === '') {
            return '';
        }

        $policy = self::resolvePolicy((string) $modelCode, (string) $providerCode);
        if (!$policy) {
            return '';
        }

        $sep = $policy['separator'];
        $size = preg_replace('/[xX*]/', $sep, $size);

        // range 策略：范围内任意组合（如万相、qwen-image 等范围型模型）
        if (!empty($policy['range']) && is_array($policy['range'])) {
            if (preg_match('/^(\d+)\s*' . preg_quote($sep, '/') . '\s*(\d+)$/', $size, $m)) {
                return self::normalizeByRange($policy['range'], (int) $m[1], (int) $m[2], $sep, $policy['fallback']);
            }

            return $policy['fallback'];
        }

        $presets = $policy['presets'];
        if (!$presets || in_array($size, $presets, true)) {
            return $size;
        }

        if (!preg_match('/^(\d+)\s*' . preg_quote($sep, '/') . '\s*(\d+)$/', $size, $m)) {
            return $policy['fallback'];
        }

        $w = (int) $m[1];
        $h = (int) $m[2];
        $ratio = $w / max(1, $h);
        $area = $w * $h;

        $best = $policy['fallback'];
        $bestScore = PHP_INT_MAX;
        foreach ($presets as $preset) {
            if (!preg_match('/^(\d+)\s*' . preg_quote($sep, '/') . '\s*(\d+)$/', $preset, $pm)) {
                continue; // auto 等非数值预设跳过
            }
            $pw = (int) $pm[1];
            $ph = (int) $pm[2];
            // 先按宽高比差异（主要），再按面积差异（次要）取就近
            $score = abs($ratio - ($pw / max(1, $ph))) * 1000 + abs($area - ($pw * $ph)) / 10000;
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $preset;
            }
        }

        return $best;
    }

    /**
     * 策略解析：精确 model_code → 家族通配（fnmatch）→ 协议级默认 → 无。
     *
     * @param string $modelCode
     * @param string $providerCode
     * @return array|null
     */
    private static function resolvePolicy($modelCode, $providerCode)
    {
        $policies = self::policies();
        if (isset($policies[$modelCode])) {
            return $policies[$modelCode];
        }
        foreach ($policies as $pattern => $policy) {
            if (strpos($pattern, '*') !== false && fnmatch($pattern, $modelCode)) {
                return $policy;
            }
        }
        $defaults = self::protocolDefaults();
        if ($providerCode !== '' && isset($defaults[$providerCode])) {
            return $defaults[$providerCode];
        }

        return null;
    }

    /**
     * @return array
     */
    private static function policies()
    {
        $block = Config::get('ai.image_size', array());
        if (!is_array($block) || !isset($block['policies']) || !is_array($block['policies'])) {
            return array();
        }

        return $block['policies'];
    }

    /**
     * @return array
     */
    private static function protocolDefaults()
    {
        $block = Config::get('ai.image_size', array());
        if (!is_array($block) || !isset($block['protocol_defaults']) || !is_array($block['protocol_defaults'])) {
            return array();
        }

        return $block['protocol_defaults'];
    }

    /**
     * 范围型钳制（模型无关）：合规原样保留（能大则大），否则等比缩放到恰好合规；
     * 低于下限等比放大；极端比例超出模型可达范围时钳到极限比例（保方向）。
     *
     * @param array $range min_side / max_side / max_pixels
     * @param int $w
     * @param int $h
     * @param string $sep
     * @param string $fallback
     * @return string
     */
    private static function normalizeByRange(array $range, $w, $h, $sep, $fallback)
    {
        $min = max(1, (int) $range['min_side']);
        $max = max($min, (int) $range['max_side']);
        $maxPixels = max($min * $min, (int) $range['max_pixels']);

        if ($w < 1 || $h < 1) {
            return $fallback;
        }

        // 已在范围内：原样保留
        if ($w >= $min && $w <= $max && $h >= $min && $h <= $max && $w * $h <= $maxPixels) {
            return $w . $sep . $h;
        }

        $rw = $w;
        $rh = $h;

        // 超上限（单边或总像素）：等比缩小到恰好合规，取最大可用尺寸
        $scale = min($max / max($rw, $rh), sqrt($maxPixels / max(1, $rw * $rh)), 1.0);
        if ($scale < 1) {
            $rw = (int) round($rw * $scale);
            $rh = (int) round($rh * $scale);
        }

        // 低于下限：等比放大到下限
        if (min($rw, $rh) < $min) {
            $up = $min / max(1, min($rw, $rh));
            $rw = (int) round($rw * $up);
            $rh = (int) round($rh * $up);

            // 放大后仍突破上限 → 极端比例超出模型可达范围，钳到极限比例（保方向）
            if (max($rw, $rh) > $max || $rw * $rh > $maxPixels) {
                if ($w >= $h) {
                    $rw = $max;
                    $rh = $min;
                } else {
                    $rw = $min;
                    $rh = $max;
                }
            }
        }

        return $rw . $sep . $rh;
    }
}
