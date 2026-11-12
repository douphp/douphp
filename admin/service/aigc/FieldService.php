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
 * Release Date: 2026-11-11
 */

namespace Dou\Admin\Service\Aigc;

use Dou\Admin\Model\Aigc\AigcField;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Aigc\FieldDefinition;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI应用表单字段（route=aigc/field/...）。
 */
class FieldService extends BaseService
{
    /**
     * 取应用行；不存在时抛异常。
     *
     * @param int $appId
     * @return array
     */
    public function mustFindApp($appId)
    {
        $app = DB::table('aigc')->where('id', (int) $appId)->find();
        if (!$app) {
            throw new DomainException(lang('aigc_field_app_missing', '应用不存在'), route('admin.aigc'));
        }

        return $app;
    }

    /**
     * 字段列表页数据。
     *
     * @param int $appId
     * @return array {app, field_list}
     */
    public function getIndexData($appId)
    {
        $app = $this->mustFindApp($appId);
        $rows = DB::table('aigc_field')
            ->where('app_id', (int) $appId)
            ->order('sort ASC, id ASC')
            ->select();

        $list = array();
        foreach ((array) $rows as $row) {
            $row['type_name'] = lang('aigc_field_type_' . $row['type'], $row['type']);
            $row['required_text'] = !empty($row['must_fill']) ? lang('yes') : lang('no');
            $row['status_text'] = !empty($row['status']) ? lang('aigc_status_1') : lang('aigc_status_0');
            $row['edit_url'] = route('admin.aigc.field', array('app_id' => (int) $appId, 'field_id' => (int) $row['id']));
            $row['delete_url'] = route('admin.aigc.field.destroy', array('id' => (int) $row['id'], 'app_id' => (int) $appId));
            $list[] = $row;
        }

        return array('app' => $app, 'field_list' => $list);
    }

    /**
     * 字段管理页数据：列表 + 新增/编辑面板合并（同页展示）。
     *
     * @param int $appId
     * @param int $fieldId 0 为新增
     * @return array
     */
    public function getPageData($appId, $fieldId = 0)
    {
        $index = $this->getIndexData($appId);
        $panel = $this->buildPanel($appId, $fieldId);

        return array(
            'app' => $index['app'],
            'field_list' => $index['field_list'],
            'field' => $panel['field'],
            'type_options' => $panel['type_options'],
            'form_action' => $panel['form_action'],
            'form_method' => $panel['form_method'],
        );
    }

    /**
     * 新增 / 编辑面板数据。
     *
     * @param int $appId
     * @param int $fieldId
     * @return array
     */
    public function buildPanel($appId, $fieldId = 0)
    {
        $app = $this->mustFindApp($appId);
        $fieldId = (int) $fieldId;

        $lastSort = (int) DB::table('aigc_field')->where('app_id', (int) $appId)->order('sort DESC')->value('sort');
        $blank = array(
            'id' => 0,
            'app_id' => (int) $appId,
            'name' => '',
            'slug' => '',
            'type' => 'input',
            'option' => '',
            'placeholder' => '',
            'tip' => '',
            'default_value' => '',
            'must_fill' => 0,
            'width' => 12,
            'sort' => $lastSort + 10,
            'status' => 1,
        );

        $field = $blank;
        if ($fieldId > 0) {
            $model = AigcField::find($fieldId);
            $row = $model ? $model->getAttributes() : null;
            if ($row && (int) $row['app_id'] === (int) $appId) {
                foreach ($blank as $key => $default) {
                    if (isset($row[$key])) {
                        $field[$key] = $row[$key];
                    }
                }
                $field['id'] = (int) $row['id'];
            } else {
                $fieldId = 0;
            }
        }

        $typeOptions = array();
        foreach (FieldDefinition::types() as $type) {
            $typeOptions[] = array(
                'value' => $type,
                'label' => lang('aigc_field_type_' . $type, $type),
                'cur' => $type === $field['type'] ? 1 : 0,
            );
        }

        return array(
            'app' => $app,
            'field' => $field,
            'type_options' => $typeOptions,
            'form_action' => $fieldId > 0
                ? route('admin.aigc.field.update', array('id' => $fieldId, 'app_id' => (int) $appId))
                : route('admin.aigc.field.store', array('app_id' => (int) $appId)),
            'form_method' => $fieldId > 0 ? 'PUT' : '',
        );
    }

