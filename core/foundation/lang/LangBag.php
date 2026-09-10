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

namespace Dou\Core\Foundation\Lang;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 当前请求的「语言包译串表」载体（请求作用域单例）。
 *
 * 语义边界：
 *  - 「语言包」= 由 SystemBootstrap 装配的语言文件清单（经 InitTrait::loadLanguageFiles require）后
 *    得到的 $_LANG 键值表，永远加载，与 features.language 无关。本类承载此表。
 *  - 「多语言功能」= features.language 开关下的语言切换 / language_value 查询 /
 *    langBox / dataLangFormat 等扩展能力。由 LanguageContract / LanguageService 承载。
 *  - 二者通过 helper 区分：language() 取契约实例，lang('key') 仅查本 Bag。
 *
 * Init 阶段由 InitTrait::loadLanguageFiles() 调用 replace() 整表写入；
 * 业务侧通过 helper（lang/lang_set/lang_has/lang_all）唯一访问，不直接 new。
 */
class LangBag
{
    /** @var array 译串键值表 */
    private $items = array();

    /**
     * 读取译串，不存在时返回 $default。
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = '')
    {
        $key = (string) $key;
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }
        return $default;
    }

    /**
     * 写入单个译串。
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set($key, $value)
    {
        $this->items[(string) $key] = $value;
    }

    /**
     * 判断译串键是否存在（专门用于 if 条件 / 复合判断这类只关心「键是否存在」的场景）。
     *
     * @param string $key
     * @return bool
     */
    public function has($key)
    {
        return array_key_exists((string) $key, $this->items);
    }

    /**
     * 整表 by-value 返回（供按值传 $lang 的位置使用，如邮件、Smarty assign、Validator 构造等）。
     *
     * @return array
     */
    public function all()
    {
        return $this->items;
    }

    /**
     * 增量合并：以传入数组覆盖已有键，未在传入数组中的已有键保留。
     *
     * @param array $items
     * @return void
     */
    public function merge(array $items)
    {
        foreach ($items as $key => $value) {
            $this->items[(string) $key] = $value;
        }
    }

    /**
     * 整表替换（Init 加载完成阶段调用）。
     *
     * @param array $items
     * @return void
     */
    public function replace(array $items)
    {
        $normalized = array();
        foreach ($items as $key => $value) {
            $normalized[(string) $key] = $value;
        }
        $this->items = $normalized;
    }
}
