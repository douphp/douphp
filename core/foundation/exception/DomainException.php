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

namespace Dou\Core\Foundation\Exception;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 领域业务异常
 *
 * Service 层用于中断当前业务流程并向 Controller 传递消息参数。
 * Controller / 入口捕获后统一调用 `$context->message->respond()` 输出（后台/前台终止页）或 JSON 响应（API）。
 *
 * 参数与 MessageResponderInterface::respond 签名对应：
 *   message     → 第 1 参（已翻译的提示文本）
 *   backUrl     → 第 2 参（返回链接）
 *   timer       → 第 4 参（自动跳转秒数，'' 表示不自动跳转）
 *   confirmUrl  → 第 5 参（二次确认时的执行 URL，'' 表示无需确认按钮）
 *   out         → 第 3 参（admin 模板 dou_msg.htm 的样式标志，'out' 表示登录前简化样式；前台忽略）
 */
class DomainException extends \RuntimeException
{
    /** @var string */
    private $backUrl;

    /** @var string */
    private $timer;

    /** @var string */
    private $confirmUrl;

    /** @var array */
    private $errors = array();

    /** @var string */
    private $out = '';

    /**
     * @param string $message 已翻译的提示文本
     * @param string $backUrl 返回 / 重定向链接
     * @param string $timer 自动跳转倒计时（秒）；'' 表示不自动
     * @param string $confirmUrl 二次确认执行 URL；'' 表示仅提示
     * @param array $errors 字段错误集合（用于 callback/json 场景）
     * @param string $out admin 模板样式标志（'out' 表示登录前简化样式；前台不使用）
     */
    public function __construct($message, $backUrl = '', $timer = '', $confirmUrl = '', array $errors = array(), $out = '')
    {
        parent::__construct((string) $message);
        $this->backUrl = (string) $backUrl;
        $this->timer = (string) $timer;
        $this->confirmUrl = (string) $confirmUrl;
        $this->errors = $errors;
        $this->out = (string) $out;
    }

    /**
     * @return string
     */
    public function getBackUrl()
    {
        return $this->backUrl;
    }

    /**
     * @return string
     */
    public function getTimer()
    {
        return $this->timer;
    }

    /**
     * @return string
     */
    public function getConfirmUrl()
    {
        return $this->confirmUrl;
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return bool
     */
    public function hasErrors()
    {
        return !empty($this->errors);
    }

    /**
     * @return string
     */
    public function getOut()
    {
        return $this->out;
    }
}
