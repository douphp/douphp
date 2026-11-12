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

use Dou\Admin\Model\Aigc\AigcSession;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Chat\SessionStore;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会话管理（aigc_session / aigc_message，route=aigc/session/...）。
 *
 * 职责：
 * - 会话列表筛选分页（应用 / 状态 / 会员 / 关键字）。
 * - 会话详情（消息流 + 会话元信息，只读运营视图）。
 * - 关闭会话与会话删除（连带消息）。
 */
class SessionService extends BaseService
{
    /** @var SessionStore */
    private $store;

    public function __construct()
    {
        // 复用通用会话持久层，按 aigc 表名与应用字段实例化
        $this->store = new SessionStore('aigc_session', 'aigc_message', 'app_id');
    }

    /**
     * 列表页数据。
     *
     * @param array $req 筛选请求（app_id / status / username / keyword / page）
     * @return array session_list、pager、app_options
     */
    public function getAdminIndexPageData(array $req)
    {
        $appId = isset($req['app_id']) ? (int) $req['app_id'] : 0;
        $status = isset($req['status']) ? trim((string) $req['status']) : '';
        $username = isset($req['username']) ? trim((string) $req['username']) : '';
        $keyword = isset($req['keyword']) ? trim((string) $req['keyword']) : '';
        $page = isset($req['page']) ? $req['page'] : 1;

        $pageUrl = route('admin.aigc.session');
        if ($appId > 0) {
            $pageUrl .= '&app_id=' . $appId;
        }
        if ($status !== '') {
            $pageUrl .= '&status=' . rawurlencode($status);
        }
        $userId = 0;
        $userNotFound = false;
        if ($username !== '') {
            $userId = user() !== null ? (int) user()->findUserId($username) : 0;
            $userNotFound = $userId <= 0;
            $pageUrl .= '&username=' . rawurlencode($username);
        }
        if ($keyword !== '') {
            $pageUrl .= '&keyword=' . rawurlencode($keyword);
        }

        $query = AigcSession::filterByAigcId($appId)
            ->filterByStatus($status)
            ->filterByUserId($userId)
            ->filterByKeyword($keyword)
            ->applyDefaultOrder();
        // 输入了用户名但解析不到会员：返回空集，避免 0 被当作“不过滤”显示全表
        if ($userNotFound) {
            $query->whereRaw('1 = 0');
        }
        $result = $query->paginate(15, $page, Util::normalizeQueryString($pageUrl));

        $appNames = $this->appNameMap();

        $session_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $session_list[] = $this->formatSessionRow($row, $appNames);
        }

