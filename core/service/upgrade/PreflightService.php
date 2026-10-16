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

namespace Dou\Core\Service\Upgrade;

use Dou\Core\Facade\DB;
use Dou\Core\Support\FileHelper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 升级前置检查（双端统一，基本盘）。
 *
 * 检查项（只做可验证的基础项，不做无上限检查）：
 *   1. 目录权限全量写测：站点根 + 关键目录（含 storage 各子目录；临时文件探测，Windows 友好）；
 *   2. PHP 硬性下限（≥5.6.0）与必需扩展（zip/mysqli；openssl 缺失降级为警告）；
 *   3. PHP × MySQL 组合判定（口径同 devtools/mysql-compat-check：blocked 拒绝、limited 警告）；
 *   4. 磁盘余量参考提示（尽力而为：disk_free_space 对虚拟主机是服务器分区而非账号配额、数值不可信，
 *      仅作参考提示，绝不阻断；真正的护栏是升级各写点的「写入失败即中止并清理」）。
 *
 * 结果：blocked/必需项缺失 → errors（调用方 fail-loud）；limited/提示 → warnings/notices（进日志不阻断）。
 */
class PreflightService
{
    /**
     * 执行前置检查。
     *
     * @param string $rootPath  站点根目录（结尾带 /）
     * @param array  $aliasDirs 可选：自定义后台/接口/小程序目录名（不存在自动跳过）
     * @return array array('ok' => bool, 'errors' => string[], 'warnings' => string[], 'notices' => string[])
     */
    public function check($rootPath, array $aliasDirs = array())
    {
        $errors = array();
        $warnings = array();
        $notices = array();

        // 1) 目录权限全量写测
        $bad = $this->unwriteableDirs($rootPath, $aliasDirs);
        if ($bad !== array()) {
            $errors[] = '以下目录没有写入权限：' . implode('、', $bad)
                . '。升级会全量覆盖站点文件，请将站点根目录及其全部子目录设置为可写后重试。';
        }

        // 2) PHP 硬性下限与必需扩展
        if (version_compare(PHP_VERSION, '5.6.0', '<')) {
            $errors[] = '当前 PHP ' . PHP_VERSION . ' 低于最低要求 5.6.0';
        }
        if (!class_exists('ZipArchive')) {
            $errors[] = '缺少 PHP zip 扩展（ZipArchive），无法解压升级包';
        }
        if (!function_exists('mysqli_connect')) {
            $errors[] = '缺少 PHP mysqli 扩展';
        }
        if (!extension_loaded('openssl')) {
            $warnings[] = '未启用 PHP openssl 扩展：HTTPS 下载与签名校验类能力可能不可用';
        }

        // 3) PHP × MySQL 组合判定
        if (class_exists('Dou\\Core\\Service\\Upgrade\\EnvCompatService')) {
            $env = new EnvCompatService();
            $verdict = $env->evaluate();
            if ($verdict['status'] === 'blocked') {
                foreach ($verdict['reasons'] as $reason) {
                    $errors[] = $reason;
                }
            } elseif ($verdict['status'] === 'limited') {
                foreach ($verdict['reasons'] as $reason) {
                    $warnings[] = $reason;
                }
            }
        }

        // 4) 磁盘余量参考提示（不阻断）
        $notices[] = $this->diskHint($rootPath);

        return array(
            'ok' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'notices' => $notices,
        );
    }

    /**
     * 全量关键目录写测：返回不可写目录清单（不存在的目录跳过）。
     *
     * 判定不以 is_writable 为准：Windows 上站点根常被判为不可写，但 PHP 实际可以创建文件，
     * 统一用 {@see FileHelper::permission()}（临时文件探测）。
     *
     * @param string $rootPath
     * @param array  $aliasDirs
     * @return string[] 不可写目录的相对名（'' 显示为「站点根目录」）
     */
    public function unwriteableDirs($rootPath, array $aliasDirs = array())
    {
        $rootPath = rtrim(str_replace(array('\\', '/'), array('/', '/'), (string) $rootPath), '/') . '/';

        $names = array(
            '', 'core', 'admin', 'api', 'front', 'plugin', 'theme', 'languages',
            'images', 'miniprogram', 'storage', 'config',
            'storage/cache', 'storage/log', 'storage/state', 'storage/install',
            'storage/install/session', 'storage/install/package', 'storage/backup', 'storage/snapshot',
        );
        foreach ($aliasDirs as $alias) {
            $alias = trim((string) $alias, '/');
            if ($alias !== '' && !in_array($alias, $names, true)) {
                $names[] = $alias;
            }
        }

        $failed = array();
        foreach ($names as $name) {
            $dir = $name === '' ? $rootPath : $rootPath . $name . '/';
            if (!file_exists($dir)) {
                continue;
            }
            if (FileHelper::permission($dir) !== 'write') {
                $failed[] = $name === '' ? '站点根目录' : $name;
            }
        }

        return array_values(array_unique($failed));
    }

    /**
     * 磁盘余量参考提示（估算 = 代码目录体积 + 数据库体积 + 20% 余量）。
     *
     * @param string $rootPath
     * @return string
     */
    private function diskHint($rootPath)
    {
        $rootPath = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';

        $free = false;
        if (function_exists('disk_free_space')) {
            $free = @disk_free_space($rootPath);
        }

        // 代码目录体积（排除 images/ 与 storage/ 动态目录，它们是体积大头且不进升级快照）
        $codeBytes = 0;
        foreach (array('core', 'admin', 'api', 'front', 'plugin', 'theme', 'languages', 'miniprogram', 'config') as $dir) {
            $codeBytes += $this->dirSize($rootPath . $dir);
        }

        // 数据库体积（Data_length + Index_length）
        $dbBytes = 0;
        $rows = DB::fnQuery('SHOW TABLE STATUS');
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $dbBytes += isset($row['Data_length']) ? (int) $row['Data_length'] : 0;
                $dbBytes += isset($row['Index_length']) ? (int) $row['Index_length'] : 0;
            }
        }

        $estimate = (int) (($codeBytes + $dbBytes) * 1.2);
        $text = '升级参考提示：预计占用磁盘约 ' . $this->formatBytes($estimate)
            . '（代码 ' . $this->formatBytes($codeBytes) . ' + 数据库 ' . $this->formatBytes($dbBytes) . '，含约 20% 余量）';

        if ($free !== false && $free > 0) {
            $text .= '；当前磁盘可用 ' . $this->formatBytes((int) $free) . '。';
            if ($free < $estimate) {
                $text .= '可用空间可能不足，建议先清理磁盘（提示仅供参考，不阻断升级）。';
            }
        } else {
            $text .= '；服务器未提供磁盘余量（虚拟主机常见），无法检测。';
        }

        return $text;
    }

    /**
     * 递归统计目录体积（静默容错，失败按 0 计）。
     *
     * @param string $dir
     * @return int 字节
     */
    private function dirSize($dir)
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $size = 0;
        foreach ((array) @scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $size += $this->dirSize($path);
            } elseif (is_file($path)) {
                $fileSize = @filesize($path);
                if ($fileSize !== false) {
                    $size += (int) $fileSize;
                }
            }
        }
        return $size;
    }

    /**
     * 字节数格式化（提示文案用）。
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return $bytes . ' B';
    }
}
