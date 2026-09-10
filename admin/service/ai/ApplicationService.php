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

namespace Dou\Admin\Service\Ai;

use Dou\Admin\Model\Ai\AiApplication;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\ApiResponse;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 创作应用业务（数据表 `ai`，路由 `index.php?route=ai/...`）。
 *
 * 职责：
 * - 列表筛选分页、新增/编辑表单数据（模型列表、可挂载模块列表）。
 * - 写入与更新应用记录（经 ApplicationFormRequest 白名单与校验）。
 * - 删除与批量删除。
 * - 模块字段 JSON（ApiResponse 标准包络短路）。
 */
class ApplicationService extends BaseService
{
    /** @var AiGateway */
    private $gateway;

    /** @var SchemaBuilder */
    private $schemaBuilder;

    /** @var ApplicationPolicy */
    private $applicationPolicy;

    /**
     * @param AiGateway $gateway
     * @param SchemaBuilder $schemaBuilder
     * @param ApplicationPolicy $applicationPolicy
     */
    public function __construct(AiGateway $gateway, SchemaBuilder $schemaBuilder, ApplicationPolicy $applicationPolicy)
    {
        $this->gateway = $gateway;
        $this->schemaBuilder = $schemaBuilder;
        $this->applicationPolicy = $applicationPolicy;
    }

    /**
     * 列表页数据：按应用形态、任务形态、状态筛选，分页并组装展示字段（含关联模型名称）。
     *
     * @param string $placement 应用形态，空为不限
     * @param string $taskType 任务形态，空为不限
     * @param mixed $statusRaw 状态筛选原始值，空为不限（非标量按空处理，避免误转 int）
     * @param int $page 页码
     * @return array ai_list、pager
     */
    public function buildAiListData($placement, $taskType, $statusRaw, $page)
    {
        $placement = is_scalar($placement) ? trim((string) $placement) : '';
        $taskType = is_scalar($taskType) ? trim((string) $taskType) : '';
        $statusStr = is_scalar($statusRaw) ? trim((string) $statusRaw) : '';

        $pageUrl = route('admin.ai');
        if ($placement !== '') {
            $pageUrl .= '&placement=' . rawurlencode($placement);
        }
        if ($taskType !== '') {
            $pageUrl .= '&task_type=' . rawurlencode($taskType);
        }
        if ($statusStr !== '') {
            $pageUrl .= '&status=' . rawurlencode($statusStr);
        }

        $result = AiApplication::filterByPlacement($placement)
            ->filterByTaskType($taskType)
            ->filterByStatus($statusStr)
            ->applyDefaultOrder()
            ->paginate(15, $page, Util::normalizeQueryString($pageUrl));

        // 一次 IN 查询取全部关联模型名，避免每行单查
        $modelIds = array();
        foreach ($result['list'] as $model) {
            $mid = (int) $model->getRawAttribute('model_id');
            if ($mid > 0) {
                $modelIds[$mid] = $mid;
            }
        }
        $modelNames = $this->gateway->getModelNames(array_values($modelIds));

        $ai_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $created_at = $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : '';
            $mid = (int) $row['model_id'];

            $ai_list[] = array(
                'id' => $row['id'],
                'name' => $row['name'],
                'placement' => language()->dataLangFormat('ai_placement_', $row['placement']),
                'task_type' => language()->dataLangFormat('ai_task_type_', $row['task_type']),
                'module' => $row['module'] ? lang($row['module'], $row['module']) : '-',
                'model_name' => ($mid > 0 && isset($modelNames[$mid])) ? $modelNames[$mid] : lang('ai_model_default'),
                'sort' => $row['sort'],
                'status' => language()->dataLangFormat('ai_status_', $row['status']),
                'created_at' => $created_at,
            );
        }

