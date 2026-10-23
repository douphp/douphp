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

namespace Dou\Core\Service\Metadata;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Support\Check;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 扩展字段值的编解码、装饰与附件清理。
 *
 * 对外契约：
 * - metadata：slug 键关联的展示条目 {label, value}；value 为可直接输出的文本 / URL / URL 数组，
 *   无值时文本为空串、gallery 为空数组，模板无需再判断类型或另设空值标识；
 * - metadata_fields：slug 键关联的完整字段数据，供高级模板定点取值；
 * - metadata_list：按定义顺序排列的展示条目列表 {slug, label, value, type}。
 */
class MetadataValues extends BaseService
{
    /**
     * @param mixed $json
     * @return array
     */
    public static function decode($json)
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || trim($json) === '') {
            return array();
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array $map
     * @return string
     * @throws DomainException
     */
    public static function encode(array $map)
    {
        if (empty($map)) {
            return '';
        }

        $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new DomainException(lang('metadata_error_encode', '扩展字段数据编码失败'));
        }

        return $json;
    }

    /**
     * @param array $base
     * @param array $override
     * @return array
     */
    public static function merge(array $base, array $override)
    {
        return array_replace($base, $override);
    }

    /**
     * 装饰一份字段值。
     *
     * @param array $schema
     * @param array $values
     * @param array $options urls（number=>url 批量映射）
     * @return array slug => field
     */
    public static function decorate(array $schema, array $values, array $options = array())
    {
        $urls = isset($options['urls']) && is_array($options['urls']) ? $options['urls'] : array();
        $fields = array();

        foreach ($schema as $slug => $definition) {
            $type = $definition['type'];
            $value = array_key_exists($slug, $values) ? $values[$slug] : null;
            $field = $definition;
            $field['value'] = $value;
            $field['options_data'] = array();
            $field['selected'] = array();
            $field['selected_labels'] = array();
            $field['url'] = '';
            $field['urls'] = array();
            $field['items'] = array();
            $field['html'] = '';
            $field['text'] = '';
            $field['summary'] = '';
            $field['switch_label'] = '';
            $field['has_value'] = !MetadataSchema::isEmptyValue($type, $value);
            $optionLabels = isset($definition['options_labels']) && is_array($definition['options_labels'])
                ? $definition['options_labels']
                : array();

            switch ($type) {
                case 'image':
                case 'file':
                    $number = is_string($value) ? $value : '';
                    $field['url'] = $number !== '' ? self::resolveUrl($number, $urls) : '';
                    $field['has_value'] = $field['url'] !== '';
                    $field['text'] = $field['url'];
                    $field['summary'] = $field['url'];
                    break;

                case 'gallery':
                    $numbers = is_array($value) ? array_values($value) : array();
                    foreach ($numbers as $number) {
                        if (!is_scalar($number)) {
                            continue;
                        }
                        $number = (string) $number;
                        $url = $number !== '' ? self::resolveUrl($number, $urls) : '';
                        if ($url === '') {
                            continue;
                        }
                        $field['items'][] = array('number' => $number, 'url' => $url);
                        $field['urls'][] = $url;
                    }
                    $field['has_value'] = !empty($field['items']);
                    $field['text'] = implode(', ', $field['urls']);
                    $field['summary'] = $field['text'];
                    break;

                case 'select':
                case 'radio':
                    $selected = is_scalar($value) ? (string) $value : '';
                    $field['selected'] = $selected;
                    foreach ($definition['options'] as $option) {
                        $field['options_data'][] = array(
                            'value' => $option,
                            'label' => isset($optionLabels[$option]) ? $optionLabels[$option] : $option,
                            'checked' => $option === $selected,
                        );
                    }
                    $selectedLabel = isset($optionLabels[$selected]) ? $optionLabels[$selected] : $selected;
                    $field['text'] = $selectedLabel;
                    $field['summary'] = $selectedLabel;
                    break;

                case 'checkbox':
                    $selected = is_array($value) ? array_values($value) : array();
                    $field['selected'] = $selected;
                    foreach ($definition['options'] as $option) {
                        $checked = in_array($option, $selected, true);
                        $field['options_data'][] = array(
                            'value' => $option,
                            'label' => isset($optionLabels[$option]) ? $optionLabels[$option] : $option,
                            'checked' => $checked,
                        );
                        if ($checked) {
                            $field['selected_labels'][] = isset($optionLabels[$option]) ? $optionLabels[$option] : $option;
                        }
                    }
                    $field['text'] = implode(', ', $field['selected_labels']);
                    $field['summary'] = $field['text'];
                    break;

                case 'switch':
                    $field['text'] = (is_scalar($value) && ((string) $value === '1' || $value === 1)) ? lang('yes', 'Yes') : lang('no', 'No');
                    $field['switch_label'] = $field['text'];
                    $field['summary'] = $field['text'];
                    break;

                case 'editor':
                    $field['html'] = self::renderEditor($value);
                    $field['text'] = $field['html'];
                    $field['summary'] = self::summary($field['html'], 80);
                    break;

                case 'textarea':
                    $field['text'] = is_scalar($value) ? (string) $value : '';
                    $field['summary'] = self::summary($field['text'], 80);
                    break;

                default:
                    $field['text'] = is_scalar($value) ? (string) $value : '';
                    $field['summary'] = $type === 'input' ? self::summary($field['text'], 60) : $field['text'];
                    break;
            }

            $fields[$slug] = $field;
        }

        return $fields;
    }

    /**
     * 展示条目：slug => {label, value}。
     *
     * @param array $fields
     * @return array
     */
    public static function publicValues(array $fields)
    {
        $values = array();
        foreach ($fields as $slug => $field) {
            $values[$slug] = self::publicEntry($field);
        }

        return $values;
    }

    /**
     * 按定义顺序排列的展示条目列表（带 slug/type，供模板过滤）。
     *
     * @param array $fields
     * @return array
     */
    public static function publicList(array $fields)
    {
        $list = array();
        foreach ($fields as $slug => $field) {
            $entry = self::publicEntry($field);
            $entry['slug'] = $slug;
            $entry['type'] = $field['type'];
            $list[] = $entry;
        }

        return $list;
    }

    /**
     * @param array $field
     * @return array
     */
    private static function publicEntry(array $field)
    {
        return array(
            'label' => (string) $field['label'],
            'value' => self::displayValue($field),
        );
    }

    /**
     * 展示值：无值为空串（gallery 为空数组），否则按类型取 URL / URL 数组 / 纯文本。
     *
     * @param array $field
     * @return string|array
     */
    private static function displayValue(array $field)
    {
        $type = $field['type'];
        if ($type === 'gallery') {
            return $field['urls'];
        }
        if (MetadataSchema::isEmptyValue($type, $field['value'])) {
            return '';
        }
        if ($type === 'editor') {
            return trim(html_entity_decode(strip_tags((string) $field['html']), ENT_QUOTES, 'UTF-8'));
        }

        return (string) $field['text'];
    }

    /**
     * 批量装饰列表行（前台当前语言 / 后台默认语言）。
     *
     * @param string $module
     * @param array $rows
     * @param string $mode current|default
     * @return void
     */
    public static function decorateRows($module, array &$rows, $mode = 'current')
    {
        $schema = MetadataService::instance()->schema($module);
        if (empty($schema)) {
            foreach ($rows as &$row) {
                $row['metadata'] = array();
                $row['metadata_fields'] = array();
                $row['metadata_list'] = array();
            }
            unset($row);
            return;
        }

        $current = $mode === 'current';
        $ids = array();
        foreach ($rows as $row) {
            if (isset($row['id']) && (int) $row['id'] > 0) {
                $ids[] = (int) $row['id'];
            }
        }
        if ($current && !empty($ids)) {
            language()->warmup($module, $ids, 'metadata');
        }

        $prepared = array();
        $numbers = array();
        $skip = array();
        foreach ($rows as $index => $row) {
            if (isset($row['metadata_fields']) && is_array($row['metadata_fields']) && !empty($row['metadata_fields'])) {
                $skip[$index] = true;
                continue;
            }
            $default = self::decode(isset($row['metadata']) ? $row['metadata'] : '');
            $override = array();
            if ($current && isset($row['id']) && (int) $row['id'] > 0) {
                $override = self::decode(language()->langValue('', $module, (int) $row['id'], 'metadata'));
            }
            $merged = self::merge($default, $override);
            $prepared[$index] = $merged;
            $numbers += self::collectNumbers($schema, $merged);
        }

        $urls = array();
        if (!empty($numbers)) {
            $urls = attachment()->urlBatch(array_keys($numbers));
        }

        foreach ($rows as $index => &$row) {
            if (!empty($skip[$index])) {
                continue;
            }
            $fields = self::decorate($schema, $prepared[$index], array('urls' => $urls));
            $row['metadata'] = self::publicValues($fields);
            $row['metadata_fields'] = $fields;
            $row['metadata_list'] = self::publicList($fields);
        }
        unset($row);
    }

    /**
     * 单行装饰。
     *
     * @param string $module
     * @param array $row
     * @param string $mode
     * @return void
     */
    public static function decorateRow($module, array &$row, $mode = 'current')
    {
        $rows = array(&$row);
        self::decorateRows($module, $rows, $mode);
    }

    /**
     * 递归装饰 {category} 树节点的 list/child。
     *
     * @param string $module
     * @param array $tree
     * @param string $mode
     * @return void
     */
    public static function decorateTree($module, array &$tree, $mode = 'current')
    {
        foreach ($tree as &$node) {
            if (isset($node['list']) && is_array($node['list'])) {
                self::decorateRows($module, $node['list'], $mode);
            }
            if (isset($node['child']) && is_array($node['child'])) {
                self::decorateTree($module, $node['child'], $mode);
            }
        }
        unset($node);
    }

    /**
     * 收集一份值 map 中的附件号。
     *
     * @param array $schema
     * @param array $values
     * @return array number => true
     */
    public static function collectNumbers(array $schema, array $values)
    {
        $numbers = array();
        foreach ($schema as $slug => $field) {
            if (!MetadataSchema::isAttachment($field['type']) || !array_key_exists($slug, $values)) {
                continue;
            }
            if ($field['type'] === 'gallery') {
                foreach ((array) $values[$slug] as $number) {
                    if (is_scalar($number) && Check::fileNumber((string) $number)) {
                        $numbers[(string) $number] = true;
                    }
                }
            } elseif (is_scalar($values[$slug]) && Check::fileNumber((string) $values[$slug])) {
                $numbers[(string) $values[$slug]] = true;
            }
        }

        return $numbers;
    }

    /**
     * 删除指定记录的扩展附件（仅限 module+item_id+type=metadata:* 的归属行）。
     *
     * @param string $module
     * @param int $itemId
     * @param array $numbers
     * @return void
     */
    public static function purgeNumbers($module, $itemId, array $numbers)
    {
        $numbers = array_values(array_filter(array_unique($numbers), array('Dou\Core\Support\Check', 'fileNumber')));
        if (empty($numbers)) {
            return;
        }

        $rows = DB::table('file')
            ->field('number')
            ->where('module', (string) $module)
            ->where('item_id', (string) (int) $itemId)
            ->where('type', 'LIKE', 'metadata:%')
            ->where('number', 'IN', $numbers)
            ->select();

        foreach ((array) $rows as $row) {
            attachment()->delete($row['number']);
        }
    }

    /**
     * 模块卸载/记录清理时的全量附件清理。
     *
     * @param string $module
     * @param int|null $itemId
     * @return void
     */
    public static function purgeByModule($module, $itemId = null)
    {
        $query = DB::table('file')
            ->field('number')
            ->where('module', (string) $module)
            ->where('type', 'LIKE', 'metadata:%');
        if ($itemId !== null) {
            $query->where('item_id', (string) (int) $itemId);
        }

        foreach ((array) $query->select() as $row) {
            attachment()->delete($row['number']);
        }
    }

    /**
     * @param string $text
     * @param int $length
     * @return string
     */
    public static function summary($text, $length = 80)
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }
        $summary = Str::excerpt($text, (int) $length, false);

        return is_string($summary) ? $summary : '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function renderEditor($value)
    {
        if (!is_scalar($value) || (string) $value === '') {
            return '';
        }
        $value = (string) $value;

        $renderer = new MarkdownRenderer();

        return (string) $renderer->toHtml($value);
    }

    /**
     * @param string $number
     * @param array $urls
     * @return string
     */
    private static function resolveUrl($number, array $urls)
    {
        if (isset($urls[$number])) {
            return (string) $urls[$number];
        }

        return (string) attachment()->url($number);
    }
}
