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

namespace Dou\Core\Web\Validation;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Infra\Database\Connection;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 声明式验证器
 *
 * 通过规则字符串对请求数据进行校验，失败时抛出 DomainException。
 * 规则用 | 分隔，如 'required|alpha_dash|unique:article_category,slug,category_id'。
 *
 * 支持规则：
 *   required          -> 必填
 *   required_if:field,val1,val2 -> 当 field 值命中 val1/val2 时必填
 *   same:field        -> 必须与指定字段值一致
 *   different:field   -> 必须与指定字段值不同
 *   alpha_dash        -> 字母、数字、下划线、横线
 *   numeric           -> 数字（整数或小数）
 *   integer           -> 整数
 *   float             -> 浮点数
 *   price             -> 价格（数字与小数点，与 Check::price 一致）
 *   digits:N          -> 必须为 N 位数字
 *   digits_between:N,M -> 必须为 N~M 位数字
 *   email             -> 邮箱
 *   url               -> URL
 *   domain            -> 域名
 *   ip                -> IP地址
 *   date              -> 日期（能被strtotime解析）
 *   date_format:fmt   -> 日期格式匹配（如 Y-m-d）
 *   boolean           -> 布尔值（true/false/1/0/yes/no/on/off）
 *   max:N             -> 最大长度（字符数）
 *   min:N             -> 最小长度
 *   between:N,M       -> 长度在N~M之间
 *   max_value:N       -> 数值最大值
 *   min_value:N       -> 数值最小值
 *   between_value:N,M -> 数值在N~M之间
 *   unique:table,field[,excludeField] -> 数据库唯一性
 *   exists:table,field -> 数据库存在性
 *   confirmed         -> 与 {field}_confirm 字段一致
 *   regex:/pattern/   -> 正则匹配
 *   in:val1,val2      -> 值在列表中
 *   not_in:val1,val2  -> 值不在列表中
 *   starts_with:str   -> 以指定字符串开头
 *   ends_with:str     -> 以指定字符串结尾
 *   alpha             -> 仅字母
 *   alpha_num         -> 字母和数字
 *   phone             -> 中国手机号（简单正则）
 *   password          -> 密码格式（与 Check::password 一致）
 *   illegal_char      -> 不能包含非法字符（与 Check::illegalChar 一致）
 *   username          -> 用户名格式（与 Check::username 一致）
 *   admin_account     -> 管理员账号格式（与 Check::adminAccount 一致）
 *   slug              -> 别名/URL 片段（与 Check::slug 一致）
 *   idcard            -> 身份证号格式
 *   qq                -> QQ号格式
 *   postcode          -> 邮政编码格式
 *   json              -> 有效JSON字符串
 *   array             -> 数组
 *   accepted          -> 接受（用于协议勾选）
 *   file              -> 必须为有效上传文件（$_FILES[$field] 存在且 error=0）
 *   image             -> 必须为有效上传图片（扩展名命中 filesystems.upload_defaults.allow_extensions 或 jpg/jpeg/png/gif/webp/svg/ico/bmp）
 *   mimes:jpg,png     -> 上传文件扩展名（小写）必须命中清单；空清单时回退到 filesystems.upload_defaults.allow_extensions
 *   max_kb:N          -> 上传文件大小不超过 N KB；N 留空时回退到 filesystems.upload_defaults.upload_max_kb
 *   dimensions:max_width=W,max_height=H,min_width=...,min_height=...,ratio=W:H -> 图片尺寸约束
 *
 * 错误消息优先级：
 *   1. $messages['field.rule'] 自定义消息（点号格式）
 *   2. 语言包中的字段专属消息（键名 {field}_{rule}，下划线）
 *   3. 语言包中的通用规则消息（键名 validator_{rule}）
 *   4. 占位符 [Missing: field.rule]（开发阶段提示）
 */
class Validator
{
    /** @var Connection 数据库连接对象（由 DB::getFacadeRoot() 注入） */
    private $db;

    /** @var array 语言包数组，键名为 validator_xxx 或字段名等 */
    private $lang;

    /** @var string 当前模块名（如 product、article），用于字段名翻译时加上前缀 */
    private $module;

