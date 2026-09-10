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

namespace Dou\Admin\Controller\Ai;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Ai\ModelFormRequest;
use Dou\Admin\Service\Ai\ModelService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Arr;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 模型（route=ai_model/...）
 *
 * 以供应商为主表分组管理模型：列表展示供应商，编辑页内嵌该供应商的
 * 密钥与模型行式表格，随表单统一提交，由 ModelService 在单事务内处理
 * 供应商、密钥、模型的增改删。
 */
class ModelController extends BaseController
{
    /** @var ModelService */
    private $modelService;

    /**
     * @param ModelService $modelService
     */
    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'ai',
            'submenu' => 'ai_model',
        );
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $bundle = $this->modelService->getAdminIndexPageData($request->all());

        return $this->view('ai_model.htm', [
            'ur_here' => lang('ai_model'),
            'page_actions' => array(
                array('href' => route('admin.ai.model.create'), 'text' => lang('ai_model_create'), 'style' => 'add'),
            ),
            'rec' => 'default',
            'keyword' => $bundle['keyword'],
            'provider_list' => $bundle['provider_list'],
            'key_total' => $bundle['key_total'],
            'pager' => $bundle['pager'],
        ]);
    }

    /**
     * @return Response
     */
    public function create()
    {
        $form = $this->modelService->getAddFormData();

        return $this->view('ai_model.htm', [
            'ur_here' => lang('ai_model_create'),
            'page_actions' => array(
                array('href' => route('admin.ai.model'), 'text' => lang('ai_model'), 'style' => ''),
            ),
            'rec' => 'create',
            'provider' => $form['provider'],
            'key_list' => $form['key_list'],
            'model_list' => $form['model_list'],
        ]);
    }

    /**
     * @param ModelFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(ModelFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $keys = $this->extractKeys($request);
        $models = $this->extractModels($request);

        // 新建时至少需要一把有效密钥（api_key 非空）
        $hasValidKey = false;
        foreach ($keys as $k) {
            if (!empty($k['api_key'])) {
                $hasValidKey = true;
                break;
            }
        }
        if (!$hasValidKey) {
            throw new DomainException(lang('ai_key_api_key_required'), route('admin.ai.model.create'));
        }

        $newId = $this->modelService->insertWithKeys($data, $keys, $models);

        return redirect(route('admin.ai.model.edit', array('id' => $newId)))
            ->with('success', lang('ai_model_add_succes'), route('admin.ai.model'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }

        $form = $this->modelService->getEditFormData($id);
        if (!$form) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }

        return $this->view('ai_model.htm', [
            'ur_here' => lang('ai_model_edit'),
            'page_actions' => array(
                array('href' => route('admin.ai.model'), 'text' => lang('ai_model'), 'style' => ''),
            ),
            'rec' => 'edit',
            'provider' => $form['provider'],
            'key_list' => $form['key_list'],
            'model_list' => $form['model_list'],
        ]);
    }

    /**
     * @param ModelFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(ModelFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $keys = $this->extractKeys($request);
        $deleteIds = $request->post('keys_delete', array());
        if (!is_array($deleteIds)) {
            $deleteIds = array();
        }
        $models = $this->extractModels($request);
        $deleteModelIds = $request->post('models_delete', array());
        if (!is_array($deleteModelIds)) {
            $deleteModelIds = array();
        }

        $this->modelService->updateWithKeys($data, $keys, $deleteIds, $models, $deleteModelIds);
        $editId = (int) Arr::get($data, 'id', 0);

        return redirect(route('admin.ai.model.edit', array('id' => $editId)))
            ->with('success', lang('ai_model_edit_succes'), route('admin.ai.model'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }

        $result = $this->modelService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function action(Request $request)
    {
        $result = $this->modelService->action($request->post());

        return redirect($result['back_url'])->with('success', $result['message']);
    }

    /**
     * 行内快捷切换供应商启用/停用（js-toggle 无刷新；非 AJAX 回退整页回列表并闪现结果）。
     *
     * @param Request $request
     * @return Response
     */
    public function toggleStatus(Request $request)
    {
        $id = $request->integer('id', 0);
        $status = $this->modelService->toggleStatus($id);

        return $this->respondToggle(
            $request,
            $status,
            $status ? lang('ai_provider_enable_succes') : lang('ai_provider_disable_succes'),
            route('admin.ai.model')
        );
    }

    /**
     * 从请求中提取并规范化密钥数组。
     *
     * 前端字段命名：keys[N][id|api_key|alias|expires_at|config]
     *
     * @param Request $request
     * @return array
     */
    private function extractKeys(Request $request)
    {
        $raw = $request->post('keys', array());
        $keys = array();
        if (!is_array($raw)) {
            return $keys;
        }
        foreach ($raw as $item) {
            if (!is_array($item)) {
                throw new DomainException(lang('ai_nested_input_invalid'));
            }
            $apiKey = isset($item['api_key']) ? trim((string) $item['api_key']) : '';
            $alias = isset($item['alias']) ? trim((string) $item['alias']) : '';
            $config = isset($item['config']) ? trim((string) $item['config']) : '';
            $clientSecret = isset($item['client_secret']) ? trim((string) $item['client_secret']) : '';
            if (strlen($apiKey) > 120 || strlen($alias) > 100 || strlen($config) > 10000
                || strlen($clientSecret) > 500 || !$this->isJsonObject($config)
            ) {
                throw new DomainException(lang('ai_nested_input_invalid'));
            }
            $keys[] = array(
                'id' => isset($item['id']) ? (int) $item['id'] : 0,
                'api_key' => $apiKey,
                'alias' => $alias,
                'expires_at' => isset($item['expires_at']) ? trim((string) $item['expires_at']) : '',
                'config' => $config,
                'client_secret' => $clientSecret,
            );
        }
        return $keys;
    }

    /**
     * 从请求中提取并规范化模型数组。
     *
     * 前端字段命名：models[N][id|name|model_code|context_length|max_tokens]
     *
     * @param Request $request
     * @return array
     */
    private function extractModels(Request $request)
    {
        $raw = $request->post('models', array());
        $models = array();
        if (!is_array($raw)) {
            return $models;
        }
        foreach ($raw as $item) {
            if (!is_array($item)) {
                throw new DomainException(lang('ai_nested_input_invalid'));
            }
            $name = isset($item['name']) ? trim((string) $item['name']) : '';
            $modelCode = isset($item['model_code']) ? trim((string) $item['model_code']) : '';
            if (strlen($name) > 200 || strlen($modelCode) > 200) {
                throw new DomainException(lang('ai_nested_input_invalid'));
            }
            $models[] = array(
                'id' => isset($item['id']) ? (int) $item['id'] : 0,
                'name' => $name,
                'model_code' => $modelCode,
                'context_length' => isset($item['context_length']) ? trim((string) $item['context_length']) : '',
                'max_tokens' => isset($item['max_tokens']) ? trim((string) $item['max_tokens']) : '',
            );
        }
        return $models;
    }

    /**
     * @param string $json
     * @return bool
     */
    private function isJsonObject($json)
    {
        if ($json === '') {
            return true;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) && json_last_error() === JSON_ERROR_NONE;
    }
}
