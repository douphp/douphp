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

namespace Dou\Core\Foundation\Auth;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 多 Guard 认证管理器。
 *
 * 三端 Init 在启动阶段以 {@see extend()} 注册自身 guard 工厂。
 * **没有「默认 guard」概念**：调用 {@see guard()} 必须显式传名（admin / front / api），
 * 业务调用面统一通过 `auth('admin'|'front'|'api')` 完整写法消费，让「当前调的是哪端」
 * 在调用点直接可读。
 *
 * 各端 guard 实现：
 * - admin：{@see \Dou\Admin\Service\Auth\AuthService}（实现 StatefulGuardContract）
 * - front：{@see \Dou\Front\Facade\Auth}（实现 StatefulGuardContract）
 * - api：{@see \Dou\Api\Facade\Auth}（实现 GuardContract）
 *
 * user 模块为可选安装：features.user 关闭或 Auth Facade 实现类缺席时，front / api Init 以
 * {@see GuestGuard} 兜底注册同名 guard，保证 `auth('front')` / `auth('api')` 永远可解析
 * 到合法的只读 GuardContract 实例。
 *
 * 容器以单例形态持有本类（由 Container::singleton(AuthManager::class) 注册）。
 */
class AuthManager
{
    /** @var array<string, callable> guard 名 → 工厂闭包 */
    private $factories = array();

    /** @var array<string, object> guard 名 → 已解析实例（首次 guard() 后缓存） */
    private $resolved = array();

    /**
     * 注册 guard 工厂。
     *
     * 工厂闭包签名：`function(): object`，返回端实现实例（如 admin AuthService、front Auth）。
     * 同名重复注册会覆盖前一次工厂并清空已缓存的实例。
     *
     * @param string $name
     * @param callable $factory
     * @return $this
     */
    public function extend($name, $factory)
    {
        $name = (string) $name;
        if (!is_callable($factory)) {
            throw new \InvalidArgumentException('AuthManager::extend() factory must be callable.');
        }
        $this->factories[$name] = $factory;
        unset($this->resolved[$name]);
        return $this;
    }

    /**
     * 是否已注册指定 guard。
     *
     * @param string $name
     * @return bool
     */
    public function has($name)
    {
        return isset($this->factories[(string) $name]);
    }

    /**
     * 解析并返回指定 guard 实例（首次解析后缓存）。
     *
     * **必须显式传 $name**：null / 空字符串均拒绝。未注册的 guard 名直接抛错。
     *
     * @param string $name
     * @return object
     * @throws \InvalidArgumentException 当 $name 为 null / 空字符串
     * @throws \RuntimeException 当指定 guard 未注册或工厂返回非对象
     */
    public function guard($name)
    {
        if ($name === null || $name === '' || !is_string($name)) {
            throw new \InvalidArgumentException(
                'AuthManager::guard() requires an explicit guard name (admin|front|api); '
                . 'shorthand auth() without a guard name is forbidden.'
            );
        }

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (!isset($this->factories[$name])) {
            throw new \RuntimeException('AuthManager guard [' . $name . '] is not registered.');
        }

        $instance = call_user_func($this->factories[$name]);
        if (!is_object($instance)) {
            throw new \RuntimeException('AuthManager guard [' . $name . '] factory did not return an object.');
        }

        $this->resolved[$name] = $instance;
        return $instance;
    }

    /**
     * 测试或重建场景：强制刷新指定 guard 缓存（保留工厂闭包）。
     *
     * @param string|null $name 不传则清空全部
     * @return void
     */
    public function forget($name = null)
    {
        if ($name === null) {
            $this->resolved = array();
            return;
        }
        unset($this->resolved[(string) $name]);
    }
}
