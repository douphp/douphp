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

namespace Dou\Core\Service\Currency;

use Dou\Core\Domain\Currency\CurrencyCatalog;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 货币上下文与金额换算服务。
 *
 * 双币种口径：
 * - 基准币：`site.currency_base_code`（来自 config.currency_code，= 默认语言货币），
 *   商品价格存储、钱包/积分/分销、后台统计均以它为口径；
 * - 订单币：当前副语言绑定币种（`site.currency_code` + `currency_rate`），
 *   前台展示与实际收款以它为准，订单创建时快照到订单行。
 *
 * 汇率方向固定为「1 基准币 = rate 个目标币」。本类只读 Config，不查库：
 * 上下文由 {@see \Dou\Core\Service\Config\SiteConfigAssembler} 注入，CLI/早期调用
 * 安全回退基准币。
 *
 * 金额精度：业务金额（订单/支付/退款）一律按 2 位小数四舍五入存储；
 * 展示精度取上下文 `decimals`（管理员可配，上限为货币 ISO 小数位）。
 */
class CurrencyService extends BaseService
{
    /**
     * 基准币代码（config.currency_code）。
     *
     * @return string
     */
    public function baseCode()
    {
        $code = strtoupper(trim((string) Config::get('site.currency_base_code', '')));
        if ($code === '' || !CurrencyCatalog::has($code)) {
            return 'CNY';
        }

        return $code;
    }

    /**
     * 基准币展示小数位（config.currency_decimal，非法值回退 ISO 值）。
     *
     * @return int
     */
    public function baseDecimals()
    {
        $code = $this->baseCode();
        $decimals = $this->normalizeDecimals($code, Config::get('site.currency_base_decimal', ''));

        return $decimals === null ? CurrencyCatalog::defaultDecimals($code) : $decimals;
    }

    /**
     * 基准币上下文。
     *
     * @return array
     */
    public function baseContext()
    {
        $code = $this->baseCode();
        $decimals = $this->baseDecimals();
        $symbol = CurrencyCatalog::symbol($code);

        return array(
            'code' => $code,
            'rate' => 1.0,
            'decimals' => $decimals,
            'symbol' => $symbol,
            'base_code' => $code,
            'base_decimals' => $decimals,
            'base_symbol' => $symbol,
        );
    }

    /**
     * 当前展示/订单币上下文（副语言激活时取语言行注入值，否则 = 基准币）。
     *
     * @return array
     */
    public function currentContext()
    {
        $base = $this->baseContext();
        $code = strtoupper(trim((string) Config::get('site.currency_code', '')));
        if ($code === '' || !CurrencyCatalog::has($code) || $code === $base['code']) {
            return $base;
        }

        $rate = (float) Config::get('site.currency_rate', 0);
        if ($rate <= 0) {
            return $base;
        }

        $decimals = $this->normalizeDecimals($code, Config::get('site.currency_decimal', ''));
        if ($decimals === null) {
            $decimals = CurrencyCatalog::defaultDecimals($code);
        }

        return $this->buildContext($code, $rate, $decimals, $base);
    }

    /**
     * 按订单快照构建上下文（历史订单冻结汇率/小数位）。
     *
     * @param array $order
     * @return array
     */
    public function contextForOrder($order)
    {
        if (!is_array($order)) {
            return $this->baseContext();
        }

        return $this->contextFromRow($order);
    }

    /**
     * 按售后单快照构建上下文。
     *
     * @param array $row
     * @return array
     */
    public function contextForAftersale($row)
    {
        if (!is_array($row)) {
            return $this->baseContext();
        }

        return $this->contextFromRow($row);
    }

    /**
     * 订单应付金额（订单币口径）：新订单取 currency_order_amount，旧单回退 order_amount。
     *
     * @param array $order
     * @return float
     */
    public function orderAmount(array $order)
    {
        $code = isset($order['currency_code']) ? trim((string) $order['currency_code']) : '';
        if ($code !== '' && isset($order['currency_order_amount'])) {
            return round((float) $order['currency_order_amount'], 2);
        }

        return round((float) (isset($order['order_amount']) ? $order['order_amount'] : 0), 2);
    }

    /**
     * 按币种代码构建上下文（汇率/小数位缺省时取目录默认值）。
     *
     * @param string $code
     * @param float $rate
     * @param mixed $decimals
     * @return array
     */
    public function contextForCode($code, $rate = 1, $decimals = '')
    {
        $base = $this->baseContext();
        $code = strtoupper(trim((string) $code));
        if ($code === '' || !CurrencyCatalog::has($code)) {
            return $base;
        }

        if ($code === $base['code']) {
            return $base;
        }

        $rate = (float) $rate;
        if ($rate <= 0) {
            $rate = 1;
        }

        $decimals = $this->normalizeDecimals($code, $decimals);
        if ($decimals === null) {
            $decimals = CurrencyCatalog::defaultDecimals($code);
        }

        return $this->buildContext($code, $rate, $decimals, $base);
    }

    /**
     * 上下文是否即基准币。
     *
     * @param array $ctx
     * @return bool
     */
    public function isBase($ctx)
    {
        $baseCode = isset($ctx['base_code']) && $ctx['base_code'] !== '' ? $ctx['base_code'] : $this->baseCode();
        return isset($ctx['code']) && $ctx['code'] === $baseCode;
    }

    /**
     * 当前展示币代码。
     *
     * @return string
     */
    public function currentCode()
    {
        $ctx = $this->currentContext();
        return $ctx['code'];
    }

    /**
     * 货币符号。
     *
     * @param string $code
     * @return string
     */
    public function symbol($code)
    {
        return CurrencyCatalog::symbol($code);
    }

