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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台 / 小程序会员鉴权中间件基类（模板方法）。
 *
 * front 与 api 两端的会员鉴权流程同构，差异仅在「身份解析所用 guard」与「拒绝方式
 * （redirect vs JSON）」。基类承载共有骨架：
 *   路由段提取 -> {@see UserAuthPolicy} 决策 -> 三态分支（public / optional / required）
 *   -> 身份注入 -> work 子策略 -> 放行
 * 子类只暴露 guard 选择、配置文件路径与拒绝响应。
 *
 * admin 端使用独立鉴权模型（session + AdminGate 权限轴），不继承本类。
 *
 * 基类工作在 HTTP 边界（中间件），可使用 request() helper；auth() 调用全部下放到子类
 * 以保持显式 guard。
 */
abstract class AbstractUserAuthMiddleware implements MiddlewareInterface, ParameterizedMiddleware
{
    /** @var array auth_modes 配置表 */
    protected $authModes;

    /** @var array work_required 配置表 */
    protected $workRequired;

    /**
     * 路由级鉴权模式覆盖（public|optional|required）；非 null 时优先于 auth_modes 配置表决策。
     *
     * @var string|null
     */
    protected $routeModeOverride = null;

    /**
     * 注入路由级鉴权参数：$params[0] = 鉴权模式 public|optional|required。
     *
     * @param string[] $params
     * @return void
     */
    public function setRouteParameters(array $params)
    {
        $mode = isset($params[0]) ? strtolower(trim((string) $params[0])) : '';
        if (in_array($mode, array('public', 'optional', 'required'), true)) {
            $this->routeModeOverride = $mode;
        }
    }

    public function __construct()
    {
        $config = $this->loadConfig();
        $this->authModes = isset($config['auth_modes']) && is_array($config['auth_modes'])
            ? $config['auth_modes']
            : array();
        $this->workRequired = isset($config['work_required']) && is_array($config['work_required'])
            ? $config['work_required']
            : array();
    }

    /**
     * 子类返回配置文件绝对路径（如 FRONT_PATH . 'init/middleware.php'）。
     *
     * @return string
     */
    abstract protected function configFile();

    /**
     * 解析当前请求的会员上下文。约定返回数组至少含 `ok` 字段（bool）。
     *
     * @return array
     */
    abstract protected function resolveContext();

    /**
     * 将解析得到的上下文注入对应 Auth Guard 的身份缓存。
     *
     * @param array $context
     * @return void
     */
    abstract protected function inject(array $context);

    /**
     * 当前已登录身份是否具备有效的 work 身份。
     *
     * @return bool
     */
    abstract protected function hasWorkIdentity();

    /**
     * 拒绝未登录请求；子类按端形态选择 redirect 或 JSON。
     * 实现须抛出 HttpResponseException 或 exit，永远不返回。
     *
     * @return void
     */
    abstract protected function rejectUnauthenticated();

    /**
     * 拒绝无 work 身份请求；子类按端形态选择 redirect 或 JSON。
     * 实现须抛出 HttpResponseException 或 exit，永远不返回。
     *
     * @return void
     */
    abstract protected function rejectForbidden();

    /**
     * @param callable $next
     * @return mixed 控制器/下游中间件返回值，原样冒泡（由 {@see MiddlewarePipeline} 串接）
     */
    public function handle($next)
    {
        $request = request();
        $module = (string) $request->routeModule();
        $action = $request->routeAction() !== '' ? (string) $request->routeAction() : 'default';
        $sub = (string) $request->routeSub();
        $parent = '';
        if (strpos($module, '_') !== false) {
            $parts = explode('_', $module, 2);
            $parent = isset($parts[0]) ? (string) $parts[0] : '';
            if ($sub === '') {
                $sub = isset($parts[1]) ? (string) $parts[1] : '';
            }
        }

        $decision = UserAuthPolicy::resolve($module, $action, $sub, $parent, $this->authModes, $this->workRequired);
        $mode = $this->routeModeOverride !== null ? $this->routeModeOverride : $decision['mode'];
        $workRequired = (bool) $decision['workRequired'];

        // public：匿名直达，不解析登录态、不注入
        if ($mode === 'public') {
            return $next();
        }

        $context = $this->resolveContext();
        $isOk = isset($context['ok']) && $context['ok'] === true;

        if (!$isOk) {
            if ($mode === 'required') {
                $this->rejectUnauthenticated();
                return null;
            }
            // optional + 未登录：放行匿名访问
            return $next();
        }

        $this->inject($context);

        if ($workRequired && !$this->hasWorkIdentity()) {
            $this->rejectForbidden();
            return null;
        }

        return $next();
    }

    /**
     * @return array
     */
    private function loadConfig()
    {
        $file = $this->configFile();
        if (!file_exists($file)) {
            return array();
        }

        $config = require $file;
        return is_array($config) ? $config : array();
    }
}
