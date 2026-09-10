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

use Dou\Admin\Model\Ai\AiKey;
use Dou\Admin\Model\Ai\AiModelCatalog;
use Dou\Admin\Model\Ai\AiProvider;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 模型（route=ai/model/...）
 *
 * 以供应商为主表分组管理模型：列表展示供应商；密钥与模型均内嵌于编辑
 * 表单，视为供应商配置的一部分。新建时一并创建（insertWithKeys），编辑时随
 * 表单统一保存增/改/删（updateWithKeys）；删除供应商（单删/批删）时同步删除
 * 其下密钥与模型。均以单事务保证原子性。
 */
class ModelService extends BaseService
{
    /** @var KeyService */
    private $keyService;

    /**
     * @param KeyService $keyService
     */
    public function __construct(KeyService $keyService)
    {
        $this->keyService = $keyService;
    }

    /**
     * @param array $req
     * @return array provider_list, pager, keyword
     */
    public function getAdminIndexPageData(array $req)
    {
        $keyword = isset($req['keyword']) ? trim((string) $req['keyword']) : '';
        $page = isset($req['page']) ? $req['page'] : 1;

        $pageUrl = route('admin.ai.model');
        if ($keyword !== '') {
            $pageUrl .= '&keyword=' . rawurlencode($keyword);
        }

        $result = AiProvider::filterByKeyword($keyword)
            ->applyDefaultOrder()
            ->paginate(15, $page, Util::normalizeQueryString($pageUrl));

        // 一次分组统计各供应商已配置的密钥数，避免行行 COUNT
        $keyCounts = array();
        foreach (DB::table('ai_key')
            ->field('provider_id, COUNT(*) AS key_num')
            ->groupBy('provider_id')
            ->select() as $stat) {
            $keyCounts[(int) $stat['provider_id']] = (int) $stat['key_num'];
        }

        // 全库密钥总数：为 0 时列表顶部给出首装配置提示
        $keyTotal = (int) DB::table('ai_key')->count();

        $provider_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $created_at = $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : '';
            $providerId = (int) $row['id'];

            $provider_list[] = array(
                'id' => $providerId,
                'name' => $row['name'],
                'code' => $row['code'],
                'base_url' => $row['base_url'],
                'status' => isset($row['status']) ? (int) $row['status'] : 1,
                'key_count' => isset($keyCounts[$providerId]) ? $keyCounts[$providerId] : 0,
                'sort' => $row['sort'],
                'created_at' => $created_at,
            );
        }