    /**
     * 新增字段。
     *
     * @param array $data
     * @return int
     */
    public function insert(array $data)
    {
        $data = $this->normalize($data);
        $this->assertSlugUnique((int) $data['app_id'], (string) $data['slug'], 0);
        $data['created_at'] = date('Y-m-d H:i:s');

        $model = AigcField::create($data);
        $id = (int) $model->getKey();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $data['name'], 'aigc_field');

        return $id;
    }

    /**
     * 更新字段。
     *
     * @param array $data
     * @return void
     */
    public function update(array $data)
    {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $model = $id > 0 ? AigcField::find($id) : null;
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.aigc'));
        }
        $data = $this->normalize($data);
        $this->assertSlugUnique((int) $data['app_id'], (string) $data['slug'], $id);

        $model->fill($data, 'update')->save();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $data['name'], 'aigc_field');
    }

    /**
     * 删除字段。
     *
     * @param int $id
     * @param array $post
     * @return array
     */
    public function delete($id, array $post)
    {
        $id = (int) $id;
        $model = $id > 0 ? AigcField::find($id) : null;
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.aigc'));
        }
        $row = $model->getAttributes();
        $appId = (int) $row['app_id'];

        if (isset($post['confirm'])) {
            AigcField::destroy($id);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $row['name'], 'aigc_field');

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.aigc.field', array('app_id' => $appId)),
            );
        }

        return array(
            'message' => preg_replace('/d%/Ums', (string) $row['name'], lang('del_check')),
            'back_url' => route('admin.aigc.field', array('app_id' => $appId)),
            'timeout' => '30',
            'confirm_url' => route('admin.aigc.field.destroy', array('id' => $id, 'app_id' => $appId)),
        );
    }

    /**
     * 按应用类型写入默认字段（仅应用尚无字段时）。
     *
     * @param int $appId
     * @param string $type
     * @return void
     */
    public function seedDefaults($appId, $type)
    {
        $appId = (int) $appId;
        if ($appId <= 0) {
            return;
        }
        if ((int) DB::table('aigc_field')->where('app_id', $appId)->count() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        foreach (FieldDefinition::defaultsForAppType((string) $type) as $field) {
            $field['app_id'] = $appId;
            $field['created_at'] = $now;
            $field['updated_at'] = $now;
            try {
                DB::table('aigc_field')->data($field)->insert();
            } catch (\Exception $e) {
                // 默认字段失败不阻断应用创建，管理员可在字段管理页手工补建
            }
        }
    }

    /**
     * 归一字段数据。
     *
     * @param array $data
     * @return array
     */
    private function normalize(array $data)
    {
        $data['app_id'] = isset($data['app_id']) ? (int) $data['app_id'] : 0;
        $data['slug'] = isset($data['slug']) ? trim((string) $data['slug']) : '';
        $data['type'] = in_array(isset($data['type']) ? $data['type'] : '', FieldDefinition::types(), true)
            ? $data['type']
            : 'input';
        $data['option'] = isset($data['option']) ? (string) $data['option'] : '';
        $data['placeholder'] = isset($data['placeholder']) ? (string) $data['placeholder'] : '';
        $data['tip'] = isset($data['tip']) ? (string) $data['tip'] : '';
        $data['default_value'] = isset($data['default_value']) ? (string) $data['default_value'] : '';
        $data['must_fill'] = !empty($data['must_fill']) ? 1 : 0;
        $data['width'] = isset($data['width']) ? max(1, min(12, (int) $data['width'])) : 12;
        $data['sort'] = isset($data['sort']) ? (int) $data['sort'] : 0;
        $data['status'] = !empty($data['status']) ? 1 : 0;
        $data['updated_at'] = date('Y-m-d H:i:s');
        unset($data['created_at']);

        return $data;
    }

    /**
     * 同应用内 slug 唯一。
     *
     * @param int $appId
     * @param string $slug
     * @param int $ignoreId
     * @return void
     */
    private function assertSlugUnique($appId, $slug, $ignoreId)
    {
        $query = DB::table('aigc_field')->where('app_id', (int) $appId)->where('slug', (string) $slug);
        if ((int) $ignoreId > 0) {
            $query->where('id', '!=', (int) $ignoreId);
        }
        if ($query->exists()) {
            throw new DomainException(lang('aigc_field_slug_exists', '同一应用内字段标识不能重复'), route('admin.aigc.field', array('app_id' => (int) $appId)));
        }
    }
}
