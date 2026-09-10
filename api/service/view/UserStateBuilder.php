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

namespace Dou\Api\Service\View;

use Dou\Core\Service\BaseService;
use Dou\Core\Service\User\UserService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 端会员状态视图装配器。
 *
 * 接收显式 $userId（由 shell 层从 auth('api')->id() 取出后传入），
 * 返回 API 响应所需的 is_login / is_vip / is_work / is_distribution 四态。
 *
 * 与 {@see \Dou\Front\Service\View\UserStateBuilder} 同构，分两端是为了让两端的
 * 视图装配责任各自封闭，互不依赖；底层仍复用 core/UserService。
 */
class UserStateBuilder extends BaseService
{
    /** @var UserService */
    private $userService;

    /**
     * @param UserService $userService 核心会员只读服务
     */
    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * 装配指定会员的状态四元组。
     *
     * @param int $userId 显式传入；<=0 时全部返回 false
     * @return array{is_login:bool,is_vip:bool,is_work:bool,is_distribution:bool}
     */
    public function build($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return array(
                'is_login' => false,
                'is_vip' => false,
                'is_work' => false,
                'is_distribution' => false,
            );
        }

        return array(
            'is_login' => true,
            'is_vip' => $this->userService->isVip($userId),
            'is_work' => $this->userService->isWork($userId),
            'is_distribution' => $this->userService->isDistribution($userId),
        );
    }
}
