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

namespace Dou\Admin\Service\Aigc;

use Dou\Admin\Model\Aigc\Aigc;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员 AI 应用业务（数据表 `aigc`，路由 `index.php?route=aigc/...`）。
 *
 * 职责：
 * - 列表筛选分页、新增/编辑表单数据（可选模型列表）。
 * - 写入与更新应用记录（经 ApplicationFormRequest 白名单与校验）。
 * - 删除与批量删除；存在会话或生成任务的应用禁止删除。
 */
class ApplicationService extends BaseService
{
    /** @var AiGateway */
    private $gateway;

    /**
     * @param AiGateway $gateway
     */
    public function __construct(AiGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * 列表页数据：按能力类型、状态筛选，分页并组装展示字段（含关联模型名称）。
     *
     * @param string $type 能力类型，空为不限
     * @param mixed $statusRaw 状态筛选原始值，空为不限
     * @param int $page 页码
     * @return array aigc_list、pager
     */
    public function buildAigcListData($type, $statusRaw, $page)
    {
        $type = is_scalar($type) ? trim((string) $type) : '';
        $statusStr = is_scalar($statusRaw) ? trim((string) $statusRaw) : '';

        $pageUrl = route('admin.aigc');
        if ($type !== '') {
            $pageUrl .= '&type=' . rawurlencode($type);
        }
        if ($statusStr !== '') {
            $pageUrl .= '&status=' . rawurlencode($statusStr);
        }

        $result = Aigc::filterByType($type)
            ->filterByStatus($statusStr)
            ->applyDefaultOrder()
            ->paginate(15, $page, Util::normalizeQueryString($pageUrl));

        // 一次 IN 查询取全部关联模型名，避免每行 N 次单查
        $allModelIds = array();
        foreach ($result['list'] as $model) {
            $ids = json_decode((string) $model->getRawAttribute('model_ids'), true);
            if (is_array($ids)) {
                foreach ($ids as $mid) {
                    $mid = (int) $mid;
                    if ($mid > 0) {
                        $allModelIds[$mid] = $mid;
                    }
                }
            }
        }
        $modelNames = $this->gateway->getModelNames(array_values($allModelIds));

        $aigc_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $created_at = $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : '';

            $names = array();
            $ids = json_decode((string) $row['model_ids'], true);
            if (is_array($ids)) {
                foreach ($ids as $mid) {
                    $mid = (int) $mid;
                    if (isset($modelNames[$mid])) {
                        $names[] = $modelNames[$mid];
                    }
                }
            }

            $aigc_list[] = array(
                'id' => $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'type' => language()->dataLangFormat('aigc_type_', $row['type']),
                'model_name' => $names ? implode(', ', $names) : '-',
                'is_free' => language()->dataLangFormat('aigc_is_free_', $row['is_free']),
                'is_public' => language()->dataLangFormat('aigc_is_public_', $row['is_public']),
                'sort' => $row['sort'],
                'status' => language()->dataLangFormat('aigc_status_', $row['status']),
                'created_at' => $created_at,
            );
        }

        return array(
            'aigc_list' => $aigc_list,
            'pager' => $result['pager'],
        );
    }

    /**
     * 新增页数据：空应用草稿、可选模型分组列表。
     *
     * @return array aigc、model_group_list、suggested_questions
     */
    public function buildAigcDefaultData()
    {
        return array(
            'aigc' => array(
                'id' => '',
                'name' => '',
                'slug' => '',
                'type' => 'chat',
                'description' => '',
                'icon' => '',
                'cover_image' => '',
                'model_ids' => '',
                'default_prompt' => '',
                'config' => '{}',
                'is_free' => 0,
                'free_limit_type' => 'requests',
                'free_limit_value' => '0',
                'is_public' => 1,
                'sort' => '0',
                'status' => 1,
            ),
            'model_group_list' => $this->gateway->getModelTransferList(),
            'suggested_questions' => '',
        );
    }

    /**
     * 编辑页数据：读取单条应用；非法 id 或不存在时返回 null。
     *
     * @param mixed $id 应用主键
     * @return array|null aigc、model_group_list、suggested_questions
     */
    public function buildAigcEditData($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $aigcModel = Aigc::find($id);
        if (!$aigcModel) {
            return null;
        }
        $aigc = $aigcModel->getAttributes();

        $config = json_decode(isset($aigc['config']) ? (string) $aigc['config'] : '', true);
        $questions = (is_array($config) && isset($config['suggested_questions']) && is_array($config['suggested_questions']))
            ? $config['suggested_questions']
            : array();

        return array(
            'aigc' => $aigc,
            'model_group_list' => $this->gateway->getModelTransferList($aigc['model_ids']),
            'suggested_questions' => implode("\n", $questions),
        );
    }

