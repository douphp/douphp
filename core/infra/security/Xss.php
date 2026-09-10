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

namespace Dou\Core\Infra\Security;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

class Xss
{
    /**
     * +----------------------------------------------------------
     * 配置参数
     * +----------------------------------------------------------
     */
    private $config = [
        'safe' => true,
        'balance_tags' => true,
        'remove_comments' => true,
        'remove_cdata' => true,
        'clean_control_chars' => true,
        'keep_bad' => 'escape',
        'parent' => 'body',
        'elements' => '*',
        'deny_elements' => '',
        'deny_attribute' => '',
        'safe_allow' => [],  // safe模式下额外允许的标签列表
    ];

    /**
     * +----------------------------------------------------------
     * 默认配置备份
     * +----------------------------------------------------------
     */
    private $default_config = [];

    /**
     * +----------------------------------------------------------
     * 安全模式下禁止的标签
     * +----------------------------------------------------------
     */
    private $unsafe_tags = [
        'applet',
        'embed',
        'iframe',
        'object',
        'script',
        'style',
        'canvas',
        'dialog',
        'link',
        'meta',
        'svg',
        'animate',
        'animatemotion',
        'animatetransform',
        'circle',
        'clippath',
        'defs',
        'desc',
        'ellipse',
        'feblend',
        'fecolormatrix',
        'fecomponenttransfer',
        'fecomposite',
        'feconvolvematrix',
        'fediffuselighting',
        'fedisplacementmap',
        'fedropshadow',
        'feflood',
        'fefunca',
        'fefuncb',
        'fefuncg',
        'fefuncr',
        'fegaussianblur',
        'feimage',
        'femerge',
        'femergenode',
        'femorphology',
        'feoffset',
        'fepointlight',
        'fespecularlighting',
        'fespotlight',
        'fetile',
        'feturbulence',
        'filter',
        'foreignobject',
        'g',
        'image',
        'line',
        'lineargradient',
        'marker',
        'mask',
        'path',
        'pattern',
        'polygon',
        'polyline',
        'radialgradient',
        'rect',
        'set',
        'stop',
        'switch',
        'symbol',
        'text',
        'textpath',
        'tspan',
        'use',
        'view',
        'math',
        'maction',
        'menclose',
        'merror',
        'mfenced',
        'mfrac',
        'mi',
        'mmultiscripts',
        'mn',
        'mo',
        'mover',
        'mpadded',
        'mphantom',
        'mroot',
        'mrow',
        'ms',
        'mspace',
        'msqrt',
        'mstyle',
        'msub',
        'msubsup',
        'msup',
        'mtable',
        'mtd',
        'mtext',
        'mtr',
        'munder',
        'munderover',
        'semantics'
    ];

    /**
     * +----------------------------------------------------------
     * 允许的HTML5标签白名单
     * +----------------------------------------------------------
     */
    private $allowed_tags = [
        'a',
        'abbr',
        'address',
        'article',
        'aside',
        'b',
        'bdi',
        'bdo',
        'blockquote',
        'br',
        'caption',
        'cite',
        'code',
        'col',
        'colgroup',
        'data',
        'dd',
        'del',
        'details',
        'dfn',
        'div',
        'dl',
        'dt',
        'em',
        'fieldset',
        'figcaption',
        'figure',
        'footer',
        'form',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'header',
        'hgroup',
        'hr',
        'i',
        'img',
        'input',
        'ins',
        'kbd',
        'label',
        'legend',
        'li',
        'main',
        'map',
        'area',
        'mark',
        'menu',
        'meter',
        'nav',
        'noscript',
        'ol',
        'optgroup',
        'option',
        'output',
        'p',
        'picture',
        'pre',
        'progress',
        'q',
        'rp',
        'rt',
        'ruby',
        's',
        'samp',
        'section',
        'select',
        'slot',
        'small',
        'source',
        'span',
        'strong',
        'sub',
        'summary',
        'sup',
        'table',
        'tbody',
        'td',
        'template',
        'textarea',
        'tfoot',
        'th',
        'thead',
        'time',
        'tr',
        'track',
        'u',
        'ul',
        'var',
        'video',
        'audio',
        'wbr',
        'button',
        'datalist'
    ];

    /**
     * +----------------------------------------------------------
     * 非安全模式额外允许的标签
     * +----------------------------------------------------------
     */
    private $unsafe_allowed_tags = [
        'applet',
        'embed',
        'iframe',
        'object',
        'script',
        'style',
        'canvas',
        'dialog',
        'link',
        'meta',
        'svg'
    ];

    /**
     * +----------------------------------------------------------
     * 全局属性
     * +----------------------------------------------------------
     */
    private $global_attrs = [
        'accesskey',
        'class',
        'contenteditable',
        'dir',
        'draggable',
        'hidden',
        'id',
        'inert',
        'lang',
        'role',
        'slot',
        'spellcheck',
        'style',
        'tabindex',
        'title',
        'translate'
    ];

    /**
     * +----------------------------------------------------------
     * 标签专属属性
     * +----------------------------------------------------------
     */
    private $tag_attrs = [];

    /**
     * +----------------------------------------------------------
     * 非安全模式专属属性
     * +----------------------------------------------------------
     */
    private $unsafe_tag_attrs = [];

