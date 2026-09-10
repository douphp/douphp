<?php

namespace Dou\Api\Controller\Bootstrap;

use Dou\Api\Controller\BaseController;
use Dou\Api\Service\Bootstrap\BootstrapService;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 应用引导控制器
 *
 * 调用端读取约定：
 *   parsed = parseApiResponse(res)
 *   parsed.data.site / parsed.data.nav_list / parsed.data.lang_v / parsed.data.version
 *   语言包译串改由独立 route=lang 接口下发（见 {@see LangController}），此处仅给出 lang_v 指纹。
 */
class BootstrapController extends BaseController
{
    /** @var BootstrapService */
    private $bootstrapService;

    /**
     * @param BootstrapService $bootstrapService
     */
    public function __construct(BootstrapService $bootstrapService)
    {
        $this->bootstrapService = $bootstrapService;
    }

    /**
     * @return Response
     */
    public function index()
    {
        $data = $this->bootstrapService->build();
        return ApiResponse::success(is_array($data) ? $data : array());
    }
}
