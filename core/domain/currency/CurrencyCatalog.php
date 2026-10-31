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

namespace Dou\Core\Domain\Currency;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * ISO 4217 货币目录（代码级静态表）。
 *
 * 只收录中文站点常用的 0 / 2 位小数币种：订单金额列统一 decimal(12,2)，
 * 3 位小数币种（KWD/BHD 等）与冷门币种不纳入，避免精度扩散与后台选项膨胀。
 *
 * `decimals` 是该币种的 ISO 参考小数位，同时作为后台小数位配置的**上限**：
 * 超过 ISO 小数位会让零小数币种在网关侧按错误最小单位收款（如 JPY 配 2 位）。
 */
class CurrencyCatalog
{
    /**
     * 货币定义表：code => name / symbol / decimals（ISO 参考值 = 允许上限）。
     */
    const CURRENCIES = array(
        'CNY' => array('name' => '人民币', 'symbol' => '￥', 'decimals' => 2),
        'USD' => array('name' => '美元', 'symbol' => '$', 'decimals' => 2),
        'EUR' => array('name' => '欧元', 'symbol' => '€', 'decimals' => 2),
        'GBP' => array('name' => '英镑', 'symbol' => '£', 'decimals' => 2),
        'JPY' => array('name' => '日元', 'symbol' => 'JP¥', 'decimals' => 0),
        'HKD' => array('name' => '港币', 'symbol' => 'HK$', 'decimals' => 2),
        'TWD' => array('name' => '新台币', 'symbol' => 'NT$', 'decimals' => 2),
        'KRW' => array('name' => '韩元', 'symbol' => '₩', 'decimals' => 0),
        'AUD' => array('name' => '澳元', 'symbol' => 'A$', 'decimals' => 2),
        'CAD' => array('name' => '加元', 'symbol' => 'C$', 'decimals' => 2),
        'SGD' => array('name' => '新加坡元', 'symbol' => 'S$', 'decimals' => 2),
    );

    /**
     * 全部货币定义。
     *
     * @return array code => ['name','symbol','decimals']
     */
    public static function all()
    {
        return self::CURRENCIES;
    }

    /**
     * 货币是否存在。
     *
     * @param string $code
     * @return bool
     */
    public static function has($code)
    {
        return isset(self::CURRENCIES[strtoupper(trim((string) $code))]);
    }

    /**
     * 是否为「独立货币」：币种合法、非空且不同于基准币。
     *
     * 语言行仅以 currency_code 是否有值判断是否启用独立货币（不另设开关字段）：
     * 留空或等于基准币即视为未启用，前台展示与收款完全按基准币处理。
     *
     * @param string $code 语言行配置的币种
     * @param string $baseCode 基准币（config.currency_code 归一结果）
     * @return bool
     */
    public static function isIndependent($code, $baseCode)
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || !self::has($code)) {
            return false;
        }

        $baseCode = strtoupper(trim((string) $baseCode));
        if ($baseCode === '' || !self::has($baseCode)) {
            $baseCode = 'CNY';
        }

        return $code !== $baseCode;
    }

    /**
     * 货币信息；未知币种回退为「代码 + 代码符号 + 2 位小数」。
     *
     * @param string $code
     * @return array ['code','name','symbol','decimals']
     */
    public static function info($code)
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            $code = 'CNY';
        }

        if (isset(self::CURRENCIES[$code])) {
            $row = self::CURRENCIES[$code];
            return array(
                'code' => $code,
                'name' => $row['name'],
                'symbol' => $row['symbol'],
                'decimals' => $row['decimals'],
            );
        }

        return array(
            'code' => $code,
            'name' => $code,
            'symbol' => $code . ' ',
            'decimals' => 2,
        );
    }

    /**
     * 货币符号。
     *
     * @param string $code
     * @return string
     */
    public static function symbol($code)
    {
        $info = self::info($code);
        return $info['symbol'];
    }

    /**
     * 货币名称。
     *
     * @param string $code
     * @return string
     */
    public static function name($code)
    {
        $info = self::info($code);
        return $info['name'];
    }

    /**
     * ISO 参考小数位（也是后台可配置的上限）。
     *
     * @param string $code
     * @return int
     */
    public static function defaultDecimals($code)
    {
        $info = self::info($code);
        return (int) $info['decimals'];
    }

    /**
     * 小数位是否合法：整数且在 0 ~ ISO 参考值之间。
     *
     * @param string $code
     * @param mixed $decimals
     * @return bool
     */
    public static function isValidDecimals($code, $decimals)
    {
        if ($decimals === null || $decimals === '' || !preg_match('/^[0-9]+$/', (string) $decimals)) {
            return false;
        }

        $decimals = (int) $decimals;
        return $decimals >= 0 && $decimals <= self::defaultDecimals($code);
    }

    /**
     * 后台下拉选项：code => "CNY 人民币 ￥"。
     *
     * @return array ['value','name'] 列表
     */
    public static function options()
    {
        $options = array();
        foreach (self::CURRENCIES as $code => $row) {
            $options[] = array(
                'value' => $code,
                'name' => $code . ' ' . $row['name'] . ' ' . $row['symbol'],
            );
        }

        return $options;
    }
}
