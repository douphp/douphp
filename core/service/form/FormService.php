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

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表单引擎（全局定义与数据还原）。
 *
 * 对外三类消费方：
 *   - 前台 Init：`all()` 注入全局 `$form`（slug 索引，模板直接 `$form.quote.contact.name`）
 *   - 前台 / API 详情页：`buildFields()` 取字段结构，`formatData()` 还原提交内容
 *   - 后台：`getFormList()` / `getFields()` 供列表与字段面板复用
 *
 * 只输出启用（status=1）的表单与字段，全部按 sort ASC, id ASC 排序；表单与字段的
 * name / content / placeholder / tip 已在服务端按当前语言翻译，读取方不需要再调 langValue。
 */
class FormService extends BaseService
{
    /** @var array|null slug => 表单定义（含字段平铺） */
    private $allCache = null;

    /**
     * 全局表单定义：slug 索引，表单 meta 与各字段平铺同层。
     *
     * @return array
     */
    public function all()
    {
        if ($this->allCache !== null) {
            return $this->allCache;
        }

        $this->allCache = array();

        $rows = DB::table('form')->where('status', 1)->order('sort ASC, id ASC')->select();
        if (empty($rows)) {
            return $this->allCache;
        }

        $formIds = array();
        foreach ($rows as $row) {
            $formIds[] = (int) $row['id'];
        }

        $built = $this->buildFieldsForForms($formIds);

        language()->warmup('form', $formIds, 'name,content');

        foreach ($rows as $row) {
            $formId = (int) $row['id'];
            $slug = $this->normalizeSlug(isset($row['slug']) ? $row['slug'] : '', $formId);

            $node = array(
                'form_id' => $formId,
                'slug' => $slug,
                'name' => language()->langValue(isset($row['name']) ? $row['name'] : '', 'form', $formId, 'name'),
                'content' => language()->langValue(isset($row['content']) ? $row['content'] : '', 'form', $formId, 'content'),
                'captcha' => !empty($row['captcha']) ? 1 : 0,
                'require_login' => !empty($row['require_login']) ? 1 : 0,
                'success_message' => isset($row['success_message']) ? (string) $row['success_message'] : '',
                'success_url' => isset($row['success_url']) ? (string) $row['success_url'] : '',
                'field_count' => isset($built[$formId]) ? count($built[$formId]) : 0,
            );

            if (!empty($built[$formId])) {
                foreach ($built[$formId] as $field) {
                    $node[$field['slug']] = $field;
                }
            }

            $this->allCache[$slug] = $node;
        }

        return $this->allCache;
    }

    /**
     * 表单列表（API / 后台复用）。
     *
     * @param bool $onlyEnabled
     * @return array
     */
    public function getFormList($onlyEnabled = true)
    {
        $query = DB::table('form');
        if ($onlyEnabled) {
            $query = $query->where('status', 1);
        }
        $rows = $query->order('sort ASC, id ASC')->select();

        return $this->formatFormRows((array) $rows);
    }

    /**
     * 表单列表分页（小程序上拉加载依赖「越界返回空列表」判定到底）。
     *
     * @param int $page
     * @param int $pageSize
     * @param string $pageUrl 分页链接基址（API 端仅供 pager 回传）
     * @return array list / pager
     */
    public function paginateFormList($page = 1, $pageSize = 10, $pageUrl = '')
    {
        $pageSize = (int) $pageSize > 0 ? (int) $pageSize : 10;
        $page = (int) $page > 0 ? (int) $page : 1;

        $result = DB::table('form')
            ->where('status', 1)
            ->order('sort ASC, id ASC')
            ->paginate($pageSize, $page, (string) $pageUrl);

        return array(
            'list' => $this->formatFormRows((array) $result['list']),
            'pager' => isset($result['pager']) ? (array) $result['pager'] : array(),
        );
    }

