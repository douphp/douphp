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

namespace Dou\Admin\Service\Theme;

use Dou\Admin\Facade\Cloud;
use Dou\Admin\Model\Theme\Theme;
use Dou\Admin\Service\Cache\CacheClearService;
use Dou\Core\Service\BaseService;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\Check;
use Dou\Core\Support\FileHelper;
use Dou\Core\Web\Http\CloudApi;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 模板管理（route=theme/...）
 */
class ThemeService extends BaseService
{

    /** @var Cloud */
    private $cloud;

    /** @var CacheClearService */
    private $cacheClear;

    /**
     * @param Cloud $cloud
     * @param CacheClearService $cacheClear
     */
    public function __construct(Cloud $cloud, CacheClearService $cacheClear)
    {
        $this->cloud = $cloud;
        $this->cacheClear = $cacheClear;
    }

    /**
     * 列表页数据（含模板所需开关与参数）。
     *
     * @param string $site_theme    当前启用主题目录名
     * @param bool   $theme_blocked \Dou\Core\Service\Theme\SiteThemePolicy::themeBlocked()
     * @return array theme_enable, theme_list, theme_no_allow, support_module
     */
    public function buildThemeListData($site_theme, $theme_blocked)
    {
        $theme_enable = $this->parseThemeMeta($site_theme);
        $theme_array = FileHelper::getSubdirs(ROOT_PATH . 'theme/');
        $theme_list = array();
        foreach ($theme_array as $slug) {
            if ($slug == $site_theme) {
                continue;
            }
            $theme_info = $this->parseThemeMeta($slug);
            if ($theme_info) {
                if (!empty($theme_info['cloud'])) {
                    $previewUrl = $this->fetchCloudPreviewFrameUrl($slug);
                    if ($previewUrl !== '') {
                        $theme_info['preview_frame_url'] = $previewUrl;
                    }
                }
                $theme_list[] = $theme_info;
            }
        }

        return array(
            'theme_enable' => $theme_enable,
            'theme_list' => $theme_list,
            'theme_no_allow' => $theme_blocked,
            'support_module' => Theme::getThemeSupportModuleValue(),
        );
    }

    /**
     * 从云服务拉取扩展 Web 预览 iframe URL（已安装云主题列表用）。
     *
     * @param string $uniqueId 主题 slug / 扩展 unique_id
     * @return string
     */
    protected function fetchCloudPreviewFrameUrl($uniqueId)
    {
        $data = CloudApi::getJson(CloudApi::PATH_EXTEND_ITEM, array(
            'unique_id' => (string) $uniqueId,
        ));
        if (!is_array($data) || empty($data['preview_frame_url'])) {
            return '';
        }

        return (string) $data['preview_frame_url'];
    }

    /**
     * 云安装列表页 assign 数据。
     *
     * @param array $getRequest request()->get()
     * @return array get, localsite
     */
    public function buildThemeInstallData(array $getRequest)
    {
        return array(
            'get' => urlencode(serialize($getRequest)),
            'localsite' => $this->cloud->localSitePayload('theme'),
        );
    }

    /**
     * theme/set：补全参数行；act=module 时同步支持模块并回到模板列表。
     *
     * @param mixed $actRaw request act
     * @return string
     */
    public function resolveSetRedirectUrl($actRaw)
    {
        $this->ensureThemeParameterRows();
        $act = Check::rec($actRaw) ? $actRaw : 'default';
        if ($act === 'module') {
            $this->syncSupportModuleFromApi();

            return route('admin.theme');
        }

        return route('admin.parameter.set', ['group' => 'theme']);
    }

    /**
     * @param string $slug
     * @return void
     */
    public function enableTheme($slug)
    {
        $theme_array = FileHelper::getSubdirs(ROOT_PATH . 'theme/');
        if (!in_array($slug, $theme_array, true)) {
            return;
        }
        if ($slug != 'default') {
            FileHelper::copyDir(ROOT_PATH . 'theme/default', ROOT_PATH . 'theme/' . $slug, false, true);
        }

        Theme::updateConfigValue('site_theme', $slug);

        $productThumbSize = (string) Config::get('theme.product_thumb_size', '');
        if ($productThumbSize !== '') {
            $thumb_size = explode('*', $productThumbSize);
            if (isset($thumb_size[0])) {
                Theme::updateConfigValue('thumb_width', $thumb_size[0]);
            }
            if (isset($thumb_size[1])) {
                Theme::updateConfigValue('thumb_height', $thumb_size[1]);
            }
        }

        $this->cacheClear->clearCache(STORAGE_PATH . 'cache/template');
    }

