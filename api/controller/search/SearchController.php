<?php

namespace Dou\Api\Controller\Search;

use Dou\Api\Controller\BaseController;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Front\Service\Search\SearchService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

class SearchController extends BaseController
{
    /** @var SearchService */
    private $searchService;

    /**
     * @param SearchService $searchService
     */
    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    public function index(Request $request)
    {
        $qParam = $request->input('q');
        $raw = $qParam !== null ? $qParam : '';
        $keyword = is_string($raw) ? trim($raw) : '';
        if ($keyword !== '' && !Check::searchKeyword($keyword)) {
            throw new DomainException(lang('search_keyword_wrong', 'Invalid keyword'));
        }

        $searchModule = 'product';
        $moduleInput = $request->input('module');
        if ($moduleInput !== null && $moduleInput !== '' && Check::letter($moduleInput)) {
            $searchModule = trim($moduleInput);
        }

        $catId = $request->integer('category_id', 0);
        $page = $request->integer('page', 1);

        $sortBy = $request->input('by');
        $sortBy = $sortBy !== null ? $sortBy : '';
        $sortDir = $request->input('sort');
        $sortDir = $sortDir !== null ? $sortDir : '';

        $result = $this->searchService->buildSearchResultData(
            $keyword,
            $searchModule,
            $catId,
            $page,
            $sortBy,
            $sortDir
        );

        $data = array();
        $data['title'] = isset($result['title']) && $result['title'] !== ''
            ? $result['title']
            : (lang('search', 'Search'));
        $data['keyword'] = isset($result['keyword']) ? $result['keyword'] : '';
        $data['search_module'] = isset($result['search_module']) ? $result['search_module'] : $searchModule;
        $data['search_results'] = isset($result['search_results']) ? $result['search_results'] : '';
        $data['search_list'] = isset($result['search_list']) ? $result['search_list'] : array();
        $data['sort_list'] = (isset($result['sort_list']) && is_array($result['sort_list']))
            ? $result['sort_list']
            : array();

        return ApiResponse::success($data);
    }
}
