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

namespace Dou\Core\Service\Ai\Task;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 异步任务仓储：ai_task（后台）/ chat_task（会员端）表读写。
 *
 * 建表 SQL 不在此类中执行（升级 SQL 由发行包单独提供，管理员手工执行）；
 * 首次读写前检查表是否存在，缺失时给出明确提示，避免白屏。
 *
 * 表归属：ai_task 专给后台（admin_id + app_id）；chat_task 专给会员端
 * （user_id + chat_id），两表结构一致、按构造参数选择操作目标。
 */
class AsyncTaskRepository extends BaseService
{
    /** @var string 后台任务表名（不含前缀，由 DB 门面自动加前缀） */
    const TABLE = 'ai_task';

    /** @var string 会员端任务表名（不含前缀） */
    const CHAT_TABLE = 'chat_task';

    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_SUCCEEDED = 'succeeded';
    const STATUS_FAILED = 'failed';
    const STATUS_TIMEOUT = 'timeout';

    /** @var string 当前操作的表名 */
    private $table;

    /** @var string 归属字段名（ai_task: admin_id；chat_task: user_id） */
    private $ownerField;

    /** @var string 应用字段名（ai_task: app_id；chat_task: chat_id） */
    private $appField;

    /** @var bool|null 表存在性缓存（每表每请求只查一次，轮询高频路径省一查） */
    private static $tableExists = array();

    /**
     * @param string $table 表名（不含前缀）：ai_task（后台）/ chat_task（会员端）
     */
    public function __construct($table = self::TABLE)
    {
        $this->table = $table;
        if ($table === self::CHAT_TABLE) {
            $this->ownerField = 'user_id';
            $this->appField = 'chat_id';
        } else {
            $this->ownerField = 'admin_id';
            $this->appField = 'app_id';
        }
    }

    /**
     * 确认任务表已创建；缺失时抛出可读错误（不自动建表）。
     *
     * @return void
     */
    public function ensureTable()
    {
        if (!isset(self::$tableExists[$this->table])) {
            if (!DB::tableExist($this->table)) {
                throw new DomainException(lang('ai_task_table_missing'));
            }
            self::$tableExists[$this->table] = true;
        }
    }

    /**
     * 创建任务行（status=created 不设，默认 pending）。
     *
     * @param array $data provider_id/model_id/key_id/app_id/admin_id|user_id/task_type/request_payload；
     *                    chat_task 表时 app_id 落 chat_id 列、user_id 为归属字段
     * @return int 任务主键
     */
    public function create(array $data)
    {
        $this->ensureTable();
        $now = date('Y-m-d H:i:s');

        $row = array(
            'provider_id' => (int) $data['provider_id'],
            'model_id' => (int) $data['model_id'],
            'key_id' => (int) $data['key_id'],
            $this->appField => isset($data['app_id']) ? (int) $data['app_id'] : 0,
            $this->ownerField => isset($data[$this->ownerField]) ? (int) $data[$this->ownerField] : 0,
            'task_type' => isset($data['task_type']) ? (string) $data['task_type'] : '',
            'provider_task_id' => '',
            'status' => isset($data['status']) ? (string) $data['status'] : self::STATUS_PENDING,
            'request_payload' => isset($data['request_payload']) ? (string) $data['request_payload'] : '',
            'created_at' => $now,
            'updated_at' => $now,
        );

        return (int) DB::table($this->table)->data($row)->insert();
    }

    /**
     * 取任务行。
     *
     * @param mixed $id
     * @return array|null
     */
    public function find($id)
    {
        $this->ensureTable();

        return DB::table($this->table)->where('id', (int) $id)->find();
    }

    /**
     * 删除任务行。
     *
     * @param int $id
     * @return void
     */
    public function delete($id)
    {
        $this->ensureTable();

        DB::table($this->table)->where('id', (int) $id)->delete();
    }

    /**
     * 提交成功后回写供应商任务 ID 与初始状态。
     *
     * @param int $id
     * @param string $providerTaskId
     * @param string $status
     * @return void
     */
    public function markSubmitted($id, $providerTaskId, $status)
    {
        DB::table($this->table)
            ->where('id', (int) $id)
            ->data(array(
                'provider_task_id' => (string) $providerTaskId,
                'status' => $status === '' ? self::STATUS_RUNNING : (string) $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ))
            ->update();
    }

    /**
     * 标记成功并落结果。
     *
     * @param int $id
     * @param array $result 结果数组（urls 等）
     * @param string|null $expiresAt 结果过期时间（Y-m-d H:i:s），可空
     * @return void
     */
    public function markSucceeded($id, array $result, $expiresAt = null)
    {
        $data = array(
            'status' => self::STATUS_SUCCEEDED,
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        );
        if ($expiresAt !== null && $expiresAt !== '') {
            $data['expires_at'] = date('Y-m-d H:i:s', strtotime($expiresAt));
        }

        DB::table($this->table)->where('id', (int) $id)->data($data)->update();
    }

    /**
     * 原地更新任务结果（成功后产物裁切等后处理使用，不改状态）。
     *
     * @param int $id
     * @param array $result
     * @return void
     */
    public function updateResult($id, array $result)
    {
        DB::table($this->table)
            ->where('id', (int) $id)
            ->data(array(
                'result' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'),
            ))
            ->update();
    }

    /**
     * 标记失败。
     *
     * @param int $id
     * @param string $error
     * @return void
     */
    public function markFailed($id, $error)
    {
        DB::table($this->table)
            ->where('id', (int) $id)
            ->data(array(
                'status' => self::STATUS_FAILED,
                'error' => mb_substr((string) $error, 0, 500, 'UTF-8'),
                'updated_at' => date('Y-m-d H:i:s'),
            ))
            ->update();
    }

    /**
     * 标记超时。
     *
     * @param int $id
     * @return void
     */
    public function markTimeout($id)
    {
        DB::table($this->table)
            ->where('id', (int) $id)
            ->data(array(
                'status' => self::STATUS_TIMEOUT,
                'error' => lang('ai_task_timeout'),
                'updated_at' => date('Y-m-d H:i:s'),
            ))
            ->update();
    }

    /**
     * 轮询后刷新最近轮询时间。
     *
     * @param int $id
     * @return void
     */
    public function touchPolled($id)
    {
        DB::table($this->table)
            ->where('id', (int) $id)
            ->data(array('last_polled_at' => date('Y-m-d H:i:s')))
            ->update();
    }

    /**
     * 任务分页列表（后台任务面板）。
     *
     * @param array $filters 可选 status / task_type / admin_id / user_id
     *                       （归属字段按表自动匹配：ai_task 用 admin_id，chat_task 用 user_id）
     * @param int $page
     * @param string $pageUrl
     * @param int $pageSize
     * @return array {list, pager}
     */
    public function paginate(array $filters, $page, $pageUrl, $pageSize = 15)
    {
        $this->ensureTable();

        $query = DB::table($this->table);
        if (!empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (!empty($filters['task_type'])) {
            $query->where('task_type', (string) $filters['task_type']);
        }
        if (!empty($filters[$this->ownerField])) {
            $query->where($this->ownerField, (int) $filters[$this->ownerField]);
        }
        $query->order('id DESC');

        return $query->paginate($pageSize, (int) $page, (string) $pageUrl);
    }
}
