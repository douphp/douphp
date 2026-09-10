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
 * Release Date: 2026-09-09
 */

namespace Dou\Core\Service\Ai;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 远程产物 URL 安全策略。
 */
class RemoteUrlPolicy
{
    /**
     * 仅允许解析到公网地址的 HTTP/HTTPS URL。
     *
     * @param string $url
     * @return bool
     */
    public static function isPublicHttpUrl($url)
    {
        return self::resolvePublic($url) !== false;
    }

    /**
     * 返回可交给 CURLOPT_RESOLVE 的公网 DNS 绑定。
     *
     * @param string $url
     * @return array|false
     */
    public static function resolvePublic($url)
    {
        $parts = parse_url(trim((string) $url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = trim((string) $parts['host'], '[]');
        if ($host === '' || strtolower($host) === 'localhost') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host) ? array() : false;
        }

        $ips = array();
        if (function_exists('dns_get_record') && defined('DNS_A') && defined('DNS_AAAA')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            foreach ((array) $records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                } elseif (!empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }
        if (!$ips) {
            $ipv4 = gethostbynamel($host);
            $ips = is_array($ipv4) ? $ipv4 : array();
        }
        $ips = array_values(array_unique($ips));
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $resolve = array();
        foreach ($ips as $ip) {
            $resolve[] = $host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip);
        }

        return $resolve;
    }

    /**
     * @param string $ip
     * @return bool
     */
    private static function isPublicIp($ip)
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
