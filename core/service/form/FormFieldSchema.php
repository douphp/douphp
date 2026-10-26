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

namespace Dou\Core\Service\Form;

use Dou\Core\Foundation\Extension\Module;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表单字段类型元数据。
 *
 * 后台类型下拉、前台控件分支、服务端校验与清洗共用这一份定义，避免三处各写一遍类型清单。
 * 类型只约束「校验 / 清洗 / 展示」，不约束模板写法：模板可以用 input 承载 select 字段的数据。
 */
class FormFieldSchema
{
    /**
     * 类型清单。
     *
     * options      - 是否需要 option（select / radio / checkbox）
     * upload       - 是否为上传类字段
     * upload_type  - 上传类对应 file 模块的 type（main / gallery / attach）
     * auto_rule    - 类型自带的校验规则，空串表示不附带
     * module       - 依赖的可选模块键，模块未安装时不在后台下拉中展示
     */
    private static $types = array(
        'input' => array('options' => false, 'upload' => false, 'upload_type' => '', 'auto_rule' => ''),
        'textarea' => array('options' => false, 'upload' => false, 'upload_type' => '', 'auto_rule' => ''),
        'select' => array('options' => true, 'upload' => false, 'upload_type' => '', 'auto_rule' => ''),
        'radio' => array('options' => true, 'upload' => false, 'upload_type' => '', 'auto_rule' => ''),
        'checkbox' => array('options' => true, 'upload' => false, 'upload_type' => '', 'auto_rule' => ''),
        'agree' => array('options' => false, 'upload' => false, 'upload_type' => '', 'auto_rule' => ''),
        'switch' => array('options' => false, 'upload' => false, 'upload_type' => '', 'auto_rule' => ''),
        'number' => array('options' => false, 'upload' => false, 'upload_type' => '', 'auto_rule' => 'numeric'),
        'email' => array('options' => false, 'upload' => false, 'upload_type' => '', 'auto_rule' => 'email'),
        'tel' => array('options' => false, 'upload' => false, 'upload_type' => '', 'auto_rule' => ''),
        'mobile' => array('options' => false, 'upload' => false, 'upload_type' => '', 'auto_rule' => 'phone'),
        'url' => array('options' => false, 'upload' => false, 'upload_type' => '', 'auto_rule' => 'url'),
        'date' => array('options' => false, 'upload' => false, 'upload_type' => '', 'auto_rule' => 'date'),
        'area' => array('options' => false, 'upload' => false, 'upload_type' => '', 'auto_rule' => '', 'module' => 'area'),
        'image' => array('options' => false, 'upload' => true, 'upload_type' => 'main', 'auto_rule' => ''),
        'gallery' => array('options' => false, 'upload' => true, 'upload_type' => 'gallery', 'auto_rule' => ''),
        'file' => array('options' => false, 'upload' => true, 'upload_type' => 'attach', 'auto_rule' => ''),
    );

    /**
     * 文本长度上限：textarea 放宽，其余按列宽。
     */
    private static $maxLengths = array(
        'input' => 255,
        'textarea' => 5000,
        'email' => 120,
        'tel' => 30,
        'mobile' => 20,
        'url' => 255,
    );

