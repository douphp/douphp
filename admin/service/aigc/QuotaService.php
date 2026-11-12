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

use Dou\Admin\Model\Aigc\AigcQuota;
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
 * 后台会员配额（aigc_quota，route=aigc/quota/...）。
 */
class QuotaService extends BaseService
{
    /**
     * 列表页数据。
     *
     * @param array $req 筛选请求（username / quota_type / page）
     * @return array quota_list、pager
     */
    public function getAdminIndexPageData(array $req)
    {
        $username = isset($req['username']) ? trim((string) $req['username']) : '';
        $quotaType = isset($req['quota_type']) ? trim((string) $req['quota_type']) : '';
        $page = isset($req['page']) ? $req['page'] : 1;

        $pageUrl = route('admin.aigc.quota');
        $userId = 0;
        if ($username !== '') {
            $userId = user() !== null ? (int) user()->findUserId($username) : 0;
            $pageUrl .= '&username=' . rawurlencode($username);
        }
        if ($quotaType !== '') {
            $pageUrl .= '&quota_type=' . rawurlencode($quotaType);
        }

        $result = AigcQuota::filterByUserId($userId)
            ->filterByQuotaType($quotaType)
            ->applyDefaultOrder()
            ->paginate(15, $page, Util::normalizeQueryString($pageUrl));

        $quota_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $created_at = !empty($row['created_at']) ? date('Y-m-d H:i', strtotime($row['created_at'])) : '';
            $last_reset_at = !empty($row['last_reset_at']) ? date('Y-m-d H:i', strtotime($row['last_reset_at'])) : '-';
            $expires_at = !empty($row['expires_at']) ? date('Y-m-d H:i', strtotime($row['expires_at'])) : '-';

            $user_info = (user() !== null ? user()->format($row['user_id']) : false);

            $usage_percent = 0;
            $remaining = 0;
            if ($row['total_limit'] > 0) {
                $usage_percent = round(($row['used_amount'] / $row['total_limit']) * 100, 2);
                $remaining = $row['total_limit'] - $row['used_amount'];
            }

            $quota_list[] = array(
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'user' => $user_info,
                'quota_type' => language()->dataLangFormat('aigc_quota_type_', $row['quota_type']),
                'total_limit' => $row['total_limit'],
                'used_amount' => $row['used_amount'],
                'remaining' => $remaining,
                'usage_percent' => $usage_percent,
                'reset_cycle' => language()->dataLangFormat('aigc_quota_reset_cycle_', $row['reset_cycle']),
                'last_reset_at' => $last_reset_at,
                'expires_at' => $expires_at,
                'created_at' => $created_at,
            );
        }