    /**
     * 构造函数
     *
     * @param Connection $db 数据库对象（由 DB::getFacadeRoot() 注入）
     * @param array $lang 语言包数组（一般来自 lang_all()，单元测试可直接传入）
     * @param string $module 当前模块名（如 product、article），可选，用于字段名翻译时优先匹配 {module}_{field}
     */
    public function __construct(Connection $db, array $lang = [], $module = '')
    {
        $this->db = $db;
        $this->lang = $lang;
        $this->module = $module;
    }

    /**
     * 执行校验，失败时抛出 DomainException
     *
     * @param array $data 待校验的数据数组（通常为 Request::all()，即 GET+POST 合并）
     * @param array $rules 验证规则数组，格式 ['field' => 'rule1|rule2|...']
     * @param array $messages 自定义错误消息数组，格式 ['field.rule' => '自定义错误消息']，键名使用点号
     * @param bool $collectAll 是否收集所有字段错误；false=首错即抛（默认），true=按字段聚合后抛出
     * @return void
     * @throws DomainException 当任何规则验证失败时抛出，消息为对应错误文本
     */
    public function validate(array $data, array $rules, array $messages = [], $collectAll = false)
    {
        $errors = array();
        $firstMessage = '';

        foreach ($rules as $field => $ruleString) {
            $value = isset($data[$field]) ? $data[$field] : null;
            $ruleList = $this->splitRules($ruleString);
            if ($this->ruleListHasFileRule($ruleList)) {
                $value = $this->resolveUploadedFile($field, $value);
            }

            foreach ($ruleList as $ruleFull) {
                $ruleFull = trim($ruleFull);
                $colonPos = strpos($ruleFull, ':');
                $ruleName = $colonPos !== false ? substr($ruleFull, 0, $colonPos) : $ruleFull;
                $ruleParam = $colonPos !== false ? substr($ruleFull, $colonPos + 1) : '';

                $errorKey = $this->applyRule($ruleName, $ruleParam, $field, $value, $data);
                if ($errorKey !== null) {
                    // 优先级：自定义消息（点号键） > 语言包消息 > 占位符
                    if (isset($messages[$errorKey])) {
                        $message = $messages[$errorKey];
                    } else {
                        $message = $this->getMessage($errorKey, $ruleName, $field, $ruleParam);
                    }

                    if (!$collectAll) {
                        throw new DomainException($message);
                    }

                    // confirmed 规则错误默认映射到 xxx_confirm，便于前端定位确认输入框
                    $errorField = $ruleName === 'confirmed' ? $field . '_confirm' : $field;

                    // 每个字段仅保留第一条错误，继续校验其他字段以便一次返回完整错误集合
                    if (!isset($errors[$errorField])) {
                        $errors[$errorField] = $message;
                        if ($firstMessage === '') {
                            $firstMessage = $message;
                        }
                    }
                    break;
                }
            }
        }

        if ($collectAll && !empty($errors)) {
            throw new DomainException($firstMessage, '', '', '', $errors);
        }
    }