    /**
     * 校验规则预选项：平铺展示，不按类型分组。
     *
     * 采用「validate 为唯一权威」的策略：类型只在新建 / 切换时把默认规则预填进 validate，
     * 之后用户看到的就是真正生效的那一份。所以格式类规则（email / url / phone / numeric / date）
     * 也放在这里——它不再是「和类型重复」，而是当前字段唯一的那条规则。
     *
     * value 为写进 validate 的规则原文，label 为语言键。
     * 区间类规则（between / between_value）刻意不提供：validate 串按逗号分隔规则，
     * 其参数本身也含逗号，写进去会被拆成两条残规则。上下限用 min / max 两条表达即可。
     */
    private static $validatePresets = array(
        array('value' => 'email', 'label' => 'form_validate_email'),
        array('value' => 'url', 'label' => 'form_validate_url'),
        array('value' => 'phone', 'label' => 'form_validate_phone'),
        array('value' => 'numeric', 'label' => 'form_validate_numeric'),
        array('value' => 'integer', 'label' => 'form_validate_integer'),
        array('value' => 'date', 'label' => 'form_validate_date'),
        array('value' => 'idcard', 'label' => 'form_validate_idcard'),
        array('value' => 'postcode', 'label' => 'form_validate_postcode'),
        array('value' => 'qq', 'label' => 'form_validate_qq'),
        array('value' => 'min:2', 'label' => 'form_validate_min'),
        array('value' => 'max:200', 'label' => 'form_validate_max'),
        array('value' => 'min_value:0', 'label' => 'form_validate_min_value'),
        array('value' => 'max_value:1000', 'label' => 'form_validate_max_value'),
        array('value' => 'regex://', 'label' => 'form_validate_regex'),
        array('value' => 'same:', 'label' => 'form_validate_same'),
    );

    /**
     * @return array
     */
    public static function types()
    {
        return self::$types;
    }

    /**
     * 全部类型键（含依赖模块未安装的类型）。
     *
     * 用于请求白名单：历史字段的 type 可能是当前不可选的类型（如卸载 area 模块后的存量数据），
     * 必须仍允许提交，否则编辑这些字段会直接校验失败。
     *
     * @return array
     */
    public static function keys()
    {
        return array_keys(self::$types);
    }

    /**
     * @param string $type
     * @return bool
     */
    public static function exists($type)
    {
        return isset(self::$types[(string) $type]);
    }

    /**
     * 类型显示名（后台下拉 / 前台语言包）。
     *
     * @param string $type
     * @return string
     */
    public static function label($type)
    {
        $type = self::normalizeType($type);

        return lang('form_type_' . $type, $type);
    }

    /**
     * 类型是否当前可用：依赖可选模块的类型在该模块未安装时不可选。
     *
     * @param string $type
     * @return bool
     */
    public static function isAvailable($type)
    {
        $type = self::normalizeType($type);
        $module = isset(self::$types[$type]['module']) ? self::$types[$type]['module'] : '';

        return $module === '' || Module::has($module);
    }

    /**
     * 后台可选类型显示名：type => label。
     *
     * 依赖可选模块的类型（如 area）在模块未安装时不下发，避免用户选了却渲染不出来；
     * 但正在编辑的历史字段仍需把自己列出来，否则下拉会回落到第一项，一保存就把类型改坏。
     *
     * @param string $forceType 强制包含的类型（当前字段类型）
     * @return array
     */
    public static function labels($forceType = '')
    {
        $forceType = self::normalizeType($forceType);

        $labels = array();
        foreach (array_keys(self::$types) as $type) {
            $available = self::isAvailable($type);
            if (!$available && $type !== $forceType) {
                continue;
            }
            $labels[$type] = self::label($type) . ($available ? '' : lang('form_type_module_missing'));
        }

        return $labels;
    }

    /**
     * @param string $type
     * @return bool
     */
    public static function supportsOptions($type)
    {
        $type = self::normalizeType($type);

        return !empty(self::$types[$type]['options']);
    }

    /**
     * @param string $type
     * @return bool
     */
    public static function supportsUpload($type)
    {
        $type = self::normalizeType($type);

        return !empty(self::$types[$type]['upload']);
    }

    /**
     * 上传类字段对应的 file 模块 type（main / gallery / attach），非上传类返回空串。
     *
     * @param string $type
     * @return string
     */
    public static function uploadType($type)
    {
        $type = self::normalizeType($type);

        return isset(self::$types[$type]['upload_type']) ? self::$types[$type]['upload_type'] : '';
    }

    /**
     * 类型自带的校验规则。
     *
     * @param string $type
     * @return string
     */
    public static function autoRule($type)
    {
        $type = self::normalizeType($type);

        return isset(self::$types[$type]['auto_rule']) ? self::$types[$type]['auto_rule'] : '';
    }