        return array(
            'quota_list' => $quota_list,
            'pager' => $result['pager'],
        );
    }

    /**
     * 新增页数据。
     *
     * @return array quota、form_action
     */
    public function getAddFormData()
    {
        return array(
            'quota' => array(),
            'form_action' => 'store',
        );
    }

    /**
     * 编辑页数据。
     *
     * @param mixed $id 配额主键
     * @return array|null quota、form_action
     */
    public function getEditFormData($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $quotaRow = AigcQuota::find($id);
        if (!$quotaRow) {
            return null;
        }
        $quota = $quotaRow->getAttributes();

        $user_info = (user() !== null ? user()->format($quota['user_id']) : false);
        $quota['username'] = $user_info ? $user_info['nickname'] : '';
        if (!empty($quota['expires_at'])) {
            $quota['expires_at'] = date('Y-m-d H:i', strtotime($quota['expires_at']));
        }

        return array(
            'quota' => $quota,
            'form_action' => 'update',
        );
    }

    /**
     * 新增提交（已通过 QuotaFormRequest 校验与白名单）。
     *
     * @param array $data
     * @return int 新增记录主键
     */
    public function insert(array $data)
    {
        $user_id = $this->resolveUserId($data['username']);

        if (AigcQuota::countByUserAndQuotaType($user_id, $data['quota_type'], 0) > 0) {
            throw new DomainException(lang('aigc_quota_exists'), route('admin.aigc.quota'));
        }

        $row = array(
            'user_id' => $user_id,
            'quota_type' => $data['quota_type'],
            'total_limit' => isset($data['total_limit']) ? intval($data['total_limit']) : 0,
            'used_amount' => 0,
            'reset_cycle' => $data['reset_cycle'],
            'expires_at' => !empty($data['expires_at']) ? $data['expires_at'] : null,
        );

        $model = AigcQuota::create($row);
        $id = (int) $model->getKey();

        $user_info = (user() !== null ? user()->format($user_id) : false);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, $user_info ? (string) $user_info['nickname'] : ('UID:' . $user_id), 'aigc_quota');

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
            throw new DomainException(lang('illegal'), route('admin.aigc.quota'));
        }
        $model = AigcQuota::find($id);
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.aigc.quota'));
        }

        $user_id = $this->resolveUserId($data['username']);

        if (AigcQuota::countByUserAndQuotaType($user_id, $data['quota_type'], $id) > 0) {
            throw new DomainException(lang('aigc_quota_exists'), route('admin.aigc.quota'));
        }

        $row = array(
            'user_id' => $user_id,
            'quota_type' => $data['quota_type'],
            'total_limit' => isset($data['total_limit']) ? intval($data['total_limit']) : 0,
            'reset_cycle' => $data['reset_cycle'],
            'expires_at' => !empty($data['expires_at']) ? $data['expires_at'] : null,
        );

        $model->fill($row, 'update')->save();

        $user_info = (user() !== null ? user()->format($user_id) : false);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, $user_info ? (string) $user_info['nickname'] : ('UID:' . $user_id), 'aigc_quota');
    }

    /**
     * 单条删除（二次确认）。
     *
     * @param mixed $id 配额主键
     * @param array $post 含 confirm 时表示用户已确认
     * @return array{message: string, back_url: string, timeout?: string, confirm_url?: string}
     * @throws DomainException
     */
    public function delete($id, array $post)
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.quota'));
        }
        $quotaRow = AigcQuota::find($id);
        if (!$quotaRow) {
            throw new DomainException(lang('illegal'), route('admin.aigc.quota'));
        }
        $quota = $quotaRow->getAttributes();

        $user_info = (user() !== null ? user()->format($quota['user_id']) : false);
        $username = $user_info ? $user_info['nickname'] : ('UID:' . $quota['user_id']);

        if (isset($post['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $username, 'aigc_quota');
            AigcQuota::destroy($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.aigc.quota'),
            );
        }

        $msg = preg_replace(
            '/d%/Ums',
            $username . '(' . $quota['quota_type'] . ')',
            lang('del_check')
        );
        return array(
            'message' => $msg,
            'back_url' => route('admin.aigc.quota'),
            'timeout' => '30',
            'confirm_url' => route('admin.aigc.quota.destroy', array('id' => $id)),
        );
    }

    /**
     * 列表批量操作（删除 / 清零已用量）。
     *
     * @param array $post checkbox、action 等表单字段
     * @return array{message: string, back_url: string}
     * @throws DomainException
     */
    public function action(array $post)
    {
        if (empty($post['checkbox']) || !is_array($post['checkbox'])) {
            throw new DomainException(lang('aigc_quota_select_empty'), route('admin.aigc.quota'));
        }

        $action = Arr::get($post, 'action', '');

        if ($action === 'del_all') {
            $ids = Check::intIds($post['checkbox']);
            if (empty($ids)) {
                throw new DomainException(lang('aigc_quota_select_empty'), route('admin.aigc.quota'));
            }
            AigcQuota::whereIn('id', $ids)->delete();
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:AigcQuota (' . count($ids) . ' items)', 'aigc_quota');
            return array('message' => lang('del_succes'), 'back_url' => route('admin.aigc.quota'));
        }

        if ($action === 'reset') {
            $ids = Check::intIds($post['checkbox']);
            if (empty($ids)) {
                throw new DomainException(lang('aigc_quota_select_empty'), route('admin.aigc.quota'));
            }
            AigcQuota::resetUsedAmountForIds($ids);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'reset:AigcQuota (' . count($ids) . ' items)', 'aigc_quota');

            return array('message' => lang('aigc_quota_reset_succes'), 'back_url' => route('admin.aigc.quota'));
        }

        throw new DomainException(lang('select_empty'), route('admin.aigc.quota'));
    }

    /**
     * 用户名 / 数字 ID → 会员 ID。
     *
     * @param mixed $usernameRaw
     * @return int
     * @throws DomainException
     */
    private function resolveUserId($usernameRaw)
    {
        $usernameRaw = is_scalar($usernameRaw) ? trim((string) $usernameRaw) : '';
        if ($usernameRaw === '') {
            throw new DomainException(lang('aigc_quota_username') . lang('is_empty'), route('admin.aigc.quota'));
        }

        if (is_numeric($usernameRaw)) {
            $user_id = intval($usernameRaw);
            if (!DB::table('user')->where('id', $user_id)->count()) {
                throw new DomainException(lang('aigc_quota_user_not_found'), route('admin.aigc.quota'));
            }

            return $user_id;
        }

        $user_id = user() !== null ? user()->findUserId($usernameRaw) : null;
        if (!$user_id) {
            throw new DomainException(lang('aigc_quota_user_not_found'), route('admin.aigc.quota'));
        }

        return (int) $user_id;
    }
}