        return array(
            'ai_list' => $ai_list,
            'pager' => $result['pager'],
        );
    }

    /**
     * 新增页数据：空应用草稿（config 为 JSON 对象字符串）、模型分组与可挂载模块列表。
     *
     * @return array ai、model_group_list、module_list、show_advanced
     */
    public function buildAiDefaultData()
    {
        return array(
            'ai' => array(
                'id' => '',
                'name' => '',
                'default_prompt' => '',
                'config' => '{}',
                'field' => '',
                'placement' => '',
                'task_type' => 'assist',
                'model_id' => 0,
                'module' => '',
                'sort' => 50,
                'status' => 1,
            ),
            'model_group_list' => $this->gateway->getModelGroupList(),
            'module_list' => $this->schemaBuilder->moduleList(),
            'show_advanced' => 0,
        );
    }

    /**
     * 编辑页数据：读取单条应用；非法 id 或不存在时返回 null。
     *
     * @param mixed $id 应用主键
     * @return array|null ai、model_group_list、module_list、show_advanced
     */
    public function buildAiEditData($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $aiModel = AiApplication::find($id);
        if (!$aiModel) {
            return null;
        }
        $ai = $aiModel->getAttributes();

        return array(
            'ai' => $ai,
            'model_group_list' => $this->gateway->getModelGroupList((int) $ai['model_id']),
            'module_list' => $this->schemaBuilder->moduleList(),
            'show_advanced' => $this->shouldShowAdvanced($ai),
        );
    }

    /**
     * 编辑页高级区是否默认展开。空 config 经 mutator 会写成 {}，不能仅凭「有值」判断。
     *
     * @param array $ai
     * @return int 1 展开，0 收起
     */
    private function shouldShowAdvanced(array $ai)
    {
        $config = isset($ai['config']) ? trim((string) $ai['config']) : '';
        if ($config === '' || $config === '{}') {
            return 0;
        }

        return 1;
    }

    /**
     * 新增提交（已通过 ApplicationFormRequest 校验与白名单）。
     *
     * @param array $data
     * @return int 新增记录主键
     */
    public function insert(array $data)
    {
        $data = $this->applyPlacementPolicy($data);

        $model = AiApplication::create($data);
        $id = (int) $model->getKey();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $data['name'], 'ai');

        return $id;
    }

    /**
     * 更新提交。
     *
     * @param array $data
     * @return void
     */
    public function update(array $data)
    {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai'));
        }
        $model = AiApplication::find($id);
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.ai'));
        }

        $data = $this->applyPlacementPolicy($data);

        $model->fill($data, 'update')->save();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $data['name'], 'ai');
    }

    /**
     * 单条删除（二次确认）。
     *
     * @param mixed $id 应用主键
     * @param array $data 含 confirm 时表示用户已确认
     * @return array{message: string, back_url: string, timeout?: string, confirm_url?: string}
     * @throws DomainException
     */
    public function delete($id, array $data)
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai'));
        }
        $aiModel = AiApplication::find($id);
        $ai = $aiModel ? $aiModel->getAttributes() : null;
        if (!$ai) {
            throw new DomainException(lang('illegal'), route('admin.ai'));
        }

        if (isset($data['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $ai['name'], 'ai');
            AiApplication::destroy($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.ai'),
            );
        }

        $msg = preg_replace('/d%/Ums', $ai['name'], lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.ai'),
            'timeout' => '30',
            'confirm_url' => route('admin.ai.destroy', array('id' => $id)),
        );
    }

    /**
     * assist / translate 不挂载模块、不选生成字段；fill / batch 二者必填。
     *
     * @param array $data
     * @return array
     */
    private function applyPlacementPolicy(array $data)
    {
        $placement = isset($data['placement']) ? trim((string) $data['placement']) : '';
        $taskType = isset($data['task_type']) ? trim((string) $data['task_type']) : '';
        if (!$this->applicationPolicy->taskMatchesPlacement($placement, $taskType)) {
            throw new DomainException(lang('ai_task_placement_mismatch'));
        }

        $modelId = isset($data['model_id']) ? (int) $data['model_id'] : 0;
        $model = $modelId > 0 ? $this->gateway->getModel($modelId) : $this->gateway->getDefaultModel();
        $providerCode = $model
            ? DB::table('ai_provider')->where('id', (int) $model['provider_id'])->value('code')
            : '';
        if (!$model || !$this->applicationPolicy->modelSupportsTask(
            $taskType,
            isset($model['model_code']) ? $model['model_code'] : '',
            $providerCode
        )) {
            throw new DomainException(lang('ai_model_task_mismatch'));
        }

        if ($placement === 'assist' || $placement === 'translate') {
            $data['module'] = null;
            $data['field'] = null;

            return $data;
        }

        foreach (array('module', 'field') as $optionalKey) {
            if (!array_key_exists($optionalKey, $data)) {
                $data[$optionalKey] = null;
            }
        }

        if ($placement === 'fill' || $placement === 'batch') {
            $module = isset($data['module']) ? trim((string) $data['module']) : '';
            if ($module === '') {
                throw new DomainException(lang('ai_module_required'));
            }
            $fields = isset($data['field']) ? $data['field'] : array();
            if (!is_array($fields) || !$fields) {
                throw new DomainException(lang('ai_field_required'));
            }
        }

        return $data;
    }

    /**
     * 按模块名取字段集，以 ApiResponse 五段包络短路响应。
     *
     * 响应 data 形如 ['fields' => [...]]；输出 schema 由生成时按 field 现算，不再下发草稿。
     *
     * @param string $module 模块逻辑名（含 _category 变体）
     * @param array $selectedFields 已选字段名集合
     * @return void
     */
    public function sendModuleFieldsJson($module, array $selectedFields = array())
    {
        $module = trim((string) $module);

        ApiResponse::throwSuccess(array(
            'fields' => $this->schemaBuilder->fieldsFor($module),
        ));
    }

    /**
     * 列表批量操作：勾选应用 id，`action === del_all` 时逐个删除。
     *
     * @param array $post checkbox、action 等表单字段
     * @return void
     * @throws DomainException
     */
    public function action(array $post)
    {
        if (empty($post['checkbox']) || !is_array($post['checkbox'])) {
            throw new DomainException(lang('ai_select_empty'), route('admin.ai'));
        }

        if (!isset($post['action']) || $post['action'] !== 'del_all') {
            throw new DomainException(lang('select_empty'), route('admin.ai'));
        }

        foreach ($post['checkbox'] as $rawId) {
            AiApplication::destroy(intval($rawId));
        }
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:AI', 'ai');
    }
}
