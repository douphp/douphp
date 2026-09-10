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
     * 视觉版式与 upgrade/service/ConfigUpdateService::writeConfigPhp、_update/action.php
     * "修改 config 文件内容" 段落三处保持一致；如需改动请三处同步修改，以免 drift。
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

        $config_str = "<?php\r\n";
        $config_str .= "/**\r\n";
        $config_str .= " * DouPHP®\r\n";
        $config_str .= " * ------------------------------------------------------------------------------------\r\n";
        $config_str .= " * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)\r\n";
        $config_str .= " *\r\n";
        $config_str .= " * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。\r\n";
        $config_str .= " * 网站地址：http://www.douphp.com\r\n";
        $config_str .= " * ------------------------------------------------------------------------------------\r\n";
        $config_str .= " * Author: DouCo Co.,Ltd.\r\n";
        $config_str .= " * Release Date: 2026-09-08\r\n";
        $config_str .= " */\r\n\r\n";

        $config_str .= "// database host\r\n";
        $config_str .= "\$dbhost   = '" . $this->sanitize($dbhost) . "';\r\n\r\n";

        $config_str .= "// database name\r\n";
        $config_str .= "\$dbname   = '" . $this->sanitize($dbname) . "';\r\n\r\n";

        $config_str .= "// database username\r\n";
        $config_str .= "\$dbuser   = '" . $this->sanitize($dbuser) . "';\r\n\r\n";

        $config_str .= "// database password\r\n";
        $config_str .= "\$dbpass   = '" . $this->sanitize($dbpass) . "';\r\n\r\n";

        $config_str .= "// table prefix\r\n";
        $config_str .= "\$prefix   = '" . $this->sanitize($prefix) . "';\r\n\r\n";

        $config_str .= "// charset\r\n";
        $config_str .= "define('DOU_CHARSET', '" . $this->sanitize($charset) . "');\r\n\r\n";

        $config_str .= "// system sign\r\n";
        $config_str .= "define('SYSTEM_SIGN', '" . $this->sanitize($systemSign) . "');\r\n\r\n";

        $config_str .= "// administrator dir\r\n";
        $config_str .= "define('ADMIN_DIR', isset(\$admining) ? \$admining : '" . $this->sanitize($adminDir) . "');\r\n\r\n";

        $config_str .= "// api dir\r\n";
        $config_str .= "define('API_DIR', '" . $this->sanitize($apiDir) . "');\r\n\r\n";

        $config_str .= "// miniprogram dir\r\n";
        $config_str .= "define('MINIPROGRAM_DIR', '" . $this->sanitize($miniprogramDir) . "');\r\n\r\n";

        $config_str .= "// admin URL rewrite\r\n";
        $config_str .= "define('ADMIN_REWRITE', " . ($adminRewrite ? 'true' : 'false') . ");\r\n\r\n";

        $config_str .= "// application secret\r\n";
        $config_str .= "define('DOU_APP_KEY', '" . $this->sanitize($appKey) . "');\r\n\r\n";

        $config_str .= "// debug flag\r\n";
        $config_str .= "define('DOU_DEBUG', " . ($debug ? 'true' : 'false') . ");\r\n\r\n";

        $config_str .= "?>";

        return file_put_contents($this->configPath, $config_str) !== false;
    }

    /**
     * 过滤掉单引号与反斜杠，防止破坏 PHP 字符串字面量。
     *
     * @param mixed $value
     * @return string
     */
    private function sanitize($value)
    {
        if (!is_string($value)) {
            $value = (string) $value;
        }
        $value = str_replace(array("\r", "\n"), '', $value);
        return addcslashes($value, "\\'");
    }
}
