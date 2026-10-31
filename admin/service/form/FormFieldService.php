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

namespace Dou\Admin\Service\Form;

use Dou\Admin\Model\Form\FormField;
use Dou\Admin\Service\Concerns\SavesLanguageDrafts;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Form\FormFieldSchema;
use Dou\Core\Service\Form\FormService as FormCore;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台表单字段（route=form/field/...）。
 *
 * 字段 CRUD 与类型元数据统一取自 {@see FormFieldSchema}；字段列表只暴露名称 / 键名 / 类型 /
 * 填写要求 / 排序，不再输出主题模板的插入代码（模板里手写 `{$form.<slug>.<field>.name}` 仍有效）。
 *
 * 字段 slug 不得占用表单节点的 meta 键，冲突时提示具体键名。
 */
class FormFieldService extends BaseService
{
    use SavesLanguageDrafts;

    /**
     * 表单节点内保留键：字段 slug 不得与之重名，否则 all() 平铺时覆盖表单 meta。
     *
     * @var array
     */
    private static $nodeReserved = array(
        'form_id',
        'slug',
        'name',
        'content',
        'captcha',
        'require_login',
        'success_message',
        'success_url',
        'field_count',
    );

    /** @var FormCore */
    private $formCore;

    /**
     * @param FormCore $formCore
     */
    public function __construct(FormCore $formCore)
    {
        $this->formCore = $formCore;
    }

    // =================================================================
    // 列表 / 表单面板
    // =================================================================

    /**
     * 字段列表数据。
     *
     * @param int $formId
     * @return array
     */
    public function buildFieldList($formId)
    {
        $formId = (int) $formId;
        $fields = $this->formCore->getFields($formId);

        $list = array();
        foreach ($fields as $field) {
            $fieldId = (int) $field['field_id'];
            $field['id'] = $fieldId;
            $field['option_text'] = FormFieldSchema::joinOptions(isset($field['option']) && is_array($field['option']) ? $field['option'] : array());
            $field['required_text'] = !empty($field['required']) ? lang('yes') : lang('no');
            $field['edit_url'] = route('admin.form.field', array('form_id' => $formId, 'field_id' => $fieldId));
            $field['delete_url'] = route('admin.form.field.destroy', array('id' => $fieldId, 'form_id' => $formId));
            $list[] = $field;
        }

        return $list;
    }

    /**
     * 字段新增 / 编辑面板数据。
     *
     * 字段列表与字段表单同页，因此提交目标不可交给框架 FormTargetBinder（它只认 create /
     * edit 两个路由），而是根据当前是否在编辑某个字段自行输出：
     *   - 新增：POST form/field，field_form_method 为空
     *   - 编辑：PUT  form/field/{id}，field_form_method = 'PUT'
     *
     * @param int $formId
     * @param int $fieldId 0 表示新建；非本表单的字段视为新建
     * @return array field, field_form_action, field_form_method, field_type_options, field_btn_lang, field_extensions_baseline
     */
    public function buildFieldPanelAssigns($formId, $fieldId = 0)
    {
        $formId = (int) $formId;
        $fieldId = (int) $fieldId;

        $lastSort = DB::table('form_field')->where('form_id', $formId)->order('sort DESC')->value('sort');
        $blank = array(
            'id' => 0,
            'form_id' => $formId,
            'name' => '',
            'slug' => '',
            'type' => 'input',
            'unit' => '',
            'option' => '',
            'placeholder' => '',
            'tip' => '',
            'default_value' => '',
            'validate' => FormFieldSchema::defaultRulesString('input'),
            'width' => 12,
            'must_fill' => 0,
            'limit' => 1,
            'extensions' => '',
            'max_size' => 0,
            'sort' => ((int) $lastSort) + 10,
        );

        $field = $blank;

        if ($fieldId > 0) {
            $model = FormField::find($fieldId);
            $row = $model ? $model->getAttributes() : null;
            if (!empty($row) && (int) $row['form_id'] === $formId) {
                foreach ($blank as $key => $default) {
                    if (isset($row[$key])) {
                        $field[$key] = $row[$key];
                    }
                }
                $field['id'] = (int) $row['id'];
                $field['form_id'] = (int) $row['form_id'];
                $field['option'] = isset($row['option']) ? (string) $row['option'] : '';
            } else {
                $fieldId = 0;
            }
        }

        // validate 是唯一权威：老字段为空时把类型默认规则回填到界面，
        // 用户不改动直接保存即固化，改了就按用户的值走。
        if (trim((string) $field['validate']) === '') {
            $field['validate'] = FormFieldSchema::defaultRulesString($field['type']);
        }

        $typeOptions = array();
        foreach (FormFieldSchema::labels($field['type']) as $value => $label) {
            $typeOptions[] = array(
                'value' => $value,
                'label' => $label,
                'cur' => $value === $field['type'] ? 1 : 0,
                'default_rules' => FormFieldSchema::defaultRulesString($value),
            );
        }

        $languageForm = $this->languageDraftService()->buildFormState('form_field', (int) $field['id']);

        return array(
            'field' => $field,
            'field_form_action' => $field['id'] > 0
                ? route('admin.form.field.update', array('id' => $field['id'], 'form_id' => $formId))
                : route('admin.form.field.store', array('form_id' => $formId)),
            'field_form_method' => $field['id'] > 0 ? 'PUT' : '',
            'field_validate_options' => FormFieldSchema::validatePresets(),
            'field_type_options' => $typeOptions,
            'field_btn_lang' => $languageForm['btn_lang'],
            'field_lang_drafts' => $languageForm['lang_drafts'],
            'field_extensions_baseline' => (string) Config::get('filesystems.upload_defaults.allow_extensions', ''),
        );
    }