        return array(
            'provider_list' => $provider_list,
            'key_total' => $keyTotal,
            'pager' => $result['pager'],
            'keyword' => $keyword,
        );
    }

    /**
     * @return array provider, key_list, model_list, form_action
     */
    public function getAddFormData()
    {
        return array(
            'provider' => array(),
            'key_list' => array(),
            'model_list' => array(),
            'form_action' => 'store',
        );
    }

    /**
     * @param mixed $id
     * @return array|null provider, key_list, model_list, form_action
     */
    public function getEditFormData($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $providerRow = AiProvider::find($id);
        if (!$providerRow) {
            return null;
        }
        $provider = $providerRow->getAttributes();

        return array(
            'provider' => $provider,
            'key_list' => $this->keyService->getKeysByProvider($id),
            'model_list' => $this->getModelsByProvider($id),
            'form_action' => 'update',
        );
    }

    /**
     * 按供应商取其全部模型（sort ASC, id ASC，供编辑页内嵌表格使用）。
     *
     * @param mixed $providerId
     * @return array
     */
    public function getModelsByProvider($providerId)
    {
        $pid = (int) $providerId;
        if ($pid <= 0) {
            return array();
        }

        $list = array();
        foreach (AiModelCatalog::filterByProviderId($pid)->applyDefaultOrder()->get() as $model) {
            $list[] = $model->getAttributes();
        }

        return $list;
    }

    /**
     * 新建供应商并创建其初始密钥与模型集合（单事务）。
     *
     * @param array $providerData 供应商字段
     * @param array $keys 密钥数组，每项含 api_key/alias/expires_at/config；
     *                    api_key 为空的条目跳过
     * @param array $models 模型数组，每项含 name/model_code/context_length/max_tokens；
     *                      name 与 model_code 均为空的条目跳过
     * @return int 新建供应商主键
     */
    public function insertWithKeys(array $providerData, array $keys, array $models = array())
    {
        DB::beginTransaction();
        try {
            $providerId = $this->insert($providerData);

            foreach ($keys as $keyData) {
                if (!empty($keyData['api_key'])) {
                    $keyData['provider_id'] = $providerId;
                    $this->keyService->insert($keyData);
                }
            }

            foreach ($models as $modelData) {
                if ($modelData['model_code'] !== '' && $modelData['name'] !== '') {
                    $modelData['provider_id'] = $providerId;
                    $this->insertModel($modelData);
                }
            }

            DB::commit();
            return $providerId;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * 更新供应商并同步其密钥/模型集合的增/改/删（单事务）。
     *
     * @param array $providerData 供应商字段（含 id）
     * @param array $keys 密钥数组：有 id 的更新，无 id 且 api_key 非空的新增
     * @param array $deleteKeyIds 待删除密钥 id 列表
     * @param array $models 模型数组：有 id 的更新，无 id 且 name/model_code 非空的新增
     * @param array $deleteModelIds 待删除模型 id 列表
     * @return void
     */
    public function updateWithKeys(array $providerData, array $keys, array $deleteKeyIds, array $models = array(), array $deleteModelIds = array())
    {
        DB::beginTransaction();
        try {
            $this->update($providerData);
            $providerId = isset($providerData['id']) ? (int) $providerData['id'] : 0;
            $backUrl = route('admin.ai.model.edit', array('id' => $providerId));

            // 先删除标记删除的密钥（含外键约束检查）
            foreach ($deleteKeyIds as $kid) {
                $kid = (int) $kid;
                if ($kid <= 0) {
                    continue;
                }
                $this->assertKeyBelongsToProvider($kid, $providerId, $backUrl);
                if (AiKey::countUsageLogsForKey($kid) > 0) {
                    throw new DomainException(lang('ai_key_has_usage'), $backUrl);
                }
                if (AiKey::countChatSessionsForKey($kid) > 0) {
                    throw new DomainException(lang('ai_key_has_chats'), $backUrl);
                }
                $name = AiKey::getAliasOrFallback($kid);
                audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $name, 'ai_key');
                AiKey::destroy($kid);
            }

            // 再处理密钥新增/更新
            foreach ($keys as $keyData) {
                $keyData['provider_id'] = $providerId;
                if (!empty($keyData['id'])) {
                    $this->assertKeyBelongsToProvider((int) $keyData['id'], $providerId, $backUrl);
                    $this->keyService->update($keyData);
                } elseif (!empty($keyData['api_key'])) {
                    $this->keyService->insert($keyData);
                }
            }

            // 删除标记删除的模型（含依赖检查）
            foreach ($deleteModelIds as $mid) {
                $this->deleteModel($mid, $backUrl, $providerId);
            }

            // 再处理模型新增/更新
            foreach ($models as $modelData) {
                if ($modelData['name'] === '' && $modelData['model_code'] === '') {
                    continue;
                }
                $modelData['provider_id'] = $providerId;
                if (!empty($modelData['id'])) {
                    $this->updateModel($modelData, $backUrl, $providerId);
                } elseif ($modelData['name'] !== '' && $modelData['model_code'] !== '') {
                    $this->insertModel($modelData, $backUrl);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * @param array $data
     * @return int 新增记录主键
     */
    public function insert(array $data)
    {
        $row = array(
            'name' => trim($data['name']),
            'code' => trim($data['code']),
            'base_url' => isset($data['base_url']) ? trim($data['base_url']) : '',
            'status' => !empty($data['status']) ? 1 : 0,
            'sort' => isset($data['sort']) && $data['sort'] !== '' ? intval($data['sort']) : 50,
            'config' => isset($data['config']) ? trim($data['config']) : '',
        );

        $model = AiProvider::create($row);
        $id = (int) $model->getKey();

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $row['name'], 'ai_provider');

        return $id;
    }

    /**
     * @param array $data
     * @return void
     */
    public function update(array $data)
    {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }
        $model = AiProvider::find($id);
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }

        $attrs = $model->getAttributes();
        $row = array(
            'name' => trim($data['name']),
            'code' => trim($data['code']),
            'base_url' => isset($data['base_url']) ? trim($data['base_url']) : '',
            'status' => isset($data['status']) ? (!empty($data['status']) ? 1 : 0)
                : (isset($attrs['status']) ? (int) $attrs['status'] : 1),
            'sort' => isset($data['sort']) && $data['sort'] !== '' ? intval($data['sort']) : 50,
            'config' => isset($data['config']) ? trim($data['config']) : '',
        );

        $model->fill($row, 'update')->save();

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $row['name'], 'ai_provider');
    }

    /**
     * 新增模型（内嵌于编辑表单）。
     *
     * @param array $data 含 provider_id/name/model_code 及可选高级字段
     * @param string|null $backUrl 校验失败跳转地址
     * @return int 新增记录主键
     */
    private function insertModel(array $data, $backUrl = null)
    {
        $backUrl = $backUrl !== null ? $backUrl : route('admin.ai.model');
        $this->assertModelCodeAvailable($data['model_code'], 0, $backUrl);

        $row = $this->buildModelRow($data);

        $model = AiModelCatalog::create($row);
        $id = (int) $model->getKey();

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $row['name'], 'ai_model');

        return $id;
    }

    /**
     * 更新模型（内嵌于编辑表单）。
     *
     * @param array $data 含 id/provider_id/name/model_code 及可选高级字段
     * @param string $backUrl 校验失败跳转地址
     * @param int $providerId 当前编辑的供应商 ID
     * @return void
     */
    private function updateModel(array $data, $backUrl, $providerId)
    {
        $id = (int) $data['id'];
        $model = AiModelCatalog::find($id);
        if (!$model || (int) $model['provider_id'] !== (int) $providerId) {
            throw new DomainException(lang('illegal'), $backUrl);
        }
        $this->assertModelCodeAvailable($data['model_code'], $id, $backUrl);

        $row = $this->buildModelRow($data);

        $model->fill($row, 'update')->save();

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $row['name'], 'ai_model');
    }

    /**
     * 删除模型（含用量/会话/统计/应用引用的依赖检查）。
     *
     * @param mixed $modelId
     * @param string $backUrl 校验失败跳转地址
     * @param int $providerId 当前编辑的供应商 ID
     * @return void
     */
    private function deleteModel($modelId, $backUrl, $providerId)
    {
        $mid = (int) $modelId;
        if ($mid <= 0) {
            return;
        }
        $model = AiModelCatalog::find($mid);
        if (!$model || (int) $model['provider_id'] !== (int) $providerId) {
            throw new DomainException(lang('illegal'), $backUrl);
        }
        if (AiModelCatalog::countUsageLogsForModel($mid) > 0) {
            throw new DomainException(lang('ai_model_has_usage'), $backUrl);
        }
        if (AiModelCatalog::countChatSessionsForModel($mid) > 0) {
            throw new DomainException(lang('ai_model_has_chats'), $backUrl);
        }
        if (AiModelCatalog::countChatMessagesForModel($mid) > 0) {
            throw new DomainException(lang('ai_model_has_records'), $backUrl);
        }
        if (AiModelCatalog::countApplicationsForModel($mid) > 0) {
            throw new DomainException(lang('ai_model_has_apps'), $backUrl);
        }

        $name = AiModelCatalog::whereKey($mid)->value('name');
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $name, 'ai_model');
        AiModelCatalog::destroy($mid);
    }

    /**
     * @param int $keyId
     * @param int $providerId
     * @param string $backUrl
     * @return void
     */
    private function assertKeyBelongsToProvider($keyId, $providerId, $backUrl)
    {
        $key = AiKey::find((int) $keyId);
        if (!$key || (int) $key['provider_id'] !== (int) $providerId) {
            throw new DomainException(lang('illegal'), $backUrl);
        }
    }

    /**
     * 模型代码唯一性校验。
     *
     * @param string $code
     * @param int $excludeId
     * @param string $backUrl
     * @return void
     */
    private function assertModelCodeAvailable($code, $excludeId, $backUrl)
    {
        if (AiModelCatalog::existsOtherRowByModelCode($code, $excludeId)) {
            throw new DomainException(lang('ai_model_code_existed'), $backUrl);
        }
    }

    /**
     * 组装 ai_model 写入行。
     *
     * @param array $data
     * @return array
     */
    private function buildModelRow(array $data)
    {
        return array(
            'provider_id' => (int) $data['provider_id'],
            'name' => trim($data['name']),
            'model_code' => trim($data['model_code']),
            'context_length' => isset($data['context_length']) && $data['context_length'] !== '' ? intval($data['context_length']) : null,
            'max_tokens' => isset($data['max_tokens']) && $data['max_tokens'] !== '' ? intval($data['max_tokens']) : null,
        );
    }

    /**
     * @param mixed $id
     * @param array $post
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException
     */
    public function delete($id, array $post)
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }
        $name = AiProvider::whereKey($id)->value('name');
        if ($name === null || $name === '') {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }

        if (isset($post['confirm'])) {
            $this->assertProviderHasNoUsageDependents($id);

            DB::beginTransaction();
            try {
                $this->deleteProviderKeysAndModels(array($id));
                audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $name, 'ai_provider');
                AiProvider::destroy($id);
                DB::commit();
            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.ai.model'),
            );
        }

        $msg = preg_replace('/d%/Ums', $name, lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.ai.model'),
            'timeout' => '30',
            'confirm_url' => route('admin.ai.model.destroy', array('id' => $id)),
        );
    }

    /**
     * 删除前仅拦截用量类依赖（会话、日志、应用引用）。
     * 密钥与模型视为供应商配置，不在此拦截，由 {@see deleteProviderKeysAndModels()} 同步删除。
     *
     * @param int $providerId
     * @return void
     */
    private function assertProviderHasNoUsageDependents($providerId)
    {
        $pid = (int) $providerId;
        if (AiProvider::countChatSessionsForProvider($pid) > 0) {
            throw new DomainException(lang('ai_provider_has_chats'), route('admin.ai.model'));
        }
        if (AiProvider::countUsageLogsForProvider($pid) > 0) {
            throw new DomainException(lang('ai_provider_has_logs'), route('admin.ai.model'));
        }
        if (AiProvider::countApplicationsForProvider($pid) > 0) {
            throw new DomainException(lang('ai_provider_has_apps'), route('admin.ai.model'));
        }
    }

    /**
     * 按供应商主键批量删除其下密钥与模型。
     *
     * @param array $providerIds
     * @return void
     */
    private function deleteProviderKeysAndModels(array $providerIds)
    {
        $ids = array();
        foreach ($providerIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if (empty($ids)) {
            return;
        }

        AiKey::whereIn('provider_id', $ids)->delete();
        AiModelCatalog::whereIn('provider_id', $ids)->delete();
    }

    /**
     * @param array $post
     * @return array{message: string, back_url: string}
     * @throws DomainException 参数不合法或动作类型不支持时抛出
     */
    public function action(array $post)
    {
        if (empty($post['checkbox']) || !is_array($post['checkbox'])) {
            throw new DomainException(lang('ai_model_select_empty'), route('admin.ai.model'));
        }

        $action = Arr::get($post, 'action', '');

        if ($action === 'del_all') {
            $ids = Check::intIds($post['checkbox']);
            if (empty($ids)) {
                throw new DomainException(lang('ai_model_select_empty'), route('admin.ai.model'));
            }
            foreach ($ids as $pid) {
                $this->assertProviderHasNoUsageDependents($pid);
            }
            DB::beginTransaction();
            try {
                $this->deleteProviderKeysAndModels($ids);
                AiProvider::whereIn('id', $ids)->delete();
                audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:AiProvider (' . count($ids) . ' items)', 'ai_provider');
                DB::commit();
            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }
            return array('message' => lang('del_succes'), 'back_url' => route('admin.ai.model'));
        }

        if ($action === 'enable' || $action === 'disable') {
            $ids = Check::intIds($post['checkbox']);
            if (empty($ids)) {
                throw new DomainException(lang('ai_model_select_empty'), route('admin.ai.model'));
            }
            $status = $action === 'enable' ? 1 : 0;
            foreach (AiProvider::whereIn('id', $ids)->get() as $model) {
                $model->fill(array('status' => $status), 'update')->save();
            }
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'bulk:AiProvider (' . count($ids) . ' items)', 'ai_provider');
            return array(
                'message' => $status ? lang('ai_provider_enable_succes') : lang('ai_provider_disable_succes'),
                'back_url' => route('admin.ai.model'),
            );
        }

        throw new DomainException(lang('select_empty'), route('admin.ai.model'));
    }

    /**
     * 切换单个供应商启用/停用状态（列表行内快捷开关）。
     *
     * @param mixed $id
     * @return int 新状态（1 启用 / 0 停用）
     */
    public function toggleStatus($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }
        $model = AiProvider::find($id);
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }

        $attrs = $model->getAttributes();
        $status = !empty($attrs['status']) ? 0 : 1;
        $model->fill(array('status' => $status), 'update')->save();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $attrs['name'], 'ai_provider');

        return $status;
    }
}
