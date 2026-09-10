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
 * CSRF 校验中间件基类（模板方法）
 *
 * 在管道层统一执行 CSRF 令牌校验：
 *   路由段提取 → 候选键构造 → except 豁免 → 方法/GET-token 判定 → tokenIdFor 解析令牌 id
 *   → csrf()->verify | check → 失败 reject
 *
 * 校验触发条件（满足其一）：
 * - 改写型方法（POST/PUT/PATCH/DELETE）：一律校验；
 * - GET-token 路由（{@see getTokenRoutes()} 命中）：带 token 的幂等链接（如取消预约、删除收藏），GET 也校验。
 *
 * 令牌经 {@see \Dou\Core\Web\Http\Request::csrfToken()} 多源读取：优先 body / query 的 token 字段
 * （原生 form / hidden input / 一次性 token dual-POST 流程），回退 HTTP Header X-CSRF-Token / X-XSRF-Token
 * （AJAX 全局拦截器注入的 static_xxx token）。服务端不规定客户端必须把 token 塞在哪里。
 *
 * AJAX 表单预检（X-Requested-With: XMLHttpRequest）：走 csrf()->check() **校验不消费**；
 * 非 AJAX 请求走 csrf()->verify() **校验+消费一次性令牌**。理由是 dou.js 中 douSubmit()
 * 实现的 dual-POST 模式——先 AJAX 验证再用同一令牌走原生表单 submit；若中间件在
 * AJAX 阶段消费一次性令牌，原生 submit 必撞「非法操作」（一次性令牌只能用一次）。
 *
 * 子类负责端特有的：令牌 id 选择（{@see tokenIdFor()}）、豁免名单（{@see except()}）、
 * 需校验 GET 的路由（{@see getTokenRoutes()}）、拒绝响应（{@see reject()}）。
 *
 * 注：基类工作在 HTTP 边界（中间件），可使用 request() / csrf() helper。
 */
abstract class AbstractCsrfMiddleware implements MiddlewareInterface
{
    /**
     * 改写型 HTTP 方法：一律要求 CSRF 令牌。
     *
     * @var array
     */
    private static $stateChangingMethods = array('POST', 'PUT', 'PATCH', 'DELETE');

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
        }

        $candidates = $this->buildCandidates($module, $action, $sub, $parent);

        // 豁免名单：完全跳过 CSRF（如外部支付 / 微信回调，外部来源无 session 令牌）
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $this->except(), true)) {
                return $next();
            }
        }

        $stateChanging = in_array($request->method(), self::$stateChangingMethods, true);
        $getTokenRoute = false;
        if (!$stateChanging) {
            $tokenRoutes = $this->getTokenRoutes();
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $tokenRoutes, true)) {
                    $getTokenRoute = true;
                    break;
                }
            }
        }

        // 既非改写方法、又非 GET-token 路由：放行不校验
        if (!$stateChanging && !$getTokenRoute) {
            return $next();
        }

        $id = (string) $this->tokenIdFor($module, $action, $sub, $candidates);
        $token = (string) $request->csrfToken();

        // AJAX 走 check()：仅校验存在不消费一次性令牌（dou.js dual-POST 预检阶段，紧接的
        // 原生 submit 仍需用同一令牌）。非 AJAX 走 verify()：校验+消费（防重放）。
        // 静态令牌（static_ 前缀）verify() 本就不删除，对它来说 check() 与 verify() 等价。
        $ok = $request->isAjax()
            ? csrf()->check($token, $id)
            : csrf()->verify($token, $id);

        if (!$ok) {
            // 仅拒绝当前请求，不清 SESSION：CSRF 误触（双击 / prefetch / 过期）不应导致
            // 用户登录态失效；一次性令牌的防重放由 csrf()->verify 内部完成（成功才消费）。
            $this->reject();
            return null;
        }

        return $next();
    }

    /**
     * 解析当前路由应校验的令牌 id。
     *
     * @param string $module 当前模块（routeModule）
     * @param string $action 当前动作（routeAction，空时为 'default'）
     * @param string $sub 当前子段（routeSub）
     * @param array $candidates 已构造的候选键（精确 > 粗粒度）
     * @return string 令牌 id（如 static_admin / static_user / 一次性表单 id）
     */
    abstract protected function tokenIdFor($module, $action, $sub, array $candidates);

    /**
     * 完全跳过 CSRF 的路由键集合（外部回调等）。
     *
     * @return array
     */
    protected function except()
    {
        return array();
    }

    /**
     * 需要在 GET 也校验令牌的路由键集合（带 token 的幂等链接）。
     *
     * @return array
     */
    protected function getTokenRoutes()
    {
        return array();
    }

    /**
     * 拒绝非法请求；子类按端形态选择 redirect（front/admin）/ JSON（api）。
     * 实现须抛出异常或 exit，永远不返回。
     *
     * @return void
     */
    abstract protected function reject();

    /**
     * 构造候选键列表（精确 > 父段 > 模块根），顺序与 {@see UserAuthPolicy::buildCandidates} 相同：
     * 复合 module（如 `order_report` / `chat_session`）下也产出 `parent/sub/action` 形态，
     * 方便配置用直觉的「业务路径」（如 `order/report/export`）声明 GET-token 路由 / 豁免名单。
     *
     * @param string $module
     * @param string $action
     * @param string $sub
     * @param string $parent 模块名含下划线时的父段，无则空串
     * @return array
     */
    private function buildCandidates($module, $action, $sub, $parent = '')
    {
        $module = strtolower(trim($module));
        $action = strtolower(trim($action));
        $sub = strtolower(trim($sub));
        $parent = strtolower(trim($parent));

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
        if ($parent !== '' && $sub !== '' && $action !== '') {
            $candidates[] = $parent . '/' . $sub . '/' . $action;
        }
        if ($parent !== '' && $sub !== '') {
            $candidates[] = $parent . '/' . $sub;
        }
        if ($module !== '') {
            $candidates[] = $module;
        }
        if ($parent !== '') {
            $candidates[] = $parent;
        }

        return array_values(array_unique($candidates));
    }
}