    /**
     * 新增提交（已通过 ApplicationFormRequest 校验与白名单）。
     *
     * @param array $data
     * @return int 新增记录主键
     */
    public function insert(array $data)
    {
        $this->mergeSuggestedQuestions($data);

        if (!array_key_exists('model_ids', $data)) {
            $data['model_ids'] = null;
        }

        $model = Aigc::create($data);
        $id = (int) $model->getKey();

        // 按应用类型写入默认表单字段，管理员可再编辑；chat 类型不生成字段
        (new FieldService())->seedDefaults($id, isset($data['type']) ? (string) $data['type'] : 'chat');

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $data['name'], 'aigc');

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
            throw new DomainException(lang('illegal'), route('admin.aigc'));
        }
        $model = Aigc::find($id);
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.aigc'));
        }

        $this->mergeSuggestedQuestions($data);

        if (!array_key_exists('model_ids', $data)) {
            $data['model_ids'] = null;
        }

        $model->fill($data, 'update')->save();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $data['name'], 'aigc');
    }

    /**
     * 单条删除（二次确认）；存在会话或生成任务时禁止删除。
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
            throw new DomainException(lang('illegal'), route('admin.aigc'));
        }
        $aigcModel = Aigc::find($id);
        $aigc = $aigcModel ? $aigcModel->getAttributes() : null;
        if (!$aigc) {
            throw new DomainException(lang('illegal'), route('admin.aigc'));
        }

        $this->assertDeletable($id, (string) $aigc['name']);

        if (isset($data['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $aigc['name'], 'aigc');
            Aigc::destroy($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.aigc'),
            );
        }

        $msg = preg_replace('/d%/Ums', $aigc['name'], lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.aigc'),
            'timeout' => '30',
            'confirm_url' => route('admin.aigc.destroy', array('id' => $id)),
        );
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
            throw new DomainException(lang('aigc_select_empty'), route('admin.aigc'));
        }

        if (!isset($post['action']) || $post['action'] !== 'del_all') {
            throw new DomainException(lang('select_empty'), route('admin.aigc'));
        }

        $ids = array();
        foreach ($post['checkbox'] as $rawId) {
            $rid = intval($rawId);
            if ($rid > 0) {
                $ids[$rid] = $rid;
            }
        }
        if (!$ids) {
            throw new DomainException(lang('aigc_select_empty'), route('admin.aigc'));
        }

        // 先整体预检，任一应用存在会话或任务则全部中止，避免部分删除
        foreach ($ids as $rid) {
            $name = (string) DB::table('aigc')->where('id', $rid)->value('name');
            $this->assertDeletable($rid, $name);
        }

        foreach ($ids as $rid) {
            Aigc::destroy($rid);
        }
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:Aigc', 'aigc');
    }

    /**
     * 删除守卫：应用仍有会话或异步任务时禁止删除，避免数据成为孤儿。
     *
     * @param int $id
     * @param string $name
     * @return void
     * @throws DomainException
     */
    private function assertDeletable($id, $name)
    {
        $hasData = DB::table('aigc_session')->where('app_id', (int) $id)->exists()
            || DB::table('aigc_task')->where('app_id', (int) $id)->exists();
        if ($hasData) {
            throw new DomainException(str_replace('d%', (string) $name, lang('aigc_delete_has_data')), route('admin.aigc'));
        }
    }

    /**
     * 合并表单提交的推荐问题到 config 的 suggested_questions 键。
     *
     * 每行一条、去重、最多 6 条；空行忽略。其余 config 键原样保留。
     *
     * @param array $data 表单数据（引用修改：合并 config / 剔除临时键）
     * @return void
     */
    private function mergeSuggestedQuestions(array &$data)
    {
        $config = array();
        if (isset($data['config']) && is_string($data['config'])) {
            $decoded = json_decode(trim($data['config']), true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        if (array_key_exists('suggested_questions', $data)) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $data['suggested_questions']);
            $questions = array();
            foreach ((array) $lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (function_exists('mb_substr')) {
                    $line = mb_substr($line, 0, 80, 'UTF-8');
                } else {
                    $line = substr($line, 0, 80);
                }
                $questions[$line] = $line;
                if (count($questions) >= 6) {
                    break;
                }
            }
            if ($questions) {
                $config['suggested_questions'] = array_values($questions);
            } else {
                unset($config['suggested_questions']);
            }
        }

        $data['config'] = empty($config) ? '{}' : json_encode($config, JSON_UNESCAPED_UNICODE);
        unset($data['suggested_questions']);
    }
}
