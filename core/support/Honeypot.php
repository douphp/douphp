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

namespace Dou\Core\Support;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 反机器人工具：复选框蜜罐 + 服务端签名时间戳，两者都对浏览器 autofill 免疫。
 *
 * 设计动机（重要）：
 * - 早期蜜罐用 `type="text"` 隐藏 input，浏览器 / 密码管理器（Chrome、1Password、Bitwarden 等）
 *   按表单结构启发式把它当作「确认邮箱」类字段并自动填值，导致合法用户被误判为机器人。
 *   autofill 的判定无视 `name` / `autocomplete` / `data-*-ignore`，只认字段**类型与结构位置**。
 * - 故蜜罐字段（{@see Honeypot::FIELD_NAME}）改用 `type="checkbox"`：autofill 不会自动勾选，
 *   真人永远未勾选（未勾选不进 POST），只有「填/勾所有字段」的机器人会勾上 → {@see isFilled} 命中。
 * - 计时令牌（{@see Honeypot::TIMESTAMP_FIELD}）用 `type="hidden"`：autofill 不碰 hidden 字段。
 *   值由服务端在 GET 渲染时 {@see issueTimestamp} 签发，POST 时 {@see failsTiming} 用**服务端**
 *   时钟校验，全程不依赖客户端 `Date.now()`，从根上避免客户端时钟漂移造成的随机误杀。
 *
 * 模板侧统一通过 {@see theme/default/inc/honeypot.tpl} 单点渲染，避免字段名漂移；
 * 时间戳由 front Init 单点 assign 到 `{$honeypot_ts}`。
 *
 * 兼容 PHP 5.6+（依赖 `hash_hmac` / `hash_equals`，均为 5.6 内置），无外部依赖。
 */
class Honeypot
{
    /**
     * 蜜罐复选框在 HTML / POST 中的字段名。
     *
     * 与业务字段语义解耦：不要改成 `email_confirm` / `email2` / `confirm_*` 等浏览器
     * autofill 启发式能识别的形态。如需更换，必须同步更新模板 partial 与扫描器。
     */
    const FIELD_NAME = '_dou_hp';

    /**
     * 服务端签名时间戳令牌在 HTML / POST 中的字段名（`type="hidden"`）。
     */
    const TIMESTAMP_FIELD = '_dou_ts';

    /**
     * 提交「太快」阈值（秒）：渲染到提交间隔小于此值视为机器人。
     *
     * 仅适用于需要真人逐字填写的表单（注册 / 留言 / 咨询 / SN / 评论）；登录等
     * 密码管理器可一键填充的场景应传 `0` 跳过本检查，避免误拦。
     */
    const MIN_SECONDS = 2;

    /**
     * 表单「过老」阈值（秒）：渲染到提交间隔大于此值视为过期表单。
     *
     * 取较宽松值（24h）：真实反重放由各表单 CSRF 一次性 token 承担，本阈值仅兜底
     * 收割型陈旧表单，避免误伤长时间挂着页面的真人。
     */
    const MAX_SECONDS = 86400;

    /**
     * 判断蜜罐字段是否被填写 / 勾选。
     *
     * 任何非空白字符均视为「被触发」 → 机器人嫌疑 → 调用方应当拒绝请求。
     * checkbox 未勾选时不进 POST，调用方取默认 '' → 视为「未触发」放行；
     * 勾选时提交其 value（如 '1'）→ 视为「被触发」。
     *
     * @param mixed $value
     * @return bool
     */
    public static function isFilled($value)
    {
        if ($value === null) {
            return false;
        }
        if (is_array($value) || is_object($value)) {
            return true;
        }
        return trim((string) $value) !== '';
    }

    /**
     * 签发服务端时间戳令牌：`"{ts}.{sig}"`，供 GET 渲染进隐藏字段。
     *
     * `ts` 为服务端 `time()`；`sig` 为对 `ts` 的 HMAC-SHA256 截断（用 DOU_SHELL 作密钥），
     * 使机器人无法伪造一个「刚好通过」的时间戳。
     *
     * @return string
     */
    public static function issueTimestamp()
    {
        $ts = (string) time();
        return $ts . '.' . self::sign($ts);
    }

    /**
     * 校验时间戳令牌，命中任一异常即判定为机器人。
     *
     * 异常情形：令牌缺失 / 格式非法 / 签名不匹配（篡改或裸 bot）；
     * 渲染到提交间隔 `< $minSeconds`（提交太快，`$minSeconds <= 0` 时跳过本检查）；
     * 间隔 `> $maxSeconds`（表单过老）。
     *
     * @param mixed $token 来自 POST 的令牌字符串
     * @param int $now 当前服务端时间（调用方传 `time()`）
     * @param int $minSeconds 最小间隔；<=0 跳过
     * @param int $maxSeconds 最大间隔
     * @return bool true=机器人嫌疑（应拒绝）
     */
    public static function failsTiming($token, $now, $minSeconds, $maxSeconds)
    {
        if (!is_string($token) || strpos($token, '.') === false) {
            return true;
        }

        $parts = explode('.', $token, 2);
        $ts = $parts[0];
        $sig = isset($parts[1]) ? $parts[1] : '';

        if ($ts === '' || !ctype_digit($ts)) {
            return true;
        }
        if (!hash_equals(self::sign($ts), (string) $sig)) {
            return true;
        }

        $elapsed = (int) $now - (int) $ts;
        if ($minSeconds > 0 && $elapsed < (int) $minSeconds) {
            return true;
        }
        if ($elapsed > (int) $maxSeconds) {
            return true;
        }

        return false;
    }

    /**
     * 对时间戳做 HMAC 签名并截断，DOU_SHELL 作密钥。
     *
     * @param string $ts
     * @return string 16 位十六进制签名
     */
    private static function sign($ts)
    {
        $secret = defined('DOU_SHELL') ? DOU_SHELL : '';
        return substr(hash_hmac('sha256', $ts, $secret), 0, 16);
    }
}
