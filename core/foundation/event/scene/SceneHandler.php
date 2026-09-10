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
 * 场景处理器接口。
 *
 * 实现者通常作为一个普通 Service，由 {@see SceneRegistry} 在分发对应场景时
 * 通过工厂闭包按需构造并调用 {@see handle()}。
 */
interface SceneHandler
{
    /**
     * 处理一个场景事件。
     *
     * @param string $scene 场景名（如 {@see SceneNames::ORDER_PAID}）
     * @param array $payload 场景载荷
     * @return void
     */
    public function handle($scene, array $payload);
}
