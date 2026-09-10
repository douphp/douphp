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

namespace Dou\Front\Http;

use Dou\Core\Contract\MessageResponderInterface;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台「提示页 + 跳转」实现。
 *
 * 完成 Smarty 装配后由 `view()` helper 构造 dou_msg.dwt 的视图响应并返回。
 * 调用方 `return message()->respond(...)` 将其交由入口统一 send，
 * 或包入 {@see \Dou\Core\Web\Http\HttpResponseException} 短路到入口 catch。
 */
class FrontMessageResponder extends BaseService implements MessageResponderInterface
{
    /**
     * `respond($time)` 收到空字符串 / null 时的兜底秒数（与 interface 签名默认值同义）。
     */
    const DEFAULT_TIMEOUT_SECONDS = 3;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /**
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     */
    public function __construct(NavigationBuilder $nav, BreadcrumbBuilder $breadcrumb)
    {
        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
    }

    /**
     * 本实现额外约定：`$time` 接受空字符串 / null（与 service 数组「键缺省即默认」契约配套），
     * 入口归一化为 {@see self::DEFAULT_TIMEOUT_SECONDS}，避免 `<meta refresh content=""; URL=...">`
     * 被浏览器忽略导致 dou_msg.dwt 不倒计时也不跳转。
     *
     * @inheritDoc
     */
    public function respond($text = '', $url = '', $out = '', $time = 3, $check = '', $btnValue = '', $checkMethod = '')
    {
        $type = '';
        $statusCode = 200;
        $pageTitle = Config::get('site.site_title', '');

        if (!$text) {
            $text = lang('dou_msg_success');
        }
        if ($time === '' || $time === null) {
            $time = self::DEFAULT_TIMEOUT_SECONDS;
        }

        if ($text == 'page_wrong') {
            $text = lang('page_wrong');
            $type = '404';
            $statusCode = 404;
            $cue = lang('dou_msg_page_wrong');
            $pageTitle = $text . ' - ' . Config::get('site.site_name', '');
        } else {
            $cueTpl = lang('dou_msg_cue');
            $cue = preg_replace('/d%/Ums', (string) $time, $cueTpl);
        }

        $data = array(
            'page_title' => $pageTitle,
            'keywords' => Config::get('site.site_keywords', ''),
            'description' => Config::get('site.site_description', ''),
            'nav_top_list' => $this->nav->top(),
            'nav_middle_list' => $this->nav->middle(),
            'nav_bottom_list' => $this->nav->bottom(),
            'ur_here' => $this->breadcrumb->build('page', '', lang('dou_msg')),
            'type' => $type,
            'text' => $text,
            'msg_url' => $url,
            'time' => $time,
            'cue' => $cue,
        );

        return view('dou_msg.dwt', $data, $statusCode);
    }
}
