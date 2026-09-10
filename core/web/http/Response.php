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
 * HTTP 响应基类。
 */
class Response
{
    /** @var int */
    protected $statusCode;

    /** @var array 小写头名 => 字符串值列表 */
    protected $headers;

    /** @var string */
    protected $content;

    /**
     * @param string $content
     * @param int $statusCode
     * @param array $headers 形如 ['Content-Type' => 'text/html; charset=utf-8']
     */
    public function __construct($content = '', $statusCode = 200, array $headers = array())
    {
        $this->content = (string) $content;
        $this->statusCode = (int) $statusCode;
        $this->headers = array();
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
    }

    /**
     * @param string $name
     * @param string $value
     * @return void
     */
    public function setHeader($name, $value)
    {
        $key = strtolower((string) $name);
        if (!isset($this->headers[$key])) {
            $this->headers[$key] = array();
        }
        $this->headers[$key][] = (string) $value;
    }

    /**
     * @param string $name
     * @return string|null 首个值
     */
    public function getHeader($name)
    {
        $key = strtolower((string) $name);
        if (!isset($this->headers[$key]) || empty($this->headers[$key])) {
            return null;
        }

        return $this->headers[$key][0];
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @param int $code
     * @return void
     */
    public function setStatusCode($code)
    {
        $this->statusCode = (int) $code;
    }

    /**
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * @param string $content
     * @return void
     */
    public function setContent($content)
    {
        $this->content = (string) $content;
    }

    /**
     * 发送状态行、头与正文（唯一出口之一，与内核配合）。
     *
     * @return void
     */
    public function send()
    {
        if (headers_sent()) {
            echo $this->content;

            return;
        }

        if (function_exists('http_response_code')) {
            http_response_code($this->statusCode);
        }

        foreach ($this->headers as $lcName => $values) {
            foreach ((array) $values as $value) {
                header($lcName . ': ' . $value, false);
            }
        }

        echo $this->content;
    }
}