        return array(
            'session_list' => $session_list,
            'pager' => $result['pager'],
            'app_options' => $this->appOptions(),
            'user_not_found' => $userNotFound,
        );
    }

    /**
     * 会话详情：会话元信息 + 消息流（每页最近 200 条，支持 before_id 向前翻页）。
     *
     * @param mixed $id 会话主键
     * @param int $beforeId 仅取该消息 ID 之前的历史（0 = 最新一页）
     * @return array|null session、message_list、has_more、oldest_id、before_id
     */
    public function getViewBundle($id, $beforeId = 0)
    {
        $id = (int) $id;
        $beforeId = (int) $beforeId;
        if ($id <= 0) {
            return null;
        }
        $sessionModel = AigcSession::find($id);
        if (!$sessionModel) {
            return null;
        }
        $session = $this->formatSessionRow($sessionModel->toArray(), $this->appNameMap());

        $limit = 200;
        $rows = $this->store->latestMessages($id, $limit + 1, $beforeId);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_shift($rows);
        }

        $feedbackMap = array();
        if (DB::tableExist('aigc_message_feedback')) {
            foreach ((array) DB::table('aigc_message_feedback')->where('session_id', $id)->select() as $feedback) {
                $feedbackMap[(int) $feedback['message_id']] = (int) $feedback['value'];
            }
        }

        $message_list = array();
        foreach ($rows as $message) {
            $messageId = (int) $message['id'];
            $message_list[] = $this->formatMessageRow($message, isset($feedbackMap[$messageId]) ? $feedbackMap[$messageId] : 0);
        }

        return array(
            'session' => $session,
            'message_list' => $message_list,
            'has_more' => $hasMore,
            'oldest_id' => $message_list ? (int) $message_list[0]['id'] : 0,
            'before_id' => $beforeId,
        );
    }

    /**
     * 关闭会话。
     *
     * @param mixed $id 会话主键
     * @param int $adminId
     * @return void
     * @throws DomainException
     */
    public function close($id, $adminId)
    {
        $session = $this->mustFind($id);
        $this->store->close((int) $session['id']);
        audit()->writeAdminLog((int) $adminId, AdminLogAction::UPDATE, 1, 'close:' . $session['session_sn'], 'aigc_session');
    }

    /**
     * 单条删除（二次确认；连带删除消息）。
     *
     * @param mixed $id 会话主键
     * @param array $post 含 confirm 时表示用户已确认
     * @return array{message: string, back_url: string, timeout?: string, confirm_url?: string}
     * @throws DomainException
     */
    public function delete($id, array $post)
    {
        $session = $this->mustFind($id);
        $id = (int) $session['id'];

        if (isset($post['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $session['session_sn'], 'aigc_session');
            DB::table('aigc_message')->where('session_id', $id)->delete();
            if (DB::tableExist('aigc_message_feedback')) {
                DB::table('aigc_message_feedback')->where('session_id', $id)->delete();
            }
            AigcSession::destroy($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.aigc.session'),
            );
        }

        $msg = preg_replace('/d%/Ums', $session['title'] !== '' ? $session['title'] : ('ID: ' . $id), lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.aigc.session'),
            'timeout' => '30',
            'confirm_url' => route('admin.aigc.session.destroy', array('id' => $id)),
        );
    }

    /**
     * 列表批量操作：勾选会话 id，`action === del_all` 时连带消息删除。
     *
     * @param array $post checkbox、action 等表单字段
     * @return array{message: string, back_url: string}
     * @throws DomainException
     */
    public function action(array $post)
    {
        if (empty($post['checkbox']) || !is_array($post['checkbox'])) {
            throw new DomainException(lang('aigc_session_select_empty'), route('admin.aigc.session'));
        }

        $action = Arr::get($post, 'action', '');
        if ($action === 'del_all') {
            $ids = Check::intIds($post['checkbox']);
            if (empty($ids)) {
                throw new DomainException(lang('aigc_session_select_empty'), route('admin.aigc.session'));
            }
            foreach ($ids as $sid) {
                DB::table('aigc_message')->where('session_id', $sid)->delete();
            }
            AigcSession::whereIn('id', $ids)->delete();
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:AigcSession (' . count($ids) . ' items)', 'aigc_session');
            return array('message' => lang('del_succes'), 'back_url' => route('admin.aigc.session'));
        }

        throw new DomainException(lang('select_empty'), route('admin.aigc.session'));
    }

    /**
     * 应用下拉选项。
     *
     * @return array
     */
    private function appOptions()
    {
        $rows = (array) DB::table('aigc')->field('id, name')->order('sort ASC, id ASC')->select();

        return $rows;
    }

    /**
     * 应用 ID → 名称映射。
     *
     * @return array
     */
    private function appNameMap()
    {
        $map = array();
        foreach ($this->appOptions() as $row) {
            $map[(int) $row['id']] = $row['name'];
        }

        return $map;
    }

    /**
     * 取会话行，找不到抛 DomainException。
     *
     * @param mixed $id
     * @return array
     * @throws DomainException
     */
    private function mustFind($id)
    {
        $id = (int) $id;
        $sessionModel = $id > 0 ? AigcSession::find($id) : null;
        if (!$sessionModel) {
            throw new DomainException(lang('illegal'), route('admin.aigc.session'));
        }

        return $sessionModel->getAttributes();
    }

    /**
     * 会话行展示格式化。
     *
     * @param array $row
     * @param array $appNames id → name
     * @return array
     */
    private function formatSessionRow(array $row, array $appNames)
    {
        $userInfo = ((int) $row['user_id'] > 0 && user() !== null) ? user()->format($row['user_id']) : false;
        if (!$userInfo) {
            // 会员专属模块：无会员主体时统一占位，避免模板读取空头像/昵称报错
            $userInfo = array('nickname' => '-', 'avatar' => '');
        }

        return array(
            'id' => $row['id'],
            'session_sn' => $row['session_sn'],
            'app_id' => $row['app_id'],
            'app_name' => isset($appNames[(int) $row['app_id']]) ? $appNames[(int) $row['app_id']] : '-',
            'user_id' => $row['user_id'],
            'user' => $userInfo,
            'title' => $row['title'],
            'message_count' => $row['message_count'],
            'total_tokens' => $row['total_tokens'],
            'status' => $row['status'],
            'status_format' => language()->dataLangFormat('aigc_session_status_', $row['status']),
            'ip' => $row['ip'],
            'last_active_at' => !empty($row['last_active_at']) ? date('Y-m-d H:i', strtotime($row['last_active_at'])) : '-',
            'created_at' => !empty($row['created_at']) ? date('Y-m-d H:i', strtotime($row['created_at'])) : '',
        );
    }

    /**
     * 消息行展示格式化。
     *
     * @param array $row
     * @return array
     */
    private function formatMessageRow(array $row, $feedback = 0)
    {
        if (!$row) {
            return array();
        }

        return array(
            'id' => isset($row['id']) ? (int) $row['id'] : 0,
            'role' => isset($row['role']) ? $row['role'] : '',
            'sender_id' => isset($row['sender_id']) ? (int) $row['sender_id'] : 0,
            'content' => isset($row['content']) ? $row['content'] : '',
            'content_type' => isset($row['content_type']) ? $row['content_type'] : 'text',
            'total_tokens' => isset($row['total_tokens']) ? (int) $row['total_tokens'] : 0,
            'has_error' => !empty($row['has_error']) ? 1 : 0,
            'error_message' => isset($row['error_message']) ? $row['error_message'] : '',
            'feedback' => (int) $feedback,
            'created_at' => !empty($row['created_at']) ? date('Y-m-d H:i:s', strtotime($row['created_at'])) : '',
        );
    }
}
