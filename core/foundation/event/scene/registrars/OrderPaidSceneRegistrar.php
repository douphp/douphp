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

namespace Dou\Core\Foundation\Event\Scene\Registrars;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Event\Scene\SceneNames;
use Dou\Core\Foundation\Event\Scene\SceneRegistry;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\Chat\SubscriptionService;
use Dou\Core\Service\Money\MoneyService;
use Dou\Core\Service\Vip\VipService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * order.paid 场景内置处理器注册器。
 *
 * 由 {@see SceneRegistry::dispatch()} 首次触发时调用，根据
 * `module.link_order_item` 站点参数把 VIP / 钱包 / chat 套餐服务挂载到 order.paid 场景。
 *
 * 注册器自身保持幂等。
 */
class OrderPaidSceneRegistrar
{
    /** @var bool */
    private static $registered = false;

    /**
     * 执行注册。
     *
     * @return void
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        $keys = Config::get('module.link_order_item');
        if (!is_array($keys) || empty($keys)) {
            return;
        }

        $map = array(
            'vip' => function () {
                return new VipService();
            },
            'money' => function () {
                return new MoneyService();
            },
            'chat' => function () {
                return new SubscriptionService();
            },
        );

        foreach ($keys as $key) {
            $key = trim((string) $key);
            if ($key !== '' && isset($map[$key]) && Module::has($key)) {
                SceneRegistry::register(SceneNames::ORDER_PAID, $key, $map[$key]);
            }
        }
    }
}