    /**
     * 执行单条规则，返回 null 表示通过，返回字符串表示错误键名（点号格式，如 'name.required'）
     *
     * @param string $ruleName 规则名称，如 'required', 'alpha_dash'
     * @param string $ruleParam 规则参数，如 'max' 规则的参数 '100'，或 'unique' 规则的 'table,field,exclude'
     * @param string $field 当前验证的字段名
     * @param mixed $value 字段对应的值
     * @param array $data 完整的待验证数据数组（用于 confirmed、unique 等需要跨字段比较的规则）
     * @return string|null 验证通过返回 null，失败返回错误键名（如 'name.required'）
     */
    private function applyRule($ruleName, $ruleParam, $field, $value, array $data)
    {
        switch ($ruleName) {
            case 'required':
                if ($this->isValueMissing($value)) {
                    return $field . '.required';
                }
                return null;

            case 'required_if':
                $params = array_map('trim', explode(',', $ruleParam));
                $otherField = isset($params[0]) ? $params[0] : '';
                $expectValues = array_slice($params, 1);
                $otherValue = isset($data[$otherField]) ? (string) $data[$otherField] : '';
                if ($otherField !== '' && !empty($expectValues) && in_array($otherValue, $expectValues, true)) {
                    if ($this->isValueMissing($value)) {
                        return $field . '.required_if';
                    }
                }
                return null;

            case 'same':
                $otherField = trim((string) $ruleParam);
                $otherValue = isset($data[$otherField]) ? $data[$otherField] : null;
                if ($value !== $otherValue) {
                    return $field . '.same';
                }
                return null;

            case 'different':
                $otherField = trim((string) $ruleParam);
                $otherValue = isset($data[$otherField]) ? $data[$otherField] : null;
                if ($value !== null && $value !== '' && $value === $otherValue) {
                    return $field . '.different';
                }
                return null;

            case 'alpha_dash':
                if ($value !== null && $value !== '' && !Check::alphaDash($value)) {
                    return $field . '.alpha_dash';
                }
                return null;

            case 'numeric':
                if ($value !== null && $value !== '' && !Check::number($value)) {
                    return $field . '.numeric';
                }
                return null;

            case 'integer':
                if ($value !== null && $value !== '' && !Check::integer($value)) {
                    return $field . '.integer';
                }
                return null;

            case 'float':
                if ($value !== null && $value !== '' && !is_numeric($value)) {
                    return $field . '.float';
                }
                return null;

            case 'price':
                if ($value !== null && $value !== '' && !Check::price((string) $value)) {
                    return $field . '.price';
                }
                return null;

            case 'digits':
                $digits = (int) $ruleParam;
                if ($value !== null && $value !== '' && (!preg_match('/^\d+$/', (string) $value) || strlen((string) $value) !== $digits)) {
                    return $field . '.digits';
                }
                return null;

            case 'digits_between':
                $params = explode(',', $ruleParam);
                $min = isset($params[0]) ? (int) $params[0] : 0;
                $max = isset($params[1]) ? (int) $params[1] : 0;
                $len = strlen((string) $value);
                if ($value !== null && $value !== '' && (!preg_match('/^\d+$/', (string) $value) || $len < $min || $len > $max)) {
                    return $field . '.digits_between';
                }
                return null;

            case 'email':
                if ($value !== null && $value !== '' && !Check::email($value)) {
                    return $field . '.email';
                }
                return null;

            case 'url':
                if ($value !== null && $value !== '' && !Check::url($value)) {
                    return $field . '.url';
                }
                return null;

            case 'domain':
                if ($value !== null && $value !== '' && !Check::domain((string) $value)) {
                    return $field . '.domain';
                }
                return null;

            case 'ip':
                if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_IP)) {
                    return $field . '.ip';
                }
                return null;

            case 'date':
                // strtotime 失败返回 false；用 === false 判定，避免把解析为 0（如 UTC 1970-01-01）的合法日期误判。
                if ($value !== null && $value !== '' && strtotime((string) $value) === false) {
                    return $field . '.date';
                }
                return null;

            case 'date_format':
                if ($value !== null && $value !== '') {
                    $format = trim((string) $ruleParam);
                    $dt = \DateTime::createFromFormat($format, (string) $value);
                    if (!$dt || $dt->format($format) !== (string) $value) {
                        return $field . '.date_format';
                    }
                }
                return null;

            case 'boolean':
                $boolValues = [true, false, 1, 0, '1', '0', 'true', 'false', 'on', 'off', 'yes', 'no'];
                if ($value !== null && $value !== '' && !in_array($value, $boolValues, true)) {
                    return $field . '.boolean';
                }
                return null;

            case 'max':
                $max = (int) $ruleParam;
                if ($max > 0 && $value !== null && mb_strlen((string) $value) > $max) {
                    return $field . '.max';
                }
                return null;

            case 'min':
                $min = (int) $ruleParam;
                if ($min > 0 && $value !== null && mb_strlen((string) $value) < $min) {
                    return $field . '.min';
                }
                return null;

            case 'between':
                $params = explode(',', $ruleParam);
                $min = isset($params[0]) ? (int) $params[0] : 0;
                $max = isset($params[1]) ? (int) $params[1] : 0;
                $len = mb_strlen((string) $value);
                if ($value !== null && ($len < $min || $len > $max)) {
                    return $field . '.between';
                }
                return null;

            case 'max_value':
                $max = (float) $ruleParam;
                if ($value !== null && is_numeric($value) && (float) $value > $max) {
                    return $field . '.max_value';
                }
                return null;