    /**
     * +----------------------------------------------------------
     * 布尔属性列表
     * +----------------------------------------------------------
     */
    private $boolean_attrs = [
        'allowfullscreen',
        'autofocus',
        'autoplay',
        'checked',
        'controls',
        'default',
        'defer',
        'disabled',
        'formnovalidate',
        'hidden',
        'inert',
        'ismap',
        'itemscope',
        'loop',
        'multiple',
        'muted',
        'novalidate',
        'open',
        'readonly',
        'required',
        'reversed',
        'selected'
    ];

    /**
     * +----------------------------------------------------------
     * URL属性及其允许的协议
     * +----------------------------------------------------------
     */
    private $url_attrs = [
        'href' => ['http', 'https', 'mailto', 'tel', 'ftp', 'ftps'],
        'src' => ['http', 'https', 'data'],
        'action' => ['http', 'https'],
        'formaction' => ['http', 'https'],
        'poster' => ['http', 'https'],
        'data' => ['http', 'https'],
        'cite' => ['http', 'https'],
        'srcset' => ['http', 'https', 'data'],
    ];

    /**
     * +----------------------------------------------------------
     * 块级元素列表
     * +----------------------------------------------------------
     */
    private $block_elements = [
        'address',
        'article',
        'aside',
        'blockquote',
        'details',
        'dialog',
        'dd',
        'div',
        'dl',
        'dt',
        'fieldset',
        'figcaption',
        'figure',
        'footer',
        'form',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'header',
        'hgroup',
        'hr',
        'li',
        'main',
        'nav',
        'ol',
        'p',
        'pre',
        'section',
        'table',
        'ul',
        'canvas',
        'noscript',
        'output',
        'video',
        'audio'
    ];

    /**
     * +----------------------------------------------------------
     * 自闭合标签
     * +----------------------------------------------------------
     */
    private $void_elements = [
        'area',
        'base',
        'br',
        'col',
        'embed',
        'hr',
        'img',
        'input',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr'
    ];

    /**
     * +----------------------------------------------------------
     * 标签栈（用于嵌套检查）
     * +----------------------------------------------------------
     */
    private $tag_stack = [];

    /**
     * 执行__construct操作。
     *
     * @param array $config 参数config。
     * @return void 返回结果。
     */
    public function __construct($config = [])
    {
        $this->initTagAttrs();
        $this->default_config = $this->config;
        if (!empty($config)) {
            $this->setConfig($config);
        }
    }

    /**
     * 执行initTagAttrs操作。
     *
     * @return void 返回结果。
     */
    private function initTagAttrs()
    {
        $this->tag_attrs = [
            'a' => ['href', 'target', 'rel', 'download', 'hreflang', 'type', 'referrerpolicy', 'ping'],
            'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding', 'fetchpriority', 'srcset', 'sizes', 'crossorigin', 'usemap', 'ismap', 'referrerpolicy'],
            'video' => ['src', 'controls', 'autoplay', 'loop', 'muted', 'preload', 'poster', 'width', 'height', 'crossorigin'],
            'audio' => ['src', 'controls', 'autoplay', 'loop', 'muted', 'preload', 'crossorigin'],
            'source' => ['src', 'srcset', 'sizes', 'type', 'media'],
            'track' => ['src', 'kind', 'srclang', 'label', 'default'],
            'form' => ['action', 'method', 'enctype', 'accept-charset', 'autocomplete', 'novalidate', 'target', 'rel'],
            'input' => ['type', 'name', 'value', 'placeholder', 'required', 'disabled', 'readonly', 'maxlength', 'minlength', 'pattern', 'min', 'max', 'step', 'multiple', 'accept', 'autocomplete', 'autofocus', 'checked', 'list', 'size', 'form', 'formaction', 'formmethod', 'formenctype', 'formnovalidate', 'formtarget'],
            'button' => ['type', 'name', 'value', 'disabled', 'form', 'formaction', 'formmethod', 'formenctype', 'formnovalidate', 'formtarget', 'autofocus'],
            'select' => ['name', 'multiple', 'size', 'disabled', 'required', 'form', 'autofocus'],
            'textarea' => ['name', 'rows', 'cols', 'placeholder', 'required', 'disabled', 'readonly', 'maxlength', 'minlength', 'wrap', 'form', 'autofocus'],
            'table' => ['border', 'cellpadding', 'cellspacing', 'width'],
            'td' => ['colspan', 'rowspan', 'headers'],
            'th' => ['colspan', 'rowspan', 'headers', 'scope', 'abbr'],
            'col' => ['span'],
            'colgroup' => ['span'],
            'ol' => ['start', 'reversed', 'type'],
            'li' => ['value'],
            'details' => ['open'],
            'meter' => ['value', 'min', 'max', 'low', 'high', 'optimum'],
            'progress' => ['value', 'max'],
            'time' => ['datetime'],
            'output' => ['for', 'form', 'name'],
            'label' => ['for', 'form'],
            'fieldset' => ['disabled', 'form', 'name'],
            'optgroup' => ['label', 'disabled'],
            'option' => ['value', 'selected', 'disabled', 'label'],
            'map' => ['name'],
            'area' => ['alt', 'coords', 'shape', 'href', 'target', 'rel', 'download', 'referrerpolicy'],
            'del' => ['cite', 'datetime'],
            'ins' => ['cite', 'datetime'],
            'blockquote' => ['cite'],
            'q' => ['cite'],
            'data' => ['value'],
            'slot' => ['name'],
            'picture' => [],
            'template' => [],
            'datalist' => [],
            'legend' => [],
            'noscript' => [],
        ];