    /**
     * @param string $slug
     * @return void
     */
    public function deleteTheme($slug)
    {
        $theme_array = FileHelper::getSubdirs(ROOT_PATH . 'theme/');
        if (!in_array($slug, $theme_array, true)) {
            return;
        }

        FileHelper::delDir(ROOT_PATH . 'theme/' . $slug);
        $this->cloud->changeUpdateDate('theme', $slug, true);

        $rows = Theme::selectDataRowsByThemeOrdered($slug);
        foreach ($rows as $row) {
            if (!empty($row['image'])) {
                attachment()->delete($row['image']);
            }
        }
        Theme::deleteDataByTheme($slug);
    }

    /**
     * @return void
     */
    public function clearSupportModule()
    {
        Theme::clearThemeSupportModule();
    }

    /**
     * 开启「隐藏不支持的模块」：云端模板取 API need_module，本地模板写入全部已装模块。
     *
     * @return bool 是否已更新并成功
     */
    public function syncSupportModuleFromApi()
    {
        $themeId = (string) Config::get('site.site_theme', '');
        $text = '';

        $cloud_account = unserialize(Config::get('site.cloud_account', ''));
        if (!is_array($cloud_account)) {
            $cloud_account = array();
        }

        $envelope = CloudApi::postJson(CloudApi::PATH_THEME_MODULE_SUPPORT, array(
            'theme_id' => $themeId,
            'user' => isset($cloud_account['user']) ? $cloud_account['user'] : '',
            'password' => isset($cloud_account['password']) ? $cloud_account['password'] : '',
        ));
        if (is_array($envelope) && isset($envelope['need_module'])) {
            $text = trim((string) $envelope['need_module']);
        }

        if ($text === '' && !preg_match('/^m[0-9]{3}$/', $themeId)) {
            $all = (array) Config::get('module.all_module');
            if (empty($all)) {
                $all = array_merge(
                    (array) Config::get('module.column_module', array()),
                    (array) Config::get('module.single_module', array())
                );
            }
            $text = implode(',', $all);
        }

        if ($text === '' || !preg_match("/^[A-Za-z0-9_,]+$/", $text)) {
            return false;
        }

        Theme::updateThemeSupportModuleValue($text);
        return true;
    }

    /**
     * @return void
     */
    public function ensureThemeParameterRows()
    {
        $set_list = array('theme_support_module');
        foreach ($set_list as $name) {
            if (!Theme::parameterExistsByName($name)) {
                Theme::insertParameterRow(array(
                    'name' => $name,
                    'lang' => lang($name . '_lang'),
                    'value' => '',
                    'cue' => lang($name . '_cue'),
                    'group' => 'theme',
                ));
            }
        }
    }

    /**
     * 从主题目录 style.css 头部注释解析元数据。
     *
     * @param string $slug 主题目录名
     * @return array|null
     */
    private function parseThemeMeta($slug)
    {
        $theme_url = 'theme/';
        $style_path = ROOT_PATH . $theme_url . $slug . '/css/style.css';
        $style_path_old = ROOT_PATH . $theme_url . $slug . '/style.css';

        if (!file_exists($style_path)) {
            if (!file_exists($style_path_old)) {
                return null;
            }
            $style_path = $style_path_old;
        }

        $info = array();
        $content = file($style_path);
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
        $info['slug'] = $slug;
        $info['image'] = ROOT_URL . $theme_url . $slug . '/images/screenshot.png';
        $info['cloud'] = preg_match('/^m[0-9]{3}$/', $slug) ? true : false;
        $info['preview_frame_url'] = '';

        return $info;
    }
}
