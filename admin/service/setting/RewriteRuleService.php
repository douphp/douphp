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

namespace Dou\Admin\Service\Setting;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 伪静态规则维护：环境探测、规则读取与站点根写入。
 *
 * 约定（重要）：
 * - plan() 的 capable 只表示“能否直接写真实文件（去掉 .txt）”，不表示“是否需要处理”。
 * - nginx 规则必须由用户写入服务器配置，plan() 固定 capable=false、target=''；
 *   ensure() 先按 type=nginx 返回 skipped，绝不落任何 .txt 文件。
 * - 未识别服务器没有可用规则源，source/target 均为空，ensure() 按 target==='' 兜底 skipped。
 */
class RewriteRuleService
{
    /**
     * 允许读取的规则源文件名（白名单）。
     *
     * @var array
     */
    private $allowedSources = array('.htaccess.txt', 'httpd.ini.txt', 'web.config.txt', 'nginx.txt');

    /**
     * Apache 模块探测结果缓存；null 表示尚未探测或无法探测。
     *
     * @var array|null
     */
    private static $apacheModules = null;

    /**
     * 规划当前 Web 服务器对应的规则源与写入目标。
     *
     * @param string $webServer SERVER_SOFTWARE 字符串
     * @param bool $iisUrlRewrite 是否探测到 IIS URL Rewrite 模块
     * @return array type(apache|iis7|iis6|nginx|other)、source、target、capable
     */
    public function plan($webServer, $iisUrlRewrite = false)
    {
        $webServer = (string) $webServer;

        // nginx 规则由用户写入服务器配置：不落文件，capable 固定 false
        if (stripos($webServer, 'nginx') !== false) {
            return array(
                'type' => 'nginx',
                'source' => 'nginx.txt',
                'target' => '',
                'capable' => false,
            );
        }

        if (stripos($webServer, 'apache') !== false) {
            $capable = $this->apacheRewriteEnabled();
            return array(
                'type' => 'apache',
                'source' => '.htaccess.txt',
                'target' => $capable ? '.htaccess' : '.htaccess.txt',
                'capable' => $capable,
            );
        }

        if (stripos($webServer, 'iis') !== false) {
            if ($this->iisVersion($webServer) >= 7) {
                $capable = (bool) $iisUrlRewrite;
                return array(
                    'type' => 'iis7',
                    'source' => 'web.config.txt',
                    'target' => $capable ? 'web.config' : 'web.config.txt',
                    'capable' => $capable,
                );
            }

            // IIS6 依赖 ISAPI_Rewrite，无法静态探测，一律按不支持处理，写 .txt 供手动启用
            return array(
                'type' => 'iis6',
                'source' => 'httpd.ini.txt',
                'target' => 'httpd.ini.txt',
                'capable' => false,
            );
        }

        // 未识别服务器：没有可写目标，ensure() 直接跳过
        return array(
            'type' => 'other',
            'source' => '',
            'target' => '',
            'capable' => false,
        );
    }

    /**
     * 描述设置页应展示的提示类型。
     *
     * @param string $webServer SERVER_SOFTWARE 字符串
     * @param string $rootPath 站点根目录
     * @param bool $iisUrlRewrite 是否探测到 IIS URL Rewrite 模块
     * @return array key(auto|exists|manual|nginx|other)、file、plan
     */
    public function describe($webServer, $rootPath, $iisUrlRewrite = false)
    {
        $plan = $this->plan($webServer, $iisUrlRewrite);

        if ($plan['type'] === 'nginx') {
            return array('key' => 'nginx', 'file' => $plan['source'], 'plan' => $plan);
        }

        if ($plan['target'] === '') {
            return array('key' => 'other', 'file' => '', 'plan' => $plan);
        }

        $rootPath = $this->normalizeRoot($rootPath);

        $existing = $this->existingRuleFile($plan, $rootPath);
        if ($existing !== '') {
            return array('key' => 'exists', 'file' => $existing, 'plan' => $plan);
        }

        return array(
            'key' => $plan['capable'] ? 'auto' : 'manual',
            'file' => $plan['target'],
            'plan' => $plan,
        );
    }

