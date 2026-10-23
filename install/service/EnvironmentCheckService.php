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

namespace Dou\Install\Service;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 环境与目录权限检测。
 */
class EnvironmentCheckService
{
    /** @var array */
    private $lang;

    /** @var array 安装时需确保存在的运行时目录（缺失会自动创建；storage 其余子目录按磁盘实况递归补查） */
    private $checkDirs = array(
        'storage',
        'storage/cache',
        'storage/cache/template',
        'storage/cache/template/admin',
        'storage/state',
        'storage/state/throttle',
        'storage/backup',
        'storage/log',
        'storage/log/front',
        'storage/log/admin',
        'storage/log/api',
        'images/slide',
        'images/article',
        'images/product',
        'images/upload',
    );

    /**
     * @param array $lang
     */
    public function __construct(array $lang)
    {
        $this->lang = $lang;
    }

    /**
     * 当前运行时 PHP 是否满足最低版本要求。
     *
     * 最低版本取自 install/version.php 的 php_min（单一来源，与安装入口门禁同源）。
     *
     * @return bool
     */
    public function isPhpVersionOk()
    {
        return version_compare(PHP_VERSION, douphp_install_min_php(), '>=');
    }

    /**
     * 采集系统信息（OS / Web 服务器 / PHP 版本 / mysqli / GD / zlib / 时区 / socket）。
     *
     * @return array
     */
    public function collectSystemInfo()
    {
        $phpOk = $this->isPhpVersionOk();

        return array(
            'os' => PHP_OS,
            'web_server' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '',
            'php_ver' => PHP_VERSION,
            'php_ok' => $phpOk,
            'mysql_ver' => extension_loaded('mysqli') ? $this->lang['yes'] : $this->lang['no'],
            'zlib' => function_exists('gzclose') ? $this->lang['yes'] : $this->lang['no'],
            'timezone' => function_exists('date_default_timezone_get') ? date_default_timezone_get() : $this->lang['no_timezone'],
            'socket' => function_exists('fsockopen') ? $this->lang['yes'] : $this->lang['no'],
            'gd' => extension_loaded('gd') ? $this->lang['yes'] : $this->lang['no'],
        );
    }

    /**
     * 检查目录可写性；不存在的目录会尝试自动创建。
     *
     * 静态清单逐项检测后，再按磁盘实况递归补查 storage/ 下全部子目录
     * （部分目录运行时才生成，先判断存在，已检过的跳过）。
     *
     * @return array array('writeable' => array<array{dir,if_write}>, 'no_write' => bool)
     */
    public function collectWriteableInfo()
    {
        $writeable = array();
        $no_write = false;

        foreach ($this->checkDirs as $dir) {
            $this->appendDirState($dir, $writeable, $no_write);
        }

        foreach ($this->collectStorageSubDirs(ROOT_PATH . 'storage/') as $sub) {
            $dir = 'storage/' . $sub;
            if (in_array($dir, $this->checkDirs)) {
                continue;
            }
            $this->appendDirState($dir, $writeable, $no_write);
        }

        return array('writeable' => $writeable, 'no_write' => $no_write);
    }

    /**
     * 检测单个目录并追加结果行（缺失时先尝试自动创建）。
     *
     * @param string $dir 相对站点根的目录
     * @param array $writeable 结果行累积
     * @param bool $no_write 是否存在不可写项（引用传入）
     * @return void
     */
    private function appendDirState($dir, array &$writeable, &$no_write)
    {
        $full_dir = ROOT_PATH . $dir;
        if (!file_exists($full_dir)) {
            @mkdir($full_dir, 0777, true);
        }

        $state = $this->dirState($full_dir);
        if ($state === 1) {
            $if_write = "<b class='write'>" . $this->lang['write'] . '</b>';
        } elseif ($state === 0) {
            $if_write = "<b class='noWrite'>" . $this->lang['no_write'] . '</b>';
            $no_write = true;
        } else {
            $if_write = "<b class='noWrite'>" . $this->lang['not_exist'] . '</b>';
            $no_write = true;
        }

        $writeable[] = array(
            'dir' => $dir,
            'if_write' => $if_write,
        );
    }

    /**
     * 递归收集目录下全部已存在的子目录（相对路径，/ 分隔，按名排序）。
     *
     * @param string $base 绝对路径（以 / 结尾）
     * @param string $prefix 递归时拼在返回值前的相对前缀
     * @return array 子目录相对路径列表（不含 $base 本身）
     */
    private function collectStorageSubDirs($base, $prefix = '')
    {
        $dirs = array();
        foreach ((array) glob($base . '*', GLOB_ONLYDIR | GLOB_NOSORT) as $entry) {
            $name = basename($entry);
            $rel = $prefix === '' ? $name : $prefix . '/' . $name;
            $dirs[] = $rel;
            $dirs = array_merge($dirs, $this->collectStorageSubDirs($entry . '/', $rel));
        }
        sort($dirs);

        return $dirs;
    }

    /**
     * 判断目录或文件是否可写。
     *
     * @param string $file
     * @return int 1 可写；0 不可写；2 不存在
     */
    private function dirState($file)
    {
        if (!file_exists($file)) {
            return 2;
        }
        if (is_dir($file)) {
            if ($fp = @fopen($file . '/__dou_test.txt', 'w')) {
                @fclose($fp);
                @unlink($file . '/__dou_test.txt');
                return 1;
            }
            return 0;
        }
        if ($fp = @fopen($file, 'a+')) {
            @fclose($fp);
            return 1;
        }
        return 0;
    }
}
