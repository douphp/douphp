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

use Dou\Admin\Model\Setting\Setting;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\RedirectException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\FileHelper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台系统参数维护（站点主配置、客户/展示/SEO、自定义与邮件等多 Tab）。
 *
 * 职责：
 * - 组装设置首页 Tab 与每组 config、parameter(system/customer) 列表。
 * - 开发者模式开关（developer）、站点域名与 ROOT_URL 同步。
 * - 表单提交写入 config / parameter（token 由控制器校验）。
 */
class SettingService extends BaseService
{
    public function __construct()
    {
    }

    /**
     * 开发者模式开关：更新 config.developer 后跳回设置首页。
     *
     * @param string $douParam open|close；其它值不处理
     * @return void
     */
    public function applyDeveloperToggle($douParam)
    {
        if ($douParam === 'open') {
            Setting::updateConfigValue('developer', '1');
            throw new RedirectException(route('admin.setting'));
        } elseif ($douParam === 'close') {
            Setting::updateConfigValue('developer', '0');
            throw new RedirectException(route('admin.setting'));
        }
    }

    /**
     * 站点域名为空或为 reset 时，将 config.domain 写成当前 ROOT_URL。
     *
     * @param mixed $domainRequest request 传入的 domain 标记（如 reset）
     * @return void
     */
    public function syncDomainFromRoot($domainRequest)
    {
        if (!Config::get('site.domain', '') || $domainRequest === 'reset') {
            Setting::updateConfigValue('domain', ROOT_URL);
        }
    }

    /**
     * 设置主屏数据：Tab 清单、每组 getCfgList 结果、系统/客户自定义参数与语言列表等。
     *
     * @param mixed $douParam 非 null 时仅展示 developer Tab（开发者入口）
     * @return array tab_list、config_list、parameter_system_list、parameter_customer_list、lang_list、is_developer_tab、column_module_list、cfg_pure_mode
     */
    public function buildSettingIndexData($douParam)
    {
        if ($douParam !== null) {
            $tab_list = array('developer');
        } else {
            $tab_list = array('main', 'customer', 'display', 'seo', 'defined', 'mail');
        }

        $config_list = array();
        foreach ($tab_list as $tab) {
            $config_list[] = array(
                'name' => $tab,
                'lang' => lang('setting_' . $tab),
                'list' => $this->buildConfigList($tab),
            );
        }

        $parameter_system_list = DB::fnQuery("SELECT * FROM " . DB::tableName('parameter') . " WHERE `group` = 'system' ORDER BY sort ASC, id ASC");
        $parameter_customer_list = DB::fnQuery("SELECT * FROM " . DB::tableName('parameter') . " WHERE `group` = 'customer' ORDER BY sort ASC, id ASC");

        return array(
            'tab_list' => $tab_list,
            'config_list' => $config_list,
            'parameter_system_list' => $parameter_system_list,
            'parameter_customer_list' => $parameter_customer_list,
            'lang_list' => language()->buildLangList(),
            'is_developer_tab' => ($douParam !== null),
            'column_module_list' => Config::get('module.column_module'),
            'cfg_pure_mode' => Setting::findRowByConfigName('pure_mode'),
        );
    }

