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

use Dou\Core\Web\Http\JsonResponse;
use Dou\Core\Web\Http\RedirectResponse;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Http\ViewResponse;
use Dou\Core\Web\Template\TemplateRendererInterface;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

if (!function_exists('redirect')) {
    /**
     * 构造重定向响应（由控制器 return 或包入 HttpResponseException）。
     *
     * @param string $url
     * @param int $statusCode 301 或 302
     * @return RedirectResponse
     */
    function redirect($url, $statusCode = 302)
    {
        return RedirectResponse::create($url, $statusCode);
    }
}

if (!function_exists('view')) {
    /**
     * 构造视图响应（底层模板渲染器经 {@see TemplateRendererInterface} 由容器解析，
     * 默认绑定为 {@see \Dou\Core\Web\Template\DouView}）。
     *
     * 与 Controller 的 $this->view(...) 语义一致；Service / Init / 中间件等
     * 不便走 $this->view 的位置可使用本 helper。
     *
     * @param string $template 模板资源名
     * @param array $data 本视图专属模板变量（叠加在已 assign 的全局之上）
     * @param int $statusCode HTTP 状态码（默认 200）
     * @return ViewResponse
     */
    function view($template, array $data = array(), $statusCode = 200)
    {
        return new ViewResponse(
            app(TemplateRendererInterface::class),
            (string) $template,
            $data,
            (int) $statusCode
        );
    }
}

if (!function_exists('json')) {
    /**
     * 构造 JSON 响应（裸 json_encode，未走 ApiResponse 标准包络）。
     *
     * API 端业务接口请优先使用 \Dou\Core\Web\Http\ApiResponse::success / error
     * （含 code / message / data / errors / request_id 标准字段），本助手仅供
     * 后台 AJAX 片段、片段接口等非标准包络场景。
     *
     * @param mixed $data
     * @param int $statusCode
     * @param int $encodeOptions 透传 json_encode 第二参（默认 0）
     * @return JsonResponse
     */
    function json($data, $statusCode = 200, $encodeOptions = 0)
    {
        return new JsonResponse($data, $statusCode, $encodeOptions);
    }
}

if (!function_exists('response')) {
    /**
     * 构造通用响应（纯文本、HTML 片段、XML、二进制等）。
     *
     * @param string $content
     * @param int $statusCode
     * @param array $headers 形如 ['Content-Type' => 'application/xml; charset=utf-8']
     * @return Response
     */
    function response($content = '', $statusCode = 200, array $headers = array())
    {
        return new Response($content, $statusCode, $headers);
    }
}