            case 'min_value':
                $min = (float) $ruleParam;
                if ($value !== null && is_numeric($value) && (float) $value < $min) {
                    return $field . '.min_value';
                }
                return null;

            case 'between_value':
                $params = explode(',', $ruleParam);
                $min = isset($params[0]) ? (float) $params[0] : 0;
                $max = isset($params[1]) ? (float) $params[1] : 0;
                if ($value !== null && is_numeric($value) && ((float) $value < $min || (float) $value > $max)) {
                    return $field . '.between_value';
                }
                return null;

            case 'unique':
                return $this->applyUniqueRule($ruleParam, $field, $value, $data);

            case 'exists':
                return $this->applyExistsRule($ruleParam, $field, $value);

            case 'confirmed':
                $confirmField = $field . '_confirm';
                if ($value !== null && (!isset($data[$confirmField]) || $value !== $data[$confirmField])) {
                    return $field . '.confirmed';
                }
                return null;

            case 'regex':
                if ($value !== null && $value !== '' && !preg_match($ruleParam, (string) $value)) {
                    return $field . '.regex';
                }
                return null;

            case 'in':
                $allowed = array_map('trim', explode(',', $ruleParam));
                if ($value !== null && $value !== '' && !in_array($value, $allowed)) {
                    return $field . '.in';
                }
                return null;

            case 'not_in':
                $disallowed = array_map('trim', explode(',', $ruleParam));
                if ($value !== null && $value !== '' && in_array($value, $disallowed)) {
                    return $field . '.not_in';
                }
                return null;

            case 'starts_with':
                if ($value !== null && $value !== '' && strpos($value, $ruleParam) !== 0) {
                    return $field . '.starts_with';
                }
                return null;

            case 'ends_with':
                if ($value !== null && $value !== '' && substr($value, -strlen($ruleParam)) !== $ruleParam) {
                    return $field . '.ends_with';
                }
                return null;

            case 'alpha':
                if ($value !== null && $value !== '' && !preg_match('/^[a-zA-Z]+$/', (string) $value)) {
                    return $field . '.alpha';
                }
                return null;

            case 'alpha_num':
                if ($value !== null && $value !== '' && !preg_match('/^[a-zA-Z0-9]+$/', (string) $value)) {
                    return $field . '.alpha_num';
                }
                return null;

            case 'phone':
                if ($value !== null && $value !== '' && !preg_match('/^1[3-9]\d{9}$/', (string) $value)) {
                    return $field . '.phone';
                }
                return null;

            case 'password':
                if ($value !== null && $value !== '' && !Check::password((string) $value)) {
                    return $field . '.password';
                }
                return null;

            case 'illegal_char':
                if ($value !== null && $value !== '' && Check::illegalChar((string) $value)) {
                    return $field . '.illegal_char';
                }
                return null;

            case 'username':
                if ($value !== null && $value !== '' && !Check::username((string) $value)) {
                    return $field . '.username';
                }
                return null;

            case 'admin_account':
                if ($value !== null && $value !== '' && !Check::adminAccount((string) $value)) {
                    return $field . '.admin_account';
                }
                return null;

            case 'slug':
                if ($value !== null && $value !== '' && !Check::slug((string) $value)) {
                    return $field . '.slug';
                }
                return null;

            case 'idcard':
                if ($value !== null && $value !== '' && !Check::idcard((string) $value)) {
                    return $field . '.idcard';
                }
                return null;

            case 'qq':
                if ($value !== null && $value !== '' && !Check::qq((string) $value)) {
                    return $field . '.qq';
                }
                return null;

            case 'postcode':
                if ($value !== null && $value !== '' && !Check::postcode((string) $value)) {
                    return $field . '.postcode';
                }
                return null;

