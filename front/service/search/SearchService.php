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

namespace Dou\Front\Service\Search;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;
use Dou\Core\Support\Str;
use Dou\Core\Support\Util;
use Dou\Front\Model\Search\Search;
use Dou\Front\Service\Sort\ListSortOptionBuilder;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 全站搜索（栏目模块 UNION + 分页）
 */
class SearchService extends BaseService
{
    /** @var ListSortOptionBuilder */
    private $sortBuilder;

    /**
     * @param ListSortOptionBuilder $sortBuilder
     */
    public function __construct(ListSortOptionBuilder $sortBuilder)
    {
        $this->sortBuilder = $sortBuilder;
    }

    /**
     * 供分页使用的搜索页 URL（含 q、module、category_id；可选含当前 by、sort 以保留排序）
     *
     * @param string $keyword 原始关键词
     * @param string $searchModule 单一模块筛选；空为全模块
     * @param int $catId
     * @param string $sortBy 可为空；非空时须与 $sortDir 同时传入才会附加到 URL
     * @param string $sortDir
     * @return string
     */
    public function buildSearchPageUrl($keyword, $searchModule, $catId, $sortBy = '', $sortDir = '')
    {
        $keyword = is_string($keyword) ? $keyword : '';
        $searchModule = is_string($searchModule) ? $searchModule : '';
        $catId = (int) $catId;
        $sortBy = is_string($sortBy) ? trim($sortBy) : '';
        $sortDir = is_string($sortDir) ? trim($sortDir) : '';

        $base = route('search');
        $glue = (strpos($base, '?') !== false) ? '&' : '?';
        $pageUrl = $base . $glue . 'q=' . rawurlencode($keyword);

        if ($searchModule !== '') {
            $pageUrl .= '&module=' . rawurlencode($searchModule);
        }
        if ($catId > 0) {
            $pageUrl .= '&category_id=' . $catId;
        }

        if ($sortBy !== '' && $sortDir !== '' && Check::basicString($sortBy) && Check::letter($sortDir)) {
            $pageUrl .= '&by=' . rawurlencode($sortBy) . '&sort=' . rawurlencode($sortDir);
        }

        return $pageUrl;
    }

    /**
     * 读取后台「显示设置」中的每页条数：search 专用 > product > article > 默认 10
     *
     * @return int
     */
    private function getSearchPageSize()
    {
        $display = Config::get('pagination', array());

        if (isset($display['search']) && (int) $display['search'] > 0) {
            return (int) $display['search'];
        }
        if (isset($display['product']) && (int) $display['product'] > 0) {
            return (int) $display['product'];
        }
        if (isset($display['article']) && (int) $display['article'] > 0) {
            return (int) $display['article'];
        }

        return 10;
    }

    /**
     * 构建搜索结果数据（模板 assign 用）
     *
     * @param string $keyword 已通过 isSearchKeyword 的检索词
     * @param string $searchModule 单一模块名；空为全模块
     * @param int $catId
     * @param int $page
     * @param string $sortBy 对应 $_GET['by']（product 排序）
     * @param string $sortDir 对应 $_GET['sort']
     * @return array search_list、search_results、sort_list、search_module、keyword、pager
     */
    public function buildSearchResultData($keyword, $searchModule, $catId, $page, $sortBy, $sortDir)
    {
        $keyword = is_string($keyword) ? $keyword : '';
        $searchModule = is_string($searchModule) ? $searchModule : '';
        $catId = (int) $catId;
        $page = (int) $page > 0 ? (int) $page : 1;
        $sortBy = is_string($sortBy) ? $sortBy : '';
        $sortDir = is_string($sortDir) ? $sortDir : '';

        $columnModules = is_array(Config::get('module.column_module'))
            ? array_values(array_filter(Config::get('module.column_module')))
            : array();
        if ($searchModule !== '') {
            if (in_array($searchModule, $columnModules, true)) {
                $columnModules = array($searchModule);
            } else {
                $columnModules = array();
            }
        }

        $langPack = locale()->pack();

        $searchList = array();
        $sortList = null;

        $keywordForLang = htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8');
        if ($keyword === '') {
            if ($searchModule === 'product' && lang('product_all') !== '') {
                $searchResults = lang('product_all');
            } else {
                $searchResults = lang('search');
            }
        } else {
            $searchResults = preg_replace(
                '/d%/Ums',
                $keywordForLang,
                lang('search_results'));
        }

        $listTitle = lang('search', 'Search');
        if ($keyword === '') {
            if ($searchModule === 'product' && lang('product_all') !== '') {
                $listTitle = lang('product_all');
            } elseif (lang('search') !== '') {
                $listTitle = lang('search');
            }
        }

        $pageSize = $this->getSearchPageSize();

        // 排序链接用不含 by/sort 的基准 URL；分页须带上当前 by/sort 以免翻页丢失
        $pageUrlForSort = $this->buildSearchPageUrl($keyword, $searchModule, $catId);
        $pageUrlForPager = $this->buildSearchPageUrl($keyword, $searchModule, $catId, $sortBy, $sortDir);

        $orderSql = 'created_at DESC, id DESC';

        if ($searchModule === 'product') {
            $douSort = $this->sortBuilder->buildSortOptions(
                'sales, price, created_at, sort',
                'price',
                'sort ASC, id DESC',
                $sortBy,
                $sortDir,
                $pageUrlForSort,
                ''
            );
            $sortList = isset($douSort['field']) ? $douSort['field'] : array();
            $orderSql = isset($douSort['sql']) ? (string) $douSort['sql'] : 'created_at DESC, id DESC';
        }

        if ($keyword === '') {
            $query = Search::buildUnionListQuery($columnModules, $catId, $orderSql);
        } else {
            $query = Search::buildUnionSearchQuery($columnModules, $keyword, $catId, $langPack, $orderSql);
        }
        if ($query === null) {
            $pager = Search::runEmptySearchPager($pageSize, $page, $pageUrlForPager);

            return array(
                'search_list' => $searchList,
                'search_results' => $searchResults,
                'sort_list' => $sortList,
                'search_module' => $searchModule,
                'keyword' => $keyword,
                'title' => $listTitle,
                'pager' => $pager,
            );
        }

        $paged = Search::fetchUnionSearchRows($query, $pageSize, $page, $pageUrlForPager);
        $rows = isset($paged['list']) && is_array($paged['list']) ? $paged['list'] : array();
        $pager = isset($paged['pager']) && is_array($paged['pager']) ? $paged['pager'] : array();
        foreach ($rows as $row) {
            $searchList[] = $this->formatSearchRow($row, $keyword);
        }

        return array(
            'search_list' => $searchList,
            'search_results' => $searchResults,
            'sort_list' => $sortList,
            'search_module' => $searchModule,
            'keyword' => $keyword,
            'title' => $listTitle,
            'pager' => $pager,
        );
    }

