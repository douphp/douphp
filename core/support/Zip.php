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

namespace Dou\Core\Support;

use Dou\Core\Foundation\Container\Container;
use Dou\Vendor\PclZip\PclZip;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * ZIP 归档能力工具。
 *
 * 业务侧用法：
 *   Zip::extract($zipPath, $destinationDir);
 *   $rv = Zip::create($zipPath, $paths, $removePath, $error);
 */
class Zip
{
    /**
     * 解压 zip 到目标目录。
     *
     * 优先使用原生 ZipArchive 扩展（C 实现，解压速度快、内存占用低，避免大包
     * 在低配服务器上解压超时触发网关 502）；扩展不可用时回落 PclZip。
     *
     * 使用 `@` 抑制解压库在损坏包等场景下的 Notice，避免污染 JSON 安装步骤响应。
     *
     * @param string $zipPath
     * @param string $destinationDir
     * @param array $allowRules 条目白名单（glob 形式，如 'storage/backup/*.sql'、'images/**'）。
     *                          空数组 = 不限定条目种类，仅做 Zip Slip 防护。
     * @param array $denyExtensions 禁止落盘的扩展名（大小写不敏感），命中即整包拒绝。
     * @return bool
     */
    public function extract($zipPath, $destinationDir, array $allowRules = array(), array $denyExtensions = array())
    {
        if (class_exists('ZipArchive')) {
            return $this->extractWithZipArchive($zipPath, $destinationDir, $allowRules, $denyExtensions);
        }

        return $this->extractWithPclZip($zipPath, $destinationDir, $allowRules, $denyExtensions);
    }

    /**
     * 原生 ZipArchive 解压。
     *
     * 与 PclZip 路径共享同一套条目校验（Zip Slip / 白名单 / 禁止扩展名）。
     *
     * @param string $zipPath
     * @param string $destinationDir
     * @param array $allowRules
     * @param array $denyExtensions
     * @return bool
     */
    private function extractWithZipArchive($zipPath, $destinationDir, array $allowRules, array $denyExtensions)
    {
        $archive = new \ZipArchive();
        if (@$archive->open($zipPath) !== true) {
            return false;
        }

        $names = array();
        $count = (int) $archive->numFiles;
        for ($i = 0; $i < $count; $i++) {
            $name = (string) $archive->getNameIndex($i);
            if ($name === '') {
                $archive->close();
                return false;
            }
            $names[] = $name;
        }
        if (!$this->entriesPass($names, $allowRules, $denyExtensions)) {
            $archive->close();
            return false;
        }

        if (!is_dir($destinationDir)) {
            @mkdir($destinationDir, 0777, true);
            if (!is_dir($destinationDir)) {
                $archive->close();
                return false;
            }
        }

        $result = @$archive->extractTo($destinationDir);
        $archive->close();

        return (bool) $result;
    }

    /**
     * PclZip 解压（ZipArchive 扩展不可用时的兜底路径）。
     *
     * @param string $zipPath
     * @param string $destinationDir
     * @param array $allowRules
     * @param array $denyExtensions
     * @return bool
     */
    private function extractWithPclZip($zipPath, $destinationDir, array $allowRules, array $denyExtensions)
    {
        $archive = Container::getInstance()->make(PclZip::class, array('p_zipname' => $zipPath));

        // Zip Slip 防护：解压前枚举条目，拒绝任何越出目标目录的路径
        // （绝对路径 / 盘符 / 含 `..` 穿越片段），命中即整体拒绝解压。
        $list = @$archive->listContent();
        if (!is_array($list)) {
            return false;
        }
        $names = array();
        foreach ($list as $entry) {
            $names[] = isset($entry['filename']) ? (string) $entry['filename'] : '';
        }
        if (!$this->entriesPass($names, $allowRules, $denyExtensions)) {
            return false;
        }

        $result = @$archive->extract(PCLZIP_OPT_PATH, $destinationDir);
        if ($result === false || $result === 0) {
            return false;
        }
        if (is_array($result) && count($result) === 0) {
            return false;
        }

        return true;
    }

