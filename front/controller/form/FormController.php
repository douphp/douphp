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

namespace Dou\Front\Controller\Form;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Form\FormService as FormCore;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Request\Form\FormSubmitFormRequest;
use Dou\Front\Service\Form\FormService;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台表单控制器
 *
 * 列表页已退役：表单只作为「引擎 + 场景配置」供详情页与任意页面手写调用，
 * `/form` 保留路由仅为兼容旧链接，直接跳首页。
 */
class FormController extends BaseController
{
    /** @var FormService */
    private $formService;

    /** @var FormCore 全局表单定义（路由模板合并用） */
    private $formCore;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param FormService $formService
     * @param FormCore $formCore
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
     */
    public function __construct(
        FormService $formService,
        FormCore $formCore,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo
    ) {
        $this->formService = $formService;
        $this->formCore = $formCore;

        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
    }

    /**
     * 表单列表已退役：`Route::simple('form')` 展开的 `/form` 仍会落到这里，
     * 直接跳首页，避免删方法触发路由兜底报错。
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        return redirect(HOME_URL);
    }

    /**
     * 表单填写页
     *
     * URL 以别名寻址（/form/{slug}）：slug 由后台必填且唯一，可读且不会随主键变化；
     * 提交仍走隐藏域 form_id。
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $slug = (string) $request->route('slug', '');
        $data = $this->formService->getShowPageDataBySlug($slug);
        if ($data === null) {
            throw new DomainException(lang('page_wrong'), HOME_URL);
        }

        // 登录会员进入填表页：清理上次未提交的过期草稿，刷新即清空，不做跨请求回显
        $userId = (int) auth('front')->id();
        if ($userId > 0) {
            attachment()->cleanupUserDrafts('user', $userId, 'form');
        }

        // 全局定义（slug 索引）与当前表单平铺合并：模板既能 foreach field_list，也能手写 $form.{slug}.{字段}
        $form = array_merge($this->formCore->all(), $data['form']);

        // 面包屑：表单列表页已退役（/form 直接跳首页），不生成「表单」模块层级，输出 首页 > 表单名称
        $urHere = $this->breadcrumb->build('', '', $data['form']['name']);

        return $this->view($this->formCore->resolveView($data['form']['slug']), [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('form', '', $data['form']['name']),

            // 页面骨架
            'rec' => 'detail',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('form'),
            'form' => $form,
            'field_count' => $data['field_count'],
            'post_data' => $data['post_data'],
        ]);
    }

    /**
     * 提交处理
     *
     * processSubmit 返回 `{ok, message, redirect_url, errors}`，这里映射成单一响应：
     * 字段错误走 DomainException 的 errors map，成功后按 redirect_url 跳转并带 message。
     *
     * @param FormSubmitFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function submit(FormSubmitFormRequest $formRequest, Request $request)
    {
        $formRequest->validated();

        $formId = (int) $request->input('form_id');
        $post = (array) $request->post();

        $result = $this->formService->processSubmit($formId, $post, (string) $request->ip(), array(
            'user_id' => (int) auth('front')->id(),
            'draft_token' => isset($post['draft_token']) ? (string) $post['draft_token'] : '',
            'source_url' => isset($post['source_url']) ? (string) $post['source_url'] : '',
        ));

        if (empty($result['ok'])) {
            $backUrl = $result['redirect_url'] !== '' ? $result['redirect_url'] : HOME_URL;

            throw new DomainException($result['message'], $backUrl, '', '', $result['errors']);
        }

        return $this->respond($request, $result['redirect_url'], array(), $result['message']);
    }

    /**
     * 提交成功提示页（/form/{slug}/success）
     *
     * 后台未配置 success_url 时，提交后的落点。此前直接回填原表单页，用户看到的是
     * 一张空表单，无法区分「提交成功」与「点击无效」，因此改为独立提示页：
     * 正文用表单的成功提示文案，并提供返回首页 / 返回表单两个出口。
     *
     * 主题未附带 form_success.dwt 时回落通用提示页 dou_msg.dwt，不至于白屏。
     *
     * @param Request $request
     * @return Response
     */
    public function success(Request $request)
    {
        $slug = (string) $request->route('slug', '');
        $data = $this->formService->getShowPageDataBySlug($slug);
        if ($data === null) {
            throw new DomainException(lang('page_wrong'), HOME_URL);
        }

        // 与填写页保持并集语义：slug 索引的全局定义 + 当前表单平铺键，
        // 主题片段里引用其他表单时不会因本页覆盖而丢键
        $form = array_merge($this->formCore->all(), $data['form']);
        $message = $this->formService->successMessage($data['form']);
        $backUrl = route('form.show', array('slug' => $data['form']['slug']));

        if (!$this->hasSuccessView()) {
            return message()->respond($message, $backUrl);
        }

        return $this->view('form_success.dwt', array(
            // 页面头信息
            'page_title' => $this->seo->pageTitle('form', '', $form['name']),

            // 页面骨架
            'rec' => 'success',
            'code_head' => Config::get('site.code_head', ''),

            // 数据
            'form' => $form,
            'success_message' => $message,
            'back_url' => $backUrl,
        ));
    }

    /**
     * 当前主题是否带有专用的提交成功页模板。
     *
     * 判定口径与核心 FormService::resolveView() 一致：主题目录下按文件名探测，
     * 存量主题未升级时不至于渲染出空白页（DouView 找不到模板时静默输出空串）。
     *
     * @return bool
     */
    private function hasSuccessView()
    {
        $theme = (string) Config::get('site.site_theme', 'default');
        if ($theme === '') {
            $theme = 'default';
        }

        return is_file(ROOT_PATH . 'theme/' . $theme . '/form_success.dwt');
    }

    /**
     * 构建公共布局变量
     *
     * @return array
     */
    protected function layoutVars()
    {
        return array(
            'keywords' => $this->seo->keywords(),
            'description' => $this->seo->description(),
            'nav_top_list' => $this->nav->top(),
            'nav_middle_list' => $this->nav->middle(),
            'nav_bottom_list' => $this->nav->bottom(),
        );
    }
}
