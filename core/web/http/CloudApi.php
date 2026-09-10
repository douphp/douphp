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

namespace Dou\Core\Web\Http;

use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 主站访问豆壳云服务 API 的薄网关。
 *
 * - **配置入口**：`config/cloud.php` 中的 `cloud.api_base`；请求路径以本类 `PATH_*` 常量为准。
 * - **JSON 信封**：`getJson` / `postJson` / `postJsonEnvelope` 解析 `{code, message, data}`；失败返回 `null`。
 * - **Raw 正文**：`postRaw()` 用于非信封响应（如 HTML 片段）；例如订购 iframe 使用 `PATH_ORDER_EXTEND_CLIENT_HTML`。
 */
class CloudApi
{
    // ---- JSON 信封（getJson / postJson / postJsonEnvelope）----
    const PATH_HEALTH = '/health';
    const PATH_CONNECT = '/connect';
    const PATH_EXTEND_LIST = '/extend/list';
    const PATH_EXTEND_ITEM = '/extend/item';
    const PATH_UPDATE_SYSTEM = '/update/system';
    const PATH_UPDATE_MODULE = '/update/module';
    const PATH_UPDATE_THEME = '/update/theme';
    /**
     * 订购扩展客户端 JSON（POST，信封）；与 `PATH_ORDER_EXTEND_CLIENT_HTML` 的 HTML 冻结入口分离。
     */
    const PATH_ORDER_EXTEND_CLIENT = '/order/extend-client';
    const PATH_USER_CLIENT_CHECK = '/user/client-check';
    const PATH_REPORT_COPYRIGHT = '/report/copyright';
    const PATH_THEME_MODULE_SUPPORT = '/theme/module-support';
    const PATH_DOWNLOAD_INSTALL_RESOLVE = '/download/install-resolve';

    // ---- Raw HTML（postRaw：iframe 等，非 JSON 信封）----
    /**
     * 订购扩展客户端 HTML（POST，正文为 `text/html`）；云端与同前缀 JSON 路由分入口实现。
     */
    const PATH_ORDER_EXTEND_CLIENT_HTML = '/order/extend_client';

    /**
     * 拼接完整 URL（自动 trim 末尾斜杠，保证不重复）。
     *
     * 未配置 `cloud.api_base` 时返回空串，调用方不得发起请求。
     *
     * @param string $path 形如 '/extend/list' 的相对路径（必须以 '/' 开头）
     * @return string 完整 URL；`cloud.api_base` 为空时返回空串
     */
    public static function url($path)
    {
        $base = rtrim((string) Config::get('cloud.api_base', ''), '/');
        if ($base === '') {
            return '';
        }
        $path = '/' . ltrim((string) $path, '/');
        return $base . $path;
    }

    /**
     * 发送 POST 并解析 JSON 信封；非 JSON / code != 0 返回 null。
     *
     * @param string $path
     * @param array<string, mixed>|string $data
     * @return array<string, mixed>|null 成功时返回 data 字段
     */
    public static function postJson($path, $data = array())
    {
        $url = self::url($path);
        if ($url === '') {
            return null;
        }
        $body = Client::post($url, $data);
        return self::decodeEnvelope($body);
    }

    /**
     * 发送 POST 并返回完整 JSON 信封（含 code / message / data）。
     *
     * 当调用方需要根据 `code` 或 `message` 区分"未授权 / 参数非法 / 资源不存在"等业务错误时使用。
     * 连不上 / 非 JSON 返回 `null`，调用方应回退到本地兜底逻辑。
     *
     * @param string $path
     * @param array<string, mixed>|string $data
     * @return array{code:int, message:string, data:array<string, mixed>}|null
     */
    public static function postJsonEnvelope($path, $data = array())
    {
        $url = self::url($path);
        if ($url === '') {
            return null;
        }
        $body = Client::post($url, $data);
        return self::decodeFullEnvelope($body);
    }

    /**
     * 发送 GET 并解析 JSON 信封；非 JSON / code != 0 返回 null。
     *
     * @param string $path
     * @param array<string, mixed> $query
     * @return array<string, mixed>|null
     */
    public static function getJson($path, array $query = array())
    {
        $url = self::url($path);
        if ($url === '') {
            return null;
        }
        $body = Client::get($url, $query);
        return self::decodeEnvelope($body);
    }

    /**
     * 发送 POST 并返回原始正文（非 JSON 信封：如 HTML 片段、纯文本协议）。
     *
     * @param string $path
     * @param array<string, mixed>|string $data
     * @return string
     */
    public static function postRaw($path, $data = array())
    {
        $url = self::url($path);
        if ($url === '') {
            return '';
        }
        $body = Client::post($url, $data);
        return $body === null ? '' : (string) $body;
    }

    /**
     * 拆解统一 JSON 信封，返回 data；失败返回 null。
     *
     * @param mixed $body
     * @return array<string, mixed>|null
     */
    protected static function decodeEnvelope($body)
    {
        if (!is_string($body) || $body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['code'])) {
            return null;
        }
        if ((int) $decoded['code'] !== 0) {
            return null;
        }
        return isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : array();
    }

    /**
     * 拆解统一 JSON 信封，原样返回 code / message / data；非 JSON 返回 null。
     *
     * @param mixed $body
     * @return array{code:int, message:string, data:array<string, mixed>}|null
     */
    protected static function decodeFullEnvelope($body)
    {
        if (!is_string($body) || $body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['code'])) {
            return null;
        }
        return array(
            'code' => (int) $decoded['code'],
            'message' => isset($decoded['message']) ? (string) $decoded['message'] : '',
            'data' => isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : array(),
        );
    }
}
