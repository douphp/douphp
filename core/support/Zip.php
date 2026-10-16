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
 *   $result = Zip::create($zipPath, $paths, $removePath);  // array('ok','response','error')
 *
 * 注：凡经静态门面调用的方法一律以返回值传错（不用引用出参）——门面经
 * call_user_func_array 转发，引用参数在 PHP 7.4/8 会告警且不回填。
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
     * @return array array('ok' => bool, 'response' => int, 'error' => string)
     */
    public function create($zipPath, array $paths, $removePath)
    {
        $archive = Container::getInstance()->make(PclZip::class, array('p_zipname' => $zipPath));
        $response = $archive->create($paths, PCLZIP_OPT_REMOVE_PATH, $removePath);
        if ($response == 0 || $response === false) {
            return array('ok' => false, 'response' => 0, 'error' => $archive->errorInfo(true));
        }

        return array(
            'ok' => true,
            'response' => is_array($response) ? count($response) : (int) $response,
            'error' => '',
        );
    }

    /**
     * 追加一批文件到 zip（不存在则创建）；归档内条目名 = 绝对路径去掉 removePath 前缀。
     *
     * 快照创建按批续传依赖本方法：ZipArchive 增量 addFile 为 O(批)，PclZip add 为兜底。
     * 结果以数组返回（而非引用出参）：静态门面经 call_user_func_array 转发，引用出参
     * 在 PHP 8 会告警且不回填，故此类批量方法一律走「返回值传错」。
     *
     * @param string $zipPath
     * @param array $absolutePaths 绝对路径列表（同一次调用内需同一 removePath 根）
     * @param string $removePath 条目名剥离前缀（通常为 ROOT_PATH）
     * @return array array('ok' => bool, 'error' => string)
     */
    public function append($zipPath, array $absolutePaths, $removePath)
    {
        $absolutePaths = array_values(array_filter((array) $absolutePaths, 'is_file'));
        if (!$absolutePaths) {
            return array('ok' => true, 'error' => '');
        }

        if (class_exists('ZipArchive')) {
            $archive = new \ZipArchive();
            if (@$archive->open($zipPath, \ZipArchive::CREATE) !== true) {
                return array('ok' => false, 'error' => 'zip open failed: ' . $zipPath);
            }
            foreach ($absolutePaths as $absPath) {
                $localName = $this->relativeEntryName($absPath, $removePath);
                if ($localName === '' || @$archive->addFile($absPath, $localName) === false) {
                    @$archive->close();
                    return array('ok' => false, 'error' => 'zip addFile failed: ' . $absPath);
                }
            }
            @$archive->close();

            return array('ok' => true, 'error' => '');
        }

        $archive = Container::getInstance()->make(PclZip::class, array('p_zipname' => $zipPath));
        $response = @$archive->add($absolutePaths, PCLZIP_OPT_REMOVE_PATH, $removePath);
        if ($response == 0) {
            return array('ok' => false, 'error' => $archive->errorInfo(true));
        }

        return array('ok' => true, 'error' => '');
    }

    /**
     * 按条目序号区间解压（快照还原按批续传依赖本方法）。
     *
     * 区间以归档当前条目顺序为准（同一文件内顺序稳定），offset 从 0 计。
     * 结果以数组返回（理由同 {@see append()}）。
     *
     * @param string $zipPath
     * @param string $destinationDir
     * @param int $offset 起始条目序号
     * @param int $limit 本批最多条目数
     * @return array array('ok' => bool, 'count' => int, 'total' => int, 'error' => string)
     *               count=0 表示已到尾部
     */
    public function extractRange($zipPath, $destinationDir, $offset, $limit)
    {
        $offset = max(0, (int) $offset);
        $limit = max(1, (int) $limit);

        if (class_exists('ZipArchive')) {
            $archive = new \ZipArchive();
            if (@$archive->open($zipPath) !== true) {
                return array('ok' => false, 'count' => 0, 'total' => 0, 'error' => 'zip open failed: ' . $zipPath);
            }
            $total = (int) $archive->numFiles;
            $names = array();
            $end = min($offset + $limit, $total);
            for ($i = $offset; $i < $end; $i++) {
                $name = $archive->getNameIndex($i);
                if ($name === false || $name === '') {
                    $archive->close();
                    return array('ok' => false, 'count' => 0, 'total' => $total, 'error' => 'zip entry read failed');
                }
                $names[] = $name;
            }
            if (!$names) {
                $archive->close();
                return array('ok' => true, 'count' => 0, 'total' => $total, 'error' => '');
            }
            if (!is_dir($destinationDir)) {
                @mkdir($destinationDir, 0777, true);
            }
            $result = @$archive->extractTo($destinationDir, $names);
            if ($result === false) {
                // Windows 文件锁（杀软/句柄占用）等导致批量解压失败：逐条流写 + 三步替换兜底
                $failed = $this->extractNamesManually($archive, $names, $destinationDir);
                $archive->close();
                if ($failed > 0) {
                    return array('ok' => false, 'count' => 0, 'total' => $total, 'error' => 'extractTo failed, manual failed: ' . $failed);
                }

                return array('ok' => true, 'count' => count($names), 'total' => $total, 'error' => '');
            }
            $archive->close();

            return array('ok' => true, 'count' => count($names), 'total' => $total, 'error' => '');
        }

        $archive = Container::getInstance()->make(PclZip::class, array('p_zipname' => $zipPath));
        $list = @$archive->listContent();
        if (!is_array($list)) {
            return array('ok' => false, 'count' => 0, 'total' => 0, 'error' => 'zip open failed: ' . $zipPath);
        }
        $total = count($list);
        $end = min($offset + $limit, $total) - 1;
        if ($end < $offset) {
            return array('ok' => true, 'count' => 0, 'total' => $total, 'error' => '');
        }
        // 区间必须用 array 形式传：PclZip 内部守卫是 `!= 0`，而 PHP 5.6/7.x 对
        // 「字符串 vs 整数」按数值比较——"0-49" 会被转成 0 判假，首个批次（offset=0）
        // 整个跳过 by-index 分支、一条都不解压，却返回成功（还原静默不完整）。
        // array 与 0 比较在 5.6~8.x 恒为 true。
        $result = @$archive->extract(PCLZIP_OPT_PATH, $destinationDir, PCLZIP_OPT_BY_INDEX, array($offset . '-' . $end));
        if (!is_array($result)) {
            return array('ok' => false, 'count' => 0, 'total' => $total, 'error' => 'pclzip extract failed');
        }

        return array('ok' => true, 'count' => count($result), 'total' => $total, 'error' => '');
    }

    /**
     * 统计 zip 内条目数。
     *
     * @param string $zipPath
     * @return int|false
     */
    public function count($zipPath)
    {
        if (class_exists('ZipArchive')) {
            $archive = new \ZipArchive();
            if (@$archive->open($zipPath) !== true) {
                return false;
            }
            $total = (int) $archive->numFiles;
            $archive->close();

            return $total;
        }

        $archive = Container::getInstance()->make(PclZip::class, array('p_zipname' => $zipPath));
        $list = @$archive->listContent();

        return is_array($list) ? count($list) : false;
    }

    /**
     * 绝对路径 → 归档条目名（去掉 removePath 前缀，统一 / 分隔）。
     *
     * @param string $absPath
     * @param string $removePath
     * @return string
     */
    private function relativeEntryName($absPath, $removePath)
    {
        $abs = str_replace('\\', '/', (string) $absPath);
        $root = str_replace('\\', '/', (string) $removePath);
        if ($root !== '' && strpos($abs, $root) === 0) {
            $abs = substr($abs, strlen($root));
        }

        return ltrim($abs, '/');
    }

    /**
     * 逐条流写解压（批量 extractTo 失败时的兜底；经临时文件 + {@see FileHelper::replace()} 容忍文件锁）。
     *
     * @param \ZipArchive $archive 已打开的归档
     * @param array $names 本批条目名
     * @param string $destinationDir
     * @return int 失败条目数（0=全部成功）
     */
    private function extractNamesManually($archive, array $names, $destinationDir)
    {
        $destRoot = rtrim(str_replace('\\', '/', (string) $destinationDir), '/');
        $failed = 0;
        foreach ($names as $name) {
            $normalized = ltrim(str_replace('\\', '/', (string) $name), '/');
            $target = $destRoot . '/' . $normalized;
            if (substr($normalized, -1) === '/') {
                if (!is_dir($target)) {
                    @mkdir($target, 0777, true);
                }
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent)) {
                @mkdir($parent, 0777, true);
            }
            $stream = @$archive->getStream($name);
            if (!$stream) {
                $failed++;
                continue;
            }
            $content = stream_get_contents($stream);
            fclose($stream);
            $tmp = $target . '.dou_tmp_' . mt_rand(1000, 9999);
            if (@file_put_contents($tmp, $content) === false || !FileHelper::replace($tmp, $target)) {
                @unlink($tmp);
                $failed++;
            }
        }

        return $failed;
    }
}
