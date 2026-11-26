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

namespace Dou\Core\Service\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * config/config.php 版式模板（单一来源）。
 *
 * 需要整份重写站点配置文件的场景（安装、搬家 doubak）统一由此渲染，版式与
 * upgrade/resources/config/config.php、_update/resources/config/config.php 基线一致；
 * 新增配置项只改本类，调用方无需各自同步，避免升级后格式漂移。
 */
class ConfigFileTemplate
{
    /**
     * 渲染 config/config.php 全文。
     *
     * admin_rewrite / app_key / dou_debug 传 null 表示原文件本无该常量，对应行整体跳过，
     * 避免凭空补值：DOU_APP_KEY 会影响库里已加密数据的解密，ADMIN_REWRITE 会短路
     * 老站 config/system.php 的迁移链。
     *
     * @param array $data 键见 $defaults
     * @return string
     */
    public static function render(array $data)
    {
        $defaults = array(
            'dbhost' => '',
            'dbname' => '',
            'dbuser' => '',
            'dbpass' => '',
            'prefix' => 'dou_',
            'charset' => 'utf-8',
            'system_sign' => 'company',
            'admin_dir' => 'admin',
            'api_dir' => 'api',
            'miniprogram_dir' => 'miniprogram',
            'admin_rewrite' => null,
            'app_key' => null,
            'dou_debug' => null,
        );
        $data = array_merge($defaults, $data);

        $content = "<?php\n\n";
        $content .= "/**\n";
        $content .= " * DouPHP®\n";
        $content .= " * ------------------------------------------------------------------------------------\n";
        $content .= " * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)\n";
        $content .= " *\n";
        $content .= " * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。\n";
        $content .= " * 网站地址：http://www.douphp.com\n";
        $content .= " * ------------------------------------------------------------------------------------\n";
        $content .= " * Author: DouCo Co.,Ltd.\n";
        $content .= " * Release Date: 2026-09-08\n";
        $content .= " */\n\n";

        $content .= "// database host\n";
        $content .= "\$dbhost = '" . self::sanitize($data['dbhost']) . "';\n\n";

        $content .= "// database name\n";
        $content .= "\$dbname = '" . self::sanitize($data['dbname']) . "';\n\n";

        $content .= "// database username\n";
        $content .= "\$dbuser = '" . self::sanitize($data['dbuser']) . "';\n\n";

        $content .= "// database password\n";
        $content .= "\$dbpass = '" . self::sanitize($data['dbpass']) . "';\n\n";

        $content .= "// table prefix\n";
        $content .= "\$prefix = '" . self::sanitize($data['prefix']) . "';\n\n";

        $content .= "// charset\n";
        $content .= "define('DOU_CHARSET', '" . self::sanitize($data['charset']) . "');\n\n";

        $content .= "// system sign\n";
        $content .= "define('SYSTEM_SIGN', '" . self::sanitize($data['system_sign']) . "');\n\n";

        $content .= "// administrator dir\n";
        $content .= "define('ADMIN_DIR', isset(\$admining) ? \$admining : '" . self::sanitize($data['admin_dir']) . "');\n\n";

        $content .= "// api dir\n";
        $content .= "define('API_DIR', '" . self::sanitize($data['api_dir']) . "');\n\n";

        $content .= "// miniprogram dir\n";
        $content .= "define('MINIPROGRAM_DIR', '" . self::sanitize($data['miniprogram_dir']) . "');\n\n";

        if ($data['admin_rewrite'] !== null) {
            $content .= "// admin URL rewrite\n";
            $content .= "define('ADMIN_REWRITE', " . ($data['admin_rewrite'] ? 'true' : 'false') . ");\n\n";
        }

        if ($data['app_key'] !== null) {
            $content .= "// application secret\n";
            $content .= "define('DOU_APP_KEY', '" . self::sanitize($data['app_key']) . "');\n\n";
        }

        if ($data['dou_debug'] !== null) {
            $content .= "// debug flag\n";
            $content .= "define('DOU_DEBUG', " . ($data['dou_debug'] ? 'true' : 'false') . ");\n\n";
        }

        return rtrim($content, "\n") . "\n";
    }

    /**
     * 过滤掉单引号与反斜杠，防止破坏 PHP 字符串字面量。
     *
     * @param mixed $value
     * @return string
     */
    private static function sanitize($value)
    {
        if (!is_string($value)) {
            $value = (string) $value;
        }
        $value = str_replace(array("\r", "\n"), '', $value);
        return addcslashes($value, "\\'");
    }
}