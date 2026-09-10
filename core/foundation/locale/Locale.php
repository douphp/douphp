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

namespace Dou\Core\Foundation\Locale;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 当前请求选定的语言（请求作用域单例）。
 *
 * 语义边界：
 *  - 「语言选择」= 当前请求选定使用哪个语言包（由 URL 前缀 / `?lang=` 解析得出）。本类承载此选择。
 *  - 「语言包译串表」= SystemBootstrap 装配的语言文件清单（经 InitTrait::loadLanguageFiles require）中 $_LANG 键值合并体。由 {@see \Dou\Core\Foundation\Lang\LangBag} 承载。
 *  - 二者通过 helper 区分：locale() 取本类实例，lang('key') 查 LangBag。
 *
 * 启动阶段由 Init 解析 URL 后调用 set() 写入；features.language 关闭 / 默认语言时
 * 整体保持空（isActive() 为 false）。
 *
 * 业务侧通过 helper locale() 唯一访问，不直接 new。
 */
class Locale
{
    /** @var string 'rewrite_open' / 'rewrite_close' / ''（空表示默认语言） */
    private $mode = '';

    /** @var string 多语言标识（连字符形式，如 'zh-cn'/'en-us'） */
    private $sign = '';

    /** @var string 语言包目录名（下划线形式，如 'zh_cn'/'en_us'） */
    private $pack = '';

    /**
     * 写入当前语言选择（一次性设定）。
     *
     * @param string $mode
     * @param string $sign
     * @param string $pack
     * @return void
     */
    public function set($mode, $sign, $pack)
    {
        $this->mode = (string) $mode;
        $this->sign = (string) $sign;
        $this->pack = (string) $pack;
    }

    /**
     * 重置为默认语言（清空语言选择）。
     *
     * @return void
     */
    public function reset()
    {
        $this->mode = '';
        $this->sign = '';
        $this->pack = '';
    }

    /**
     * 当前语言模式。
     *
     * @return string
     */
    public function mode()
    {
        return $this->mode;
    }

    /**
     * 当前语言标识（连字符形式）。
     *
     * @return string
     */
    public function sign()
    {
        return $this->sign;
    }

    /**
     * 当前语言包目录名（下划线形式）。
     *
     * @return string
     */
    public function pack()
    {
        return $this->pack;
    }

    /**
     * 是否处于非默认语言模式（等价于原 `!empty(state.lang.current)`）。
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->pack !== '';
    }

    /**
     * 兼容形态：返回原 `state.lang.current` 数组等价结构（默认语言时返回空数组）。
     *
     * @return array
     */
    public function toArray()
    {
        if (!$this->isActive()) {
            return array();
        }
        return array(
            'mode' => $this->mode,
            'sign' => $this->sign,
            'pack' => $this->pack,
        );
    }
}