            case 'json':
                // 用 json_last_error 判定合法性，避免把合法 JSON 字面量 "null"/"false"/"0"
                // （json_decode 返回 null/false/0）误判为非法 JSON。
                if ($value !== null && $value !== '') {
                    if (!is_string($value)) {
                        return $field . '.json';
                    }
                    json_decode($value);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        return $field . '.json';
                    }
                }
                return null;

            case 'array':
                if ($value !== null && !is_array($value)) {
                    return $field . '.array';
                }
                return null;

            case 'accepted':
                if (!in_array($value, [1, '1', true, 'true', 'yes', 'on'], true)) {
                    return $field . '.accepted';
                }
                return null;

            case 'file':
                if (!($value instanceof UploadedFile)) {
                    return null;
                }
                if (!$value->isValid()) {
                    return $field . '.file';
                }
                return null;

            case 'image':
                if (!($value instanceof UploadedFile)) {
                    return null;
                }
                if (!$value->isValid()) {
                    return $field . '.image';
                }
                $imageExtensions = $this->getImageExtensions();
                $ext = strtolower($value->getClientOriginalExtension());
                if ($ext === '' || !in_array($ext, $imageExtensions, true)) {
                    return $field . '.image';
                }
                return null;

            case 'mimes':
                if (!($value instanceof UploadedFile) || !$value->isValid()) {
                    return null;
                }
                $allowed = $this->parseExtensionList($ruleParam);
                if (empty($allowed)) {
                    $allowed = $this->getDefaultAllowExtensions();
                }
                $ext = strtolower($value->getClientOriginalExtension());
                if ($ext === '' || !in_array($ext, $allowed, true)) {
                    return $field . '.mimes';
                }
                return null;

            case 'max_kb':
                if (!($value instanceof UploadedFile) || !$value->isValid()) {
                    return null;
                }
                $maxKb = trim((string) $ruleParam) === ''
                    ? $this->getDefaultUploadMaxKb()
                    : (int) $ruleParam;
                if ($maxKb > 0 && $value->getSizeKb() > $maxKb) {
                    return $field . '.max_kb';
                }
                return null;

            case 'dimensions':
                if (!($value instanceof UploadedFile) || !$value->isValid()) {
                    return null;
                }
                if (!$this->checkImageDimensions($value, $ruleParam)) {
                    return $field . '.dimensions';
                }
                return null;

            default:
                return null;
        }
    }

    /**
     * 按 `|` 切分规则串，但不切碎 regex:/not_regex: 模式内部的 `|`。
     *
     * 不含 regex 规则时走 explode 快路径；含 regex 时，被 `|` 切断的模式按
     * 分隔符配平重新合并，避免 `regex:/^(a|b)$/` 被拆坏。
     *
     * @param string $ruleString
     * @return array
     */
    private function splitRules($ruleString)
    {
        $ruleString = (string) $ruleString;
        if (strpos($ruleString, 'regex:') === false && strpos($ruleString, 'not_regex:') === false) {
            return explode('|', $ruleString);
        }

        $segments = explode('|', $ruleString);
        $rules = array();
        $buffer = null;
        foreach ($segments as $seg) {
            if ($buffer !== null) {
                $buffer .= '|' . $seg;
                if ($this->regexSegmentBalanced($buffer)) {
                    $rules[] = $buffer;
                    $buffer = null;
                }
                continue;
            }
            $trimmed = ltrim($seg);
            if ((strncmp($trimmed, 'regex:', 6) === 0 || strncmp($trimmed, 'not_regex:', 10) === 0)
                && !$this->regexSegmentBalanced($seg)) {
                $buffer = $seg;
                continue;
            }
            $rules[] = $seg;
        }
        if ($buffer !== null) {
            // 容错：分隔符始终未配平时归一为一条，避免静默吞掉规则。
            $rules[] = $buffer;
        }

        return $rules;
    }

    /**
     * 判断 regex 规则片段的模式分隔符是否已成对闭合。
     *
     * @param string $segment 形如 `regex:/pattern/flags`
     * @return bool
     */
    private function regexSegmentBalanced($segment)
    {
        $colon = strpos($segment, ':');
        if ($colon === false) {
            return true;
        }
        $value = ltrim(substr($segment, $colon + 1));
        if ($value === '') {
            return true;
        }
        $delim = $value[0];
        $count = 0;
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            if ($value[$i] === '\\') {
                $i++; // 跳过转义字符
                continue;
            }
            if ($value[$i] === $delim) {
                $count++;
            }
        }

        return $count >= 2;
    }

    /**
     * 判定 required 类规则下值是否缺失（兼容 UploadedFile）。
     *
     * @param mixed $value
     * @return bool
     */
    private function isValueMissing($value)
    {
        if ($value instanceof UploadedFile) {
            return !$value->isValid();
        }
        if ($value === null || $value === '') {
            return true;
        }
        if (is_array($value) && empty($value)) {
            return true;
        }
        return false;
    }

    /**
     * 判断规则列表中是否含文件类规则（file / image / mimes / max_kb / dimensions）。
     *
     * @param array $ruleList
     * @return bool
     */
    private function ruleListHasFileRule(array $ruleList)
    {
        foreach ($ruleList as $ruleFull) {
            $ruleFull = trim((string) $ruleFull);
            $colonPos = strpos($ruleFull, ':');
            $ruleName = $colonPos !== false ? substr($ruleFull, 0, $colonPos) : $ruleFull;
            if ($ruleName === 'file' || $ruleName === 'image' || $ruleName === 'mimes'
                || $ruleName === 'max_kb' || $ruleName === 'dimensions') {
                return true;
            }
        }
        return false;
    }

    /**
     * 解析字段对应的上传文件：原值已是 UploadedFile 时透传；否则从 $_FILES[$field] 取。
     *
     * @param string $field
     * @param mixed $value
     * @return UploadedFile|null
     */
    private function resolveUploadedFile($field, $value)
    {
        if ($value instanceof UploadedFile) {
            return $value;
        }
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
            return null;
        }
        $info = $_FILES[$field];
        if (is_array(isset($info['name']) ? $info['name'] : null)) {
            return null;
        }
        return UploadedFile::fromGlobals($field);
    }

    /**
     * 校验图片宽 / 高 / 宽高比约束。
     *
     * @param UploadedFile $file
     * @param string $ruleParam
     * @return bool
     */
    private function checkImageDimensions(UploadedFile $file, $ruleParam)
    {
        $pathname = $file->getPathname();
        if ($pathname === '' || !is_file($pathname)) {
            return false;
        }
        $info = @getimagesize($pathname);
        if (!is_array($info) || !isset($info[0], $info[1])) {
            return false;
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        $constraints = $this->parseDimensionsRule($ruleParam);
        if (isset($constraints['max_width']) && $width > $constraints['max_width']) {
            return false;
        }
        if (isset($constraints['min_width']) && $width < $constraints['min_width']) {
            return false;
        }
        if (isset($constraints['max_height']) && $height > $constraints['max_height']) {
            return false;
        }
        if (isset($constraints['min_height']) && $height < $constraints['min_height']) {
            return false;
        }
        if (isset($constraints['width']) && $width !== $constraints['width']) {
            return false;
        }
        if (isset($constraints['height']) && $height !== $constraints['height']) {
            return false;
        }
        if (isset($constraints['ratio'])) {
            $parts = explode(':', $constraints['ratio']);
            $rw = isset($parts[0]) ? (float) $parts[0] : 0;
            $rh = isset($parts[1]) ? (float) $parts[1] : 0;
            if ($rw > 0 && $rh > 0 && $height > 0) {
                $actual = $width / $height;
                $expected = $rw / $rh;
                if (abs($actual - $expected) > 0.01) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * 把 'max_width=800,min_height=120,ratio=16:9' 解析为 ['max_width'=>800,...] 形态。
     *
     * @param string $ruleParam
     * @return array
     */
    private function parseDimensionsRule($ruleParam)
    {
        $result = array();
        if (trim((string) $ruleParam) === '') {
            return $result;
        }
        $pairs = explode(',', (string) $ruleParam);
        foreach ($pairs as $pair) {
            $eqPos = strpos($pair, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = trim(substr($pair, 0, $eqPos));
            $val = trim(substr($pair, $eqPos + 1));
            if ($key === 'ratio') {
                $result['ratio'] = $val;
            } elseif ($key !== '') {
                $result[$key] = (int) $val;
            }
        }
        return $result;
    }

    /**
     * 把 'jpg,png' 解析为 ['jpg','png']（小写、去空、去 .）。
     *
     * @param string $ruleParam
     * @return array
     */
    private function parseExtensionList($ruleParam)
    {
        if (trim((string) $ruleParam) === '') {
            return array();
        }
        $list = array();
        $parts = explode(',', (string) $ruleParam);
        foreach ($parts as $part) {
            $part = strtolower(trim($part, " \t\n\r\0\x0B."));
            if ($part !== '') {
                $list[] = $part;
            }
        }
        return $list;
    }

    /**
     * 从 filesystems.upload_defaults.allow_extensions 取允许扩展名清单。
     *
     * @return array
     */
    private function getDefaultAllowExtensions()
    {
        $defaults = Config::get('filesystems.upload_defaults', array());
        $raw = isset($defaults['allow_extensions']) ? $defaults['allow_extensions'] : 'jpg,jpeg,gif,png,webp,ico';
        return $this->parseExtensionList((string) $raw);
    }

    /**
     * 从 filesystems.upload_defaults.upload_max_kb 取大小上限（KB）。
     *
     * @return int
     */
    private function getDefaultUploadMaxKb()
    {
        $defaults = Config::get('filesystems.upload_defaults', array());
        return isset($defaults['upload_max_kb']) ? (int) $defaults['upload_max_kb'] : 2048;
    }

    /**
     * image 规则使用的图片扩展名集合，复用配置但兜底常见图片类型。
     *
     * @return array
     */
    private function getImageExtensions()
    {
        $allowed = $this->getDefaultAllowExtensions();
        $fallback = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp');
        if (empty($allowed)) {
            return $fallback;
        }
        return array_values(array_intersect($allowed, $fallback));
    }

    /**
     * 处理 unique 规则（数据库唯一性检查）
     *
     * 规则格式：
     *   unique:table,field
     *   unique:table,field,excludeField           从 $data 中取 excludeField 的值，按主键列 id 排除自身
     *   unique:table,field,excludeField,idColumn  排除列显式指定（默认 id）
     *
     * 说明：excludeField 仅作为「从 $data 取排除值」的键；用于比较的列固定为主键 idColumn（默认 id）。
     * 这样即使请求字段沿用 category_id / user_id / admin_id 等历史命名，排除条件仍落在统一主键列 id 上。
     *
     * @param string $ruleParam 规则参数，格式 "table,field"、"table,field,excludeField" 或 "table,field,excludeField,idColumn"
     * @param string $field 当前验证的字段名
     * @param mixed $value 字段值
     * @param array $data 完整请求数据（用于获取排除字段的值）
     * @return string|null 验证通过返回 null，失败返回 "{$field}.unique"
     */
    private function applyUniqueRule($ruleParam, $field, $value, array $data)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $ruleParam));
        $table = isset($parts[0]) ? $parts[0] : '';
        $column = isset($parts[1]) ? $parts[1] : $field;
        $excludeField = isset($parts[2]) ? $parts[2] : '';
        $idColumn = (isset($parts[3]) && $parts[3] !== '') ? $parts[3] : 'id';

        if ($table === '') {
            return null;
        }

        $query = $this->db->table($table)->where($column, $value);

        if ($excludeField !== '' && isset($data[$excludeField]) && $data[$excludeField] !== '') {
            $query->where($idColumn, '!=', $data[$excludeField]);
        }

        $exists = $query->value($column);
        if ($exists) {
            return $field . '.unique';
        }
        return null;
    }

    /**
     * 处理 exists 规则（数据库存在性检查）
     *
     * 规则格式：exists:table,field
     *
     * @param string $ruleParam 规则参数，格式 "table,field"
     * @param string $field 当前验证的字段名
     * @param mixed $value 字段值
     * @return string|null 验证通过返回 null，失败返回 "{$field}.exists"
     */
    private function applyExistsRule($ruleParam, $field, $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $ruleParam));
        $table = isset($parts[0]) ? $parts[0] : '';
        $column = isset($parts[1]) ? $parts[1] : $field;

        if ($table === '') {
            return null;
        }

        $exists = $this->db->table($table)->where($column, $value)->value($column);
        if (!$exists) {
            return $field . '.exists';
        }
        return null;
    }

    /**
     * 从语言包获取错误消息，支持点号转下划线，并支持 validator_ 前缀
     *
     * @param string $msgKey 错误键名，如 'name.required'（点号格式）
     * @param string $rule 规则名，如 'required'
     * @param string $field 字段名，用于替换消息中的 :field 占位符
     * @param string $ruleParam 规则参数，用于替换消息中的 :max、:min、:values 等占位符
     * @return string 最终的错误消息字符串
     */
    private function getMessage($msgKey, $rule, $field, $ruleParam = '')
    {
        // 将点号转换为下划线，得到基础键名，如 'name_required'
        $baseKey = str_replace('.', '_', $msgKey);

        $template = null;

        // 1. 尝试字段专属带 validator_ 前缀的键名，如 validator_name_required
        $fieldKey = $this->module . '_' . $baseKey;
        if (isset($this->lang[$fieldKey])) {
            $template = $this->lang[$fieldKey];
        }
        // 2. 尝试通用规则键名，如 validator_required
        elseif (isset($this->lang['validator_' . $rule])) {
            $template = $this->lang['validator_' . $rule];
        }

        if ($template === null) {
            return '[Missing: ' . $msgKey . ']';
        }

        // 替换 :field 占位符
        $fieldLabel = $this->getFieldLabel($field);
        $message = str_replace(':field', $fieldLabel, $template);

        // 根据规则替换其他占位符
        switch ($rule) {
            case 'max':
            case 'min':
                $message = str_replace(':' . $rule, $ruleParam, $message);
                break;
            case 'max_value':
                $message = str_replace(':max', $ruleParam, $message);
                break;
            case 'min_value':
                $message = str_replace(':min', $ruleParam, $message);
                break;
            case 'max_kb':
                $maxKb = trim((string) $ruleParam) === '' ? $this->getDefaultUploadMaxKb() : (int) $ruleParam;
                $message = str_replace(':max', (string) $maxKb, $message);
                $message = str_replace(':kb', (string) $maxKb, $message);
                break;
            case 'mimes':
                $exts = $this->parseExtensionList($ruleParam);
                if (empty($exts)) {
                    $exts = $this->getDefaultAllowExtensions();
                }
                $message = str_replace(':values', implode('、', $exts), $message);
                break;
            case 'dimensions':
                $constraints = $this->parseDimensionsRule($ruleParam);
                $summary = array();
                foreach ($constraints as $k => $v) {
                    $summary[] = $k . '=' . $v;
                }
                $message = str_replace(':values', implode(', ', $summary), $message);
                break;
            case 'between':
            case 'between_value':
                $params = explode(',', $ruleParam);
                $min = isset($params[0]) ? $params[0] : '';
                $max = isset($params[1]) ? $params[1] : '';
                $message = str_replace(':min', $min, $message);
                $message = str_replace(':max', $max, $message);
                break;
            case 'in':
            case 'not_in':
            case 'starts_with':
            case 'ends_with':
                $values = str_replace(',', '、', $ruleParam);
                $message = str_replace(':values', $values, $message);
                break;
            case 'same':
            case 'different':
                $otherField = trim((string) $ruleParam);
                $otherLabel = $this->getFieldLabel($otherField);
                $message = str_replace(':other', $otherLabel, $message);
                break;
            case 'required_if':
                $params = array_map('trim', explode(',', $ruleParam));
                $otherField = isset($params[0]) ? $params[0] : '';
                $values = implode('、', array_slice($params, 1));
                $message = str_replace(':other', $this->getFieldLabel($otherField), $message);
                $message = str_replace(':values', $values, $message);
                break;
            case 'digits':
                $message = str_replace(':digits', $ruleParam, $message);
                break;
            case 'digits_between':
                $params = explode(',', $ruleParam);
                $min = isset($params[0]) ? $params[0] : '';
                $max = isset($params[1]) ? $params[1] : '';
                $message = str_replace(':min', $min, $message);
                $message = str_replace(':max', $max, $message);
                break;
            case 'date_format':
                $message = str_replace(':format', $ruleParam, $message);
                break;
        }

        return $message;
    }

    /**
     * 获取字段的显示名称（支持模块前缀）
     *
     * 查找优先级：
 *   1. 语言包中的 {module}_{field}（如 product_name）
 *   2. 语言包中的 {field}（如 name）
 *   3. 原字段名（如 name）
     *
     * @param string $field 字段名（原始英文名）
     * @return string 字段的显示名称（用于替换 :field 占位符）
     */
    private function getFieldLabel($field)
    {
        // 优先查找 {module}_{field}
        if ($this->module) {
            $moduleKey = $this->module . '_' . $field;
            if (isset($this->lang[$moduleKey])) {
                return $this->lang[$moduleKey];
            }
        }
        // 其次查找 {field}
        if (isset($this->lang[$field])) {
            return $this->lang[$field];
        }
        // 最后返回原字段名
        return $field;
    }
}