    // =================================================================
    // CRUD
    // =================================================================

    /**
     * 新增字段。
     *
     * @param array $data 来自 FormFieldFormRequest::validated()
     * @return int 新增字段 ID
     */
    public function insertField(array $data)
    {
        $formId = (int) $data['form_id'];
        $adminId = (int) auth('admin')->id();
        $languagePlan = $this->languageDraftBegin('form_field', $data, 0, $adminId);

        $fieldId = (int) DB::transaction(function () use ($formId, $data, $adminId, $languagePlan) {
            $model = FormField::create($this->normalizeField($formId, $data));
            $fieldId = $model ? (int) $model->getKey() : 0;
            if ($fieldId <= 0) {
                throw new DomainException(lang('operation_failed'));
            }
            $this->languageDraftCommit($languagePlan, $fieldId, $adminId);

            return $fieldId;
        });

        $this->languageDraftPurge($languagePlan, $fieldId);

        return $fieldId;
    }

    /**
     * 预置模板播种单条字段：不做多语言草稿编排，直接落库。
     *
     * @param int $formId
     * @param array $field 模板内的字段定义
     * @return int 新增字段 ID
     */
    public function insertFromSource($formId, array $field)
    {
        $model = FormField::create($this->normalizeField((int) $formId, $field));

        return $model ? (int) $model->getKey() : 0;
    }

    /**
     * 更新字段。
     *
     * @param array $data 来自 FormFieldFormRequest::validated()
     * @return int 所属表单 ID
     */
    public function updateField(array $data)
    {
        $fieldId = (int) $data['id'];
        $model = FormField::find($fieldId);
        if (!$model) {
            throw new DomainException(lang('item_not_exist'), route('admin.form.form_list'));
        }
        $row = $model->getAttributes();
        $formId = isset($row['form_id']) ? (int) $row['form_id'] : (int) $data['form_id'];

        $normalized = $this->normalizeField($formId, $data, $fieldId);
        unset($normalized['form_id']);
        $adminId = (int) auth('admin')->id();
        $languagePlan = $this->languageDraftBegin('form_field', $data, $fieldId, $adminId);

        try {
            DB::transaction(function () use ($model, $normalized, $fieldId, $adminId, $languagePlan) {
                $model->fill($normalized, 'update')->save();
                $this->languageDraftCommit($languagePlan, $fieldId, $adminId);
            });
        } catch (\Exception $e) {
            $this->languageDraftAbort($languagePlan);
            throw $e;
        }

        $this->languageDraftPurge($languagePlan, $fieldId);

        return $formId;
    }

    /**
     * @param int $fieldId
     * @return string
     */
    public function getFieldName($fieldId)
    {
        return (string) DB::table('form_field')->where('id', (int) $fieldId)->value('name');
    }

    /**
     * @param int $fieldId
     * @return int
     */
    public function getFieldFormId($fieldId)
    {
        return (int) DB::table('form_field')->where('id', (int) $fieldId)->value('form_id');
    }

    /**
     * @param int $fieldId
     * @return void
     */
    public function deleteField($fieldId)
    {
        FormField::destroy((int) $fieldId);
    }

    // =================================================================
    // 内部
    // =================================================================