    /**
     * 类型的默认校验规则：新建字段或切换类型时预填进 validate。
     *
     * 返回值只是「推荐初值」。一旦写进 validate 就由用户全权支配：buildRules() 不再额外注入，
     * 界面上不会出现「类型偷偷加了规则、用户看不见也改不了」的情况。
     *
     * @param string $type
     * @return array
     */
    public static function defaultRules($type)
    {
        $type = self::normalizeType($type);

        $rules = array();
        $auto = self::autoRule($type);
        if ($auto !== '') {
            $rules[] = $auto;
        }

        // 选项类由选项白名单约束，上传类按附件编号长度约束，都不预填长度规则
        if (self::supportsOptions($type) || self::supportsUpload($type)) {
            return $rules;
        }

        $max = self::maxLength($type);
        if ($max > 0) {
            $rules[] = 'max:' . $max;
        }

        return $rules;
    }

    /**
     * 类型默认规则串（逗号分隔），用于后台表单预填。
     *
     * @param string $type
     * @return string
     */
    public static function defaultRulesString($type)
    {
        return implode(',', self::defaultRules($type));
    }

    /**
     * 类型的默认长度上限，0 表示不限制（非文本类）。
     *
     * @param string $type
     * @return int
     */
    public static function maxLength($type)
    {
        $type = self::normalizeType($type);

        return isset(self::$maxLengths[$type]) ? (int) self::$maxLengths[$type] : 0;
    }

    /**
     * 校验规则预选项（平铺，已翻译）。
     *
     * @return array [{value, label}]
     */
    public static function validatePresets()
    {
        $items = array();
        foreach (self::$validatePresets as $preset) {
            $items[] = array(
                'value' => $preset['value'],
                'label' => lang($preset['label'], $preset['value']),
            );
        }

        return $items;
    }

