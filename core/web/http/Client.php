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
     * @param array $options 可选项：timeout、connect_timeout、return_meta、verify_ssl、max_bytes、resolve、stream_to
     *                       stream_to：将响应体直接流式写入该文件路径（用于大文件下载，
     *                       避免整包读入内存字符串；return_meta 时 body 返回空串，并附带 size / content_length）
     * @return mixed
     */
    public static function request($method, $url, $data = array(), $headers = array(), $options = array())
    {
        $returnMeta = !empty($options['return_meta']);
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 30;
        $connectTimeout = isset($options['connect_timeout']) ? (int) $options['connect_timeout'] : 10;
        $verifySsl = !isset($options['verify_ssl']) || $options['verify_ssl'] !== false;
        $maxBytes = isset($options['max_bytes']) ? max(0, (int) $options['max_bytes']) : 0;
        $streamTo = isset($options['stream_to']) ? (string) $options['stream_to'] : '';
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
        if ($verifySsl) {
            // 优先使用程序内置 CA 证书包，避免因服务器 php.ini 未配置 curl.cainfo 导致校验失败
            $caBundle = isset($options['ca_info']) ? (string) $options['ca_info'] : __DIR__ . '/cacert.pem';
            if (is_file($caBundle)) {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            }
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $responseBody = '';
        $tooLarge = false;
        $streamFp = null;
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
        } elseif ($streamTo !== '') {
            // 流式落盘：不把响应体读进 PHP 内存，仅写入文件句柄，适合大体积安装包下载
            $streamFp = @fopen($streamTo, 'wb');
            if ($streamFp === false) {
                curl_close($ch);
                if ($returnMeta) {
                    return array(
                        'body' => '',
                        'http_code' => 0,
                        'errno' => -2,
                        'error' => '无法打开下载临时文件: ' . $streamTo,
                        'content_type' => '',
                        'too_large' => false,
                        'streamed' => false,
                        'size' => 0,
                        'content_length' => -1,
                    );
                }

                return false;
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_FILE, $streamFp);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $downloadSize = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        $contentLength = isset($options['stream_to']) ? (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD) : 0;
        if ($streamFp !== null && is_resource($streamFp)) {
            @fflush($streamFp);
            @fclose($streamFp);
            $streamFp = null;
        }
        if ($maxBytes > 0) {
            $response = $tooLarge ? false : $responseBody;
        }

        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($returnMeta) {
            $meta = array(
                'body' => ($streamTo !== '' || $response === false) ? '' : $response,
                'http_code' => $httpCode,
                'errno' => $errno,
                'error' => $error,
                'content_type' => $contentType,
                'too_large' => $tooLarge,
            );
            if ($streamTo !== '') {
                $meta['streamed'] = $response !== false;
                $meta['size'] = $downloadSize;
                $meta['content_length'] = $contentLength;
            }

            return $meta;
        }

        return $response;
    }
}