    /**
     * 表单行归一：补字段数、slug 兜底，列表与分页共用。
     *
     * @param array $rows
     * @return array
     */
    private function formatFormRows(array $rows)
    {
        if (empty($rows)) {
            return array();
        }

        $counts = $this->countFields();

        $list = array();
        foreach ($rows as $row) {
            $formId = (int) $row['id'];
            $list[] = array(
                'id' => $formId,
                'form_id' => $formId,
                'slug' => $this->normalizeSlug(isset($row['slug']) ? $row['slug'] : '', $formId),
                'name' => isset($row['name']) ? (string) $row['name'] : '',
                'content' => isset($row['content']) ? (string) $row['content'] : '',
                'success_message' => isset($row['success_message']) ? (string) $row['success_message'] : '',
                'success_url' => isset($row['success_url']) ? (string) $row['success_url'] : '',
                'captcha' => !empty($row['captcha']) ? 1 : 0,
                'require_login' => !empty($row['require_login']) ? 1 : 0,
                'status' => isset($row['status']) ? (int) $row['status'] : 1,
                'sort' => isset($row['sort']) ? (int) $row['sort'] : 0,
                'field_count' => isset($counts[$formId]) ? $counts[$formId] : 0,
                'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : '',
            );
        }

        return $list;
    }

    /**
     * 单个表单的原始行（含字段）。
     *
     * @param int|string $formIdOrSlug
     * @return array
     */
    public function getForm($formIdOrSlug)
    {
        $row = null;
        if (is_numeric($formIdOrSlug)) {
            $row = DB::table('form')->where('id', (int) $formIdOrSlug)->find();
        } else {
            $row = DB::table('form')->where('slug', (string) $formIdOrSlug)->find();
        }

        if (empty($row)) {
            return array();
        }

        $row['fields'] = $this->getFields((int) $row['id']);

        return $row;
    }

    /**
     * 字段原始列表（后台字段面板与编辑回填使用）。
     *
     * @param int $formId
     * @return array
     */
    public function getFields($formId)
    {
        $formId = (int) $formId;
        if ($formId <= 0) {
            return array();
        }

        $rows = DB::table('form_field')->where('form_id', $formId)->order('sort ASC, id ASC')->select();
        if (empty($rows)) {
            return array();
        }

        $fields = array();
        foreach ($rows as $row) {
            $fields[] = $this->normalizeFieldRow($row, $formId);
        }

        return $fields;
    }

    /**
     * 字段结构：fields（slug 索引）与 field_list（顺序列表）双份。
     *
     * @param int $formId
     * @return array
     */
    public function buildFields($formId)
    {
        $fields = $this->getFields($formId);

        $indexed = array();
        foreach ($fields as $field) {
            $indexed[$field['slug']] = $field;
        }

        return array(
            'fields' => $indexed,
            'field_list' => $fields,
        );
    }