    /**
     * 保存系统表单：写入 config 与 _parameter_* 参数行（入参须已通过 SettingFormRequest）。
     *
     * @param array $validated validated() 结果（可含控制器合并的文件名字段）
     * @return void
     */
    public function persistSettingConfig(array $validated)
    {
        $data = $validated;
        if (isset($data['domain'])) {
            $data['domain'] = $this->normalizeDomainValue($data['domain']);
        }

        Setting::persistFromValidated($data);

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'setting');
    }

    /**
     * 站点网址字段清洗。
     *
     * @param mixed $value
     * @return string|null null 仅当入参为 null；其余返回字符串（可为空串）
     */
    public function normalizeDomainValue($value)
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\\\~@$%^&=+{};\'"<>]/', $value)) {
            return '';
        }
        if (substr($value, -1) !== '/') {
            $value .= '/';
        }

        return $value;
    }

    /**
     * 为设置页面的某个 Tab 组装配置项列表。
     *
     * @param string $tab 参数tab。
     * @return array
     */
    public function buildConfigList($tab = 'main')
    {
        $exclude = [];

        if (SYSTEM_SIGN == 'api') {
            $exclude = ['site_title', 'site_keywords', 'site_description', 'site_logo', 'site_logo_other', 'site_closed', 'rewrite', 'domain', 'sitemap', 'site_favicon', 'show_customer'];
        } else {
            $exclude = ['site_logo_miniprogram'];
        }

        if (Config::get('site.language', '') == 'zh_cn') {
            $exclude = array_merge($exclude, ['facebook', 'twitter', 'pinterest', 'youtube', 'vk', 'linkedin', 'instagram', 'tiktok', 'skype', 'whatsapp']);
        } else {
            $exclude = array_merge($exclude, ['qq', 'weibo']);
        }

        $query = DB::table('config')
            ->where('type', '<>', 'hidden')
            ->where('tab', $tab);

        if (!empty($exclude)) {
            $query->where('name', 'NOT IN', $exclude);
        }

        $cfg_data = $query->order('id ASC')->select();

        $cfg_list = [];
        foreach ((array) $cfg_data as $row) {
            $box = array();
            // 预设选项
            if ($row['box']) {
                $box = explode(",", $row['box']);
            }

            // 必须有值才进行格式化
            if ($row['value']) {
                if ($row['name'] == 'site_logo' || $row['name'] == 'site_logo_other') {
                    if (SYSTEM_SIGN == 'api') {
                        $row['value'] = MINIPROGRAM_DIR . "/" . Config::get('site.miniprogram_code', '') . "/images/" . $row['value'];
                    } else {
                        $row['value'] = "theme/" . Config::get('site.site_theme', '') . "/images/" . $row['value'];
                    }
                }

                if ($row['name'] == 'site_logo_miniprogram') {
                    $row['value'] = MINIPROGRAM_DIR . "/" . Config::get('site.miniprogram_code', '') . "/images/" . $row['value'];
                }

                if ($row['name'] == 'weixin_img') {
                    $row['value'] = $row['value'] ? "images/upload/" . $row['value'] : '';
                }
            }

            if ($row['name'] == 'chat_link' && Config::get('features.chat', false)) {
                $row['value'] = route('admin.chat');
            }

            if ($row['name'] == 'language') {
                $box = FileHelper::getSubdirs(ROOT_PATH . 'languages');
            }

            if ($row['name'] == 'route_page') {
                $box = $this->routeOptions('route_page');
            }

            if ($row['name'] == 'route_column') {
                $box = $this->routeOptions('route_column');
            }

            if ($row['name'] == 'route_simple') {
                $box = $this->routeOptions('route_simple');
            }

            if ($row['name'] == 'editor') {
                $box = $this->formatConfigOption('editor_', array('ueditor', 'vditor'));
            }

            if ($row['name'] == 'open_icon') {
                $box = $this->formatConfigOption('open_icon_', array('close', 'image', 'text'));
            }

            $cueKey = $row['name'] . '_cue';
            $cue = lang($cueKey);

            if ($row['name'] == 'rewrite') {
                $rewrite_file = '';
                // 根据 Web 服务器信息 判断伪静态文件
                if (stristr($_SERVER['SERVER_SOFTWARE'], "Apache")) {
                    $rewrite_file = ".htaccess";
                } elseif (stristr($_SERVER['SERVER_SOFTWARE'], "IIS")) {
                    $iis_exp = explode("/", $_SERVER['SERVER_SOFTWARE']);
                    $iis_ver = isset($iis_exp[1]) ? $iis_exp[1] : '';

                    if ($iis_ver >= 7.0) {
                        $rewrite_file = "web.config";
                    } else {
                        $rewrite_file = "httpd.ini";
                    }
                }

                if (stristr($_SERVER['SERVER_SOFTWARE'], "nginx")) {
                    $nginxKey = $row['name'] . '_cue_nginx';
                    $cue = lang($nginxKey, $cue);
                } elseif ($rewrite_file) {
                    $cue = preg_replace('/d%/Ums', $rewrite_file, $cue);
                } else {
                    $otherKey = $row['name'] . '_cue_other';
                    $cue = lang($otherKey, $cue);
                }
            }

            // 数组类型的设置选项
            $value_array = null;
            if ($row['type'] == 'array') {
                $arr = unserialize($row['value']);
                foreach ((array) $arr as $key => $v) {
                    $subLangKey = $row['name'] . '_' . $key;
                    $subCueKey = $row['name'] . '_' . $key . '_cue';
                    $subLang = ((lang($subLangKey) !== '')) ? lang($subLangKey) : $key;
                    $subCue = lang($subCueKey);
                    $value_array[] = array(
                        "value" => $v,
                        "name" => $row['name'] . '[' . $key . ']',
                        "lang" => $subLang,
                        "cue" => $subCue
                    );
                }
            }

            // select / radio_group 在模板里按 item.value / item.name 读取，这里统一标准化结构
            if ($row['type'] == 'select' || $row['type'] == 'radio_group') {
                $normalizedBox = array();
                foreach ((array) $box as $item) {
                    if (is_array($item)) {
                        $value = isset($item['value']) ? $item['value'] : '';
                        $name = isset($item['name']) ? $item['name'] : $value;
                        $normalizedBox[] = array(
                            'value' => $value,
                            'name' => $name,
                        );
                    } else {
                        $item = (string) $item;
                        $labelKey = $row['name'] . '_' . $item;
                        $label = ((lang($labelKey) !== '')) ? lang($labelKey) : $item;
                        $normalizedBox[] = array(
                            'value' => $item,
                            'name' => $label,
                        );
                    }
                }
                $box = $normalizedBox;
            }

            $itemLangKey = $row['name'];
            $itemLang = lang($itemLangKey);
            if ($itemLang === '' && lang_has('setting_' . $itemLangKey)) {
                $itemLang = lang('setting_' . $itemLangKey);
            }
            if ($itemLang === '') {
                $itemLang = $row['name'];
            }

            $cfg_list[] = array(
                "value" => $value_array ? $value_array : $row['value'],
                "name" => $row['name'],
                "type" => $row['type'],
                "box" => $box,
                "lang" => $itemLang,
                "cue" => $cue
            );
        }

        return $cfg_list;
    }

    /**
     * 从 config/route.php 读取伪静态/路由样式下拉选项。
     *
     * @param string $type route_page | route_column | route_simple
     * @return array
     */
    private function routeOptions($type = 'route_page')
    {
        $type_map = array(
            'route_page' => 'page',
            'route_column' => 'column',
            'route_simple' => 'simple',
        );

        if (!isset($type_map[$type])) {
            return array();
        }
        $config_key = $type_map[$type];

        $file = defined('ROOT_PATH') && ROOT_PATH !== ''
            ? ROOT_PATH . 'config/route.php'
            : dirname(dirname(dirname(__DIR__))) . '/config/route.php';
        if (!file_exists($file)) {
            return array();
        }
        $config = include($file);
        if (!is_array($config) || empty($config[$config_key])) {
            return array();
        }

        $styles = $config[$config_key];
        $options = array();

        foreach ($styles as $key => $style) {
            if (isset($style['name'])) {
                $options[] = array(
                    'value' => $key,
                    'name' => str_replace('{root_url}', ROOT_URL, $style['name']),
                );
            }
        }

        return $options;
    }

    /**
     * 格式化 config 选项为模板可用数组。
     *
     * @param string $prefix 参数prefix。
     * @param array $option 参数option。
     * @return array
     */
    private function formatConfigOption($prefix, $option = array())
    {
        $options = [];

        foreach ($option as $value) {
            $langKey = $prefix . $value;
            $options[] = array(
                'value' => $value,
                'name' => lang($langKey, $value),
            );
        }

        return $options;
    }
}
