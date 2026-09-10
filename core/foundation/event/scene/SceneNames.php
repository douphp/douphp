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

namespace Dou\Core\Foundation\Event\Scene;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 场景名常量集合。
 *
 * 统一收敛场景字符串，避免散落硬编码。
 */
class SceneNames
{
    /** 订单支付完成（已写入 paid_at，未必已发货/完成） */
    const ORDER_PAID = 'order.paid';
}