        $this->unsafe_tag_attrs = [
            'link' => ['href', 'rel', 'type', 'media', 'sizes', 'crossorigin'],
            'meta' => ['name', 'content', 'charset', 'http-equiv'],
            'dialog' => ['open'],
            'embed' => ['src', 'type', 'width', 'height'],
            'object' => ['data', 'type', 'width', 'height', 'name', 'form'],
            'iframe' => ['src', 'srcdoc', 'name', 'sandbox', 'allow', 'allowfullscreen', 'width', 'height', 'loading', 'referrerpolicy'],
            'canvas' => ['width', 'height'],
        ];
    }

    /**
     * 执行setConfig操作。
     *
     * @param mixed $config 参数config。
     * @return mixed 返回结果。
     */
    public function setConfig($config)
    {
        if (is_array($config)) {
            foreach ($config as $key => $value) {
                if (array_key_exists($key, $this->config)) {
                    $this->config[$key] = $value;
                }
            }
        }
        return $this;
    }

    /**
     * 执行getConfig操作。
     *
     * @param string $key 参数key。
     * @return mixed 返回结果。
     */
    public function getConfig($key = '')
    {
        if ($key === '') {
            return $this->config;
        }
        return isset($this->config[$key]) ? $this->config[$key] : null;
    }

    /**
     * 执行resetConfig操作。
     *
     * @return mixed 返回结果。
     */
    public function resetConfig()
    {
        $this->config = $this->default_config;
        return $this;
    }

    /**
     * 执行filterHtml操作。
     *
     * @param mixed $html 参数html。
     * @param array $config 参数config。
     * @return mixed 返回结果。
     */
    public function filterHtml($html, $config = [])
    {
        if (!is_string($html) || $html === '') {
            return '';
        }

        $original_config = $this->config;
        if (!empty($config)) {
            $this->setConfig($config);
        }

        $html = $this->cleanBom($html);
        $html = $this->cleanNullBytes($html);

        if ($this->config['clean_control_chars']) {
            $html = $this->cleanControlChars($html);
        }

        // 移除 DOCTYPE 声明
        $html = preg_replace('/<!DOCTYPE[^>]*>/is', '', $html);

        if ($this->config['remove_comments']) {
            $html = $this->removeComments($html);
        }

        if ($this->config['remove_cdata']) {
            $html = $this->removeCdata($html);
        }

        $html = $this->normalizeEntities($html);
        $this->tag_stack = [];
        $html = $this->processTags($html);

        if ($this->config['balance_tags']) {
            $html = $this->balanceTags($html);
        }

        $this->config = $original_config;

        return $html;
    }

    /**
     * 执行cleanBom操作。
     *
     * @param mixed $html 参数html。
     * @return mixed 返回结果。
     */
    private function cleanBom($html)
    {
        if (substr($html, 0, 3) === "\xEF\xBB\xBF") {
            $html = substr($html, 3);
        }
        return $html;
    }

    /**
     * 执行cleanNullBytes操作。
     *
     * @param mixed $html 参数html。
     * @return mixed 返回结果。
     */
    private function cleanNullBytes($html)
    {
        return str_replace("\0", '', $html);
    }

    /**
     * 执行cleanControlChars操作。
     *
     * @param mixed $html 参数html。
     * @return mixed 返回结果。
     */
    private function cleanControlChars($html)
    {
        return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $html);
    }

    /**
     * 执行removeComments操作。
     *
     * @param mixed $html 参数html。
     * @return mixed 返回结果。
     */
    private function removeComments($html)
    {
        // 先处理正常闭合的注释
        $html = preg_replace('/<!--.*?-->/is', '', $html);
        // 再处理未闭合的注释（到文件末尾）
        $html = preg_replace('/<!--.*$/is', '', $html);
        return $html;
    }

    /**
     * 执行removeCdata操作。
     *
     * @param mixed $html 参数html。
     * @return mixed 返回结果。
     */
    private function removeCdata($html)
    {
        return preg_replace('/<!\[CDATA\[.*?\]\]>/is', '', $html);
    }

    /**
     * 执行normalizeEntities操作。
     *
     * @param mixed $html 参数html。
     * @return mixed 返回结果。
     */
    private function normalizeEntities($html)
    {
        $html = preg_replace_callback('/&#([0-9]+);?/i', array($this, 'decodeNumericEntity'), $html);
        $html = preg_replace_callback('/&#x([0-9a-f]+);?/i', array($this, 'decodeHexEntity'), $html);
        return $html;
    }

    /**
     * 执行decodeNumericEntity操作。
     *
     * @param mixed $matches 参数matches。
     * @return mixed 返回结果。
     */
    private function decodeNumericEntity($matches)
    {
        $code = intval($matches[1]);
        return $this->safeChr($code);
    }

    /**
     * 执行decodeHexEntity操作。
     *
     * @param mixed $matches 参数matches。
     * @return mixed 返回结果。
     */
    private function decodeHexEntity($matches)
    {
        $code = hexdec($matches[1]);
        return $this->safeChr($code);
    }

    /**
     * 执行safeChr操作。
     *
     * @param mixed $code 参数code。
     * @return mixed 返回结果。
     */
    private function safeChr($code)
    {
        if ($code < 32 && $code !== 9 && $code !== 10 && $code !== 13) {
            return '';
        }
        if ($code >= 127 && $code <= 159) {
            return '';
        }
        if ($code > 0x10FFFF) {
            return '';
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding('&#' . intval($code) . ';', 'UTF-8', 'HTML-ENTITIES');
        }
        if ($code < 128) {
            return chr($code);
        }
        return '&#' . $code . ';';
    }

    /**
     * 执行processTags操作。
     *
     * @param mixed $html 参数html。
     * @return mixed 返回结果。
     */
    private function processTags($html)
    {
        // 改进的正则：正确处理引号内的 > 字符
        // 属性值模式："[^"]*"（双引号）|'[^']*'（单引号）|[^\s>]*（无引号）
        $pattern = '/<(\/?)([a-z][a-z0-9]*)((?:\s+(?:[a-z_:][\w:.-]*(?:\s*=\s*(?:"[^"]*"|' . "'[^']*'" . '|[^\s>]*))?|\s+))*)\s*(\/?)>/is';
        return preg_replace_callback($pattern, array($this, 'processTagCallback'), $html);
    }

    /**
     * 执行processTagCallback操作。
     *
     * @param mixed $matches 参数matches。
     * @return mixed 返回结果。
     */
    private function processTagCallback($matches)
    {
        $is_closing = $matches[1] === '/';
        $tag_name = strtolower($matches[2]);
        $attrs_str = isset($matches[3]) ? $matches[3] : '';
        $is_self_closing = isset($matches[4]) && $matches[4] === '/';

        if (!$this->isAllowedTag($tag_name)) {
            if ($this->config['keep_bad'] === 'escape') {
                return htmlspecialchars($matches[0], ENT_QUOTES, 'UTF-8');
            }
            return '';
        }

        if (!$this->checkNesting($tag_name, $is_closing)) {
            if ($this->config['keep_bad'] === 'escape') {
                return htmlspecialchars($matches[0], ENT_QUOTES, 'UTF-8');
            }
            return '';
        }

        if ($is_closing) {
            $this->popTagStack($tag_name);
            return '</' . $tag_name . '>';
        }

        $attrs = $this->parseAttributes($attrs_str);
        $filtered_attrs = $this->filterAttributes($tag_name, $attrs);

        if (!in_array($tag_name, $this->void_elements)) {
            $this->pushTagStack($tag_name);
        }

        $attr_string = $this->buildAttrString($filtered_attrs);
        $closing = in_array($tag_name, $this->void_elements) ? ' /' : '';

        return '<' . $tag_name . $attr_string . $closing . '>';
    }

    /**
     * 执行isAllowedTag操作。
     *
     * @param mixed $tag 参数tag。
     * @return mixed 返回结果。
     */
    private function isAllowedTag($tag)
    {
        $tag = strtolower($tag);

        // 第一层：安全模式下检查不安全标签
        if ($this->config['safe'] && in_array($tag, $this->unsafe_tags)) {
            $allowed_by_exception = false;

            // 检查 safe_allow 配置
            $safe_allow = $this->config['safe_allow'];
            if (!is_array($safe_allow)) {
                $safe_allow = !empty($safe_allow) ? array_map('trim', explode(',', $safe_allow)) : array();
            }
            if (in_array($tag, $safe_allow)) {
                $allowed_by_exception = true;
            }

            // 检查 elements 是否显式指定了该标签（非 * 时）
            $elements = $this->config['elements'];
            if ($elements !== '*') {
                $allow_list = is_array($elements) ? $elements : array_map('trim', explode(',', $elements));
                if (in_array($tag, $allow_list)) {
                    $allowed_by_exception = true;
                }
            }

            if (!$allowed_by_exception) {
                return false;
            }
        }

        // 第二层：显式拒绝列表
        $deny = $this->config['deny_elements'];
        if (!empty($deny)) {
            $deny_list = is_array($deny) ? $deny : array_map('trim', explode(',', $deny));
            if (in_array($tag, $deny_list)) {
                return false;
            }
        }

        // 第三层：允许列表检查
        $elements = $this->config['elements'];
        if ($elements === '*') {
            if ($this->config['safe']) {
                return in_array($tag, $this->allowed_tags);
            }
            return in_array($tag, $this->allowed_tags) || in_array($tag, $this->unsafe_allowed_tags);
        }

        $allow_list = is_array($elements) ? $elements : array_map('trim', explode(',', $elements));
        return in_array($tag, $allow_list);
    }

    /**
     * 执行parseAttributes操作。
     *
     * @param mixed $attrs_str 参数attrs_str。
     * @return mixed 返回结果。
     */
    private function parseAttributes($attrs_str)
    {
        $attrs = [];
        if (empty($attrs_str)) {
            return $attrs;
        }

        $attrs_str = trim($attrs_str);
        $pattern = '/([a-z][a-z0-9\-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]*)))?/is';

        if (preg_match_all($pattern, $attrs_str, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = strtolower($match[1]);
                $value = '';
                if (isset($match[2]) && $match[2] !== '') {
                    $value = $match[2];
                } elseif (isset($match[3]) && $match[3] !== '') {
                    $value = $match[3];
                } elseif (isset($match[4])) {
                    $value = $match[4];
                }
                $attrs[$name] = $this->decodeAttrValue($value);
            }
        }

        return $attrs;
    }

    /**
     * 执行decodeAttrValue操作。
     *
     * @param mixed $value 参数value。
     * @return mixed 返回结果。
     */
    private function decodeAttrValue($value)
    {
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = preg_replace_callback('/&#([0-9]+);?/i', array($this, 'decodeNumericEntity'), $value);
        $value = preg_replace_callback('/&#x([0-9a-f]+);?/i', array($this, 'decodeHexEntity'), $value);
        return $value;
    }

    /**
     * 执行filterAttributes操作。
     *
     * @param mixed $tag 参数tag。
     * @param mixed $attrs 参数attrs。
     * @return mixed 返回结果。
     */
    private function filterAttributes($tag, $attrs)
    {
        $filtered = [];
        $allowed_attrs = $this->getAllowedAttrs($tag);
        $deny_attrs = $this->config['deny_attribute'];
        $deny_list = [];
        if (!empty($deny_attrs)) {
            $deny_list = is_array($deny_attrs) ? $deny_attrs : array_map('trim', explode(',', $deny_attrs));
        }

        foreach ($attrs as $name => $value) {
            $name_lower = strtolower($name);

            if (preg_match('/^on[a-z]+$/i', $name_lower)) {
                continue;
            }

            if (in_array($name_lower, $deny_list)) {
                continue;
            }

            if (!$this->isAllowedAttr($name_lower, $allowed_attrs)) {
                continue;
            }

            if (isset($this->url_attrs[$name_lower])) {
                $value = $this->filterUrl($value, $this->url_attrs[$name_lower], $name_lower);
                if ($value === false) {
                    continue;
                }
            }

            if ($name_lower === 'style') {
                $value = $this->filterStyle($value);
                if ($value === '') {
                    continue;
                }
            }

            if ($name_lower === 'srcset') {
                $value = $this->filterSrcset($value);
                if ($value === '') {
                    continue;
                }
            }

            $value = $this->escapeAttrValue($value);
            $filtered[$name_lower] = $value;
        }

        return $filtered;
    }

    /**
     * 执行getAllowedAttrs操作。
     *
     * @param mixed $tag 参数tag。
     * @return mixed 返回结果。
     */
    private function getAllowedAttrs($tag)
    {
        $attrs = $this->global_attrs;

        if (isset($this->tag_attrs[$tag])) {
            $attrs = array_merge($attrs, $this->tag_attrs[$tag]);
        }

        if (!$this->config['safe'] && isset($this->unsafe_tag_attrs[$tag])) {
            $attrs = array_merge($attrs, $this->unsafe_tag_attrs[$tag]);
        }

        return $attrs;
    }

    /**
     * 执行isAllowedAttr操作。
     *
     * @param mixed $name 参数name。
     * @param mixed $allowed_attrs 参数allowed_attrs。
     * @return mixed 返回结果。
     */
    private function isAllowedAttr($name, $allowed_attrs)
    {
        if (in_array($name, $allowed_attrs)) {
            return true;
        }

        if (strpos($name, 'aria-') === 0) {
            return $this->isValidAriaAttr($name);
        }

        if (strpos($name, 'data-') === 0) {
            return $this->isValidDataAttr($name);
        }

        return false;
    }

    /**
     * 执行isValidAriaAttr操作。
     *
     * @param mixed $name 参数name。
     * @return mixed 返回结果。
     */
    private function isValidAriaAttr($name)
    {
        $valid_aria = [
            'aria-activedescendant',
            'aria-atomic',
            'aria-autocomplete',
            'aria-busy',
            'aria-checked',
            'aria-colcount',
            'aria-colindex',
            'aria-colspan',
            'aria-controls',
            'aria-current',
            'aria-describedby',
            'aria-description',
            'aria-details',
            'aria-disabled',
            'aria-dropeffect',
            'aria-errormessage',
            'aria-expanded',
            'aria-flowto',
            'aria-grabbed',
            'aria-haspopup',
            'aria-hidden',
            'aria-invalid',
            'aria-keyshortcuts',
            'aria-label',
            'aria-labelledby',
            'aria-level',
            'aria-live',
            'aria-multiline',
            'aria-multiselectable',
            'aria-orientation',
            'aria-owns',
            'aria-placeholder',
            'aria-posinset',
            'aria-pressed',
            'aria-readonly',
            'aria-relevant',
            'aria-required',
            'aria-roledescription',
            'aria-rowcount',
            'aria-rowindex',
            'aria-rowspan',
            'aria-selected',
            'aria-setsize',
            'aria-sort',
            'aria-valuemax',
            'aria-valuemin',
            'aria-valuenow',
            'aria-valuetext'
        ];
        return in_array($name, $valid_aria);
    }

    /**
     * 执行isValidDataAttr操作。
     *
     * @param mixed $name 参数name。
     * @return bool 返回结果。
     */
    private function isValidDataAttr($name)
    {
        $suffix = substr($name, 5);
        if ($suffix === '' || $suffix === false) {
            return false;
        }
        if (preg_match('/^[a-z][a-z0-9\-]*$/i', $suffix)) {
            return true;
        }
        return false;
    }

    /**
     * 执行filterUrl操作。
     *
     * @param mixed $url 参数url。
     * @param mixed $allowed_protocols 参数allowed_protocols。
     * @param string $attr_name 参数attr_name。
     * @return mixed 返回结果。
     */
    private function filterUrl($url, $allowed_protocols, $attr_name = '')
    {
        $url = trim($url);
        $url = preg_replace('/[\x00-\x20]+/', '', $url); // 移除控制字符和空白

        // 递归解码URL编码（最多10次，防止双重编码绕过）
        $decoded_url = $url;
        $max_iterations = 10;
        $i = 0;
        while ($i < $max_iterations) {
            $new = urldecode($decoded_url);
            if ($new === $decoded_url) {
                break;
            }
            $decoded_url = $new;
            $i++;
        }

        // 用于协议检测的检查字符串（去除空格等）
        $url_check = strtolower($decoded_url);
        $url_check = preg_replace('/[\s\x00-\x1f]+/', '', $url_check);

        // 检测危险协议
        if ($this->isDangerousProtocol($url_check)) {
            return false;
        }

        // 检查协议白名单
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $url_check, $matches)) {
            $protocol = strtolower($matches[1]);

            // data协议特殊处理
            if ($protocol === 'data') {
                if ($attr_name === 'src' && preg_match('/^data:image\/(png|jpe?g|gif|webp|svg\+xml);base64,/i', $decoded_url)) {
                    return $decoded_url;
                }
                return false;
            }

            if (!in_array($protocol, $allowed_protocols)) {
                return false;
            }
        }

        // 返回解码后的URL（与浏览器解析行为一致）
        return $decoded_url;
    }

    /**
     * 执行isDangerousProtocol操作。
     *
     * @param mixed $url 参数url。
     * @return bool 返回结果。
     */
    private function isDangerousProtocol($url)
    {
        $url = preg_replace('/[\s\x00-\x1f\xc0\xc1]+/u', '', $url);
        $url = strtolower($url);

        $dangerous = [
            'javascript',
            'vbscript',
            'livescript',
            'mocha',
            'ecmascript'
        ];

        foreach ($dangerous as $proto) {
            $pattern = '/^' . preg_quote($proto, '/') . '\s*:/i';
            if (preg_match($pattern, $url)) {
                return true;
            }
            $encoded_check = $this->decodeProtocolEncoding($url);
            if (preg_match($pattern, $encoded_check)) {
                return true;
            }
        }

        if (preg_match('/^data:text\/html/i', $url)) {
            return true;
        }

        return false;
    }

    /**
     * 执行decodeProtocolEncoding操作。
     *
     * @param mixed $url 参数url。
     * @return mixed 返回结果。
     */
    private function decodeProtocolEncoding($url)
    {
        $decoded = $url;
        $max_iterations = 10;
        $i = 0;
        while ($i < $max_iterations) {
            $new_decoded = preg_replace_callback('/&#([0-9]+);?/', function ($m) {
                return chr(intval($m[1]));
            }, $decoded);
            $new_decoded = preg_replace_callback('/&#x([0-9a-f]+);?/i', function ($m) {
                return chr(hexdec($m[1]));
            }, $new_decoded);
            $new_decoded = preg_replace('/\\\\([0-9a-f]{1,6})\s?/i', '', $new_decoded);

            if ($new_decoded === $decoded) {
                break;
            }
            $decoded = $new_decoded;
            $i++;
        }
        $decoded = preg_replace('/[\s\t\r\n\x00-\x1f]+/', '', $decoded);
        return strtolower($decoded);
    }

    /**
     * 执行filterStyle操作。
     *
     * @param mixed $style 参数style。
     * @return mixed 返回结果。
     */
    private function filterStyle($style)
    {
        // 移除CSS注释
        $style = preg_replace('/\/\*.*?\*\//s', '', $style);

        // 处理CSS反斜杠转义：十六进制转义删除，普通转义去掉反斜杠保留字符
        $style = preg_replace_callback('/\\\\([0-9a-f]{1,6}\s?|.)/i', function ($m) {
            if (preg_match('/^[0-9a-f]{1,6}\s*$/i', $m[1])) {
                return '';  // 十六进制转义：删除（防止拼出危险协议字符）
            }
            return $m[1];  // 普通反斜杠转义：去掉反斜杠，保留字符本身
        }, $style);

        // 移除换行、回车、Tab等空白，防止拆分关键字绕过
        $style = preg_replace('/[\r\n\t]+/', ' ', $style);

        // 用回调处理所有 url()，复用 filter_url() 进行协议检测
        $self = $this;
        $style = preg_replace_callback('/url\s*\(\s*(["\']?)(.+?)\1\s*\)/i', function ($m) use ($self) {
            $url = trim($m[2]);
            // 允许 http, https
            $allowed = array('http', 'https');
            $filtered = $self->filterUrl($url, $allowed, 'style');
            if ($filtered === false) {
                // 额外检查 data:image/* 白名单
                if (preg_match('/^data:image\/(png|jpe?g|gif|webp|svg\+xml|bmp|ico);base64,/i', $url)) {
                    return 'url(' . $m[1] . $url . $m[1] . ')';
                }
                return '';  // 危险URL，移除整个 url()
            }
            return 'url(' . $m[1] . $filtered . $m[1] . ')';
        }, $style);

        // 检测非url相关的危险模式
        $dangerous_patterns = [
            '/expression\s*\(/i',
            '/behavior\s*:/i',
            '/-moz-binding\s*:/i',
            '/@import/i',
            '/-o-link\s*:/i',
            '/-o-link-source\s*:/i',
        ];

        $style_lower = strtolower($style);
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $style_lower)) {
                return '';
            }
        }

        $style = preg_replace('/[<>]/', '', $style);

        return $style;
    }

    /**
     * 执行filterSrcset操作。
     *
     * @param mixed $srcset 参数srcset。
     * @return mixed 返回结果。
     */
    private function filterSrcset($srcset)
    {
        $parts = preg_split('/\s*,\s*/', $srcset);
        $filtered = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            if (preg_match('/^(\S+)(\s+.+)?$/', $part, $m)) {
                $url = $m[1];
                $descriptor = isset($m[2]) ? $m[2] : '';

                $filtered_url = $this->filterUrl($url, array('http', 'https'), 'srcset');
                if ($filtered_url === false) {
                    // 额外允许 data:image/*
                    if (preg_match('/^data:image\/(png|jpe?g|gif|webp|svg\+xml|bmp|ico);base64,/i', $url)) {
                        $filtered_url = $url;
                    } else {
                        continue; // 跳过不安全的URL
                    }
                }
                $filtered[] = $filtered_url . $descriptor;
            }
        }

        return implode(', ', $filtered);
    }

    /**
     * 执行escapeAttrValue操作。
     *
     * @param mixed $value 参数value。
     * @return mixed 返回结果。
     */
    private function escapeAttrValue($value)
    {
        $value = str_replace('<', '&lt;', $value);
        $value = str_replace('"', '&quot;', $value);
        return $value;
    }

    /**
     * 执行buildAttrString操作。
     *
     * @param mixed $attrs 参数attrs。
     * @return mixed 返回结果。
     */
    private function buildAttrString($attrs)
    {
        if (empty($attrs)) {
            return '';
        }

        $parts = [];
        foreach ($attrs as $name => $value) {
            if (in_array($name, $this->boolean_attrs)) {
                $parts[] = $name;
            } else {
                $parts[] = $name . '="' . $value . '"';
            }
        }

        return ' ' . implode(' ', $parts);
    }

    /**
     * 执行checkNesting操作。
     *
     * @param mixed $tag 参数tag。
     * @param mixed $is_closing 参数is_closing。
     * @return bool 返回结果。
     */
    private function checkNesting($tag, $is_closing)
    {
        if ($is_closing) {
            return true;
        }

        $parent = $this->getCurrentParent();

        if ($tag === 'a' && $this->isInStack('a')) {
            return false;
        }

        if ($tag === 'form' && $this->isInStack('form')) {
            return false;
        }

        if ($tag === 'button') {
            if ($this->isInStack('button') || $this->isInStack('a')) {
                return false;
            }
        }

        if ($parent === 'p' && in_array($tag, $this->block_elements)) {
            return false;
        }

        if ($tag === 'li' && $parent !== null && $parent !== 'ul' && $parent !== 'ol' && $parent !== 'menu') {
            $in_list = $this->isInStack('ul') || $this->isInStack('ol') || $this->isInStack('menu');
            if (!$in_list) {
                return false;
            }
        }

        if (in_array($tag, ['td', 'th']) && $parent !== 'tr') {
            if (!$this->isInStack('tr')) {
                return false;
            }
        }

        if ($tag === 'tr') {
            if ($parent !== null && !in_array($parent, ['table', 'thead', 'tbody', 'tfoot'])) {
                if (!$this->isInStack('table')) {
                    return false;
                }
            }
        }

        // tbody/thead/tfoot 必须在 table 内
        if (in_array($tag, ['tbody', 'thead', 'tfoot'])) {
            if (!$this->isInStack('table')) {
                return false;
            }
        }

        // caption 必须在 table 内
        if ($tag === 'caption') {
            if (!$this->isInStack('table')) {
                return false;
            }
        }

        // colgroup 必须在 table 内
        if ($tag === 'colgroup') {
            if (!$this->isInStack('table')) {
                return false;
            }
        }

        // col 必须在 colgroup 或 table 内
        if ($tag === 'col') {
            if (!$this->isInStack('colgroup') && !$this->isInStack('table')) {
                return false;
            }
        }

        // optgroup 必须在 select 内
        if ($tag === 'optgroup') {
            if (!$this->isInStack('select')) {
                return false;
            }
        }

        // option 必须在 select 或 datalist 或 optgroup 内
        if ($tag === 'option') {
            if (!$this->isInStack('select') && !$this->isInStack('datalist') && !$this->isInStack('optgroup')) {
                return false;
            }
        }

        // dt/dd 必须在 dl 内
        if (in_array($tag, ['dt', 'dd'])) {
            if (!$this->isInStack('dl')) {
                return false;
            }
        }

        // figcaption 必须在 figure 内
        if ($tag === 'figcaption') {
            if (!$this->isInStack('figure')) {
                return false;
            }
        }

        // summary 必须在 details 内
        if ($tag === 'summary') {
            if (!$this->isInStack('details')) {
                return false;
            }
        }

        // legend 必须在 fieldset 内
        if ($tag === 'legend') {
            if (!$this->isInStack('fieldset')) {
                return false;
            }
        }

        return true;
    }

    /**
     * 执行getCurrentParent操作。
     *
     * @return mixed 返回结果。
     */
    private function getCurrentParent()
    {
        if (empty($this->tag_stack)) {
            return $this->config['parent'];
        }
        return end($this->tag_stack);
    }

    /**
     * 执行isInStack操作。
     *
     * @param mixed $tag 参数tag。
     * @return mixed 返回结果。
     */
    private function isInStack($tag)
    {
        return in_array($tag, $this->tag_stack);
    }

    /**
     * 执行pushTagStack操作。
     *
     * @param mixed $tag 参数tag。
     * @return void 返回结果。
     */
    private function pushTagStack($tag)
    {
        $this->tag_stack[] = $tag;
    }

    /**
     * 执行popTagStack操作。
     *
     * @param mixed $tag 参数tag。
     * @return void 返回结果。
     */
    private function popTagStack($tag)
    {
        $pos = array_search($tag, array_reverse($this->tag_stack, true));
        if ($pos !== false) {
            $this->tag_stack = array_slice($this->tag_stack, 0, $pos);
        }
    }

    /**
     * 执行balanceTags操作。
     *
     * @param mixed $html 参数html。
     * @return mixed 返回结果。
     */
    private function balanceTags($html)
    {
        $stack = [];

        $html = preg_replace_callback('/<(\/?)([a-z][a-z0-9]*)[^>]*>/is', function ($m) use (&$stack) {
            $is_closing = $m[1] === '/';
            $tag = strtolower($m[2]);

            if (in_array($tag, $this->void_elements)) {
                return $m[0];
            }

            if ($is_closing) {
                $pos = array_search($tag, array_reverse($stack, true));
                if ($pos !== false) {
                    $unclosed = array_slice($stack, $pos + 1);
                    $stack = array_slice($stack, 0, $pos);
                    $close_tags = '';
                    foreach (array_reverse($unclosed) as $t) {
                        $close_tags .= '</' . $t . '>';
                    }
                    return $close_tags . $m[0];
                }
                // 如果找不到对应开放标签
                if ($this->config['keep_bad'] === 'escape') {
                    return htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
                }
                return '';
            } else {
                $stack[] = $tag;
                return $m[0];
            }
        }, $html);

        foreach (array_reverse($stack) as $tag) {
            $html .= '</' . $tag . '>';
        }

        return $html;
    }

    /**
     * 执行comment操作。
     *
     * @param mixed $html 参数html。
     * @return string 过滤后的 HTML。
     */
    public function comment($html)
    {
        return $this->filterHtml($html, [
            'safe' => true,
            'elements' => 'p,br,b,strong,i,em,u,s,a,blockquote,ul,ol,li,code,pre,img'
        ]);
    }

    /**
     * 执行content操作。
     *
     * @param mixed $html 参数html。
     * @return string 过滤后的 HTML。
     */
    public function content($html)
    {
        return $this->filterHtml($html, [
            'safe' => true,
            'elements' => '*',
            'deny_elements' => ''
        ]);
    }

    /**
     * 执行rss操作。
     *
     * @param mixed $html 参数html。
     * @return string 过滤后的 HTML。
     */
    public function rss($html)
    {
        return $this->filterHtml($html, [
            'safe' => true,
            'elements' => 'p,br,b,strong,i,em,u,s,a,blockquote,ul,ol,li,code,pre,img,h1,h2,h3,h4,h5,h6,div,span,figure,figcaption,table,thead,tbody,tfoot,tr,td,th'
        ]);
    }

    /**
     * 执行plain操作。
     *
     * @param mixed $html 参数html。
     * @return mixed 返回结果。
     */
    public function plain($html)
    {
        if (!is_string($html) || $html === '') {
            return '';
        }

        $html = $this->cleanBom($html);
        $html = $this->cleanNullBytes($html);
        $html = $this->cleanControlChars($html);

        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<\/(div|h[1-6]|li|tr)>/i', "\n", $html);

        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $html = preg_replace('/[ \t]+/', ' ', $html);
        $html = preg_replace('/\n{3,}/', "\n\n", $html);
        $html = trim($html);

        return $html;
    }

    /**
     * 执行text操作。
     *
     * @param mixed $value 参数value。
     * @return mixed 返回结果。
     */
    public function text($value)
    {
        if (!is_scalar($value) && !is_null($value)) {
            return '';
        }

        $text = is_null($value) ? '' : (string) $value;
        if ($text === '') {
            return '';
        }

        $text = $this->cleanBom($text);
        $text = $this->cleanNullBytes($text);
        $text = $this->cleanControlChars($text);
        $text = strip_tags($text);
        $text = trim($text);

        return $text;
    }

    /**
     * 执行post操作。
     *
     * @param mixed $value 参数value。
     * @return mixed 返回结果。
     */
    public function post($value)
    {
        if (is_array($value)) {
            $out = array();
            foreach ($value as $k => $v) {
                $out[$k] = $this->post($v);
            }
            return $out;
        }

        if (is_string($value)) {
            $value = $this->cleanBom($value);
            $value = $this->cleanNullBytes($value);
            $value = $this->cleanControlChars($value);
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        if (is_null($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
