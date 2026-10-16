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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 升级版本发现与解析（双端统一）。
 *
 * 从「升级物料根」的 resources/patches/upgrade_*.php、resources/txt/upgrade_*.txt 两个目录里
 * 解析出版本号集合（并集），按数值升序返回；changelog（txt）与升级执行（php）共用同一份列表。
 * 历史 upgrade_*.sql 已作废（纯结构操作移交 SchemaSync 标准同步，字段改名/值迁移并入对应
 * 版本的 upgrade_*.php），不再参与版本发现。
 *
 * 目标版本为单一来源：物料根 version.php 的 version 字段（兼容读取 UPGRADE_TARGET_VERSION 常量）。
 */
class VersionRegistry
{
    /** @var string 升级物料根（结尾带 /） */
    private $materialsRoot;

    /** @var string */
    private $phpDir;

    /** @var string */
    private $txtDir;

    /**
     * @param string $materialsRoot 升级物料根目录（U_PATH / UPGRADE_PATH）
     */
    public function __construct($materialsRoot)
    {
        $this->materialsRoot = rtrim(str_replace('\\', '/', (string) $materialsRoot), '/') . '/';
        $this->phpDir = $this->materialsRoot . 'resources/patches/';
        $this->txtDir = $this->materialsRoot . 'resources/txt/';
    }

    /**
     * 物料根 version.php 元信息（可含 version / php_min / mysql_min 等字段）。
     *
     * @return array
     */
    public function versionMeta()
    {
        $file = $this->materialsRoot . 'version.php';
        if (!is_file($file)) {
            return array();
        }
        $meta = include $file;

        return is_array($meta) ? $meta : array();
    }

    /**
     * 发版目标版本（单一来源：物料根 version.php 的 version 字段；兼容 UPGRADE_TARGET_VERSION 常量）。
     *
     * @return string
     */
    public function targetVersion()
    {
        $meta = $this->versionMeta();
        if (isset($meta['version']) && (string) $meta['version'] !== '') {
            return (string) $meta['version'];
        }

        return defined('UPGRADE_TARGET_VERSION') ? (string) UPGRADE_TARGET_VERSION : '';
    }

    /**
     * 发现所有版本（php / txt 文件名的并集），按数值升序。
     *
     * @return string[] 形如 array('1.0','1.1','1.2','1.3','1.5','1.6','1.7','1.8','1.9','2.0')
     */
    public function discover()
    {
        $versions = array();
        foreach (array($this->phpDir . '*.php', $this->txtDir . '*.txt') as $pattern) {
            $files = glob($pattern);
            if (!is_array($files)) {
                continue;
            }
            foreach ($files as $file) {
                $name = basename($file);
                if (preg_match('/^upgrade_([0-9]+(?:\.[0-9]+)?)\.[a-z]+$/i', $name, $m)) {
                    $versions[number_format((float) $m[1], 1)] = true;
                }
            }
        }
        $versions = array_keys($versions);
        usort($versions, function ($a, $b) {
            $diff = (float) $a - (float) $b;
            if ($diff < 0) {
                return -1;
            }
            if ($diff > 0) {
                return 1;
            }
            return 0;
        });

        return $versions;
    }

    /**
     * 从 dou_config.douphp_version 解析出当前版本号（如 '1.5'）。
     *
     * @param string $versionString 例如 'v1.5 Release 20240101'
     * @return string
     */
    public function parseCurrentVersion($versionString)
    {
        $versionString = (string) $versionString;
        if ($versionString === '') {
            return '1.0';
        }
        if (preg_match('/(\d+\.\d+)/', $versionString, $m)) {
            return number_format((float) $m[1], 1);
        }

        return '1.0';
    }

    /**
     * 从 'vX.Y Release YYYYMMDD' 解析出 Release 日期（'YYYYMMDD'）。
     * 末尾不含 8 位数字时返回 '00000000'，参与比较时被视为最早。
     *
     * @param string $versionString
     * @return string
     */
    public function parseReleaseDate($versionString)
    {
        $versionString = (string) $versionString;
        if ($versionString !== '' && preg_match('/(\d{8})\s*$/', $versionString, $m)) {
            return $m[1];
        }
        return '00000000';
    }

    /**
     * 以 (主次版本 X.Y, Release 日期 yyyymmdd) 元组语义比较两个版本字符串。
     * 仅按 X.Y 比较时 'v1.9 Release 20260313' 与 'v1.9 Release 20260516' 等价，
     * 会让"同 X.Y 但 Release 较新"的发布无法触发升级，因此本方法把日期纳入比较。
     *
     * @param string $a
     * @param string $b
     * @return int 负 / 0 / 正：a 早于 / 等同 / 晚于 b
     */
    public function compareVersionStrings($a, $b)
    {
        $majorA = (float) $this->parseCurrentVersion($a);
        $majorB = (float) $this->parseCurrentVersion($b);
        if ($majorA < $majorB) {
            return -1;
        }
        if ($majorA > $majorB) {
            return 1;
        }
        $dateA = (int) $this->parseReleaseDate($a);
        $dateB = (int) $this->parseReleaseDate($b);
        if ($dateA < $dateB) {
            return -1;
        }
        if ($dateA > $dateB) {
            return 1;
        }
        return 0;
    }

    /**
     * 过滤出 ver >= current 的版本。
     *
     * @param string[] $versions
     * @param string   $current
     * @return string[]
     */
    public function pending(array $versions, $current)
    {
        $cur = (float) $current;
        $out = array();
        foreach ($versions as $ver) {
            if ((float) $ver >= $cur) {
                $out[] = $ver;
            }
        }
        return $out;
    }

    /**
     * 单个版本的 PHP 文件绝对路径（不判断是否存在）。
     *
     * @param string $ver
     * @return string
     */
    public function phpFile($ver)
    {
        return $this->phpDir . 'upgrade_' . $ver . '.php';
    }

    /**
     * 单个版本的 txt（changelog）文件绝对路径（不判断是否存在）。
     *
     * @param string $ver
     * @return string
     */
    public function txtFile($ver)
    {
        return $this->txtDir . 'upgrade_' . $ver . '.txt';
    }
}
