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

namespace Dou\Core\Foundation\Exception;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 跳转异常
 *
 * Service / 业务层用于在不带"提示页"的场景下，从深层调用栈中无感跳转到指定 URL。
 * 三端入口（前台 / 后台 / API）会捕获该异常并发送 302（或自定义状态码）重定向响应。
 *
 * 与 {@see DomainException} 的区别：
 * - DomainException：业务规则违反 → 三端入口渲染"提示页 + 返回链接 + 自动跳转倒计时"。
 * - RedirectException：无感跳转 → 三端入口直接发 302 头，无 HTML 提示页。
 *
 * 不要塞 Response 对象进来：本类只持有 URL 与状态码，由入口决定如何渲染。
 */
class RedirectException extends \RuntimeException
{
    /** @var string */
    private $url;

    /** @var int */
    private $statusCode;

    /**
     * @param string $url 目标 URL（绝对或相对均可，由入口侧 redirect() helper 处理）
     * @param int $statusCode HTTP 状态码（默认 302）
     * @param string $message 调试用文案（不展示给最终用户）
     */
    public function __construct($url, $statusCode = 302, $message = '')
    {
        parent::__construct((string) $message);
        $this->url = (string) $url;
        $this->statusCode = (int) $statusCode;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }
}