    /**
     * 提交内容还原：按字段定义还原标签、展示值与附件信息。
     *
     * 未在字段定义中的键原样保留，标记 custom=true，由后台标注「模板自定义字段」。
     *
     * @param int $formId
     * @param string $dataJson
     * @return array
     */
    public function formatData($formId, $dataJson)
    {
        $formId = (int) $formId;
        $data = json_decode((string) $dataJson, true);
        if (!is_array($data)) {
            $data = array();
        }

        $fields = $this->getFields($formId);

        // 一次批量取回全部附件行，避免逐字段查库
        $numbers = array();
        foreach ($fields as $field) {
            if (!$field['is_upload'] || !isset($data[$field['slug']])) {
                continue;
            }
            foreach ((array) $data[$field['slug']] as $number) {
                if (is_scalar($number) && (string) $number !== '') {
                    $numbers[] = (string) $number;
                }
            }
        }
        $fileMap = $this->resolveFiles($numbers);

        $result = array();
        $known = array();
        foreach ($fields as $field) {
            $known[$field['slug']] = true;
            $value = isset($data[$field['slug']]) ? $data[$field['slug']] : '';
            $files = array();
            if ($field['is_upload']) {
                foreach ((array) $value as $number) {
                    $number = (string) $number;
                    if ($number !== '' && isset($fileMap[$number])) {
                        $files[] = $fileMap[$number];
                    }
                }
                $display = '';
                foreach ($files as $file) {
                    $display .= ($display === '' ? '' : '、') . $file['name'];
                }
            } else {
                $display = FormFieldSchema::formatValue($field, $value);
            }

            $result[] = array(
                'slug' => $field['slug'],
                'name' => $field['name'],
                'type' => $field['type'],
                'type_name' => $field['type_name'],
                'value' => $value,
                'display' => $display,
                'files' => $files,
                'custom' => false,
            );
        }

        foreach ($data as $key => $value) {
            $key = (string) $key;
            if ($key === '' || isset($known[$key])) {
                continue;
            }
            $result[] = array(
                'slug' => $key,
                'name' => $key,
                'type' => 'custom',
                'type_name' => lang('form_field_custom', $key),
                'value' => $value,
                'display' => is_array($value) ? implode('、', array_map('strval', $value)) : (string) $value,
                'files' => array(),
                'custom' => true,
            );
        }

        return $result;
    }

