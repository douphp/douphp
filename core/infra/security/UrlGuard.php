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
 * Release Date: 2026-10-13
 */

namespace Dou\Core\Infra\Security;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 出站 URL 安全闸（SSRF 防护，纯静态）。
 *
 * 任何「由用户/会员可控的地址」在服务端发起请求前都必须过本闸：远程图片转存、
 * 模块推送、连通性测试等同属出站面，只放行 http/https + 标准端口，且要求主机名
 * 解析出的每个 A/AAAA 记录都落在公网段，拦住指向内网服务与云元数据端点
 * （169.254.169.254）的请求。
 *
 * 注意：本闸只做「发起前」判定。DNS rebinding（解析两次结果不同）需要更强的防护时，
 * 应在客户端侧固定 IP 连接（如 curl resolve 选项），不要指望调用方二次校验。
 */
class UrlGuard
{
    /** @var array 允许的目标端口 */
    private static $allowedPorts = array(80, 443);

    /**
     * URL 是否可作为出站目标（协议 + 端口 + 解析后的全部 IP 均为公网）。
     *
     * @param string $url
     * @return bool
     */
    public static function isPublicUrl($url)
    {
        $parts = parse_url((string) $url);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return false;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        if (isset($parts['port']) && !in_array((int) $parts['port'], self::$allowedPorts, true)) {
            return false;
        }

        $host = trim($parts['host'], '[]');
        $addresses = self::resolveHost($host);
        if (empty($addresses)) {
            return false;
        }

        foreach ($addresses as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 判断 IP 是否为公网地址（排除私有段、环回、链路本地与保留段）。
     *
     * @param string $ip
     * @return bool
     */
    public static function isPublicIp($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE 覆盖 10/8、172.16/12、192.168/16、127/8、
        // 169.254/16、::1、fc00::/7 等；返回 false 即命中私有或保留段。
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * 解析主机名到 IP 列表；主机名本身即 IP 时直接返回。
     *
     * @param string $host
     * @return array
     */
    public static function resolveHost($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return array($host);
        }

        $addresses = array();
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = $v4;
        }
        if (defined('DNS_AAAA')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ipv6']) && $record['ipv6'] !== '') {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }

        return $addresses;
    }
}
