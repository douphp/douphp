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

use Dou\Core\Service\BaseService;
use Dou\Core\Service\Snapshot\SnapshotRepository;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台「升级还原点」页数据组装（展示层，不含还原执行链）。
 *
 * 数据源为 core 侧 {@see SnapshotRepository}（storage/snapshot 扫描）+ 维护模式状态 +
 * 进行中还原状态；还原 / 删除 / 救援令牌等动作由 {@see \Dou\Admin\Controller\Backup\SnapshotController}
 * 直接驱动服务。
 */
class SnapshotPageService extends BaseService
{
    /**
     * 组装还原点列表页数据。
     *
     * @return array rows_system、rows_module、maintenance、active_restore*、maintenance_off_url
     */
    public function buildIndexData()
    {
        $repository = new SnapshotRepository(ROOT_PATH);
        $rows = $repository->all();
        $activeRestore = (new RestoreService(ROOT_PATH))->activeDir();
        $maintenance = (new MaintenanceModeService(ROOT_PATH))->state();
        $token = csrf()->token();
        $now = time();

        $groups = array('system' => array(), 'module' => array());
        foreach ($rows as $row) {
            $manifest = $row['manifest'];
            $scope = isset($manifest['scope']) && $manifest['scope'] === 'system' ? 'system' : 'module';
            $dirName = $row['name'];
            $isRestoring = ($activeRestore === $dirName);

            $statusText = lang('snapshot_status_valid');
            $statusClass = 'ok';
            if ($isRestoring) {
                $statusText = lang('snapshot_status_restoring');
                $statusClass = 'running';
            } elseif ((int) $row['restored_at'] > 0) {
                $statusText = lang('snapshot_status_restored');
                $statusClass = 'done';
            }

            $rescueState = '';
            if ($scope === 'system') {
                if ($row['rescue_expires_at'] > $now) {
                    $rescueState = str_replace('d%', date('m-d H:i', $row['rescue_expires_at']), lang('snapshot_rescue_valid'));
                } elseif ($row['rescue_expires_at'] > 0) {
                    $rescueState = lang('snapshot_rescue_expired');
                } else {
                    $rescueState = lang('snapshot_rescue_none');
                }
            }

            $dbBytes = 0;
            foreach ((array) (isset($manifest['db_files']) ? $manifest['db_files'] : array()) as $dbFile) {
                $dbBytes += isset($dbFile['size']) ? (int) $dbFile['size'] : 0;
            }

            $groups[$scope][] = array(
                'name' => $dirName,
                'title' => !empty($manifest['title']) ? (string) $manifest['title'] : $dirName,
                'scope_id' => isset($manifest['scope_id']) ? (string) $manifest['scope_id'] : '',
                'douphp_version' => isset($manifest['douphp_version']) ? (string) $manifest['douphp_version'] : '',
                'created_at_text' => date('Y-m-d H:i:s', (int) $row['created_at']),
                'size_text' => $this->formatBytes($row['total_size']),
                // 0 文件时按 db 字段区分文案：主题 / 插件还原点固定 db=false，
                // 不能显示「仅数据库」这样自相矛盾的内容描述。
                'files_text' => empty($manifest['files_count'])
                    ? (!empty($manifest['db']) ? lang('snapshot_files_db_only') : lang('snapshot_files_none'))
                    : str_replace('d%', (string) (int) $manifest['files_count'], lang('snapshot_files_count')),
                'db_text' => count((array) (isset($manifest['db_files']) ? $manifest['db_files'] : array())) === 0
                    ? lang('snapshot_db_none')
                    : str_replace(array('d%', 'e%'), array((string) count($manifest['db_files']), $this->formatBytes($dbBytes)), lang('snapshot_db_count')),
                'status_text' => $statusText,
                'status_class' => $statusClass,
                'is_restoring' => $isRestoring,
                'abandon_url' => $isRestoring
                    ? route('admin.backup.snapshot_abandon', array(), array('query' => array('snapshot' => $dirName, 'token' => $token)))
                    : '',
                // GET 也走 CSRF 校验（getTokenRoutes）：首跳确认链接必须携带令牌
                'restore_url' => route('admin.backup.snapshot_restore', array(), array('query' => array('snapshot' => $dirName, 'token' => $token))),
                'download_url' => route('admin.backup.snapshot_down', array(), array('query' => array('snapshot' => $dirName))),
                'delete_url' => route('admin.backup.snapshot_destroy', array(), array('query' => array('snapshot' => $dirName))),
                'rescue_url' => $scope === 'system'
                    ? route('admin.backup.snapshot_rescue', array(), array('query' => array('snapshot' => $dirName, 'token' => $token)))
                    : '',
                'rescue_state_text' => $rescueState,
            );
        }

        return array(
            'rows_system' => $groups['system'],
            'rows_module' => $groups['module'],
            'maintenance' => $maintenance,
            'active_restore' => $activeRestore,
            'active_restore_resume_url' => $activeRestore !== null
                ? route('admin.backup.snapshot_restore', array(), array('query' => array('snapshot' => $activeRestore, 'token' => $token)))
                : '',
            'maintenance_off_url' => route('admin.backup.snapshot_maintenance_off', array(), array('query' => array('token' => $token))),
        );
    }

    /**
     * 字节数格式化（展示用）。
     *
     * @param int $bytes
     * @return string
     */
    public function formatBytes($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
