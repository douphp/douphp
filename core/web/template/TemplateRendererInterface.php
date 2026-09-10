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

namespace Dou\Core\Web\Template;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 模板渲染器契约：DouPHP 视图响应入口（{@see \Dou\Core\Web\Http\ViewResponse}、
 * 前/后台 BaseController::view()、全局 view() helper）面向本接口编程，
 * 默认绑定为 {@see DouView}。
 *
 * 仅声明「视图响应」最小渲染契约（两段式 assign + fetch）；引擎专属能力（如 setEscapeHtml /
 * registerPrefilter / template_dir 等配置项）不在本接口范围内，调用方仍可注入具体 {@see DouView} 类。
 */
interface TemplateRendererInterface
{
    /**
     * 给模板上下文写入一个变量；底层实现应保证后续 {@see fetch()} 渲染时可见。
     *
     * @param string|array $tpl_var 变量名；为 array 时按 key => value 批量写入
     * @param mixed|null $value 当 $tpl_var 为字符串时使用
     * @return void
     */
    public function assign($tpl_var, $value = null);

    /**
     * 按模板资源名渲染出 HTML 字符串。
     *
     * @param string $template 模板资源名（如 'index.dwt' / 'product.htm'）
     * @return string 已渲染的 HTML
     */
    public function fetch($template);
}
