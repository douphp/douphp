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
 * 词法单元：{@see Lexer} 把模板源切分为有序 Token 序列，供 {@see Parser} 构建 AST。
 *
 * TEXT 与「指令类」（TAG/COMMENT/LITERAL/PHP）在序列中严格交替（首尾恒为 TEXT，
 * 相邻指令之间以空 TEXT 占位），保持与编译期空白处理一一对应。
 */
class Token
{
    /** @var string {tag} 之间的原始文本块（含 HTML） */
    const TEXT = 'text';
    /** @var string 指令标签（去定界符与首尾空白后的内容，如 '$x|escape'、'if $a eq 1'） */
    const TAG = 'tag';
    /** @var string {* 注释 *} 区段（运行期丢弃） */
    const COMMENT = 'comment';
    /** @var string {literal}...{/literal} 区段（原样输出） */
    const LITERAL = 'literal';
    /** @var string {php}...{/php} 区段（编译期拒绝） */
    const PHP = 'php';

    /** @var string Token 类型（本类常量之一） */
    public $type;
    /** @var string Token 文本载荷 */
    public $value;
    /** @var int 源模板行号（错误定位） */
    public $line;

    /**
     * @param string $type 类型（本类常量之一）
     * @param string $value 文本载荷
     * @param int $line 行号
     */
    public function __construct($type, $value, $line = 1)
    {
        $this->type = $type;
        $this->value = $value;
        $this->line = (int) $line;
    }
}
