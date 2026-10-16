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

use Dou\Core\Support\FileHelper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 升级还原点仓库：storage/snapshot 下快照目录的枚举 / 查找 / 删除 / 留存策略。
 *
 * 目录命名：`{scope}-{scope_id}-{YmdHis}`；有效快照必须含 manifest.json。
 * 留存策略（prune）：同一 scope+scope_id 分组下，保留最新 1 个 + 7 天内全部，
 * 其余删除；无 manifest 的残缺目录（创建中断）一并清理，但不触碰活跃快照
 * （active.json 指向）与正在还原的快照（含 restore_state.json）。
 *
 * 兼容 PHP 5.6+。
 */
class SnapshotRepository
{
    /** @var string 站点根（结尾带 /） */
    private $rootPath;

    /** @var string storage/snapshot/ 绝对路径（结尾带 /） */
    private $snapshotRoot;

    /**
     * @param string $rootPath 站点根目录
     */
    public function __construct($rootPath)
    {
        $this->rootPath = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';
        $this->snapshotRoot = $this->rootPath . 'storage/snapshot/';
    }

    /**
     * @return string storage/snapshot/ 绝对路径（结尾带 /）
     */
    public function baseDir()
    {
        return $this->snapshotRoot;
    }

    /**
     * @param string $dirName
     * @return string 快照目录绝对路径（结尾带 /）
     */
    public function dirPath($dirName)
    {
        return $this->snapshotRoot . $dirName . '/';
    }

    /**
     * 校验快照目录名（防路径穿越，仅允许本仓库命名规则）。
     *
     * @param mixed $dirName
     * @return bool
     */
    public function isValidName($dirName)
    {
        $dirName = is_string($dirName) ? $dirName : '';

        return (bool) preg_match('/^(system|module)-[A-Za-z0-9_\-]{1,60}-\d{14}$/', $dirName);
    }

