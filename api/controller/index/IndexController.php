<?php

namespace Dou\Api\Controller\Index;

use Dou\Api\Controller\BaseController;
use Dou\Api\Service\Index\IndexService;
use Dou\Core\Web\Http\ApiResponse;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

class IndexController extends BaseController
{
    /** @var IndexService */
    private $indexService;

    /**
     * @param IndexService $indexService
     */
    public function __construct(IndexService $indexService)
    {
        $this->indexService = $indexService;
    }

    public function index()
    {
        $data = $this->indexService->buildIndexData();
        return ApiResponse::success($data);
    }
}
