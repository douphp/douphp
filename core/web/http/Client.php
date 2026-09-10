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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 轻量 HTTP 客户端
 *
 * 提供 GET/POST 与通用 request 能力。
 * 兼容 PHP 5.6+，仅依赖 cURL 扩展。
 */
class Client
{
    /**
     * 发送 GET 请求。
     *
     * @param string $url 请求地址
     * @param array $query 查询参数
     * @param array $headers 请求头
     * @return mixed
     */
    public static function get($url, $query = array(), $headers = array())
    {
        return static::request('GET', $url, $query, $headers);
    }

    /**
     * 发送 POST 请求。
     *
     * @param string $url 请求地址
     * @param array|string $data POST 数据
     * @param array $headers 请求头
     * @return mixed
     */
    public static function post($url, $data = array(), $headers = array())
    {
        return static::request('POST', $url, $data, $headers);
    }

    /**
     * 发送通用 HTTP 请求。
     *
     * @param string $method 请求方法（GET/POST/PUT/DELETE...）
     * @param string $url 请求地址
     * @param array|string $data 请求数据（GET 时作为 query）
     * @param array $headers 请求头
     * @param array $options 可选项：timeout、connect_timeout、return_meta、verify_ssl、max_bytes、resolve
     * @return mixed
     */
    public static function request($method, $url, $data = array(), $headers = array(), $options = array())
    {
        $returnMeta = !empty($options['return_meta']);
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 30;
        $connectTimeout = isset($options['connect_timeout']) ? (int) $options['connect_timeout'] : 10;
        $verifySsl = !isset($options['verify_ssl']) || $options['verify_ssl'] !== false;
        $maxBytes = isset($options['max_bytes']) ? max(0, (int) $options['max_bytes']) : 0;
        if ($timeout < 1) {
            $timeout = 30;
        }
        if ($connectTimeout < 1) {
            $connectTimeout = 10;
        }

        if (!function_exists('curl_init')) {
            if ($returnMeta) {
                return array(
                    'body' => '',
                    'http_code' => 0,
                    'errno' => 0,
                    'error' => 'cURL扩展未安装',
                );
            }

            return null;
        }

        $method = strtoupper((string) $method);
        $ch = curl_init();

        if ($method === 'GET' && !empty($data)) {
            $query = is_array($data) ? http_build_query($data) : (string) $data;
            $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        if (!empty($options['resolve']) && is_array($options['resolve'])) {
            if (!defined('CURLOPT_RESOLVE')) {
                curl_close($ch);
                if ($returnMeta) {
                    return array(
                        'body' => false,
                        'http_code' => 0,
                        'errno' => -1,
                        'error' => 'Secure DNS pinning is not supported by this cURL build',
                        'content_type' => '',
                        'too_large' => false,
                    );
                }

                return false;
            }
            curl_setopt($ch, CURLOPT_RESOLVE, array_values($options['resolve']));
        }

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $responseBody = '';
        $tooLarge = false;
        if ($maxBytes > 0) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($handle, $chunk) use (&$responseBody, &$tooLarge, $maxBytes) {
                if ((strlen($responseBody) + strlen($chunk)) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;

                return strlen($chunk);
            });
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($maxBytes > 0) {
            $response = $tooLarge ? false : $responseBody;
        }

        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($returnMeta) {
            return array(
                'body' => $response === false ? '' : $response,
                'http_code' => $httpCode,
                'errno' => $errno,
                'error' => $error,
                'content_type' => $contentType,
                'too_large' => $tooLarge,
            );
        }

        return $response;
    }
}