    /**
     * 列出全部有效快照（含 manifest），按创建时间倒序。
     *
     * @return array 行数组：name、path、manifest、created_at、has_rescue、
     *               rescue_expires_at、restored_at、total_size
     */
    public function all()
    {
        $rows = array();
        foreach ((array) glob($this->snapshotRoot . '*/manifest.json') as $manifestFile) {
            $dirName = basename(dirname($manifestFile));
            if (!$this->isValidName($dirName)) {
                continue;
            }
            $row = $this->row($dirName);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        usort($rows, function ($a, $b) {
            if ($a['created_at'] === $b['created_at']) {
                return strcmp($b['name'], $a['name']);
            }
            return $b['created_at'] - $a['created_at'];
        });

        return $rows;
    }

    /**
     * 读取单个快照行；目录缺失 / manifest 缺失时返回 null。
     *
     * @param string $dirName
     * @return array|null
     */
    public function row($dirName)
    {
        if (!$this->isValidName($dirName)) {
            return null;
        }
        $dir = $this->dirPath($dirName);
        $manifest = $this->readManifest($dir);
        if ($manifest === null) {
            return null;
        }

        $rescueExpires = 0;
        $tokenFile = $dir . 'rescue.token';
        if (is_file($tokenFile)) {
            $token = json_decode((string) @file_get_contents($tokenFile), true);
            if (is_array($token) && !empty($token['expires_at'])) {
                $rescueExpires = (int) $token['expires_at'];
            }
        }

        return array(
            'name' => $dirName,
            'path' => $dir,
            'manifest' => $manifest,
            'created_at' => isset($manifest['created_at']) ? (int) $manifest['created_at'] : (int) @filemtime($dir),
            'has_rescue' => is_file($dir . 'rescue.php'),
            'rescue_expires_at' => $rescueExpires,
            'restored_at' => isset($manifest['restored_at']) ? (int) $manifest['restored_at'] : 0,
            'total_size' => $this->dirSize($dir),
        );
    }

    /**
     * 读取快照 manifest.json。
     *
     * @param string $dir 快照目录绝对路径（结尾带 /）
     * @return array|null
     */
    public function readManifest($dir)
    {
        $file = rtrim(str_replace('\\', '/', $dir), '/') . '/manifest.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * 按 scope+scope_id 找最新的一个快照。
     *
     * @param string $scope
     * @param string $scopeId 精确匹配；空串表示任意
     * @return array|null 行数组（同 {@see row()}）
     */
    public function latest($scope, $scopeId = '')
    {
        foreach ($this->all() as $row) {
            $manifest = $row['manifest'];
            if (isset($manifest['scope']) && (string) $manifest['scope'] === (string) $scope) {
                if ($scopeId === '' || (isset($manifest['scope_id']) && (string) $manifest['scope_id'] === (string) $scopeId)) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * 删除快照目录（活跃 / 还原中的快照拒绝删除）。
     *
     * @param string $dirName
     * @return bool
     */
    public function delete($dirName)
    {
        if (!$this->isValidName($dirName)) {
            return false;
        }
        if ($this->isActive($dirName) || $this->isRestoring($dirName)) {
            return false;
        }
        $dir = $this->dirPath($dirName);
        if (!is_dir($dir)) {
            return false;
        }
        FileHelper::delDir($dir);

        return !is_dir($dir);
    }

    /**
     * 该快照是否为当前活跃创建目标（active.json 指向）。
     *
     * @param string $dirName
     * @return bool
     */
    public function isActive($dirName)
    {
        $active = json_decode((string) @file_get_contents($this->snapshotRoot . 'active.json'), true);

        return is_array($active) && isset($active['dir']) && (string) $active['dir'] === (string) $dirName;
    }

    /**
     * 该快照是否存在未完成的还原状态。
     *
     * @param string $dirName
     * @return bool
     */
    public function isRestoring($dirName)
    {
        if (!$this->isValidName($dirName)) {
            return false;
        }

        return is_file($this->dirPath($dirName) . 'restore_state.json');
    }

    /**
     * 留存策略：同一 scope+scope_id 分组保留最新 1 个 + 7 天内全部；
     * 残缺目录（无 manifest，且非活跃、超过 2 小时）一并清理。
     *
     * @return array 被删除的目录名列表
     */
    public function prune()
    {
        $deleted = array();
        $keepWindow = 7 * 86400;
        $now = time();
        $activeDir = '';

        $active = json_decode((string) @file_get_contents($this->snapshotRoot . 'active.json'), true);
        if (is_array($active) && !empty($active['dir'])) {
            $activeDir = (string) $active['dir'];
        }

        // 残缺目录清理（创建中断 / 手工残留）
        foreach ((array) glob($this->snapshotRoot . '*') as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $dirName = basename($dir);
            if ($dirName === 'active.json' || !$this->isValidName($dirName)) {
                continue;
            }
            if ($dirName === $activeDir || $this->isRestoring($dirName)) {
                continue;
            }
            if (is_file($dir . '/manifest.json')) {
                continue;
            }
            // 刚创建到一半就被并发扫描命中的概率极小，2 小时门槛兜底
            if ($now - (int) @filemtime($dir) < 7200) {
                continue;
            }
            FileHelper::delDir($dir . '/');
            if (!is_dir($dir)) {
                $deleted[] = $dirName;
            }
        }

        // 分组留存策略
        $groups = array();
        foreach ($this->all() as $row) {
            $manifest = $row['manifest'];
            $key = (isset($manifest['scope']) ? $manifest['scope'] : '') . '|' . (isset($manifest['scope_id']) ? $manifest['scope_id'] : '');
            $groups[$key][] = $row;
        }
        foreach ($groups as $rows) {
            // all() 已按时间倒序：$rows[0] 为最新
            foreach ($rows as $index => $row) {
                if ($index === 0) {
                    continue;
                }
                if ($now - $row['created_at'] <= $keepWindow) {
                    continue;
                }
                if ($this->delete($row['name'])) {
                    $deleted[] = $row['name'];
                }
            }
        }

        return $deleted;
    }

    /**
     * 计算目录占用字节数。
     *
     * @param string $dir
     * @return int
     */
    public function dirSize($dir)
    {
        $size = 0;
        $handle = @opendir($dir);
        if (!$handle) {
            return 0;
        }
        while (($item = readdir($handle)) !== false) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . $item;
            if (is_dir($path)) {
                $size += $this->dirSize($path . '/');
            } elseif (is_file($path)) {
                $size += (int) @filesize($path);
            }
        }
        closedir($handle);

        return $size;
    }
}
