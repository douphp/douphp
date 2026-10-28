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

namespace Dou\Core\Service\Data;

use Dou\Core\Contract\DataServiceContract;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * data 模块核心服务：碎片化数据读取，承载 {@see DataServiceContract} 的真实现。
 *
 * 两类读路径共用底层私有方法 {@see fetchTree()}（按 group / item / parent 取 data 行 +
 * is_class 父节点递归 child）；各自维护实例缓存，请求生命周期内同样入参不重复打 DB。
 */
class DataService extends BaseService implements DataServiceContract
{
    /**
     * 整张 dict 的实例缓存（首次调 get() 时填充）。
     *
     * @var array|null
     */
    private $getCache = null;

    /**
     * 按 (group, item, parent) tuple 缓存的模块查询结果。
     *
     * @var array<string, array>
     */
    private $queryCache = array();

    /**
     */
    public function __construct()
    {
    }

    /**
     * 读取当前模板下整张 data 数据，可按 code / field 精确取值。
     *
     * 模板（theme 列）按上下文解析：常规端取网站主题；api 端（小程序）在此基础上再叠加
     * 小程序模板包（site.miniprogram_code）的行，见 {@see fetchAll()}。
     * 首次调用打一次 DB，之后任何形态的访问全部命中实例缓存。
     *
     * - get()                          整张 dict（按 code 索引）
     * - get('abc')                     单行 array|null
     * - get('abc', 'name')             单字段 mixed|null
     * - get('abc', 'image', '/d.png')  带默认值
     *
     * @param string|null $code
     * @param string|null $field
     * @param mixed       $default
     * @return mixed
     */
    public function get($code = null, $field = null, $default = null)
    {
        if ($this->getCache === null) {
            $this->getCache = $this->fetchAll();
        }

        if ($code === null) {
            return $this->getCache;
        }

        if (!isset($this->getCache[$code])) {
            return $default;
        }

        if ($field === null) {
            return $this->getCache[$code];
        }

        return isset($this->getCache[$code][$field]) ? $this->getCache[$code][$field] : $default;
    }

    /**
     * 按 (group, item, parent) tuple 查询模块绑定数据。
     *
     * 同 tuple 在请求生命周期内只打一次 DB；典型用于 controller 取某条内容的 data 列表。
     *
     * @param string $group   data_group
     * @param string $item    data_item
     * @param string $parent  parent_code（递归层用）
     * @return array
     */
    public function query($group, $item = '', $parent = '')
    {
        $cacheKey = $group . '|' . $item . '|' . $parent;
        if (!isset($this->queryCache[$cacheKey])) {
            $this->queryCache[$cacheKey] = $this->fetchTree((string) $group, (string) $item, (string) $parent);
        }

        return $this->queryCache[$cacheKey];
    }

    /**
     * 载入整张 data 字典（`get()` 的缓存源）。
     *
     * 常规端（前台 / 后台）只载入当前网站主题的行；api 端（小程序）在网站主题行之上
     * 再叠加当前小程序模板包的行，同 code 由小程序模板行覆盖：既保证老模板能继续读到网站
     * 主题的公用片段，又能读到小程序模板自己的片段。
     *
     * 模板包标识取 {@see DataTemplate::currentCode()}：api 端按请求上报的包身份（X-MP-Code），
     * 未上报时回落后台启用值 site.miniprogram_code。
     *
     * @return array code => row
     */
    private function fetchAll()
    {
        $siteTheme = (string) Config::get('site.site_theme', '');
        $dataList = $this->fetchTree('data', '', '', $siteTheme);

        if (!DataTemplate::inMiniprogram()) {
            return $dataList;
        }

        $miniprogramCode = DataTemplate::currentCode();
        if ($miniprogramCode === '' || $miniprogramCode === $siteTheme) {
            return $dataList;
        }

        return array_merge($dataList, $this->fetchTree('data', '', '', $miniprogramCode));
    }

    /**
     * 按 group / item / parent 取 data 行并递归填充 is_class 父节点的 child。
     *
     * `$dataGroup == 'data'` 时不过滤 group / item / parent，对应整张模板数据；其余按字段精确过滤。
     * 主题（theme 列）按分组解析：小程序分组取 site.miniprogram_code，其余取 site.site_theme，
     * 见 {@see DataTemplate::codeForGroup()}；递归子行沿用同一模板。
     *
     * @param string      $dataGroup
     * @param string      $dataItem
     * @param string      $parentCode
     * @param string|null $theme 模板标识（null 时按分组解析）
     * @return array
     */
    private function fetchTree($dataGroup, $dataItem, $parentCode, $theme = null)
    {
        if ($theme === null) {
            $theme = DataTemplate::codeForGroup($dataGroup);
        }

        $query = DB::table('data')
            ->where('theme', (string) $theme);
        $dataList = array();

        if ($dataGroup != 'all' && $dataGroup != 'data') {
            $query->where('data_group', $dataGroup)
                ->where('data_item', $dataItem);
        }

        if ($dataGroup != 'data') {
            $query->where('parent_code', $parentCode);
        }

        $query->order('sort ASC, id ASC');
        $result = $query->select();

        foreach ((array) $result as $row) {
            $row = language()->langBox($row, 'data', 'name, image, text, link');

            $text = (string) $row['text'];
            $textArray = $text === ''
                ? array()
                : explode("\n", str_replace(array("\r\n", "\r"), "\n", $text));

            $child = array();
            if ($row['is_class']) {
                $child = $this->fetchTree($row['data_group'], $row['data_item'], $row['code'], $theme);
            }

            $dataList[$row['code']] = array(
                'id' => $row['id'],
                'data_group' => $row['data_group'],
                'data_item' => $row['data_item'],
                'name' => $row['name'],
                'code' => $row['code'],
                'image' => attachment()->url($row['image']),
                'text' => $row['text'],
                'text_array' => $textArray,
                'link' => $row['link'],
                'is_class' => $row['is_class'],
                'child' => $row['is_class'] ? $child : array(),
                'content' => $row['image']
                    ? '<img src="' . attachment()->url($row['image']) . '">'
                    : '<span>' . $row['text'] . '</span>',
            );
        }

        return $dataList;
    }
}
