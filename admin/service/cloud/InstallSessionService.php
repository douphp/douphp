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

namespace Dou\Admin\Service\Cloud;

use Dou\Core\Service\BaseService;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 云端在线安装会话存储。
 *
 * - 会话文件存放于 `storage/install/{id}.json`，以 `install_id` 为键；
 * - 单次安装链路记录：扩展类型、cloud_id、模式（install/update/patch/local）、版本、主题 id、
 *   批量队列、当前步骤、已完成步骤、日志条目、错误以及收尾按钮 HTML；
 * - 通过 `withLock()` 对单个会话文件加排他锁，保证浏览器并发请求（如重试）按序处理；
 * - 构造时尽力清理过期文件（TTL_SECONDS），避免目录无限增长；
 *   不依赖 cron / 常驻进程，符合「对用户站点零额外要求」。
 * - 仓库根 `.gitignore` 已整体忽略 `storage/`，会话 json 自然不会进入版本库。
 * - `storage/` 不暴露在 robots/HTTP 路径上；并由站点 `.htaccess` / Nginx 配置在边界拒绝直访。
 */
class InstallSessionService extends BaseService
{
    /** 会话目录相对站点根（带斜杠结尾） */
    const SESSION_DIR = 'storage/install/';

    /** 会话过期时间（秒）：30 分钟 */
    const TTL_SECONDS = 1800;

    /** @var string 绝对路径，斜杠结尾 */
    private $baseDir;

    /**
     */
    public function __construct()
    {
        $this->baseDir = ROOT_PATH . self::SESSION_DIR;
        $this->ensureDir();
        $this->gcExpired();
    }

    /**
     * 新建一个会话并落盘，返回 install_id。
     *
     * @param array $params 至少包含 type / cloud_id / mode / version / theme_id；可选 batch、download_url（云端解析后的安装包 URL）、
     *                      auth_status（云端解析后的授权状态：ok / unauthorized / unavailable，默认 ok）。mode 取值 install|update|patch|local。
     * @return string 64 位十六进制 install_id
     */
    public function create(array $params)
    {
        $type = isset($params['type']) ? (string) $params['type'] : '';
        $cloudId = isset($params['cloud_id']) ? (string) $params['cloud_id'] : '';
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'install';
        $version = isset($params['version']) ? (string) $params['version'] : '';
        $themeId = isset($params['theme_id']) ? (string) $params['theme_id'] : '';
        $batch = isset($params['batch']) && is_array($params['batch']) ? array_values($params['batch']) : array();
        $downloadUrl = isset($params['download_url']) ? (string) $params['download_url'] : '';
        $authStatus = isset($params['auth_status']) ? (string) $params['auth_status'] : 'ok';

        $installId = Str::randomHex(16);
        $now = time();

        $state = array(
            'install_id' => $installId,
            'created_at' => $now,
            'updated_at' => $now,
            'type' => $type,
            'cloud_id' => $cloudId,
            'mode' => $mode,
            'version' => $version,
            'theme_id' => $themeId,
            'download_url' => $downloadUrl,
            'auth_status' => $authStatus,
            'batch' => $batch,
            'current_step' => 'preflight',
            'completed_steps' => array(),
            'failed_step' => '',
            'logs' => array(),
            'success' => false,
            'finished' => false,
            'result' => array(
                'btn_action_html' => '',
                'btn_back_html' => '',
                'next_install' => null,
            ),
        );

        $this->writeFile($installId, $state);
        return $installId;
    }

    /**
     * 读取会话；不存在或过期返回 null。
     *
     * @param string $installId
     * @return array|null
     */
    public function load($installId)
    {
        if (!$this->isValidId($installId)) {
            return null;
        }
        $file = $this->baseDir . $installId . '.json';
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $updatedAt = isset($data['updated_at']) ? (int) $data['updated_at'] : 0;
        if ($updatedAt && $updatedAt + self::TTL_SECONDS < time()) {
            @unlink($file);
            return null;
        }
        return $data;
    }