    /**
     * 上传类字段的 DOM 前缀（同时作为草稿键后缀），形如 f3_images。
     *
     * @param int $formId
     * @param string $slug
     * @return string
     */
    public static function uploadPrefix($formId, $slug)
    {
        $slug = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $slug));

        return 'f' . (int) $formId . '_' . $slug;
    }

    /**
     * 解析 option 文本（每行一个纯值）为一维字符串数组。
     *
     * @param string $raw
     * @return array
     */
    public static function parseOptions($raw)
    {
        $raw = (string) $raw;
        if ($raw === '') {
            return array();
        }

        $raw = str_replace(array("\r\n", "\r"), "\n", $raw);
        $options = array();
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $options[] = $line;
            }
        }

        return $options;
    }

    /**
     * 把一维数组还原为入库文本（\r\n 分隔）。
     *
     * @param array|string $options
     * @return string
     */
    public static function joinOptions($options)
    {
        if (!is_array($options)) {
            return (string) $options;
        }

        $lines = array();
        foreach ($options as $option) {
            $option = trim((string) $option);
            if ($option !== '') {
                $lines[] = $option;
            }
        }

        return implode("\r\n", $lines);
    }

    /**
     * 选项里是否含规则串分隔符（`,` / `|`）。
     *
     * @param array $options
     * @return bool
     */
    private static function hasRuleSeparator(array $options)
    {
        foreach ($options as $option) {
            if (strpbrk((string) $option, ',|') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 构造字段的校验规则（不含 required 以外的业务规则）。
     *
     * @param array $field
     * @return array
     */
    public static function buildRules(array $field)
    {
        $type = isset($field['type']) ? self::normalizeType($field['type']) : 'input';
        $rules = array();

        if (!empty($field['must_fill'])) {
            $rules[] = 'required';
        }

        if (self::supportsOptions($type)) {
            $options = isset($field['option']) && is_array($field['option']) ? $field['option'] : self::parseOptions(isset($field['option']) ? $field['option'] : '');
            // 选项含分隔符（`,` / `|`）时无法安全拼进规则串：`in:` 会被拆碎，`|` 会让整条规则串
            // 断裂后静默失效。这类选项的合法性统一由 normalizeValue() 的白名单交集兜底。
            if (!empty($options) && !self::hasRuleSeparator($options)) {
                $rules[] = 'in:' . implode(',', $options);
            }
        }

        if (self::supportsUpload($type)) {
            // 上传值只存附件编号，长度由 number 列（80）约束
            $rules[] = 'max:80';
        }

        $custom = isset($field['validate']) ? trim((string) $field['validate']) : '';
        if ($custom !== '') {
            foreach (preg_split('/[,，]/u', $custom) as $rule) {
                $rule = trim($rule);
                if ($rule === '') {
                    continue;
                }
                if (!self::isRuleUsable($rule)) {
                    continue;
                }
                $rules = self::mergeRule($rules, $rule);
            }
        } else {
            // 兼容老字段：本次改造前创建的字段 validate 多为空，仍按类型默认规则校验，
            // 避免上线后这些表单悄悄失去格式校验。用户在后台保存一次即固化为显式规则。
            foreach (self::defaultRules($type) as $rule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * 规则是否可用：需要参数的规则在参数为空时丢弃。
     *
     * 后台预选项点击后会先落一个只有冒号的壳（same: / regex://），用户没接着填就保存时，
     * `same:` 会拿空字段名去比较，导致该字段只要非空就永远校验失败。这类残规则在组装阶段
     * 直接忽略，而不是留到前台报错。
     *
     * @param string $rule
     * @return bool
     */
    private static function isRuleUsable($rule)
    {
        $name = self::ruleNameOf($rule);
        $param = trim(self::ruleParamOf($rule));

        if ($name === 'regex') {
            return trim($param, '/') !== '';
        }

        static $needParam = array(
            'min', 'max', 'between', 'min_value', 'max_value', 'between_value',
            'in', 'not_in', 'digits', 'digits_between', 'date_format',
            'same', 'different', 'required_if', 'starts_with', 'ends_with',
        );

        return !in_array($name, $needParam, true) || $param !== '';
    }

    /**
     * 合并一条自定义规则：同名规则不重复堆叠，数值上下限取更严的一侧。
     *
     * 典型场景：email 类型自带 max:120，用户又填了 max:200。两条并列时 120 先生效，
     * 用户会以为设了 200 却没放宽。这里改成就地取小，规则串里只留一条有效约束。
     *
     * @param array $rules
     * @param string $rule
     * @return array
     */
    private static function mergeRule(array $rules, $rule)
    {
        $name = self::ruleNameOf($rule);
        $param = self::ruleParamOf($rule);

        foreach ($rules as $index => $exists) {
            if (self::ruleNameOf($exists) !== $name) {
                continue;
            }

            $existsParam = self::ruleParamOf($exists);
            if (is_numeric($param) && is_numeric($existsParam)) {
                // 上限取小、下限取大，两层约束叠加时保留更严的那条
                if (in_array($name, array('max', 'max_value', 'max_kb'), true) && (float) $param < (float) $existsParam) {
                    $rules[$index] = $rule;
                } elseif (in_array($name, array('min', 'min_value'), true) && (float) $param > (float) $existsParam) {
                    $rules[$index] = $rule;
                }
            }

            return $rules;
        }

        $rules[] = $rule;

        return $rules;
    }

    /**
     * 规则名（冒号前部分）。
     *
     * @param string $rule
     * @return string
     */
    private static function ruleNameOf($rule)
    {
        $colon = strpos($rule, ':');

        return $colon === false ? $rule : substr($rule, 0, $colon);
    }

    /**
     * 规则参数（冒号后部分，无参数返回空串）。
     *
     * @param string $rule
     * @return string
     */
    private static function ruleParamOf($rule)
    {
        $colon = strpos($rule, ':');

        return $colon === false ? '' : substr($rule, $colon + 1);
    }

    /**
     * 按类型清洗提交值。
     *
     * 返回标量（input / textarea / select / radio / switch / agree / number / email / tel / url / date）
     * 或数组（checkbox / area / image / gallery / file）。
     *
     * @param array $field
     * @param mixed $value
     * @return mixed
     */
    public static function normalizeValue(array $field, $value)
    {
        $type = isset($field['type']) ? self::normalizeType($field['type']) : 'input';

        switch ($type) {
            case 'textarea':
                if (is_array($value)) {
                    $value = implode("\n", array_map('strval', $value));
                }
                $value = str_replace(array("\r\n", "\r"), "\n", (string) $value);

                return trim($value);

            case 'checkbox':
                $options = isset($field['option']) && is_array($field['option']) ? $field['option'] : array();
                $values = is_array($value) ? $value : ($value === '' || $value === null ? array() : array($value));
                $result = array();
                foreach ($values as $item) {
                    $item = self::scalarText($item);
                    if ($item === '') {
                        continue;
                    }
                    if (!empty($options) && !in_array($item, $options, true)) {
                        continue;
                    }
                    if (!in_array($item, $result, true)) {
                        $result[] = $item;
                    }
                }
                $limit = isset($field['limit']) ? (int) $field['limit'] : 0;
                if ($limit > 0 && count($result) > $limit) {
                    $result = array_slice($result, 0, $limit);
                }

                return $result;

            case 'switch':
            case 'agree':
                return self::scalarText($value) === '1' ? '1' : '0';

            case 'area':
                $value = is_array($value) ? $value : array();
                $area = array();
                foreach (array('province', 'city', 'district') as $part) {
                    $area[$part] = isset($value[$part]) ? self::scalarText($value[$part]) : '';
                }

                return $area;

            case 'image':
            case 'gallery':
            case 'file':
                $values = is_array($value) ? $value : ($value === '' || $value === null ? array() : array($value));
                $result = array();
                foreach ($values as $item) {
                    $item = self::scalarText($item);
                    if ($item === '' || !preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $item)) {
                        continue;
                    }
                    if (!in_array($item, $result, true)) {
                        $result[] = $item;
                    }
                }
                $limit = isset($field['limit']) ? (int) $field['limit'] : 1;
                if ($type === 'image') {
                    $limit = 1;
                }
                if ($limit > 0 && count($result) > $limit) {
                    $result = array_slice($result, 0, $limit);
                }

                return $result;

            case 'number':
                return trim(self::scalarText($value));

            case 'select':
            case 'radio':
                $value = trim(self::scalarText($value));
                $options = isset($field['option']) && is_array($field['option']) ? $field['option'] : array();

                // 非选项值一律置空：`in:` 规则可能因选项含分隔符而缺位，这里必须兜底
                return (!empty($options) && !in_array($value, $options, true)) ? '' : $value;

            default:
                return trim(self::scalarText($value));
        }
    }

    /**
     * 按类型把入库值格式化为展示文本（上传类由调用方补齐文件信息）。
     *
     * @param array $field
     * @param mixed $value
     * @return string
     */
    public static function formatValue(array $field, $value)
    {
        $type = isset($field['type']) ? self::normalizeType($field['type']) : 'input';

        if (is_array($value)) {
            if ($type === 'area') {
                $parts = array();
                foreach (array('province', 'city', 'district') as $part) {
                    if (!empty($value[$part])) {
                        $parts[] = $value[$part];
                    }
                }

                return implode(' ', $parts);
            }

            if ($type === 'image' || $type === 'gallery' || $type === 'file') {
                return implode('、', array_map('strval', $value));
            }

            return implode('、', array_map('strval', $value));
        }

        if ($type === 'switch') {
            return (string) $value === '1' ? lang('yes') : lang('no');
        }

        if ($type === 'agree') {
            return (string) $value === '1'
                ? lang('form_agree_checked', lang('yes'))
                : lang('form_agree_unchecked', lang('no'));
        }

        return (string) $value;
    }

    /**
     * 类型名归一：未登记的类型按 input 处理。
     *
     * @param string $type
     * @return string
     */
    public static function normalizeType($type)
    {
        $type = (string) $type;

        return isset(self::$types[$type]) ? $type : 'input';
    }

    /**
     * 把提交值压成标量文本。
     *
     * @param mixed $value
     * @return string
     */
    private static function scalarText($value)
    {
        if (is_array($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
