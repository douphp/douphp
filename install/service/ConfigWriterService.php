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

use Dou\Core\Service\Config\ConfigFileTemplate;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 写入 config/config.php。
 *
 * 字段与主站 [core/bootstrap.php](core/bootstrap.php) 期望一致：
 *   $dbhost / $dbname / $dbuser / $dbpass / $prefix
 *   DOU_CHARSET / SYSTEM_SIGN / ADMIN_DIR / API_DIR / MINIPROGRAM_DIR / ADMIN_REWRITE / DOU_APP_KEY / DOU_DEBUG
 */
class ConfigWriterService
{
    /** @var string */
    private $configPath;

    /**
     * @param string|null $configPath 不传则使用默认 ROOT_PATH/config/config.php
     */
    public function __construct($configPath = null)
    {
        $this->configPath = $configPath !== null ? $configPath : (ROOT_PATH . 'config/config.php');
    }

    /**
     * 写入 config.php。
     *
     * 版式由 {@see ConfigFileTemplate} 统一渲染（与 upgrade/_update 的 config.php 基线一致），
     * 本类只负责取值与默认值，不再自带模板，避免多处模板漂移。
     *
     * @param array $data 必填：dbhost/dbname/dbuser/dbpass/prefix；可选：charset/system_sign/admin_dir/api_dir/miniprogram_dir/admin_rewrite/app_key/dou_debug
     * @return bool
     */
    public function write(array $data)
    {
        $dbhost = isset($data['dbhost']) ? (string) $data['dbhost'] : '';
        $dbname = isset($data['dbname']) ? (string) $data['dbname'] : '';
        $dbuser = isset($data['dbuser']) ? (string) $data['dbuser'] : '';
        $dbpass = isset($data['dbpass']) ? (string) $data['dbpass'] : '';
        $prefix = isset($data['prefix']) ? (string) $data['prefix'] : 'dou_';
        $charset = isset($data['charset']) ? (string) $data['charset'] : 'utf-8';
        $systemSign = isset($data['system_sign']) ? (string) $data['system_sign'] : 'company';
        $adminDir = isset($data['admin_dir']) ? (string) $data['admin_dir'] : 'admin';
        $apiDir = isset($data['api_dir']) ? (string) $data['api_dir'] : 'api';
        $miniprogramDir = isset($data['miniprogram_dir']) ? (string) $data['miniprogram_dir'] : 'miniprogram';
        $adminRewrite = isset($data['admin_rewrite']) ? (bool) $data['admin_rewrite'] : false;
        $appKey = isset($data['app_key']) ? trim((string) $data['app_key']) : '';
        if ($appKey === '') {
            $appKey = Str::randomHex(32);
        }
        $debug = isset($data['dou_debug']) ? (bool) $data['dou_debug'] : false;

        $content = ConfigFileTemplate::render(array(
            'dbhost' => $dbhost,
            'dbname' => $dbname,
            'dbuser' => $dbuser,
            'dbpass' => $dbpass,
            'prefix' => $prefix,
            'charset' => $charset,
            'system_sign' => $systemSign,
            'admin_dir' => $adminDir,
            'api_dir' => $apiDir,
            'miniprogram_dir' => $miniprogramDir,
            'admin_rewrite' => $adminRewrite,
            'app_key' => $appKey,
            'dou_debug' => $debug,
        ));

        return file_put_contents($this->configPath, $content) !== false;
    }
}
