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

namespace Dou\Admin\Service\Miniprogram;

use Dou\Admin\Facade\Cloud;
use Dou\Admin\Model\Miniprogram\MiniprogramParameter;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\FileHelper;

use Dou\Core\Facade\DB;
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序后台公共逻辑
 */
class MiniprogramService extends BaseService
{
    /**
     * 与 ensureMiniprogramParameters、MiniprogramSystemFormRequest 白名单一致
     *
     * @var array
     */
    public static $parameterNames = array(
        'miniprogram_appid',
        'miniprogram_appsecret',
        'miniprogram_pay_mch_id',
        'miniprogram_pay_key',
        'miniprogram_domain',
    );

    /** @var Cloud */
    private $cloud;

    /**
     * @param Cloud $cloud
     */
    public function __construct(Cloud $cloud)
    {
        $this->cloud = $cloud;
    }

    /**
     * @return void
     */
    public function defineCodePath()
    {
        if (!defined('MINIPROGRAM_CODE_PATH')) {
            define('MINIPROGRAM_CODE_PATH', ROOT_PATH . MINIPROGRAM_DIR . '/');
        }
    }

    /**
     * 确保代码目录存在
     *
     * @return void
     */
    public function prepareMiniprogramCodeDirectory()
    {
        $this->defineCodePath();
        if (!file_exists(MINIPROGRAM_CODE_PATH)) {
            mkdir(MINIPROGRAM_CODE_PATH, 0777);
        }
    }

    /**
     * 代码包目录名列表（已确保 MINIPROGRAM_CODE_PATH 存在）
     *
     * @return array
     */
    public function listMiniprogramCodeSlugs()
    {
        $this->prepareMiniprogramCodeDirectory();

        return FileHelper::getSubdirs(MINIPROGRAM_CODE_PATH);
    }

    /**
     * @param string|null $domain
     * @return void
     */
    public function syncMiniprogramConfig($domain = null)
    {
        if ($domain === null || $domain === '') {
            $this->cloud->changeMiniprogramConfigFile();
        } else {
            $this->cloud->changeMiniprogramConfigFile($domain);
        }
    }

    /**
     * 小程序列表页数据
     *
     * @return array miniprogram_enable、miniprogram_list
     */
    public function buildMiniprogramListData()
    {
        $this->defineCodePath();
        $miniprogram_enable = $this->parseMiniprogramMeta(Config::get('site.miniprogram_code', ''));
        $miniprogram_list = array();
        $miniprogram_array = FileHelper::getSubdirs(MINIPROGRAM_CODE_PATH);
        if ($miniprogram_array) {
            foreach ($miniprogram_array as $slug) {
                if ($slug == Config::get('site.miniprogram_code', '')) {
                    continue;
                }
                $miniprogram_info = $this->parseMiniprogramMeta($slug);
                if ($miniprogram_info) {
                    $miniprogram_list[] = $miniprogram_info;
                }
            }
        }

        return array(
            'miniprogram_enable' => $miniprogram_enable,
            'miniprogram_list' => $miniprogram_list,
        );
    }

    /**
     * @param string $slug
     * @return void
     */
    public function enablePackage($slug)
    {
        $this->defineCodePath();
        $miniprogram_array = FileHelper::getSubdirs(MINIPROGRAM_CODE_PATH);
        if (!in_array($slug, $miniprogram_array)) {
            return;
        }
        if ($slug != 'default') {
            FileHelper::copyDir(MINIPROGRAM_CODE_PATH . 'default', MINIPROGRAM_CODE_PATH . $slug, false, true);
        }
        DB::table('config')->where('name', 'miniprogram_code')->update(array('value' => $slug));
    }

