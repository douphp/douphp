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
 * Release Date: 2026-10-10
 */

namespace Dou\Core\Support;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 数据库版本归一纯工具
 *
 * 与 Arr / Str / Naming 同层的无状态静态工具；不依赖 Config / 容器 / 数据库连接。
 *
 * 背景：版本串不能直接做字符串比较，存在两类陷阱：
 *   1. MariaDB 兼容前缀：协议层版本串形如 5.5.5-10.11.6-MariaDB，直接比较会被误判为 5.5.x；
 *   2. MySQL CalVer：9.7 LTS 之后改用日历版本（如 26.7.0），字符串比较 '26.7.0' > '4.1'
 *      会因首字符 '2' < '4' 得出 false，导致归一逻辑被误跳过。
 * 所有版本门槛判断统一走 {@see isAtLeast()}，禁止在业务代码中直接比较原始版本串。
 */
class DbVersion
{
    /**
     * 识别数据库家族。
     *
     * @param string $raw 原始版本串（如 5.7.44-log / 5.5.5-10.11.6-MariaDB）
     * @return string mysql / mariadb / unknown
     */
    public static function serverFamily($raw)
    {
        $raw = (string) $raw;
        if ($raw !== '' && stripos($raw, 'mariadb') !== false) {
            return 'mariadb';
        }
        if (preg_match('/\d+(?:\.\d+){1,2}/', $raw)) {
            return 'mysql';
        }
        return 'unknown';
    }

    /**
     * 归一版本串为可比较的数字版本。
     *
     * 5.7.44-log → 5.7.44；5.5.5-10.11.6-MariaDB → 10.11.6；26.7.0 → 26.7.0。
     *
     * @param string $raw 原始版本串
     * @return string 归一后的版本号；无法解析时返回空串
     */
    public static function normalize($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        // 剥离 MariaDB 协议兼容前缀（仅当以 5.5.5- 开头时存在，其余场景正则不命中、原样保留）
        $raw = preg_replace('/^5\.5\.5-/', '', $raw);
        if (preg_match('/(\d+(?:\.\d+){1,2})/', $raw, $match)) {
            return $match[1];
        }
        return '';
    }

    /**
     * 判断数据库版本是否达到最低要求。
     *
     * 注意：MariaDB 10.x 按其真实版本号与 MySQL 门槛比较（10.x ≥ 5.7 恒成立），
     * 模块如需区分 MySQL / MariaDB 能力差异，应使用能力探测而非版本门槛表达。
     *
     * @param string $raw 原始版本串
     * @param string $min 最低要求（如 '4.1' / '5.7' / '8.0.13'）
     * @return bool 达到要求返回 true；任一版本无法解析时返回 false
     */
    public static function isAtLeast($raw, $min)
    {
        $current = self::normalize($raw);
        $minimum = self::normalize($min);
        if ($current === '' || $minimum === '') {
            return false;
        }
        return version_compare($current, $minimum, '>=');
    }
}
