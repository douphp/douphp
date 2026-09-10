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

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Infra\Database\Connection;
use Dou\Core\Support\Check;
use Dou\Core\Support\FileHelper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 安装阶段的数据库相关业务。
 *
 * 拆分点：
 *   - 表单校验：validateAccount() / validateConnection()
 *   - 建库：connectAndCreateDatabase()
 *   - 整体安装：runInstall()（写 config.php、导 SQL、初始化管理员/配置、可选清理测试数据）
 *
 * Connection->error() 内部会 exit()，因此先用裸 mysqli 做完连接 + 建库 + 选库的探活，
 * 再构造 Connection 实例做后续业务，避免连接失败时把整页打断。
 */
class DatabaseInstallService
{
    /** @var array */
    private $lang;

    /** @var ConfigWriterService */
    private $configWriter;

    /**
     * @param array $lang
     * @param ConfigWriterService $configWriter
     */
    public function __construct(array $lang, ConfigWriterService $configWriter)
    {
        $this->lang = $lang;
        $this->configWriter = $configWriter;
    }

    /**
     * 校验管理员账号 / 密码 / 邮箱。
     *
     * @param array $post
     * @return string 空串表示校验通过；否则返回错误提示
     */
    public function validateAccount(array $post)
    {
        $username = isset($post['username']) ? trim((string) $post['username']) : '';
        $password = isset($post['password']) ? (string) $post['password'] : '';
        $password_confirm = isset($post['password_confirm']) ? (string) $post['password_confirm'] : '';
        $email = isset($post['email']) ? trim((string) $post['email']) : '';

        if ($username === '') {
            return $this->lang['cue_username_empty'];
        }
        if (!Check::adminAccount($username)) {
            return $this->lang['cue_username_wrong'];
        }
        if ($password === '') {
            return $this->lang['cue_password_empty'];
        }
        if (!Check::password($password)) {
            return $this->lang['cue_password_wrong'];
        }
        if ($password_confirm === '') {
            return $this->lang['cue_password_confirm_empty'];
        }
        if ($password !== $password_confirm) {
            return $this->lang['cue_password_confirm_wrong'];
        }
        if ($email !== '' && !Check::email($email)) {
            return $this->lang['cue_email_wrong'];
        }
        return '';
    }

    /**
     * 尝试连接 MySQL 并建库选库（utf8mb4_unicode_ci）。
     *
     * @param string $dbhost
     * @param string $dbuser
     * @param string $dbpass
     * @param string $dbname
     * @return string 空串表示成功；否则返回错误提示
     */
    public function connectAndCreateDatabase($dbhost, $dbuser, $dbpass, $dbname)
    {
        // PHP 8.1+ mysqli 默认 throw exception；安装阶段要求把错误以友好提示形式回显
        if (version_compare(PHP_VERSION, '8.1', '>=')) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        } else {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        try {
            // 解析 host:port（与 Connection::connect 行为一致）
            $hostonly = $dbhost;
            $port = 3306;
            if (strpos($dbhost, ':') !== false) {
                $segments = explode(':', $dbhost, 2);
                $hostonly = $segments[0];
                $portNum = (int) $segments[1];
                if ($portNum > 0) {
                    $port = $portNum;
                }
            }

            $link = @mysqli_connect($hostonly, $dbuser, $dbpass, null, $port);
            if (!$link) {
                return $this->lang['cue_connect'] . ': ' . mysqli_connect_error();
            }

            @mysqli_set_charset($link, 'utf8mb4');
            $create_db_result = @mysqli_query($link, "CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            if ($create_db_result === false) {
                $cue = $this->lang['cue_create_db_failed'] . ': ' . mysqli_error($link);
                @mysqli_close($link);
                return $cue;
            }
            if (mysqli_select_db($link, $dbname) === false) {
                @mysqli_close($link);
                return $this->lang['cue_no_this_dbname'];
            }
            @mysqli_query($link, "ALTER DATABASE `$dbname` CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci");
            @mysqli_close($link);
            return '';
        } catch (\Exception $e) {
            return $this->lang['cue_connect'] . ': ' . $e->getMessage();
        }
    }