    /**
     * 整页模板解析：theme/{theme}/form/{slug}.dwt 优先，缺失回落 form.dwt。
     *
     * @param string $slug
     * @return string
     */
    public function resolveView($slug)
    {
        $slug = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $slug));
        if ($slug === '') {
            return 'form.dwt';
        }

        $theme = (string) Config::get('site.site_theme', 'default');
        if ($theme === '') {
            $theme = 'default';
        }

        $file = ROOT_PATH . 'theme/' . $theme . '/form/' . $slug . '.dwt';
        if (is_file($file)) {
            return 'form/' . $slug . '.dwt';
        }

        return 'form.dwt';
    }

    /**
     * 按表单集合批量构造字段结构。
     *
     * @param array $formIds
     * @return array formId => 字段列表
     */
    private function buildFieldsForForms(array $formIds)
    {
        $formIds = array_values(array_unique(array_map('intval', $formIds)));
        if (empty($formIds)) {
            return array();
        }

        $rows = DB::table('form_field')->whereIn('form_id', $formIds)->order('sort ASC, id ASC')->select();
        if (empty($rows)) {
            return array();
        }

        $fieldIds = array();
        foreach ($rows as $row) {
            $fieldIds[] = (int) $row['id'];
        }
        language()->warmup('form_field', $fieldIds, 'name,placeholder,tip');

        $built = array();
        foreach ($rows as $row) {
            $formId = (int) $row['form_id'];
            $field = $this->normalizeFieldRow($row, $formId);
            $built[$formId][] = $field;
        }

        return $built;
    }

    /**
     * 单行字段归一：解析 option、翻译文案、补齐派生属性。
     *
     * @param array $row
     * @param int $formId
     * @return array
     */
    private function normalizeFieldRow(array $row, $formId)
    {
        $fieldId = (int) $row['id'];
        $slug = isset($row['slug']) ? (string) $row['slug'] : '';
        $type = FormFieldSchema::normalizeType(isset($row['type']) ? $row['type'] : 'input');
        $options = FormFieldSchema::parseOptions(isset($row['option']) ? $row['option'] : '');
        $default = isset($row['default_value']) ? (string) $row['default_value'] : '';
        $width = isset($row['width']) ? (int) $row['width'] : 12;
        if ($width < 1 || $width > 12) {
            $width = 12;
        }

        // 模板无法调用 in_array：选中态由服务端预算成 option_list
        $defaultList = $this->parseDefaultList($default);
        $optionList = array();
        if (FormFieldSchema::supportsOptions($type)) {
            foreach ($options as $option) {
                $optionList[] = array(
                    'value' => $option,
                    'selected' => in_array($option, $defaultList, true),
                );
            }
        }

        return array(
            'field_id' => $fieldId,
            'slug' => $slug,
            'name' => language()->langValue(isset($row['name']) ? $row['name'] : '', 'form_field', $fieldId, 'name'),
            'type' => $type,
            'type_name' => FormFieldSchema::label($type),
            'required' => !empty($row['must_fill']) ? 1 : 0,
            'must_fill' => !empty($row['must_fill']) ? 1 : 0,
            'placeholder' => language()->langValue(isset($row['placeholder']) ? $row['placeholder'] : '', 'form_field', $fieldId, 'placeholder'),
            'tip' => language()->langValue(isset($row['tip']) ? $row['tip'] : '', 'form_field', $fieldId, 'tip'),
            'unit' => isset($row['unit']) ? (string) $row['unit'] : '',
            'default' => $default,
            'default_value' => $default,
            'default_list' => $defaultList,
            'width' => $width,
            'validate' => isset($row['validate']) ? (string) $row['validate'] : '',
            'option' => $options,
            'option_list' => $optionList,
            'limit' => isset($row['limit']) ? (int) $row['limit'] : 1,
            'extensions' => isset($row['extensions']) ? (string) $row['extensions'] : '',
            'max_size' => isset($row['max_size']) ? (int) $row['max_size'] : 0,
            'target' => FormFieldSchema::supportsUpload($type) ? FormFieldSchema::uploadPrefix($formId, $slug) : '',
            'is_upload' => FormFieldSchema::supportsUpload($type),
            'upload_type' => FormFieldSchema::uploadType($type),
            'sort' => isset($row['sort']) ? (int) $row['sort'] : 0,
        );
    }

    /**
     * 默认值拆成多值：checkbox 可能存「甲,乙」或换行分隔，其余类型通常只有一个值。
     *
     * @param string $default
     * @return array
     */
    private function parseDefaultList($default)
    {
        $default = trim((string) $default);
        if ($default === '') {
            return array();
        }

        $list = array();
        foreach (preg_split('/[,，\n\r]+/u', $default) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * 各表单字段数：formId => count。
     *
     * @return array
     */
    private function countFields()
    {
        $rows = DB::table('form_field')->field('form_id, COUNT(*) AS total')->groupBy('form_id')->select();
        $counts = array();
        foreach ((array) $rows as $row) {
            $counts[(int) $row['form_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * 附件编号批量还原为文件信息。
     *
     * @param array $numbers
     * @return array number => array(number, name, size, url, is_image)
     */
    private function resolveFiles(array $numbers)
    {
        $numbers = array_values(array_unique(array_filter(array_map('strval', $numbers), 'strlen')));
        if (empty($numbers)) {
            return array();
        }

        $rows = DB::table('file')->whereIn('number', $numbers)->select();
        if (empty($rows)) {
            return array();
        }

        $urls = attachment()->urlBatch($numbers);

        $map = array();
        foreach ($rows as $row) {
            $number = (string) $row['number'];
            $mime = isset($row['mime_type']) ? (string) $row['mime_type'] : '';
            $map[$number] = array(
                'number' => $number,
                'name' => isset($row['original_name']) && $row['original_name'] !== '' ? (string) $row['original_name'] : basename((string) $row['file']),
                'size' => isset($row['size']) ? (int) $row['size'] : 0,
                'url' => isset($urls[$number]) ? $urls[$number] : '',
                'is_image' => strpos($mime, 'image/') === 0,
            );
        }

        return $map;
    }

    /**
     * 表单 slug 兜底：为空时用 form{id}，保证模板变量始终可枚举。
     *
     * @param string $slug
     * @param int $formId
     * @return string
     */
    private function normalizeSlug($slug, $formId)
    {
        $slug = trim((string) $slug);

        return $slug !== '' ? $slug : 'form' . (int) $formId;
    }
}
