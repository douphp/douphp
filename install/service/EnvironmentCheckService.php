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
 * 环境与目录权限检测，并根据 Web 服务器类型复制对应的伪静态规则文件到站点根。
 */
class EnvironmentCheckService
{
    /** @var array */
    private $lang;

    /** @var array 与 MVC 主站当前所需运行时目录一致 */
    private $checkDirs = array(
        'storage',
        'storage/cache/template',
        'storage/cache/template/admin',
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

    /** 与 install/init/php_version_gate.php、core/bootstrap.php 对齐的最低 PHP 版本 */
    const MIN_PHP_VERSION = '5.6.0';

    /**
     * 当前运行时 PHP 是否满足最低版本要求。
     *
     * @return bool
     */
    public function isPhpVersionOk()
    {
        return version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');
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
     * @return array array('writeable' => array<array{dir,if_write}>, 'no_write' => bool)
     */
    public function collectWriteableInfo()
    {
        $writeable = array();
        $no_write = false;

        foreach ($this->checkDirs as $dir) {
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

        return array('writeable' => $writeable, 'no_write' => $no_write);
    }

    /**
     * 根据 SERVER_SOFTWARE 选择并复制伪静态规则到站点根。
     *
     * @param string $webServer SERVER_SOFTWARE 字符串
     * @return string 实际复制的源文件名（空串表示未匹配到）
     */
    public function copyRewriteRule($webServer)
    {
        $rewrite_file = '';
        if (strpos($webServer, 'Apache') !== false) {
            $rewrite_file = '.htaccess.txt';
        } elseif (strpos($webServer, 'nginx') !== false) {
            $rewrite_file = 'nginx.txt';
        } elseif (strpos($webServer, 'IIS') !== false) {
            $iis_exp = explode('/', $webServer);
            $iis_ver = isset($iis_exp[1]) ? $iis_exp[1] : '';
            if ((float) $iis_ver >= 7.0) {
                $rewrite_file = 'web.config.txt';
            } else {
                $rewrite_file = 'httpd.ini.txt';
            }
        }

        if ($rewrite_file !== '') {
            $source = INSTALL_PATH . 'data/rewrite/' . $rewrite_file;
            $destination = ROOT_PATH . $rewrite_file;
            @copy($source, $destination);
        }

        return $rewrite_file;
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
