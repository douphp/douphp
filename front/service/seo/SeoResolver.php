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

namespace Dou\Front\Service\Seo;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台 SEO 元信息解析。
 *
 * 统一承载页面 <head> 三要素：title / keywords / description。
 *
 * 拼接规则（pageTitle）：
 * - 详情/分类：title + 分类名 + 模块名 + site_name + " - Powered by DouPHP"
 * - 列表：模块名 + site_name + 后缀
 * - 单页：title + site_name + 后缀
 * - 首页：site_title + 后缀
 * 后缀在 Config::get('app.licensed') 为真时不输出。
 *
 * keywords / description：传空 / null 时回退到 site.site_keywords / site.site_description。
 */
class SeoResolver extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 统一页面标题。覆盖原 home / category / detail / page / module 五种形态。
     *
     * - pageTitle()                                     站点首页（site_title + 后缀）
     * - pageTitle('article_category', $catId)           分类列表
     * - pageTitle('article_category', $catId, $title)   分类下详情
     * - pageTitle('page', '', $name)                    page 模块单页
     * - pageTitle('vote', '', $name)                    模块 + 动态标题
     * - pageTitle('user', 'user_login')                 模块 + sub lang key
     * - pageTitle('user', 'user_login', $name)          模块 + sub + 动态标题
     *
     * @param string $module 模块名 / 分类表名（article_category / product_category 等）/ 'page'
     * @param string|int $class 分类 id 或 sub lang key
     * @param string $title 动态标题 / 内容 title
     * @param array $archive 归档参数（{@see \Dou\Core\Support\Util::parseArchive()}）；非空时输出「站点首页标题 - 2026年01月」并忽略 module/class/title
     * @return string
     */
    public function pageTitle($module = '', $class = '', $title = '', array $archive = array())
    {
        if ($archive) {
            return $this->build() . ' - ' . Util::archiveLabel($archive);
        }

        return $this->build($module, $class, $title);
    }

    /**
     * SEO keywords：传空 / null 时回退到 site.site_keywords。
     *
     * @param string|null $value 行字段或分类字段值；空字符串 / null 触发回退
     * @param array $archive 归档参数；非空时在基线 keywords 后追加「 2026年01月」
     * @return string
     */
    public function keywords($value = null, array $archive = array())
    {
        $v = is_string($value) ? trim($value) : '';
        $base = $v !== '' ? $v : (string) Config::get('site.site_keywords', '');

        if ($archive) {
            return $base . ' ' . Util::archiveLabel($archive);
        }

        return $base;
    }

    /**
     * SEO description：传空 / null 时回退到 site.site_description。
     *
     * @param string|null $value 行字段或分类字段值；空字符串 / null 触发回退
     * @param array $archive 归档参数；非空时追加「 - 2026年01月的{模块名}」
     * @param string $module 归档描述的模块名（取 lang 名词，如 video → 视频中心）
     * @return string
     */
    public function description($value = null, array $archive = array(), $module = '')
    {
        $v = is_string($value) ? trim($value) : '';
        $base = $v !== '' ? $v : (string) Config::get('site.site_description', '');

        if ($archive) {
            return $base . ' - ' . Util::archiveLabel($archive) . '的' . lang($module);
        }

        return $base;
    }

    /**
     * @param string $module
     * @param string|int $class
     * @param string $title
     * @return string
     */
    private function build($module = '', $class = '', $title = '')
    {
        $titles = '';
        $catName = '';
        $main = '';

        if ($module == 'page') {
            $titles = $title . ' | ';
        } elseif ($module) {
            if ($module != 'item' && $module != 'item_category') {
                $main = (lang($module)) . ' | ';
            }

            if ($class) {
                if (is_numeric($class)) {
                    $catName = DB::table($module)->where('id', intval($class))->value('name');
                } else {
                    $catName = lang($class);
                }
                if (locale()->isActive()) {
                    $catName = language()->langValue($catName, $module, $class, 'name');
                }
                $catName = $catName . ' | ';
            }

            if ($title) {
                $title = $title . ' | ';
            }

            $titles = $title . $catName . $main;
        }

        $powerTitle = Config::get('app.licensed', false)
            ? ''
            : ' - ' . Util::fromCharCodes(array(0x50, 0x6f, 0x77, 0x65, 0x72, 0x65, 0x64, 0x20, 0x62, 0x79)) . ' DouPHP';

        return ($titles ? $titles . Config::get('site.site_name', '') : Config::get('site.site_title', '')) . $powerTitle;
    }
}