    /**
     * 完整执行安装：写 config.php → 导 SQL → 初始化管理员/系统配置 → 可选清理测试数据。
     *
     * @param array $post 安装表单
     * @return array array('username' => 设置成功后的管理员用户名)
     */
    public function runInstall(array $post)
    {
        $dbhost = isset($post['dbhost']) ? trim((string) $post['dbhost']) : '';
        $dbname = isset($post['dbname']) ? trim((string) $post['dbname']) : '';
        $dbuser = isset($post['dbuser']) ? trim((string) $post['dbuser']) : '';
        $dbpass = isset($post['dbpass']) ? (string) $post['dbpass'] : '';
        $prefix = isset($post['prefix']) ? trim((string) $post['prefix']) : 'dou_';
        $username = isset($post['username']) ? trim((string) $post['username']) : '';
        $password = isset($post['password']) ? (string) $post['password'] : '';
        $email = isset($post['email']) ? trim((string) $post['email']) : '';
        $test_data = isset($post['test_data']) ? (string) $post['test_data'] : 'yes';

        // 落盘 config/config.php
        $this->configWriter->write(array(
            'dbhost' => $dbhost,
            'dbname' => $dbname,
            'dbuser' => $dbuser,
            'dbpass' => $dbpass,
            'prefix' => $prefix,
        ));

        // 不 include config/config.php（其内 define 会污染当前请求常量），DB 参数直接用局部变量
        $dou = new Connection($dbhost, $dbuser, $dbpass, $dbname, $prefix, 'utf-8');

        // 把刚连好的 Connection 绑定为容器单例，让本类与后续业务一致通过 DB:: 静态门面访问数据库
        // （install 不走 core/bootstrap.php，没有 InitTrait::instantiateCoreObjects 的预绑定）
        Container::getInstance()->instance(Connection::class, $dou);

        $this->importSql($prefix);

        $build_date = time();
        $this->initAdmin($username, $password, $email, $build_date);
        $this->initSiteConfig($dbhost, $dbname, $dbuser, $dbpass, $build_date);
        $this->maybeClearTestData($prefix, $test_data);

        return array('username' => $username);
    }

    /**
     * 导入 install/data/backup/douphp.sql：替换表前缀后按 fnExecute 批量执行。
     *
     * @param string $prefix
     * @return void
     */
    private function importSql($prefix)
    {
        $sql = file_get_contents(INSTALL_PATH . 'data/backup/douphp.sql');
        $sql = preg_replace('/dou_/Ums', $prefix, $sql);
        $sql_head = "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\r\n";
        $sql_head .= "SET time_zone = '+00:00';\r\n";
        $sql_head .= "/*!40101 SET NAMES utf8mb4 */;\r\n\r\n";
        DB::fnExecute($sql_head . $sql);
    }

    /**
     * 写入站点初始 dou_config：hash_code / build_date / update_date / domain / cloud_account / rewrite。
     *
     * @param string $dbhost
     * @param string $dbname
     * @param string $dbuser
     * @param string $dbpass
     * @param int $buildDate
     * @return void
     */
    private function initSiteConfig($dbhost, $dbname, $dbuser, $dbpass, $buildDate)
    {
        // 站点唯一性密钥
        $hash_code = md5(md5(time()) . md5(md5(ROOT_URL . $dbhost . $dbname . $dbuser . $dbpass)));

        $douphp_version = DB::table('config')->where('name', 'douphp_version')->value('value');
        $version_date = substr(trim((string) $douphp_version), -8);
        $update_date = 'a:3:{s:6:"system";a:2:{s:6:"update";s:8:"' . $version_date . '";s:5:"patch";s:8:"' . $version_date . '";}s:6:"module";a:3:{s:7:"article";s:8:"' . $version_date . '";s:7:"product";s:8:"' . $version_date . '";s:4:"data";s:8:"' . $version_date . '";}s:5:"theme";a:0:{}}';

        $configUpdates = array(
            'rewrite' => '0',
            'build_date' => (string) $buildDate,
            'hash_code' => $hash_code,
            'update_date' => $update_date,
            'cloud_account' => '',
            'domain' => ROOT_URL,
        );
        foreach ($configUpdates as $name => $value) {
            DB::table('config')->where('name', $name)->update(array('value' => $value));
        }
    }

    /**
     * 初始化管理员账号（id=1）：BCRYPT 密码，与 LoginService 对齐。
     *
     * @param string $username
     * @param string $password
     * @param string $email
     * @param int $buildDate
     * @return void
     */
    private function initAdmin($username, $password, $email, $buildDate)
    {
        DB::table('admin')->where('id', 1)->update(array(
            'username' => $username,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'email' => $email,
            'add_time' => $buildDate,
        ));
    }

    /**
     * 当 $test_data === 'no' 时清空示例数据：TRUNCATE 一组示例表 + 删 images/slide 与 images 子内容。
     *
     * @param string $prefix
     * @param string $testData
     * @return void
     */
    private function maybeClearTestData($prefix, $testData)
    {
        if ($testData !== 'no') {
            return;
        }
        $truncate_tables = array(
            'admin_log',
            'article',
            'article_category',
            'product',
            'product_category',
            'show',
            'file',
            'nav',
        );
        foreach ($truncate_tables as $t) {
            DB::query('TRUNCATE ' . $prefix . $t);
        }
        FileHelper::delDir(ROOT_PATH . 'images/slide', true);
        FileHelper::delDir(ROOT_PATH . 'images', true);
    }
}
