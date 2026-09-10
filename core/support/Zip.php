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
     * 使用 `@` 抑制 PclZip 在损坏包等场景下的 Notice，避免污染 JSON 安装步骤响应。
     *
     * @param string $zipPath
     * @param string $destinationDir
     * @return bool
     */
    public function extract($zipPath, $destinationDir)
    {
        $archive = Container::getInstance()->make(PclZip::class, array('p_zipname' => $zipPath));

        // Zip Slip 防护：解压前枚举条目，拒绝任何越出目标目录的路径
        // （绝对路径 / 盘符 / 含 `..` 穿越片段），命中即整体拒绝解压。
        $list = @$archive->listContent();
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $entry) {
            $name = isset($entry['filename']) ? (string) $entry['filename'] : '';
            if ($this->isUnsafeEntryPath($name)) {
                return false;
            }
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
