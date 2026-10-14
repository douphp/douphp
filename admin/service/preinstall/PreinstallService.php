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
 * Release Date: 2026-10-14
 */

namespace Dou\Admin\Service\Preinstall;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\MenuIconMap;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 预置模块服务（打包方 → 站点后台的「预置推广入口」）
 *
 * 机制：升级包 / 发布包在 storage/install/preinstall/ 放置 <模块slug>.php 标识文件，
 * 后台侧栏为「标识存在 且 模块尚未安装（以 config/module.php 为唯一口径）」的模块
 * 渲染一对一入口，点击进入详情页（route=preinstall/<slug>）；详情页「隐藏此预置项」
 * 按钮删除标识文件后入口不再出现。用户真正安装模块后，入口自动隐藏、由真实菜单接管。
 *
 * 文案统一走 preinstall 语言文件（扁平键）：
 *   - 界面键：preinstall / preinstall_hide / preinstall_hide_confirm …；
 *   - 内容键：preinstall_<slug>_intro、preinstall_<slug>_feature_<n>_title/_desc
 *     （n 从 1 顺序读取直到缺键，"有几个功能显示几个"）。
 */
class PreinstallService extends BaseService
{
    /** @var string 标识文件目录（相对 ROOT_PATH） */
    const IDENT_DIR = 'storage/install/preinstall';

    /**
     * 扫描全部标识文件，返回模块 slug 列表（字母序）。
     *
     * 仅识别 <slug>.php（slug 为小写字母开头的 [a-z0-9_-] 串）；index.html 等防护文件忽略。
     *
     * @return array<int,string>
     */
    public function identifiers()
    {
        $dir = ROOT_PATH . self::IDENT_DIR;
        $list = array();
        if (!is_dir($dir)) {
            return $list;
        }

        $dh = @opendir($dir);
        if ($dh === false) {
            return $list;
        }
        while (($entry = readdir($dh)) !== false) {
            if (!preg_match('/^([a-z][a-z0-9_\-]*)\.php$/', $entry, $m)) {
                continue;
            }
            if (is_file($dir . '/' . $entry)) {
                $list[] = $m[1];
            }
        }
        closedir($dh);

        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * 已安装模块全集（config/module.php 的 column_module ∪ single_module）。
     *
     * @return array<int,string>
     */
    public function installedModules()
    {
        return array_values(array_unique(array_merge(
            (array) Config::get('module.column_module'),
            (array) Config::get('module.single_module')
        )));
    }

    /**
     * 模块是否已安装（唯一口径：config/module.php）。
     *
     * @param string $slug
     * @return bool
     */
    public function isInstalled($slug)
    {
        return in_array((string) $slug, $this->installedModules(), true);
    }

    /**
     * 侧栏预置菜单项：标识 ∖ 已安装，逐项计算活跃态。
     *
     * @param string $activeSlug 当前详情页 slug（空串=无高亮）
     * @return array<int,array{name: string, lang: string, url: string, icon: string, is_active: bool}>
     */
    public function promotions($activeSlug = '')
    {
        $installed = $this->installedModules();
        $items = array();

        foreach ($this->identifiers() as $slug) {
            if (in_array($slug, $installed, true)) {
                continue;
            }
            $items[] = array(
                'name' => $slug,
                'lang' => $this->displayName($slug),
                'url' => route('admin.preinstall.show', array('slug' => $slug)),
                'icon' => MenuIconMap::resolve($slug),
                'is_active' => ($activeSlug !== '' && $activeSlug === $slug),
            );
        }

        return $items;
    }

    /**
     * 详情页数据：模块名称 / 简介 / 主要功能卡片。
     *
     * 非法 slug、模块已安装或标识缺失均视为无效（已安装后由真实菜单接管，
     * 不再提供预置详情页）。
     *
     * @param string $slug
     * @return array{name: string, lang: string, intro: string, icon: string, features: array<int,array{title: string, desc: string}>}
     * @throws DomainException
     */
    public function detail($slug)
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '' || $this->isInstalled($slug) || !$this->hasIdentifier($slug)) {
            throw new DomainException(lang('illegal'), route('admin.index'));
        }

        return array(
            'name' => $slug,
            'lang' => $this->displayName($slug),
            'intro' => (string) lang('preinstall_' . $slug . '_intro'),
            'icon' => MenuIconMap::resolve($slug),
            'features' => $this->features($slug),
        );
    }

    /**
     * 删除标识文件（等效「隐藏此预置项」）。
     *
     * 防护：slug 白名单 + realpath 前缀校验，仅允许删除标识目录内的 <slug>.php。
     *
     * @param string $slug
     * @return bool
     */
    public function remove($slug)
    {
        $path = $this->identifierPath($slug);
        if ($path === '') {
            return false;
        }

        return @unlink($path);
    }

    /**
     * 功能卡片：顺序读取 preinstall_<slug>_feature_<n>_title，直到缺键为止。
     *
     * @param string $slug
     * @return array<int,array{title: string, desc: string}>
     */
    private function features($slug)
    {
        $features = array();

        // 上限防御：异常语言包也不会造成死循环
        for ($n = 1; $n <= 50; $n++) {
            $titleKey = 'preinstall_' . $slug . '_feature_' . $n . '_title';
            if (!lang_has($titleKey)) {
                break;
            }
            $features[] = array(
                'title' => (string) lang($titleKey),
                'desc' => (string) lang('preinstall_' . $slug . '_feature_' . $n . '_desc'),
            );
        }

        return $features;
    }

    /**
     * 显示名：模块自身语言键优先，其次 preinstall_<slug>_name 兜底，最后回退 ucfirst(slug)。
     *
     * @param string $slug
     * @return string
     */
    private function displayName($slug)
    {
        if (lang_has($slug)) {
            return (string) lang($slug);
        }
        if (lang_has('preinstall_' . $slug . '_name')) {
            return (string) lang('preinstall_' . $slug . '_name');
        }

        return ucfirst($slug);
    }

    /**
     * 标识文件是否存在（且位于标识目录内）。
     *
     * @param string $slug
     * @return bool
     */
    private function hasIdentifier($slug)
    {
        return $this->identifierPath($slug) !== '';
    }

    /**
     * 解析标识文件真实路径（防护合一：白名单 + realpath 前缀校验）。
     *
     * @param string $slug
     * @return string 合法且存在的真实路径；任一校验失败返回空串
     */
    private function identifierPath($slug)
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') {
            return '';
        }

        $baseReal = realpath(ROOT_PATH . self::IDENT_DIR);
        if ($baseReal === false) {
            return '';
        }

        $real = realpath(ROOT_PATH . self::IDENT_DIR . '/' . $slug . '.php');
        if ($real === false || strpos($real, $baseReal . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }

        return $real;
    }

    /**
     * slug 归一化：仅接受小写字母开头的 [a-z0-9_-] 串，非法返回空串。
     *
     * @param string $slug
     * @return string
     */
    private function normalizeSlug($slug)
    {
        $slug = trim((string) $slug);

        return preg_match('/^[a-z][a-z0-9_\-]*$/', $slug) ? $slug : '';
    }
}