    /**
     * @param string $slug
     * @return void
     */
    public function deletePackage($slug)
    {
        $this->defineCodePath();
        $miniprogram_array = FileHelper::getSubdirs(MINIPROGRAM_CODE_PATH);
        if (!in_array($slug, $miniprogram_array)) {
            return;
        }
        FileHelper::delDir(MINIPROGRAM_CODE_PATH . $slug);
        $this->cloud->changeUpdateDate('miniprogram', $slug, true);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $slug, 'miniprogram');
    }

    /**
     * 系统参数页数据
     *
     * @return array parameter_list
     */
    public function buildMiniprogramSystemIndexData()
    {
        $this->ensureMiniprogramParameters();

        return array(
            'parameter_list' => MiniprogramParameter::listMiniprogramGroup(),
        );
    }

    /**
     * 保存白名单内的参数并同步小程序配置
     *
     * @param array $validated MiniprogramSystemFormRequest::validated()
     * @return void
     */
    public function updateMiniprogramParameters(array $validated)
    {
        MiniprogramParameter::updateMiniprogramValues($validated, self::$parameterNames);
        $domain = null;
        if (array_key_exists('miniprogram_domain', $validated)) {
            $domain = $validated['miniprogram_domain'];
        }
        $this->syncMiniprogramConfig($domain);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'miniprogram_system', 'miniprogram');
    }

    /**
     * @return void
     */
    public function ensureMiniprogramParameters()
    {
        foreach (self::$parameterNames as $name) {
            if (!DB::table('parameter')->where('name', $name)->find()) {
                DB::table('parameter')->insert(array(
                    'name' => $name,
                    'lang' => lang('miniprogram_' . $name . '_lang'),
                    'value' => lang('miniprogram_' . $name . '_value'),
                    'cue' => lang('miniprogram_' . $name . '_cue'),
                    'group' => 'miniprogram',
                ));
            }
        }
    }

    /**
     * 解析小程序代码包元数据。
     *
     * 优先读取包根 `manifest.json`（主流包清单约定：纯数据、不参与微信打包）；
     * 老代码包无 manifest 时回退解析 `app.wxss` 头部注释（WordPress style.css 式旧格式）。
     *
     * @param string $slug 代码包目录名
     * @return array|null 两条路径都取不到时返回 null
     */
    private function parseMiniprogramMeta($slug)
    {
        if (!$slug) {
            return null;
        }

        $info = $this->parseMiniprogramManifest($slug);
        if ($info === null) {
            $info = $this->parseMiniprogramStyleMeta($slug);
        }
        if ($info === null) {
            return null;
        }

        $info['slug'] = $slug;
        $screenshot = isset($info['screenshot']) ? (string) $info['screenshot'] : '';
        unset($info['screenshot']);
        $info['image'] = ROOT_URL . MINIPROGRAM_DIR . '/' . $slug . '/' . $this->resolveMiniprogramScreenshot($slug, $screenshot);

        return $info;
    }

    /**
     * 读取包根 manifest.json 元数据（字段对齐 npm / uni-app 命名习惯）。
     *
     * @param string $slug 代码包目录名
     * @return array|null 无 manifest（或 JSON 非法、缺 name）时返回 null
     */
    private function parseMiniprogramManifest($slug)
    {
        $file = MINIPROGRAM_CODE_PATH . $slug . '/manifest.json';
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || $this->manifestString($data, 'name') === '') {
            return null;
        }

        $homepage = $this->manifestString($data, 'homepage');
        $uri = $this->manifestString($data, 'uri');
        $authorUri = $this->manifestString($data, 'author_uri');

        return array(
            'miniprogram_name' => $this->manifestString($data, 'name'),
            'miniprogram_uri' => $uri !== '' ? $uri : $homepage,
            'description' => $this->manifestString($data, 'description'),
            'version' => $this->manifestString($data, 'version'),
            'author' => $this->manifestString($data, 'author'),
            'author_uri' => $authorUri !== '' ? $authorUri : $homepage,
            'screenshot' => $this->manifestString($data, 'screenshot'),
        );
    }

    /**
     * 取 manifest 字符串字段（缺失或非字符串时返回空串）。
     *
     * @param array $data manifest 解构后的数组
     * @param string $key 字段名
     * @return string
     */
    private function manifestString(array $data, $key)
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            return '';
        }

        return trim($data[$key]);
    }

    /**
     * 回退路径：从小程序包 app.wxss 头部注释解析元数据（旧代码包兼容）。
     *
     * @param string $slug 代码包目录名
     * @return array|null
     */
    private function parseMiniprogramStyleMeta($slug)
    {
        $readme_file = MINIPROGRAM_CODE_PATH . $slug . '/app.wxss';
        if (!file_exists($readme_file)) {
            return null;
        }

        $info = array();
        $content = file($readme_file);
        foreach ((array) $content as $line) {
            if (strpos($line, '/*') !== false) {
                continue;
            }
            if (strpos($line, '*/') !== false) {
                break;
            }

            $line = preg_replace('/:/', '#', $line, 1);
            $arr = explode('#', trim($line));
            $key = str_replace(' ', '_', strtolower($arr[0]));
            $info[$key] = isset($arr[1]) ? trim($arr[1]) : '';
        }

        return $info;
    }

    /**
     * 解析包内截图相对路径：manifest 指定优先，缺失或不存在时回退约定位置。
     *
     * @param string $slug 代码包目录名
     * @param string $preferred manifest 中声明的截图相对路径
     * @return string
     */
    private function resolveMiniprogramScreenshot($slug, $preferred = '')
    {
        $candidates = array(
            'images/screenshot.png',
            'system.dou/screenshot.png',
        );
        if ($preferred !== '') {
            array_unshift($candidates, $preferred);
        }

        foreach ($candidates as $rel) {
            if (is_file(MINIPROGRAM_CODE_PATH . $slug . '/' . $rel)) {
                return $rel;
            }
        }

        return 'images/screenshot.png';
    }
}
