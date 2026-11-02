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
use Dou\Admin\Model\Data\Data;
use Dou\Admin\Model\Theme\Theme;
use Dou\Admin\Service\Cache\CacheClearService;
use Dou\Admin\Service\Cloud\CloudService;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Data\DataTemplate;
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
     * @param string $slug 主题 slug
     * @return string
     */
    protected function fetchCloudPreviewFrameUrl($slug)
    {
        $data = CloudApi::getJson(CloudApi::PATH_EXTEND_ITEM, array(
            'slug' => (string) $slug,
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
     * 删除主题：目录 + 该主题全部数据（dou_data 作用域）与素材。
     *
     * 数据回收按 dou_data.theme 作用域执行，不依赖安装期写的任何记录：
     * 逐行/批量 Data::destroy() 复用 PurgesRelatedOnDelete（附件行 + 物理文件 + 缩略图 +
     * 多语言 + 扩展字段），事务提交后清空 images/data/<slug>/。
     *
     * @param string $slug
     * @return void
     * @throws DomainException 删除默认主题 / 当前启用主题时抛出
     */
    public function deleteTheme($slug)
    {
        $slug = (string) $slug;
        $theme_array = FileHelper::getSubdirs(ROOT_PATH . 'theme/');
        if (!in_array($slug, $theme_array, true)) {
            return;
        }
        if ($slug === 'default') {
            // 默认主题是 enableTheme() 的拷贝基准，删了会导致后续主题无法启用
            throw new DomainException(lang('theme_delete_default_denied'), route('admin.theme'));
        }
        if ($slug === (string) Config::get('site.site_theme', '')) {
            throw new DomainException(lang('theme_delete_current_denied'), route('admin.theme'));
        }

        // 先回收数据：失败时不删目录，便于重试
        $this->purgeThemeData($slug);

        FileHelper::delDir(ROOT_PATH . 'theme/' . $slug);
        $this->cloud->changeUpdateDate('theme', $slug, true);
        // 主题卸载后其专属还原点不可再用，一并清理（还原中的快照由仓库层保护）
        if (class_exists('Dou\\Core\\Service\\Snapshot\\SnapshotRepository')) {
            $repository = new \Dou\Core\Service\Snapshot\SnapshotRepository(ROOT_PATH);
            $repository->deleteByScope('theme', $slug);
        }

        $this->cacheClear->clearCache(STORAGE_PATH . 'cache/template');
    }

    /**
     * 删除指定主题的全部数据与素材（按 dou_data.theme 作用域）。
     *
     * 必须排除 data_group='miniprogram'：该分组的 theme 列存的是小程序模板包标识
     * （DataTemplate::codeForGroup），与网站主题可能同名（如都叫 default）。
     *
     * @param string $slug 主题目录名
     * @return void
     */
    private function purgeThemeData($slug)
    {
        $rows = DB::table('data')->where('theme', (string) $slug)->field('id, data_group')->select();
        $ids = array();
        foreach ((array) $rows as $row) {
            if ((string) $row['data_group'] === DataTemplate::MINIPROGRAM_GROUP) {
                continue;
            }
            $ids[] = (int) $row['id'];
        }

        if ($ids) {
            DB::transaction(function () use ($ids) {
                Data::destroy($ids);
            });
        }

        // 主题私有素材目录（布局见 admin/service/data/DataService::uploadDirectory）
        FileHelper::delDir(ROOT_PATH . 'images/data/' . $slug);
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

        $cloud_account = CloudService::loadCloudAccount();

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
