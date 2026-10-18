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
 * Release Date: 2026-10-16
 */

namespace Dou\Core\Service\Snapshot;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 还原点留存水位记录：storage/state/snapshot_retention.json。
 *
 * 记录「最后创建 / 最后清理」时间，供后台首页做 O(1) 判断：
 * 距最后一次活动超过保留窗口才触发一次实际清理，避免每次请求扫描快照目录。
 *
 * 该文件只是水位线与元信息，不是快照清单的真相来源；「有哪些快照、各多大」
 * 仍以 manifest.json 为准。文件丢失 / 损坏视为「已到期」，先跑一次 prune()，
 * prune() 幂等且只删超策略快照，失败方向安全。
 *
 * 兼容 PHP 5.6+。
 */
class SnapshotRetention
{
    /** 保留窗口（秒）：窗口内的还原点全部保留，与 SnapshotRepository::KEEP_DAYS 对齐 */
    const KEEP_SECONDS = 604800;

    /** @var string 水位记录文件绝对路径 */
    private $file;

    /**
     * @param string $rootPath 站点根目录
     */
    public function __construct($rootPath)
    {
        $this->file = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/storage/state/snapshot_retention.json';
    }

    /**
     * @return string 水位记录文件绝对路径
     */
    public function file()
    {
        return $this->file;
    }

    /**
     * 读取水位记录（缺失 / 损坏时返回空数组）。
     *
     * @return array
     */
    public function read()
    {
        if (!is_file($this->file)) {
            return array();
        }
        $data = json_decode((string) @file_get_contents($this->file), true);

        return is_array($data) ? $data : array();
    }

    /**
     * 记录一次还原点创建（SnapshotService::doFinalize() 成功后调用）。
     *
     * @param string $dirName 快照目录名
     * @param string $scope 域（system / module / theme / plugin）
     * @param int $time 创建时间（0=当前时间）
     * @return void
     */
    public function markCreated($dirName, $scope = '', $time = 0)
    {
        $data = $this->read();
        $data['last_created_at'] = $time > 0 ? (int) $time : time();
        $data['last_created_name'] = (string) $dirName;
        $data['last_created_scope'] = (string) $scope;
        $this->write($data);
    }

    /**
     * 记录一次清理执行（无论是否删到）。
     *
     * 必须与 last_created_at 一同参与判定：只记创建时间会导致清理后的
     * 第二天、第三天……每次首页加载都重复触发。
     *
     * @param int $deleted 本次删除数量
     * @param int $time 清理时间（0=当前时间）
     * @return void
     */
    public function markPruned($deleted = 0, $time = 0)
    {
        $data = $this->read();
        $data['last_pruned_at'] = $time > 0 ? (int) $time : time();
        $data['pruned_total'] = (isset($data['pruned_total']) ? (int) $data['pruned_total'] : 0) + (int) $deleted;
        $this->write($data);
    }

    /**
     * 最后一次活动时间（创建或清理，取较大值）。
     *
     * @return int 0 表示无记录
     */
    public function lastActivityAt()
    {
        $data = $this->read();
        $created = isset($data['last_created_at']) ? (int) $data['last_created_at'] : 0;
        $pruned = isset($data['last_pruned_at']) ? (int) $data['last_pruned_at'] : 0;

        return max($created, $pruned);
    }

    /**
     * 是否已到清理时机（无记录视为到期，先跑一次安全）。
     *
     * @param int $seconds 窗口秒数
     * @return bool
     */
    public function isDue($seconds = self::KEEP_SECONDS)
    {
        $last = $this->lastActivityAt();
        if ($last <= 0) {
            return true;
        }

        return time() - $last >= (int) $seconds;
    }

    /**
     * @param array $data
     * @return void
     */
    protected function write($data)
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($this->file, json_encode($data), LOCK_EX);
    }
}