    /**
     * 按当前环境写入伪静态规则。
     *
     * 返回 state：
     * - skipped：nginx / 未识别服务器，不写文件
     * - exists：目标文件或同名的真实规则文件已存在，不覆盖
     * - written：已写入可直接生效的真实规则文件
     * - manual：已写入 .txt，需用户手动去掉后缀
     * - failed：目录不可写或写入失败
     * - missing_source：规则源文件缺失
     *
     * @param string $webServer SERVER_SOFTWARE 字符串
     * @param string $rootPath 站点根目录
     * @param bool $iisUrlRewrite 是否探测到 IIS URL Rewrite 模块
     * @return array state、file、plan
     */
    public function ensure($webServer, $rootPath, $iisUrlRewrite = false)
    {
        $plan = $this->plan($webServer, $iisUrlRewrite);

        // 约定一：nginx 只能由用户配置服务器，绝不写 .txt
        if ($plan['type'] === 'nginx') {
            return array('state' => 'skipped', 'file' => $plan['source'], 'plan' => $plan);
        }

        // 约定二：未识别服务器没有目标文件，直接跳过
        if ($plan['source'] === '' || $plan['target'] === '') {
            return array('state' => 'skipped', 'file' => '', 'plan' => $plan);
        }

        // 空根目录不能归一化成 "/"，否则可能误写文件系统根目录
        if ((string) $rootPath === '') {
            return array('state' => 'failed', 'file' => $plan['target'], 'plan' => $plan);
        }

        $rootPath = $this->normalizeRoot($rootPath);

        $existing = $this->existingRuleFile($plan, $rootPath);
        if ($existing !== '') {
            return array('state' => 'exists', 'file' => $existing, 'plan' => $plan);
        }

        $target = $rootPath . $plan['target'];

        $content = $this->readRules($plan['source']);
        if ($content === '') {
            return array('state' => 'missing_source', 'file' => $plan['source'], 'plan' => $plan);
        }

        if (!is_dir($rootPath) || !function_exists('file_put_contents')) {
            return array('state' => 'failed', 'file' => $plan['target'], 'plan' => $plan);
        }

        $bytes = @file_put_contents($target, $content);
        if ($bytes === false || $bytes !== strlen($content)) {
            if ($bytes !== false) {
                @unlink($target);
            }
            return array('state' => 'failed', 'file' => $plan['target'], 'plan' => $plan);
        }

        return array(
            'state' => $plan['capable'] ? 'written' : 'manual',
            'file' => $plan['target'],
            'plan' => $plan,
        );
    }

    /**
     * 返回已存在的规则文件：优先真实文件，其次才看 .txt 目标。
     *
     * 能力不足时 plan 的 target 是 *.txt；若同名的真实文件已存在（通常由用户
     * 手动改名或此前环境可用时写入），应视为 exists，既避免重复落 .txt，也避免
     * 提示用户改名时覆盖真实文件。
     *
     * @param array $plan plan() 结果
     * @param string $rootPath 已归一化的站点根目录
     * @return string 已存在的文件名；不存在返回空串
     */
    private function existingRuleFile($plan, $rootPath)
    {
        if ($plan['target'] === '') {
            return '';
        }

        if (substr($plan['target'], -4) === '.txt') {
            $real = substr($plan['target'], 0, -4);
            if ($real !== '' && file_exists($rootPath . $real)) {
                return $real;
            }
        }

        if (file_exists($rootPath . $plan['target'])) {
            return $plan['target'];
        }

        return '';
    }

    /**
     * 读取伪静态规则内容。
     *
     * @param string $name 规则源文件名
     * @return string 读取失败返回空串
     */
    public function readRules($name)
    {
        if (!in_array($name, $this->allowedSources, true)) {
            return '';
        }

        $file = CORE_PATH . 'resources/rewrite/' . $name;
        if (!is_file($file) || !is_readable($file) || !function_exists('file_get_contents')) {
            return '';
        }

        $content = @file_get_contents($file);

        return $content === false ? '' : $content;
    }

