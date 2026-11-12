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
 * Release Date: 2026-11-11
 */

namespace Dou\Api\Controller\Aigc;

use Dou\Api\Controller\BaseController;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Aigc\AigcService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 小程序套餐列表（购买仍走 order/buynow，module=aigc_package）。
 */
class PackageController extends BaseController
{
    /** @var AigcService */
    private $aigcService;

    public function __construct(AigcService $aigcService)
    {
        $this->aigcService = $aigcService;
    }

    /**
     * 套餐列表（游客可浏览）。
     *
     * @return Response
     */
    public function package()
    {
        return ApiResponse::success(array(
            'title' => lang('aigc_package', 'AI套餐'),
            'package_list' => $this->aigcService->packages(),
        ));
    }
}
