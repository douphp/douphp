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

namespace Dou\Front\Service\View;

use Dou\Core\Service\BaseService;
use Dou\Core\Service\User\UserService;
use Dou\Core\Service\User\UserStatsService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台会员状态视图装配器。
 *
 * 接收显式 $userId（由 shell 层从 auth('front')->id() 取出后传入），
 * 返回模板 / 中间件聚合所需的 is_login / is_vip / is_work / is_distribution 四态，
 * 以及 dou_user_wallet 快照派生的 money_balance / point_balance / total_consumption。
 *
 * 「auth 主体识别」与「业务状态推导」分离：auth 边界仅产 ID/上下文，业务标记由
 * {@see UserService::isVip()} 等承担，账户余额由 {@see UserStatsService} 承担。
 */
class UserStateBuilder extends BaseService
{
    /** @var UserService */
    private $userService;

    /** @var UserStatsService */
    private $userStatsService;

    /**
     * @param UserService $userService 核心会员只读服务
     * @param UserStatsService $userStatsService 会员统计 / 账户余额服务
     */
    public function __construct(UserService $userService, UserStatsService $userStatsService)
    {
        $this->userService = $userService;
        $this->userStatsService = $userStatsService;
    }

    /**
     * 装配指定会员的状态视图。
     *
     * @param int $userId 显式传入；<=0 时全部返回 false / 0
     * @return array{is_login:bool,is_vip:bool,is_work:bool,is_distribution:bool,money_balance:float,point_balance:int,total_consumption:float}
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
                'money_balance' => 0.0,
                'point_balance' => 0,
                'total_consumption' => 0.0,
            );
        }

        $money = $this->userStatsService->totalMoney($userId);
        $point = $this->userStatsService->totalPoint($userId);

        return array(
            'is_login' => true,
            'is_vip' => $this->userService->isVip($userId),
            'is_work' => $this->userService->isWork($userId),
            'is_distribution' => $this->userService->isDistribution($userId),
            'money_balance' => $money === false ? 0.0 : (float) $money,
            'point_balance' => $point === false ? 0 : (int) $point,
            'total_consumption' => (float) $this->userStatsService->totalConsumption($userId),
        );
    }
}