    /**
     * 基准币 → 目标币（业务金额固定 2 位小数）。
     *
     * @param mixed $amount
     * @param array|null $ctx
     * @return float
     */
    public function fromBase($amount, $ctx = null)
    {
        if ($ctx === null) {
            $ctx = $this->currentContext();
        }

        $amount = (float) $amount;
        if ($this->isBase($ctx)) {
            return round($amount, 2);
        }

        return round($amount * (float) $ctx['rate'], 2);
    }

    /**
     * 目标币 → 基准币（业务金额固定 2 位小数）。
     *
     * @param mixed $amount
     * @param array|null $ctx
     * @return float
     */
    public function toBase($amount, $ctx = null)
    {
        if ($ctx === null) {
            $ctx = $this->currentContext();
        }

        $amount = (float) $amount;
        if ($this->isBase($ctx) || (float) $ctx['rate'] <= 0) {
            return round($amount, 2);
        }

        return round($amount / (float) $ctx['rate'], 2);
    }

    /**
     * 按上下文格式化目标币金额（不再换算）。
     *
     * @param mixed $amount
     * @param array $ctx
     * @return string
     */
    public function formatIn($amount, $ctx)
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        $number = number_format((float) $amount, (int) $ctx['decimals'], '.', '');
        // 尾零只在存在小数点时去除：零小数币种 number_format(100, 0) = "100"，不能裁成 "1"
        if (strpos($number, '.') !== false) {
            $number = rtrim(rtrim($number, '0'), '.');
        }
        if ($number === '-0') {
            $number = '0';
        }

        return $ctx['symbol'] . $number;
    }

    /**
     * 基准币金额 → 当前展示币格式化。
     *
     * @param mixed $baseAmount
     * @param array|null $ctx
     * @return string
     */
    public function formatFromBase($baseAmount, $ctx = null)
    {
        if ($baseAmount === null || $baseAmount === '') {
            return '';
        }

        if ($ctx === null) {
            $ctx = $this->currentContext();
        }

        return $this->formatIn($this->fromBase($baseAmount, $ctx), $ctx);
    }

    /**
     * 基准币金额格式化（钱包/余额/统计等基准币场景）。
     *
     * @param mixed $baseAmount
     * @return string
     */
    public function formatBase($baseAmount)
    {
        return $this->formatIn($baseAmount, $this->baseContext());
    }

    /**
     * 从请求行（订单/售后）构建上下文；无币种快照时回退基准币。
     *
     * @param array $row
     * @return array
     */
    private function contextFromRow($row)
    {
        $base = $this->baseContext();
        $code = isset($row['currency_code']) ? strtoupper(trim((string) $row['currency_code'])) : '';
        if ($code === '' || !CurrencyCatalog::has($code)) {
            return $base;
        }

        $baseCode = isset($row['base_currency_code']) ? strtoupper(trim((string) $row['base_currency_code'])) : '';
        if ($baseCode === '' || !CurrencyCatalog::has($baseCode)) {
            $baseCode = $base['code'];
        }

        $baseDecimals = isset($row['base_currency_decimal']) && $row['base_currency_decimal'] !== ''
            ? $this->normalizeDecimals($baseCode, $row['base_currency_decimal'])
            : null;
        if ($baseDecimals === null) {
            $baseDecimals = $baseCode === $base['code'] ? $base['decimals'] : CurrencyCatalog::defaultDecimals($baseCode);
        }
        $baseSymbol = CurrencyCatalog::symbol($baseCode);

        if ($code === $baseCode) {
            return array(
                'code' => $code,
                'rate' => 1.0,
                'decimals' => $baseDecimals,
                'symbol' => $baseSymbol,
                'base_code' => $baseCode,
                'base_decimals' => $baseDecimals,
                'base_symbol' => $baseSymbol,
            );
        }

        $rate = isset($row['currency_rate']) ? (float) $row['currency_rate'] : 0;
        if ($rate <= 0) {
            $rate = 1;
        }

        $decimals = isset($row['currency_decimal']) && $row['currency_decimal'] !== ''
            ? $this->normalizeDecimals($code, $row['currency_decimal'])
            : null;
        if ($decimals === null) {
            $decimals = CurrencyCatalog::defaultDecimals($code);
        }

        return array(
            'code' => $code,
            'rate' => $rate,
            'decimals' => $decimals,
            'symbol' => CurrencyCatalog::symbol($code),
            'base_code' => $baseCode,
            'base_decimals' => $baseDecimals,
            'base_symbol' => $baseSymbol,
        );
    }

    /**
     * 组装上下文数组。
     *
     * @param string $code
     * @param float $rate
     * @param int $decimals
     * @param array $base
     * @return array
     */
    private function buildContext($code, $rate, $decimals, $base)
    {
        return array(
            'code' => $code,
            'rate' => (float) $rate,
            'decimals' => (int) $decimals,
            'symbol' => CurrencyCatalog::symbol($code),
            'base_code' => $base['code'],
            'base_decimals' => $base['decimals'],
            'base_symbol' => $base['symbol'],
        );
    }

    /**
     * 小数位归一化：非法/缺失返回 null，合法返回 int。
     *
     * @param string $code
     * @param mixed $decimals
     * @return int|null
     */
    private function normalizeDecimals($code, $decimals)
    {
        if ($decimals === null || $decimals === '' || !preg_match('/^[0-9]+$/', (string) $decimals)) {
            return null;
        }

        $decimals = (int) $decimals;
        if (!CurrencyCatalog::isValidDecimals($code, $decimals)) {
            return null;
        }

        return $decimals;
    }
}
