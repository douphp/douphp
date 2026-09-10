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

namespace Dou\Core\Foundation\Middleware;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Infra\Security\ThrottleStore;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 定向限流中间件基类（targeted，默认不限流）。
 *
 * 仅对 {@see throttleFor()} 显式配额的敏感端点（登录 / 注册 / 找回密码 / 短信验证码 / 公共表单提交等）
 * 计数限流；未配额路由直接放行。限流键默认 `module.action.ip`，须置于 TrustProxy 之后以确保 ip 可信。
 *
 * 注：中间件工作在 HTTP 边界，可使用 request() helper。
 */
abstract class AbstractThrottleMiddleware implements MiddlewareInterface, ParameterizedMiddleware
{
    /**
     * 路由级配额覆盖（`array('max' => N, 'window' => seconds)`）；非 null 时优先于 throttleFor 表。
     *
     * @var array|null
     */
    protected $routeLimit = null;

    /**
     * 注入路由级限流参数：$params[0] = max 次数，$params[1] = window 秒数（缺省 60）。
     *
     * @param string[] $params
     * @return void
     */
    public function setRouteParameters(array $params)
    {
        $max = isset($params[0]) ? (int) $params[0] : 0;
        $window = isset($params[1]) ? (int) $params[1] : 60;
        if ($max > 0 && $window > 0) {
            $this->routeLimit = array('max' => $max, 'window' => $window);
        }
    }

    /**
     * @param callable $next
     * @return mixed
     */
    public function handle($next)
    {
        $request = request();
        $module = (string) $request->routeModule();
        $action = $request->routeAction() !== '' ? (string) $request->routeAction() : 'default';
        $sub = (string) $request->routeSub();

        $limit = $this->routeLimit !== null ? $this->routeLimit : $this->throttleFor($module, $action, $sub);
        if (!is_array($limit) || empty($limit['max']) || empty($limit['window'])) {
            return $next();
        }

        $max = (int) $limit['max'];
        $window = (int) $limit['window'];
        $store = $this->store();
        $key = $this->resolveKey($module, $action, $sub, (string) $request->ip());

        if ($store->tooMany($key, $max, $window)) {
            $this->reject($store->availableIn($key));
            return null;
        }
        $store->hit($key, $window);

        return $next();
    }

    /**
     * 返回当前路由的限流配额 `array('max' => N, 'window' => seconds)`，无配额返回 null。
     *
     * @param string $module
     * @param string $action
     * @param string $sub
     * @return array|null
     */
    abstract protected function throttleFor($module, $action, $sub);

    /**
     * 超限拒绝（永不返回：抛异常 / send + exit）。
     *
     * @param int $retryAfter 距窗口重置剩余秒数
     * @return void
     */
    abstract protected function reject($retryAfter);

    /**
     * 限流键：默认 module.action.ip。
     *
     * @param string $module
     * @param string $action
     * @param string $sub
     * @param string $ip
     * @return string
     */
    protected function resolveKey($module, $action, $sub, $ip)
    {
        return $module . '.' . $action . '.' . $ip;
    }

    /**
     * @return ThrottleStore
     */
    protected function store()
    {
        $dir = Config::get('security.throttle.store', STORAGE_PATH . 'cache/throttle/');

        return new ThrottleStore($dir);
    }

    /**
     * 候选路由键（精确 > 父段 > 模块根），供子类 throttleFor 做表匹配。
     *
     * @param string $module
     * @param string $action
     * @param string $sub
     * @return array
     */
    protected function candidates($module, $action, $sub)
    {
        $module = strtolower(trim($module));
        $action = strtolower(trim($action));
        $sub = strtolower(trim($sub));

        $candidates = array();
        if ($module !== '' && $sub !== '' && $action !== '') {
            $candidates[] = $module . '/' . $sub . '/' . $action;
        }
        if ($module !== '' && $sub !== '') {
            $candidates[] = $module . '/' . $sub;
        }
        if ($module !== '' && $action !== '') {
            $candidates[] = $module . '/' . $action;
        }
        if ($module !== '') {
            $candidates[] = $module;
        }

        return array_values(array_unique($candidates));
    }
}
