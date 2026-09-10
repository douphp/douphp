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
 * 通用格式校验工具类
 *
 * 全部为无状态纯静态调用，与 Arr / Str / Num 同层。
 * 与现网 Validator / FormRequest / Request 业务规则层共享同一份正则定义。
 * 兼容 PHP 5.6+，无外部依赖。
 */
class Check
{
    /**
     * 是否为纯数字字符串（^[0-9]+$）
     *
     * @param mixed $number
     * @return bool
     */
    public static function number($number)
    {
        if ($number === null || !is_string($number)) {
            return false;
        }

        if (preg_match("/^[0-9]+$/", $number)) {
            return true;
        }

        return false;
    }

    /**
     * 是否为整数串（^[0-9]+$）
     *
     * @param mixed $int
     * @return bool
     */
    public static function integer($int)
    {
        if ($int === null || !is_string($int)) {
            return false;
        }
        return preg_match('/^[0-9]+$/', $int) === 1;
    }

    /**
     * 将批量勾选的原始值归一化为去重正整数数组。
     *
     * 用于后台批量操作（删除 / 移动分类）的 checkbox 入参消毒：逐项 trim 后
     * 仅保留纯数字串，转 int 并去重；非数组或无合法项时返回空数组。
     *
     * @param mixed $raw
     * @return int[]
     */
    public static function intIds($raw)
    {
        if (!is_array($raw)) {
            return array();
        }

        $ids = array();
        foreach ($raw as $id) {
            $id = trim((string) $id);
            if (self::number($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 是否为小写字母串（^[a-z]+$）
     *
     * @param mixed $letter
     * @return bool
     */
    public static function letter($letter)
    {
        if (!$letter === null || !is_string($letter)) {
            return false;
        }

        if (preg_match("/^[a-z]+$/", $letter)) {
            return true;
        }
    }

    /**
     * 是否为基础字符串（^[A-Za-z0-9._-]+$）
     *
     * @param mixed $string
     * @return bool
     */
    public static function basicString($string)
    {
        if ($string === null || !is_string($string)) {
            return false;
        }

        if (preg_match("/^[A-Za-z0-9._-]+$/", $string)) {
            return true;
        }
    }

    /**
     * 是否为字母数字串（^[a-zA-Z0-9]+$）
     *
     * @param mixed $char
     * @return bool
     */
    public static function letterNumber($char)
    {
        if ($char === null || !is_string($char)) {
            return false;
        }

        if (preg_match("/^[a-zA-Z0-9]+$/", $char)) {
            return true;
        }
    }

    /**
     * URL Slug 格式：小写字母数字、连字符、下划线，且不能首尾是分隔符
     *
     * @param mixed $slug
     * @return bool
     */
    public static function slug($slug)
    {
        if ($slug === null || !is_string($slug) || $slug === '') {
            return false;
        }

        return preg_match("/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/", $slug) === 1;
    }

    /**
     * 是否为 4-6 位字母数字图形验证码
     *
     * @param mixed $captcha
     * @return bool
     */
    public static function captcha($captcha)
    {
        if ($captcha === null || !is_string($captcha)) {
            return false;
        }

        return (bool) preg_match("/^[A-Za-z0-9]{4,6}$/", $captcha);
    }

    /**
     * 是否为合法邮箱
     *
     * @param mixed $email
     * @return bool
     */
    public static function email($email)
    {
        if ($email === null || !is_string($email)) {
            return false;
        }

        if (preg_match("/^[\w-]+(\.[\w-]+)*@[\w-]+(\.[\w-]+)+$/", $email)) {
            return true;
        }
    }

    /**
     * 是否为合法手机号（13-19 段，11 位）
     *
     * @param mixed $mobile
     * @return bool
     */
    public static function telphone($mobile)
    {
        if ($mobile === null || !is_string($mobile)) {
            return false;
        }

        if (preg_match("/^(13[0-9]|14[0-9]|15[0-9]|16[0-9]|17[0-9]|18[0-9]|19[0-9])\d{8}$/", $mobile)) {
            return true;
        }
    }

    /**
     * 是否为金额字符串（数字与小数点）
     *
     * @param mixed $money
     * @return bool
     */
    public static function money($money)
    {
        if ($money === null || !is_string($money)) {
            return false;
        }

        if (preg_match("/^[0-9.]+$/", $money)) {
            return true;
        }
    }

    /**
     * 是否为合法身份证号
     *
     * @param mixed $idcard
     * @return bool
     */
    public static function idcard($idcard)
    {
        if ($idcard === null || !is_string($idcard)) {
            return false;
        }

        if (preg_match("/^[1-9]\d{7}((0\d)|(1[0-2]))(([0|1|2]\d)|3[0-1])\d{3}$|^[1-9]\d{5}[1-9]\d{3}((0\d)|(1[0-2]))(([0|1|2]\d)|3[0-1])\d{3}([0-9]|X)$/", $idcard)) {
            return true;
        }
    }

    /**
     * 是否为合法 QQ 号
     *
     * @param mixed $qq
     * @return bool
     */
    public static function qq($qq)
    {
        if ($qq === null || !is_string($qq)) {
            return false;
        }

        if (preg_match("/^[1-9]*[1-9][0-9]*$/", $qq)) {
            return true;
        }
    }

    /**
     * 是否为合法邮政编码
     *
     * @param mixed $postcode
     * @return bool
     */
    public static function postcode($postcode)
    {
        if ($postcode === null || !is_string($postcode)) {
            return false;
        }

        if (preg_match("/^[A-Za-z0-9_-\s]*$/", $postcode)) {
            return true;
        }
    }

    /**
     * 是否为价格字符串（数字与小数点）
     *
     * @param mixed $price
     * @return bool
     */
    public static function price($price)
    {
        if ($price === null || !is_string($price)) {
            return false;
        }

        if (preg_match("/^[0-9.]+$/", $price)) {
            return true;
        }
    }

    /**
     * 是否为合法 URL
     *
     * @param mixed $url
     * @return bool
     */
    public static function url($url)
    {
        if ($url === null || !is_string($url)) {
            return false;
        }

        if (preg_match("/^(http(s)?:\/\/)?(www\.)?[A-Za-z0-9-]+\.[A-Za-z0-9]+\.?[A-Za-z0-9]+(\/)?$/", $url)) {
            return true;
        }
    }

    /**
     * 是否为合法域名（含 http/https，IP 地址不通过）
     *
     * @param mixed $domain
     * @return bool
     */
    public static function domain($domain)
    {
        if ($domain === null || !is_string($domain)) {
            return false;
        }

        if (preg_match('/^https?:\/\/(www\.)?(?!\d+\.\d+\.\d+\.\d+$)[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*\.[a-zA-Z]{2,}([\/\w\.-]*)*\/?$/', $domain)) {
            return true;
        }
    }

    /**
     * 是否为合法路由动作段（^[a-z_]+$）
     *
     * @param string $actionSegment
     * @return bool
     */
    public static function routeActionSegment($actionSegment = '')
    {
        if ($actionSegment === null || !is_string($actionSegment)) {
            return false;
        }
        if ($actionSegment === '') {
            return false;
        }

        return preg_match('/^[a-z_]+$/', $actionSegment) === 1;
    }

    /**
     * 是否为合法 rec 段（语义同 routeActionSegment）
     *
     * @param string $rec
     * @return bool
     */
    public static function rec($rec = '')
    {
        return self::routeActionSegment($rec);
    }

    /**
     * 是否为合法扩展 ID（^[A-Za-z0-9-_.]+$）
     *
     * @param mixed $extend_id
     * @return bool
     */
    public static function extendId($extend_id)
    {
        if ($extend_id === null || !is_string($extend_id)) {
            return false;
        }

        if (preg_match("/^[A-Za-z0-9-_.]+$/", $extend_id)) {
            return true;
        }
    }

    /**
     * 是否为字母数字下划线短横线串（^[a-zA-Z0-9_-]+$）
     *
     * @param mixed $str
     * @return bool
     */
    public static function alphaDash($str)
    {
        if ($str === null || !is_string($str)) {
            return false;
        }

        if (preg_match("/^[a-zA-Z0-9_-]+$/", $str)) {
            return true;
        }

        return false;
    }

    /**
     * 是否为合法分类 / class 段（字母数字+下划线+短横线+中文）
     *
     * @param string $class
     * @return bool
     */
    public static function className($class = '')
    {
        if ($class === null || !is_string($class)) {
            return false;
        }

        if ($class) {
            if (preg_match("/^[a-zA-Z0-9-_\x{4e00}-\x{9fa5}]+$/u", $class)) {
                return true;
            }
        }
    }

    /**
     * 是否为合法搜索关键字（trim 后非空）
     *
     * @param mixed $search_keyword
     * @return bool
     */
    public static function searchKeyword($search_keyword)
    {
        if ($search_keyword === null || !is_string($search_keyword)) {
            return false;
        }

        return trim($search_keyword) !== '';
    }

    /**
     * 是否为合法管理员账号（首字符为字母，4-20 位，含字母数字点和下划线）
     *
     * @param mixed $username
     * @return bool
     */
    public static function adminAccount($username)
    {
        if ($username === null || !is_string($username)) {
            return false;
        }

        if (preg_match("/^[a-zA-Z]{1}([0-9a-zA-Z]|[._]){3,19}$/", $username)) {
            return true;
        }
    }

    /**
     * 是否为合法用户名（手机号、邮箱、或不含非法字符）
     *
     * @param mixed $username
     * @return bool
     */
    public static function username($username)
    {
        if ($username === null || !is_string($username)) {
            return false;
        }

        if (self::telphone($username) || self::email($username)) {
            return true;
        } elseif (!self::illegalChar($username)) {
            return true;
        }
    }

    /**
     * 是否为合法密码（长度 6+）
     *
     * @param mixed $password
     * @return bool
     */
    public static function password($password)
    {
        if ($password === null || !is_string($password)) {
            return false;
        }

        if (preg_match("/^.{6,}$/", $password)) {
            return true;
        }
    }

    /**
     * 是否包含非法字符
     *
     * @param mixed $char
     * @return bool
     */
    public static function illegalChar($char)
    {
        if ($char === null || !is_string($char)) {
            return false;
        }

        if (preg_match("/[\\\~@$%^&=+{};'\"<>\/]/", $char)) {
            return true;
        }
    }

    /**
     * 是否包含中文字符
     *
     * @param mixed $value
     * @return bool
     */
    public static function chinese($value)
    {
        if ($value === null || !is_string($value)) {
            return false;
        }

        if (preg_match("/[\x{4e00}-\x{9fa5}]+/u", $value)) {
            return true;
        }
    }

    /**
     * 长度是否在 (0, $length] 区间
     *
     * @param mixed $value
     * @param int $length
     * @return bool
     */
    public static function length($value, $length)
    {
        if (strlen($value) > 0 && strlen($value) <= $length) {
            return true;
        }
    }

    /**
     * 是否为合法表名（^[A-Za-z0-9-_]+$）
     *
     * @param mixed $table_name
     * @return bool
     */
    public static function tableName($table_name)
    {
        if ($table_name === null || !is_string($table_name)) {
            return false;
        }

        if (preg_match("/^[A-Za-z0-9-_]+$/", $table_name)) {
            return true;
        }
    }

    /**
     * 是否为合法附件 number（^[a-zA-Z0-9]+\.file$）
     *
     * @param mixed $file_number
     * @return bool
     */
    public static function fileNumber($file_number)
    {
        if ($file_number === null || !is_string($file_number)) {
            return false;
        }

        if (preg_match('/^[a-zA-Z0-9]+\.file$/', $file_number)) {
            return true;
        }
    }

    /**
     * 是否为合法语言包目录名（^[a-z_]+$）
     *
     * @param mixed $language_pack
     * @return bool
     */
    public static function languagePack($language_pack)
    {
        if ($language_pack === null || !is_string($language_pack)) {
            return false;
        }

        if (preg_match("/^[a-z_]+$/", $language_pack)) {
            return true;
        }
    }

    /**
     * 是否为 YYYY-MM-DD 日期串
     *
     * @param mixed $date
     * @return bool
     */
    public static function date($date)
    {
        if ($date === null || !is_string($date)) {
            return false;
        }

        if (preg_match("/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/", $date)) {
            return true;
        }
    }

    /**
     * 是否为 HH:MM 或 HH:MM-HH:MM / HH:MM~HH:MM 时间串
     *
     * @param mixed $time
     * @return bool
     */
    public static function time($time)
    {
        if ($time === null || !is_string($time)) {
            return false;
        }

        if (preg_match("/^(?:[01]\d|2[0-3]):[0-5]\d(?:[~-](?:[01]\d|2[0-3]):[0-5]\d)?$/", $time)) {
            return true;
        }
    }

    /**
     * 是否为 10 位 unix 时间戳串
     *
     * @param mixed $strtotime
     * @return bool
     */
    public static function strtotime($strtotime)
    {
        if ($strtotime === null || !is_string($strtotime)) {
            return false;
        }

        if (preg_match("/^\d{10}$/", $strtotime)) {
            return true;
        }
    }
}
