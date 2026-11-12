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
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Aigc\AigcService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 小程序会员侧：配额与订阅（route=user/aigc）。
 */
class UserController extends BaseController
{
    /** @var AigcService */
    private $aigcService;

    public function __construct(AigcService $aigcService)
    {
        $this->aigcService = $aigcService;
    }

    /**
     * 我的 AI 应用：配额状态 + 订阅记录。
     *
     * @return Response
     */
    public function index()
    {
        $userId = (int) auth('api')->id();
        if ($userId <= 0) {
            return ApiResponse::error(ApiCodes::UNAUTHORIZED, lang('aigc_tips_need_login', '请先登录'), array(), 401);
        }

        return ApiResponse::success(array(
            'title' => lang('aigc_user', '我的AI应用'),
            'quota_status' => $this->aigcService->quotaStatus($userId),
            'subscription_list' => $this->aigcService->subscriptions($userId),
        ));
    }
}