    /**
     * 逐条目执行解压前校验，任一条目不通过即整包拒绝。
     *
     * @param array $names 归档内 stored filename 列表
     * @param array $allowRules 条目白名单
     * @param array $denyExtensions 禁止的扩展名
     * @return bool
     */
    private function entriesPass(array $names, array $allowRules, array $denyExtensions)
    {
        foreach ($names as $name) {
            if ($this->isUnsafeEntryPath($name)) {
                return false;
            }
            if (!empty($allowRules) && !$this->isAllowedEntryPath($name, $allowRules)) {
                return false;
            }
            if (!empty($denyExtensions) && $this->hasDeniedExtension($name, $denyExtensions)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 判断归档条目路径是否越出目标目录（Zip Slip）。
     *
     * @param string $name 归档内 stored filename
     * @return bool
     */
    private function isUnsafeEntryPath($name)
    {
        if ($name === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $name);

        // 绝对路径（/etc/passwd）
        if (isset($normalized[0]) && $normalized[0] === '/') {
            return true;
        }
        // 盘符前缀（C:\、D:/）
        if (preg_match('#^[A-Za-z]:#', $normalized)) {
            return true;
        }
        // 任意 `..` 穿越片段
        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断归档条目是否命中白名单。
     *
     * 规则为 glob 形式：`*` 匹配单层内任意字符（不跨 `/`），`**` 匹配任意层级。
     * 目录条目（以 `/` 结尾）只要落在某条规则的目录前缀下即放行，便于恢复 `images/` 的多级子目录。
     *
     * @param string $name 归档内 stored filename
     * @param array $rules 白名单规则
     * @return bool
     */
    private function isAllowedEntryPath($name, array $rules)
    {
        $normalized = ltrim(str_replace('\\', '/', $name), '/');
        if ($normalized === '') {
            return true;
        }

        $isDir = substr($normalized, -1) === '/';
        $trimmed = rtrim($normalized, '/');

        foreach ($rules as $rule) {
            $rule = ltrim(str_replace('\\', '/', (string) $rule), '/');
            if ($rule === '') {
                continue;
            }

            if ($this->matchGlob($trimmed, $rule)) {
                return true;
            }

            // 目录条目：命中规则所在目录树即可，交由其下的文件条目各自受规则约束。
            if ($isDir) {
                $ruleDir = rtrim(preg_replace('#/[^/]*$#', '', $rule), '/');
                if ($ruleDir !== '' && ($trimmed === $ruleDir || strpos($trimmed . '/', $ruleDir . '/') === 0)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 判断归档条目的扩展名是否落在禁止清单内。
     *
     * 同时检查完整文件名，覆盖 `.htaccess` 这类无主名的点文件。
     *
     * @param string $name 归档内 stored filename
     * @param array $denyExtensions 禁止的扩展名
     * @return bool
     */
    private function hasDeniedExtension($name, array $denyExtensions)
    {
        $basename = strtolower(basename(str_replace('\\', '/', $name)));
        if ($basename === '') {
            return false;
        }

        $extension = strtolower((string) pathinfo($basename, PATHINFO_EXTENSION));
        foreach ($denyExtensions as $denied) {
            $denied = strtolower(ltrim(trim((string) $denied), '.'));
            if ($denied === '') {
                continue;
            }
            if ($extension === $denied || $basename === '.' . $denied || $basename === $denied) {
                return true;
            }
        }

        return false;
    }

    /**
     * glob 规则匹配（`**` 跨层级，`*` 限单层）。
     *
     * @param string $path
     * @param string $rule
     * @return bool
     */
    private function matchGlob($path, $rule)
    {
        $pattern = preg_quote($rule, '#');
        $pattern = str_replace('\*\*', '__DOU_GLOBSTAR__', $pattern);
        $pattern = str_replace('\*', '[^/]*', $pattern);
        $pattern = str_replace('__DOU_GLOBSTAR__', '.*', $pattern);

        return (bool) preg_match('#^' . $pattern . '$#i', $path);
    }

    /**
     * 创建 zip（按 remove path 规则写入归档）。
     *
     * @param string $zipPath
     * @param array $paths
     * @param string $removePath
     * @param string $errorInfo 出参：失败时写入 PclZip 错误信息
     * @return int PclZip::create 返回值（0 表示失败）
     */
    public function create($zipPath, array $paths, $removePath, &$errorInfo = '')
    {
        $archive = Container::getInstance()->make(PclZip::class, array('p_zipname' => $zipPath));
        $response = $archive->create($paths, PCLZIP_OPT_REMOVE_PATH, $removePath);
        if ($response == 0) {
            $errorInfo = $archive->errorInfo(true);
        }

        return $response;
    }
}
