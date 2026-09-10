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

namespace Dou\Core\Model\Concerns;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 内容模型通用字段修改器
 */
trait ContentMutators
{
    /**
     * @param mixed $value
     * @return string
     */
    public function setTitleAttribute($value, $data = array(), $writeMode = '')
    {
        return trim((string) $value);
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function setSlugAttribute($value, $data = array(), $writeMode = '')
    {
        return !empty($value) ? trim((string) $value) : '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function setDefinedAttribute($value, $data = array(), $writeMode = '')
    {
        return !empty($value) ? str_replace("\r\n", ',', (string) $value) : '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function setKeywordsAttribute($value, $data = array(), $writeMode = '')
    {
        return (string) $value;
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function setDescriptionAttribute($value, $data = array(), $writeMode = '')
    {
        return (string) $value;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function setSortAttribute($value, $data = array(), $writeMode = '')
    {
        return is_numeric($value) ? (int) $value : '';
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function setAddTimeAttribute($value, $data = array(), $writeMode = '')
    {
        if ($value === null || $value === '') {
            return '';
        }

        $time = strtotime((string) $value);
        return $time ? $time : '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function setPriceAttribute($value, $data = array(), $writeMode = '')
    {
        return trim((string) $value);
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function setPromotePriceAttribute($value, $data = array(), $writeMode = '')
    {
        return trim((string) $value);
    }
}
