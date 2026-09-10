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

namespace Dou\Front\Service\Nav;

use Dou\Core\Facade\DB;
use Dou\Core\Facade\Url;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;
use Dou\Core\Web\Routing\UrlGenerator;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台主导航 ViewModel 构建。
 *
 * 输出与模板对齐的字段：name、url、cur、icon、child、target 等。
 * 一次实例内全表静态缓存，避免控制器多次调用重复读表。
 */
class NavigationBuilder extends BaseService
{
    /** @var array|null 全表缓存（status=1） */
    private $rowsCache = null;

    /**
     */
    public function __construct()
    {
    }

    /**
     * 顶部导航。
     *
     * @return array
     */
    public function top()
    {
        return $this->buildType('top');
    }

    /**
     * 中部导航（主菜单），可携带当前页高亮上下文。
     *
     * @param int $parentId 起始父级 id
     * @param string $currentModule 当前模块名
     * @param int|string $currentId 当前内容 / 分类 id
     * @param int|string $currentParentId 当前内容所属父级分类 id
     * @return array
     */
    public function middle($parentId = 0, $currentModule = '', $currentId = '', $currentParentId = '')
    {
        return $this->buildType('middle', $parentId, $currentModule, $currentId, $currentParentId);
    }

    /**
     * 底部导航；当底部数据为空时回退到中部导航。
     *
     * @return array
     */
    public function bottom()
    {
        $nav = $this->buildType('bottom');
        return $nav ?: $this->buildType('middle');
    }

    /**
     * @param string $type
     * @param int|string $parentId
     * @param string $currentModule
     * @param int|string $currentId
     * @param int|string $currentParentId
     * @return array
     */
    private function buildType($type, $parentId = 0, $currentModule = '', $currentId = '', $currentParentId = '')
    {
        $data = $this->loadRows();
        $nav = array();

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }
            $value = language()->langBox($value, 'nav', 'name, guide');

            if ($value['parent_id'] != $parentId || $value['type'] != $type) {
                continue;
            }

            if (strpos($value['name'], '#') !== false) {
                $parts = explode('#', $value['name']);
                $value['name'] = $parts[0];
                $value['name_en'] = $parts[1];
            }

            if ($value['module'] == 'nav') {
                if (strpos($value['guide'], 'http://') === 0 || strpos($value['guide'], 'https://') === 0) {
                    $value['url'] = $value['guide'];
                    $value['target'] = true;
                } else {
                    $guide = isset($value['guide']) ? (string) $value['guide'] : '';
                    $value['url'] = ROOT_URL . $guide;
                    $value['cur'] = $guide !== '' ? strpos($_SERVER['REQUEST_URI'], $guide) : false;
                }
            } else {
                $value['slug'] = Url::getSlugPath($value['module'], $value['guide'], 'short');
                list($navRoute, $navParams, $navOptions) = UrlGenerator::navStorageToUrlArgs($value['module'], $value['guide']);
                $value['url'] = route($navRoute, $navParams, $navOptions);
                $value['cur'] = Util::isCurrent($value['module'], $value['guide'], $currentModule, $currentId, $currentParentId);
            }
            $value['icon'] = Config::get('site.open_icon', '') == 'image' ? attachment()->url($value['icon']) : $value['icon'];
            $value['status'] = language()->dataLangFormat('nav_status_', $value['status']);

            $hasChild = false;
            foreach ($data as $child) {
                if (!is_array($child)) {
                    continue;
                }
                if ($child['parent_id'] == $value['id']) {
                    $hasChild = true;
                    break;
                }
            }
            $value['child'] = $hasChild ? $this->buildType($type, $value['id'], $currentModule, $currentId, $currentParentId) : array();
            if (!isset($value['target'])) {
                $value['target'] = false;
            }
            $nav[] = $value;
        }

        return $nav;
    }

    /**
     * @return array
     */
    private function loadRows()
    {
        if ($this->rowsCache === null) {
            $this->rowsCache = DB::table('nav')->where('status', '1')->order('sort ASC')->select();
            if (!is_array($this->rowsCache)) {
                $this->rowsCache = array();
            }
            language()->warmup('nav', array_column($this->rowsCache, 'id'), 'name, guide');
        }

        return $this->rowsCache;
    }
}