    /**
     * 保存会话（覆盖写入，调用方负责传完整结构）。
     *
     * @param string $installId
     * @param array $state
     * @return void
     */
    public function save($installId, array $state)
    {
        if (!$this->isValidId($installId)) {
            return;
        }
        $state['install_id'] = $installId;
        $state['updated_at'] = time();
        $this->writeFile($installId, $state);
    }

    /**
     * 删除会话文件（成功或显式取消时调用）。
     *
     * @param string $installId
     * @return void
     */
    public function delete($installId)
    {
        if (!$this->isValidId($installId)) {
            return;
        }
        $file = $this->baseDir . $installId . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * 在文件锁保护下执行回调；并发的同一会话请求会被串行化。
     *
     * 回调签名：`function (array $state, callable $save): array` —— 必须返回新 state。
     * 内部已处理读取与写回，回调中无需再调用 save()。
     *
     * @param string $installId
     * @param callable $fn
     * @return array|null 新 state；会话不存在返回 null
     */
    public function withLock($installId, $fn)
    {
        if (!$this->isValidId($installId)) {
            return null;
        }
        $file = $this->baseDir . $installId . '.json';
        if (!is_file($file)) {
            return null;
        }

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return null;
        }

        $state = null;
        try {
            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                return null;
            }
            rewind($fp);
            $raw = stream_get_contents($fp);
            if ($raw === false || $raw === '') {
                flock($fp, LOCK_UN);
                fclose($fp);
                return null;
            }
            $state = json_decode($raw, true);
            if (!is_array($state)) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return null;
            }

            $updatedAt = isset($state['updated_at']) ? (int) $state['updated_at'] : 0;
            if ($updatedAt && $updatedAt + self::TTL_SECONDS < time()) {
                flock($fp, LOCK_UN);
                fclose($fp);
                @unlink($file);
                return null;
            }

            $newState = call_user_func($fn, $state);
            if (!is_array($newState)) {
                $newState = $state;
            }
            $newState['install_id'] = $installId;
            $newState['updated_at'] = time();

            $encoded = json_encode($newState, JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                $encoded = '{}';
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $encoded);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            return $newState;
        } catch (\Exception $e) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
            throw $e;
        }
    }

    /**
     * 追加一条日志到 state 数组（不写盘，调用方决定）。
     *
     * @param array $state
     * @param string $step
     * @param string $level info|warn|error
     * @param string $text
     * @return array
     */
    public function pushLog(array $state, $step, $level, $text)
    {
        if (!isset($state['logs']) || !is_array($state['logs'])) {
            $state['logs'] = array();
        }
        $state['logs'][] = array(
            'step' => (string) $step,
            'level' => (string) $level,
            'text' => (string) $text,
            'time' => time(),
        );
        return $state;
    }

    /**
     * 判断 install_id 形态是否合法（小写十六进制，长度 ≤ 64）。
     *
     * @param string $installId
     * @return bool
     */
    public function isValidId($installId)
    {
        $installId = (string) $installId;
        if ($installId === '' || strlen($installId) > 64) {
            return false;
        }
        return (bool) preg_match('/^[a-f0-9]+$/', $installId);
    }

    /**
     * 确保会话目录存在且可写。
     *
     * @return void
     */
    private function ensureDir()
    {
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0777, true);
        }
    }

    /**
     * 清理过期会话文件（best-effort，失败不抛错）。
     *
     * @return void
     */
    private function gcExpired()
    {
        $threshold = time() - self::TTL_SECONDS;
        $items = @scandir($this->baseDir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (substr($name, -5) !== '.json') {
                continue;
            }
            $file = $this->baseDir . $name;
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $threshold) {
                @unlink($file);
            }
        }
    }

    /**
     * 写盘（覆盖），JSON_UNESCAPED_UNICODE 以保留中文日志。
     *
     * @param string $installId
     * @param array $state
     * @return void
     */
    private function writeFile($installId, array $state)
    {
        $encoded = json_encode($state, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = '{}';
        }
        $file = $this->baseDir . $installId . '.json';
        @file_put_contents($file, $encoded, LOCK_EX);
    }
}
