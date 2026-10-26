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
 * Release Date: 2026-10-14
 */

namespace Dou\Admin\Controller\Preinstall;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Service\Preinstall\PreinstallService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Routing\RouteManifest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台预置模块详情（route=preinstall/<slug>）
 *
 * 承载打包方在升级包内预置的模块推广入口：页面展示模块名称 / 简介 / 主要功能卡片，
 * 顶部「隐藏此预置项」按钮经 js-delete → DELETE 路由删除标识文件，入口不再出现。
 */
class PreinstallController extends BaseController
{
    /** @var PreinstallService */
    private $preinstallService;

    /**
     * @param PreinstallService $preinstallService
     */
    public function __construct(PreinstallService $preinstallService)
    {
        $this->preinstallService = $preinstallService;
    }

    /**
     * 预置模块详情页（route=preinstall/<slug>）
     *
     * 非法 slug / 模块已安装 / 标识缺失时由 service 抛 DomainException 回后台首页。
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $slug = (string) $request->route('slug', '');
        $detail = $this->preinstallService->detail($slug);

        // 安装入口：走 cloud/install 容器页；模块版本与最低系统版本由 install-resolve 实时解析，
        // 预置页不再把当前系统版本当作 version 传入。
        // 自定义返回地址：安装成功后「进入模块」按钮指向模块真实页面（admin.<slug>）。
        // 注意：route() 对未注册的 admin.<x> 并不返回空串（会退化为前台形态 URL），
        // 因此必须用 RouteManifest 精确判断该后台路由是否真实存在，避免把无效地址带进安装会话；
        // 不存在时不传 back_url，由 buildSuccessButtons 只输出「返回模块管理中心」。
        $landingRouteName = 'admin.' . $slug;
        $landingEntry = RouteManifest::getEntryByName($landingRouteName);
        $moduleLandingUrl = ($landingEntry !== null && $landingEntry->endNamespace() === 'Admin')
            ? route($landingRouteName)
            : '';
        $installQuery = array(
            'type' => 'module',
            'cloud_id' => $slug,
        );
        if ($moduleLandingUrl !== '') {
            $installQuery['back_url'] = $moduleLandingUrl;
        }
        $detail['install_url'] = route('admin.cloud.install', array(), array(
            'query' => $installQuery,
        ));

        return $this->view('preinstall.htm', array(
            'ur_here' => lang('preinstall'),
            'page_actions' => array(
                array(
                    'href' => route('admin.preinstall.remove', array('slug' => $slug)),
                    'text' => lang('preinstall_hide'),
                    'style' => '',
                    'delete' => true,
                    'confirm' => lang('preinstall_hide_confirm'),
                ),
            ),
            'rec' => 'default',
            'preinstall' => $detail,
        ));
    }

    /**
     * 隐藏预置项（DELETE，删除标识文件）
     *
     * @param Request $request
     * @return Response
     */
    public function remove(Request $request)
    {
        $slug = (string) $request->route('slug', '');

        if (!$this->preinstallService->remove($slug)) {
            throw new DomainException(lang('illegal'), route('admin.index'));
        }

        // 跳转剩余首个预置项（信息触达最短），无剩余则回后台首页
        $remain = $this->preinstallService->promotions('');
        $backUrl = !empty($remain) ? $remain[0]['url'] : route('admin.index');

        return redirect($backUrl)->with('success', lang('preinstall_hide_success'));
    }
}
