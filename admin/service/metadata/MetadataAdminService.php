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

namespace Dou\Admin\Service\Metadata;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Metadata\MetadataModules;
use Dou\Core\Service\Metadata\MetadataSchema;
use Dou\Core\Service\Metadata\MetadataService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台扩展字段定义管理页数据编排。
 */
class MetadataAdminService extends BaseService
{
    /** @var MetadataDefinitionTranslationService */
    private $definitionTranslations;

    /**
     * @param MetadataDefinitionTranslationService $definitionTranslations
     */
    public function __construct(MetadataDefinitionTranslationService $definitionTranslations)
    {
        $this->definitionTranslations = $definitionTranslations;
    }

    /**
     * 列表页数据：全部注册模块分组（空模块同样输出，便于发现全部可配置项）。
     *
     * @return array
     */
    public function buildListData()
    {
        $labels = MetadataModules::labels();
        $ready = MetadataService::instance()->isReady();

        $groups = array();
        foreach ($labels as $module => $label) {
            $fields = array();
            if ($ready) {
                foreach (MetadataService::instance()->schema($module, true) as $definition) {
                    $definition['type_label'] = lang('metadata_type_' . $definition['type'], $definition['type']);
                    $definition['required_label'] = $definition['required'] ? lang('yes', 'Yes') : lang('no', 'No');
                    $definition['status_label'] = $definition['status']
                        ? lang('metadata_status_on', '启用')
                        : lang('metadata_status_off', '禁用');
                    $fields[] = $definition;
                }
            }
            $groups[] = array(
                'group_id' => 'module-' . $module,
                'module' => $module,
                'label' => $label,
                'count' => count($fields),
                'fields' => $fields,
            );
        }

        return array(
            'groups' => $groups,
            'page_cue' => $ready ? '' : lang('metadata_cue_not_ready', '扩展字段定义表尚未就绪，请先升级系统。'),
        );
    }

    /**
     * 新建 / 编辑表单数据。
     *
     * @param int $id 0 表示新建
     * @param string $defaultModule 新建时的所属模块（来自 ?module=，由列表分组入口携带）
     * @return array
     * @throws DomainException
     */
    public function buildFormData($id = 0, $defaultModule = '')
    {
        $id = (int) $id;
        $edit = $id > 0 ? MetadataService::instance()->find($id) : null;
        if ($id > 0 && !$edit) {
            throw new DomainException(lang('metadata_error_not_found', '扩展字段不存在'), route('admin.metadata.fields'));
        }
        if ($id === 0 && !MetadataService::instance()->isReady()) {
            throw new DomainException(
                lang('metadata_error_not_ready', '扩展字段定义表尚未就绪，请先升级系统'),
                route('admin.metadata.fields')
            );
        }

        $labels = MetadataModules::labels();
        $currentModule = $edit ? $edit['module'] : (is_scalar($defaultModule) ? (string) $defaultModule : '');
        if (!isset($labels[$currentModule])) {
            // 所属模块由列表分组入口决定，缺失时回列表重新选择
            throw new DomainException(
                $edit
                    ? lang('metadata_error_module', '所属模块不合法')
                    : lang('metadata_create_need_module', '请从对应模块分组点击「新增」创建扩展字段'),
                route('admin.metadata.fields')
            );
        }

        $field = array(
            'id' => 0,
            'module' => $currentModule,
            'slug' => '',
            'label' => '',
            'type' => 'input',
            'options_text' => '',
            'extensions' => '',
            'max_kb' => 0,
            'required' => 0,
            'limit' => 0,
            'tip' => '',
            'sort' => 50,
            'status' => 1,
        );
        if ($edit) {
            $field = array_merge($field, $edit, array('options_text' => implode("\n", $edit['options'])));
        }

        $typeOptions = array();
        foreach (MetadataSchema::types() as $type) {
            $typeOptions[] = array(
                'value' => $type,
                'label' => lang('metadata_type_' . $type, $type),
                'cur' => $type === $field['type'],
            );
        }

        $translations = $this->definitionTranslations->load($id);
        $defaultPack = (string) Config::get('site.language', 'zh_cn');
        $btnLang = language()->buildLangButtons('metadata', $id, array('label', 'options'), array(
            'draft' => true,
            'draft_create' => true,
            'default_pack' => $defaultPack,
            'exclude_pack' => $defaultPack,
            'has_value' => isset($translations['has_value']) ? $translations['has_value'] : array(),
            'labels' => array(
                'label' => lang('metadata_label'),
                'options' => lang('metadata_options'),
            ),
            'targets' => array(
                'label' => array(
                    'kind' => 'text',
                    'target' => 'definition_language[%s][label]',
                    'source' => 'label',
                    'ai_field' => 'label',
                    'ai_type' => 'text',
                ),
                'options' => array(
                    'kind' => 'options',
                    'target' => 'definition_language[%s][options]',
                    'source' => 'options',
                ),
            ),
        ));

        return array(
            'field' => $field,
            'module_label' => $labels[$field['module']],
            'type_options' => $typeOptions,
            'type_label' => lang('metadata_type_' . $field['type'], $field['type']),
            'is_edit' => $edit ? 1 : 0,
            'btn_lang' => $btnLang,
            'definition_lang_drafts' => isset($translations['drafts']) ? $translations['drafts'] : array(),
            'extensions_baseline' => implode(',', MetadataSchema::platformFileExtensions()),
            'page_cue' => '',
        );
    }

    /**
     * 保存定义（新建 / 更新）。
     *
     * @param array $data
     * @return array{id:int, module:string}
     * @throws DomainException
     */
    public function save(array $data)
    {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $adminId = (int) auth('admin')->id();

        return DB::transaction(function () use ($data, $id, $adminId) {
            if ($id > 0) {
                MetadataService::instance()->update($id, $data);
                // 所属模块以库内记录为准，避免表单被篡改时审计串到其他模块
                $field = MetadataService::instance()->find($id);
                $module = $field ? $field['module'] : (isset($data['module']) ? (string) $data['module'] : '');
                $this->definitionTranslations->save($id, $data, $field ? $field['options'] : array());
                audit()->writeAdminLog($adminId, AdminLogAction::UPDATE, 1, 'metadata:' . $module);
            } else {
                $module = isset($data['module']) ? (string) $data['module'] : '';
                // 表单不再提供模块选择，提交侧仍需拦住未注册模块
                if (!MetadataModules::exists($module)) {
                    throw new DomainException(
                        lang('metadata_error_module', '所属模块不合法'),
                        route('admin.metadata.fields')
                    );
                }
                $id = MetadataService::instance()->create($data);
                $field = MetadataService::instance()->find($id);
                $this->definitionTranslations->save($id, $data, $field ? $field['options'] : array());
                audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, 'metadata:' . $module);
            }

            return array('id' => (int) $id, 'module' => $module);
        });
    }

    /**
     * @param int $id
     * @return string 所属模块
     * @throws DomainException
     */
    public function delete($id)
    {
        $field = MetadataService::instance()->find($id);
        if (!$field) {
            throw new DomainException(lang('metadata_error_not_found', '扩展字段不存在'));
        }
        MetadataService::instance()->delete($id);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'metadata:' . $field['module']);

        return $field['module'];
    }
}