    /**
     * 字段写入值归一：类型白名单、option 解析、上传限制与栅格宽度。
     *
     * 预置模板播种与后台表单提交共用同一套归一逻辑。
     *
     * @param int $formId
     * @param array $data
     * @param int $fieldId 编辑时用于重名排除
     * @return array
     */
    public function normalizeField($formId, array $data, $fieldId = 0)
    {
        $formId = (int) $formId;
        $type = FormFieldSchema::normalizeType(isset($data['type']) ? $data['type'] : 'input');
        $slug = strtolower(trim(isset($data['slug']) ? (string) $data['slug'] : ''));
        $this->assertFieldSlug($formId, $slug, (int) $fieldId);

        $width = isset($data['width']) ? (int) $data['width'] : 12;
        if ($width < 1 || $width > 12) {
            $width = 12;
        }

        // limit 语义：上传类为最多文件数（image 恒 1），checkbox 为最多可选数（0 不限），其余恒 1
        $hasLimit = isset($data['limit']) && trim((string) $data['limit']) !== '';
        $limit = $hasLimit ? (int) $data['limit'] : 1;
        if ($type === 'image') {
            $limit = 1;
        } elseif (FormFieldSchema::supportsUpload($type)) {
            $limit = $limit < 1 ? 1 : ($limit > 50 ? 50 : $limit);
        } elseif ($type === 'checkbox') {
            // 未提交时按「不限」落库：多选默认只能勾一个是反直觉的
            $limit = !$hasLimit ? 0 : ($limit < 0 ? 0 : ($limit > 50 ? 50 : $limit));
        } else {
            $limit = 1;
        }

        $isUpload = FormFieldSchema::supportsUpload($type);

        return array(
            'form_id' => $formId,
            'name' => mb_substr(trim(isset($data['name']) ? (string) $data['name'] : ''), 0, 150),
            'slug' => $slug,
            'type' => $type,
            'unit' => mb_substr(trim(isset($data['unit']) ? (string) $data['unit'] : ''), 0, 30),
            'option' => FormFieldSchema::supportsOptions($type)
                ? FormFieldSchema::joinOptions(isset($data['option']) ? $data['option'] : '')
                : '',
            'placeholder' => mb_substr(trim(isset($data['placeholder']) ? (string) $data['placeholder'] : ''), 0, 255),
            'tip' => mb_substr(trim(isset($data['tip']) ? (string) $data['tip'] : ''), 0, 255),
            'default_value' => mb_substr(trim(isset($data['default_value']) ? (string) $data['default_value'] : ''), 0, 255),
            'validate' => $this->normalizeValidate(isset($data['validate']) ? $data['validate'] : ''),
            'width' => $width,
            'must_fill' => !empty($data['must_fill']) ? 1 : 0,
            'limit' => $limit,
            'extensions' => $isUpload ? $this->normalizeExtensions(isset($data['extensions']) ? $data['extensions'] : '') : '',
            'max_size' => $isUpload ? max(0, (int) (isset($data['max_size']) ? $data['max_size'] : 0)) : 0,
            'sort' => isset($data['sort']) ? max(0, (int) $data['sort']) : 0,
        );
    }

    /**
     * 校验规则串归一：全角逗号 / 竖线转半角逗号，去空去重。
     *
     * @param string $raw
     * @return string
     */
    private function normalizeValidate($raw)
    {
        $raw = str_replace(array('，', '|'), ',', (string) $raw);
        $rules = array();
        foreach (explode(',', $raw) as $rule) {
            $rule = trim($rule);
            if ($rule !== '' && !in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }

        return mb_substr(implode(',', $rules), 0, 255);
    }

    /**
     * 允许扩展名归一：小写、去点、去重，逗号分隔。
     *
     * @param string $raw
     * @return string
     */
    private function normalizeExtensions($raw)
    {
        $list = array();
        foreach (explode(',', strtolower((string) $raw)) as $extension) {
            $extension = preg_replace('/[^a-z0-9]/', '', trim($extension));
            if ($extension !== '' && !in_array($extension, $list, true)) {
                $list[] = $extension;
            }
        }

        return mb_substr(implode(',', $list), 0, 255);
    }

    /**
     * 字段 slug 保留键与同表单内唯一性校验。
     *
     * @param int $formId
     * @param string $slug
     * @param int $fieldId 编辑时排除自身
     * @return void
     */
    private function assertFieldSlug($formId, $slug, $fieldId = 0)
    {
        $slug = (string) $slug;
        if (in_array($slug, self::$nodeReserved, true)) {
            throw new DomainException(preg_replace('/d%/Ums', $slug, lang('form_field_reserved')));
        }

        $query = DB::table('form_field')->where('form_id', (int) $formId)->where('slug', $slug);
        if ($fieldId > 0) {
            $query->where('id', '!=', (int) $fieldId);
        }
        if ($query->value('id')) {
            throw new DomainException(preg_replace('/d%/Ums', $slug, lang('form_field_slug_exists')));
        }
    }
}
