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

namespace Dou\Core\Web\Template;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Token 序列：{@see Lexer} 的产物，带游标供 {@see Parser} 顺序消费。
 */
class TokenStream
{
    /** @var Token[] */
    private $tokens;
    /** @var int 当前游标 */
    private $pos = 0;

    /**
     * @param Token[] $tokens
     */
    public function __construct(array $tokens)
    {
        $this->tokens = array_values($tokens);
    }

    /**
     * 是否还有未消费的 Token。
     *
     * @return bool
     */
    public function valid()
    {
        return $this->pos < count($this->tokens);
    }

    /**
     * 取当前 Token（不前进）；越界返回 null。
     *
     * @return Token|null
     */
    public function current()
    {
        return isset($this->tokens[$this->pos]) ? $this->tokens[$this->pos] : null;
    }

    /**
     * 取当前 Token 并前进游标；越界返回 null。
     *
     * @return Token|null
     */
    public function next()
    {
        $token = isset($this->tokens[$this->pos]) ? $this->tokens[$this->pos] : null;
        $this->pos++;

        return $token;
    }
}