    /**
     * 组装 Nginx 规则弹窗提示（规则内容来自 core/resources/rewrite/nginx.txt）。
     *
     * @return string
     */
    public function buildNginxCue()
    {
        $rules = $this->readRules('nginx.txt');
        if ($rules === '') {
            return sprintf(lang('config_rewrite_source_missing'), 'nginx.txt');
        }

        // 弹窗内容经 data-html 传给前端，需转义一次；textarea 内的规则再单独转义，
        // 浏览器解析属性时会还原一层，最终写入弹窗的仍是可复制的纯规则文本。
        $textarea = '<textarea readonly rows="16" class="text-area-auto" style="width:100%;height:320px" onclick="this.select()">'
            . htmlspecialchars($rules, ENT_QUOTES, 'UTF-8')
            . '</textarea>';

        return lang('config_rewrite_cue_nginx') . ' <a href="javascript:;" data-dou-toggle="modal" data-dou-modal="message"'
            . ' data-title="' . htmlspecialchars(lang('config_rewrite_nginx_title'), ENT_QUOTES, 'UTF-8') . '"'
            . ' data-html="' . htmlspecialchars($textarea, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-placement="center">'
            . htmlspecialchars(lang('config_rewrite_nginx_link'), ENT_QUOTES, 'UTF-8')
            . '</a>';
    }

    /**
     * 归一化站点根目录（统一使用 / 结尾）。
     *
     * @param string $rootPath
     * @return string
     */
    private function normalizeRoot($rootPath)
    {
        return rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';
    }

    /**
     * 探测 Apache 是否启用 mod_rewrite。
     *
     * 参考 WordPress apache_mod_loaded()：优先 apache_get_modules()，否则解析
     * phpinfo(INFO_MODULES)；两者都不可用时一律视为不支持，避免误写导致整站 500。
     *
     * @return bool
     */
    private function apacheRewriteEnabled()
    {
        $modules = $this->apacheModules();
        if (!is_array($modules)) {
            return false;
        }

        foreach ($modules as $module) {
            if (strpos(strtolower((string) $module), 'mod_rewrite') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取 Apache 已加载模块列表。
     *
     * @return array|null null 表示无法探测
     */
    private function apacheModules()
    {
        if (self::$apacheModules !== null) {
            return self::$apacheModules;
        }

        if (function_exists('apache_get_modules')) {
            $modules = @apache_get_modules();
            if (is_array($modules)) {
                self::$apacheModules = $modules;
                return self::$apacheModules;
            }
        }

        // CLI / FastCGI 下通常没有 apache_get_modules()，退回 phpinfo 模块列表
        if (!function_exists('phpinfo') || $this->functionDisabled('phpinfo')) {
            return null;
        }

        ob_start();
        @phpinfo(INFO_MODULES);
        $info = ob_get_clean();

        if (!is_string($info) || $info === '') {
            return null;
        }

        self::$apacheModules = stripos($info, 'mod_rewrite') !== false ? array('mod_rewrite') : array();

        return self::$apacheModules;
    }

    /**
     * 解析 IIS 主版本号。
     *
     * @param string $webServer SERVER_SOFTWARE 字符串
     * @return float
     */
    private function iisVersion($webServer)
    {
        if (preg_match('#IIS/([0-9.]+)#i', (string) $webServer, $match)) {
            return (float) $match[1];
        }

        return 0.0;
    }

    /**
     * 判断函数是否被 disable_functions 禁用。
     *
     * @param string $name 函数名
     * @return bool
     */
    private function functionDisabled($name)
    {
        $disabled = ini_get('disable_functions');
        if (!is_string($disabled) || $disabled === '') {
            return false;
        }

        $list = array_map('trim', explode(',', strtolower($disabled)));

        return in_array(strtolower($name), $list, true);
    }
}
