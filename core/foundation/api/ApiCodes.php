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

namespace Dou\Core\Foundation\Api;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 错误返回规范的业务码（code）常量集合
 *
 * 与 {@see \Dou\Core\Web\Http\ApiResponse} 的 `code` 入参对外契约一一对应。
 * 客户端（前端 JS / 移动端 / 小程序）已硬编码这些字符串值，常量值不允许改名；
 * 仅可在末尾追加新的常量，并同步通知客户端发版。
 */
class ApiCodes
{
    /** 成功（HTTP 200） */
    const OK = 'OK';

    /** 通用业务规则违反（默认 422） */
    const BUSINESS_RULE_VIOLATION = 'BUSINESS_RULE_VIOLATION';

    /** 参数非法 / 无效 ID 等（默认 422，部分场景 400） */
    const INVALID_PARAMS = 'INVALID_PARAMS';

    /** 字段级校验失败（默认 422，常带 errors / data） */
    const VALIDATION_FAILED = 'VALIDATION_FAILED';

    /** 未携带或携带了无效凭证（默认 401） */
    const UNAUTHORIZED = 'UNAUTHORIZED';

    /** 需要登录后再访问（默认 401，常带 jump_url） */
    const AUTH_REQUIRED = 'AUTH_REQUIRED';

    /** 已登录但无权限 / 模块未装等（默认 403） */
    const FORBIDDEN = 'FORBIDDEN';

    /** 资源不存在（默认 404） */
    const NOT_FOUND = 'NOT_FOUND';

    /** 配额 / 额度超限（默认 422，部分场景 429） */
    const QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';

    /** 请求过频被限流（默认 429） */
    const RATE_LIMITED = 'RATE_LIMITED';

    /** 服务端兜底错误（默认 500） */
    const SERVER_ERROR = 'SERVER_ERROR';

    /** AI 模型 / Provider 不可用（默认 503） */
    const AI_CONFIG_UNAVAILABLE = 'AI_CONFIG_UNAVAILABLE';

    /** AI 会话不存在或无权限访问（默认 404） */
    const CHAT_NOT_FOUND = 'CHAT_NOT_FOUND';

    /** AI 会话创建失败（默认 500） */
    const CHAT_CREATE_FAILED = 'CHAT_CREATE_FAILED';

    /** AI 会话保存失败（默认 500） */
    const CHAT_SAVE_FAILED = 'CHAT_SAVE_FAILED';
}
