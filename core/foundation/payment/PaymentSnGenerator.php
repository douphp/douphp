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

namespace Dou\Core\Foundation\Payment;

use Dou\Core\Facade\DB;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 支付流水号生成器。
 *
 * 生成规则：`P` + `YmdHis`（14 位）+ 8 位随机数 = 23 位。
 *
 * 与 order_sn（20 位数字）格式不同，方便日志/排查时一眼区分。重名时回退重生成。
 * 该流水号作为第三方网关的 `out_trade_no` 传入，确保"一个订单多次支付尝试"
 * 在第三方侧也能精准对应到本地的 payment 行。
 */
class PaymentSnGenerator
{
    /**
     * 生成一个唯一的 payment_sn。
     *
     * @return string
     */
    public function generate()
    {
        $sn = 'P' . date('YmdHis') . str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);

        $exists = DB::table('order_payment')
            ->where('payment_sn', $sn)
            ->exists();

        if ($exists) {
            usleep(1000);
            return $this->generate();
        }

        return $sn;
    }
}