    /**
     * 单条 UNION 结果格式化为模板行
     *
     * @param array $row
     * @param string $keyword
     * @return array
     */
    private function formatSearchRow($row, $keyword)
    {
        $row = is_array($row) ? $row : array();
        $keyword = is_string($keyword) ? $keyword : '';

        $module = isset($row['module']) ? (string) $row['module'] : '';

        $useLangValue = locale()->isActive();
        if (!$useLangValue && !empty($GLOBALS['_CUR_LANG']) && is_array($GLOBALS['_CUR_LANG'])) {
            $useLangValue = true;
        }
        if ($useLangValue) {
            if (isset($row['title'])) {
                $row['title'] = language()->langValue($row['title'], $module, $row['id'], 'title');
            }
            if (isset($row['content'])) {
                $row['content'] = language()->langValue($row['content'], $module, $row['id'], 'content');
            }
            if (isset($row['description'])) {
                $row['description'] = language()->langValue($row['description'], $module, $row['id'], 'description');
            }
        }

        $url = route($module . '.show', array('id' => $row['id']));
        $addTime = Util::toTimestamp(isset($row['created_at']) ? $row['created_at'] : null);
        $addTimeStr = $addTime !== null ? date('Y-m-d', $addTime) : '';
        $addTimeShort = $addTime !== null ? date('m-d', $addTime) : '';

        $description = !empty($row['description'])
            ? $row['description']
            : Str::excerpt(isset($row['content']) ? $row['content'] : '', 150, false);

        $thumb = attachment()->url(isset($row['image']) ? $row['image'] : '', true);
        $priceRaw = isset($row['price']) ? $row['price'] : 0;
        $price = $priceRaw > 0
            ? Util::formatPrice($priceRaw)
            : (lang('price_discuss'));

        $cateInfoRow = DB::table($module . '_category')
            ->field('id, name')
            ->where('id', isset($row['category_id']) ? $row['category_id'] : 0)
            ->find();
        $cateInfoRow = is_array($cateInfoRow) ? $cateInfoRow : array();
        $cateInfoRow = language()->langBox($cateInfoRow, $module . '_category', 'name');
        $cateInfoRow['url'] = route($module . '.category', array('category_id' => isset($row['category_id']) ? $row['category_id'] : 0));

        $titlePlain = isset($row['title']) ? $row['title'] : '';
        $titleEsc = htmlspecialchars($titlePlain, ENT_QUOTES, 'UTF-8');
        $kwEsc = htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8');
        if ($kwEsc !== '') {
            $highlightTitle = str_replace($kwEsc, '<b>' . $kwEsc . '</b>', $titleEsc);
        } else {
            $highlightTitle = $titleEsc;
        }

        return array(
            'id' => isset($row['id']) ? $row['id'] : 0,
            'category_id' => isset($row['category_id']) ? $row['category_id'] : 0,
            'module' => $module,
            'name' => $highlightTitle,
            'title' => $highlightTitle,
            'price' => $price,
            'image' => $module === 'product' ? $thumb : attachment()->url(isset($row['image']) ? $row['image'] : ''),
            'thumb' => $thumb,
            'created_at' => $addTimeStr,
            'add_time_short' => $addTimeShort,
            'time' => Util::toDateParts($addTime),
            'description' => $description,
            'url' => $url,
            'cate_info' => $cateInfoRow,
        );
    }
}
