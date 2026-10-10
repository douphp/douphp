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
 * 数据库连接认证错误识别与中文引导
 *
 * 原则（「代码只提示，环境来执行」）：PHP 用户层无法干预 MySQL 认证握手，
 * 代码不尝试解决认证协议问题，只负责识别认证插件类错误并输出环境层操作指引。
 *
 * 典型场景：PHP < 7.4 的 mysqlnd 不支持 caching_sha2_password（MySQL 8.0+ 默认插件）；
 * MySQL 8.4 默认禁用 mysql_native_password（可配置开启）；MySQL 9.x 已彻底移除该插件。
 *
 * 文案内联中文 + 英文技术关键词，不接语言包：连接失败阶段可能早于任何语言环境初始化。
 */
class DbConnectError
{
    /**
     * 判断连接错误是否属于认证插件类错误。
     *
     * 覆盖三类：客户端驱动不认识服务器要求的插件（2054/2059，如 caching_sha2_password）；
     * 服务器端账号插件被禁用/移除（1524，如 MySQL 8.4/9.x 上的 mysql_native_password）；
     * 旧驱动不含插件名的笼统报错（authentication method unknown to the client）。
     *
     * @param string $message mysqli_connect_error() 或 mysqli_sql_exception 消息
     * @return bool
     */
    public static function isAuthPluginError($message)
    {
        $message = (string) $message;
        if ($message === '') {
            return false;
        }
        return (bool) preg_match(
            '/(2054|2059|1524|caching_sha2_password|sha256_password|authentication plugin'
                . '|plugin[^\r\n]*is not loaded|authentication method unknown to the client)/i',
            $message
        );
    }

    /**
     * 生成认证插件类错误的环境层中文引导（按服务器版本分场景，追加在原始错误之后）。
     *
     * @param string $message 原始错误消息（预留：当前统一输出完整指引）
     * @return string 以换行开头的引导文本
     */
    public static function guide($message)
    {
        return "\n【数据库认证插件不兼容】当前 PHP 环境无法完成 MySQL 服务器要求的身份认证，请在环境层按服务器版本处理：\n"
            . "1) MySQL 8.0：推荐升级 PHP 至 7.4 及以上（建议 8.x）；或将数据库账号改为 mysql_native_password 认证，\n"
            . "   如：ALTER USER '用户名'@'主机' IDENTIFIED WITH mysql_native_password BY '密码';\n"
            . "2) MySQL 8.4：除上述方式外，也可在 my.cnf 配置 mysql_native_password=ON 并重启后使用 native 账号\n"
            . "3) MySQL 9.x：mysql_native_password 插件已被移除、无法配置开启，只能升级 PHP 至 7.4 及以上\n"
            . "（数据库账号请在主机面板创建；PHP 代码无法代为完成认证握手）";
    }
}
