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

/**
 * 支付回调声明式路由（公开 webhook，靠第三方验签鉴权）。
 */

use Dou\Api\Controller\Pay\PaymentNotifyController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('api.')->group(function () {
    Route::post(
        'pay/{plugin_id}/notify',
        PaymentNotifyController::class,
        'notify',
        'pay',
        'api.pay.notify',
        array('plugin_id' => '[a-zA-Z][a-zA-Z0-9_-]{0,29}')
    );
});
