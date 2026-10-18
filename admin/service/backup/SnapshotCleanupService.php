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

namespace Dou\Admin\Service\Backup;

use Dou\Core\Service\Snapshot\SnapshotRepository;
use Dou\Core\Service\Snapshot\SnapshotRetention;
use Dou\Core\Service\Upgrade\RunLockService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 还原点清理：超时还原状态兜底 + 留存策略裁剪 + 水位记录。
 *
 * 触发点：
 *   - 后台首页加载（{@see runScheduled()}）：距最后一次创建 / 清理超过保留窗口才执行；
 *   - 还原点页手动按钮（{@see cleanup()}）。
 *
 * 只按留存策略删除超出上限的旧还原点，不做「全部清空」；活跃创建中、还原中、
 * 未超时的快照由 SnapshotRepository 保护，不会被删除。
 *
 * 兼容 PHP 5.6+。
 */
class SnapshotCleanupService
{
    /** @var string 站点根（结尾带 /） */
    private $rootPath;

    /** @var SnapshotRepository */
    private $repository;

    /** @var SnapshotRetention */
    private $retention;

    /** @var MaintenanceModeService */
    private $maintenance;

    /**
     * @param string $rootPath 站点根目录
     */
    public function __construct($rootPath)
    {
        $this->rootPath = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';
        $this->repository = new SnapshotRepository($this->rootPath);
        $this->retention = new SnapshotRetention($this->rootPath);
        $this->maintenance = new MaintenanceModeService($this->rootPath);
    }

    /**
     * 执行一次清理（不判断时机）。
     *
     * @return array array('deleted' => 目录名列表, 'stale' => 被放弃的还原任务目录名列表)
     */
    public function cleanup()
    {
        $restore = new RestoreService($this->rootPath);
        $stale = $restore->cleanupStaleStates();

        $deleted = $this->repository->prune();

        // 被删快照若仍残留还原锁，一并释放（非持有者调用为无操作）
        if ($deleted) {
            $runLock = new RunLockService($this->rootPath);
            foreach ($deleted as $dirName) {
                $runLock->release('snapshot:' . $dirName);
            }
        }

        // 无论是否删到都要记水位，否则「无东西可删」会让首页每次加载都重复扫描
        $this->retention->markPruned(count($deleted));

        return array('deleted' => $deleted, 'stale' => $stale);
    }

    /**
     * 后台首页调用的时机判定入口。
     *
     * 正常情况只读一次水位 JSON（几百字节）；到期才拿锁并执行清理。
     * 同时兜底恢复「还原中断超过 24 小时」遗留的维护模式。
     *
     * @return array|null 清理结果；未到期 / 抢锁失败返回 null
     */
    public function runScheduled()
    {
        $this->maintenance->recoverIfStale(RestoreService::STALE_SECONDS);

        if (!$this->retention->isDue()) {
            return null;
        }

        $lockFile = $this->rootPath . 'storage/cache/snapshot_cleanup.lock';
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0777, true);
        }
        $handle = @fopen($lockFile, 'c');
        if (!$handle) {
            return null;
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return null;
        }

        $result = null;
        try {
            @set_time_limit(30);
            $result = $this->cleanup();
        } catch (\Exception $e) {
            // 清理失败不影响页面渲染，下次首页加载会再次触发
        } catch (\Throwable $e) {
            // PHP 7+ 的 \Error 同样吞掉
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);

        return $result;
    }
}
