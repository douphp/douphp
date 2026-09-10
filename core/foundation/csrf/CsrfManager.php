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

namespace Dou\Core\Foundation\Csrf;

use Dou\Core\Facade\Session;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * CSRF 令牌管理器：generate / token / verify。
 * 令牌按 id 存于 Session['token'][$id]（即 $_SESSION[DOU_ID]['token'][$id]）。
 *
 * 本类**不**自渲染、**不**清 session、**不** echo/throw —— 令牌校验失败后的拒绝响应
 * （清 session + 报错 / 跳转）由各端 CsrfMiddleware 按端形态承担，与
 * {@see \Dou\Core\Foundation\Middleware\AbstractUserAuthMiddleware} 的 reject 模式一致。
 *
 * 令牌分两类，由 id 前缀区分：
 * - static_*（如 static_admin / static_user）：共享静态令牌，校验成功**不**删除，跨表单复用；
 * - 其它（如 user_login / guestbook）：一次性表单令牌，校验成功后立即删除，抗重放。
 */
class CsrfManager
{
    /**
     * 生成并写入指定 id 的 CSRF 令牌，返回令牌值。
     *
     * 优先用 CSPRNG 生成 32 hex（128bit）令牌；不可用时回退到 md5(uniqid) 派生。
     *
     * @param string $id 令牌标记（static_admin / static_user / 一次性表单 id）
     * @return string
     */
    public function generate($id)
    {
        if (function_exists('random_bytes')) {
            try {
                $value = bin2hex(random_bytes(16));
            } catch (\Exception $e) {
                $value = substr(md5(uniqid((string) rand(), true)), 0, 16);
            }
        } else {
            $value = substr(md5(uniqid((string) rand(), true)), 0, 16);
        }
        Session::set('token', $value, $id);

        return $value;
    }

    /**
     * 读取指定 id 的当前令牌（不存在返回 null）。
     *
     * @param string $id 令牌标记
     * @return mixed
     */
    public function token($id = 'static_admin')
    {
        return Session::get('token', null, $id);
    }

    /**
     * 确保指定 id 的令牌存在：已存在则复用其值，不存在则就地 generate 一个，返回令牌值。
     *
     * 每个 session（含匿名）从首次进站起就分发一个 CSRF 令牌，模板 meta / 表单 hidden
     * 渲染、前端 AJAX 全局拦截器注入 X-CSRF-Token 都依赖它非空。登录态切换（如
     * {@see \Dou\Core\Service\User\UserAuthService::login()} 内 `generate('static_user')`）
     * 自行调 generate 旋转令牌防 session fixation，与本方法并行不冲突。
     *
     * 仅用于 static_* 类共享令牌；一次性令牌（每次提交后消费、需抗重放）仍需调用方显式
     * 在 GET 阶段 generate。
     *
     * @param string $id 令牌标记（static_admin / static_user 等）
     * @return string
     */
    public function ensure($id)
    {
        $current = $this->token($id);
        if (is_string($current) && $current !== '') {
            return $current;
        }

        return $this->generate($id);
    }

    /**
     * 校验令牌：命中返回 true，未命中返回 false。
     *
     * 一次性令牌（非 static_ 前缀）校验成功后立即删除；静态令牌保留复用。
     * 纯布尔判定：**不**清 session、**不** echo/throw，由调用方（CsrfMiddleware）决定拒绝响应。
     *
     * @param string $token 待校验令牌
     * @param string $id 令牌标记
     * @return bool
     */
    public function verify($token, $id = 'static_admin')
    {
        $id = $id ? $id : 'static_admin';
        if (Session::has('token', $id) && hash_equals((string) Session::get('token', null, $id), (string) $token)) {
            if ($this->isOneTime($id)) {
                Session::del('token', $id);
            }
            return true;
        }

        return false;
    }

    /**
     * 校验令牌但不消费：命中返回 true，未命中返回 false。
     *
     * 与 {@see verify()} 区别：一次性令牌**不**从 Session 删除——令牌仍可被
     * 后续真正提交所消费。专为 AJAX 表单预检场景设计：dou.js 中 douSubmit() 先发
     * `?do=callback` AJAX 验证，再用同一令牌走原生表单 submit；若中间件在 AJAX
     * 阶段消费一次性令牌，原生 submit 必撞「非法操作」。
     *
     * 静态令牌（static_ 前缀）的 verify() 本就不删除 token，对它来说 check()
     * 与 verify() 行为完全等价；保留两套接口仅为语义清晰。
     *
     * @param string $token 待校验令牌
     * @param string $id 令牌标记
     * @return bool
     */
    public function check($token, $id = 'static_admin')
    {
        $id = $id ? $id : 'static_admin';
        return Session::has('token', $id)
            && hash_equals((string) Session::get('token', null, $id), (string) $token);
    }

    /**
     * 是否一次性令牌（非 static_ 前缀）。
     *
     * @param string $id 令牌标记
     * @return bool
     */
    public function isOneTime($id)
    {
        return strpos((string) $id, 'static_') !== 0;
    }
}
