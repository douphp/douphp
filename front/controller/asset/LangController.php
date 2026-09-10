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

namespace Dou\Front\Controller\Asset;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\I18n\JsLangExporter;
use Dou\Front\Controller\BaseController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台语言包 manifest 脚本端点。
 *
 * 由 code_footer.tpl 以 `<script src="{route('lang_js')}?v={hash}">` 阻塞加载，route.js 之前就位。
 * 输出 window.__douLang（key => 译文），供 theme/default/js/lang.js 的 t() 消费。内容为当前请求
 * 已加载的译串全表，按内容指纹 immutable 缓存；译串变更时 Init 生成的 ?v= 指纹随之变化。
 */
class LangController extends BaseController
{
    /**
     * 输出 window.__douLang 脚本。
     *
     * 先 header_remove 早期可能下发的 text/html / 缓存头，再以 application/javascript 重写，
     * 避免与 SecurityHeaders 的 X-Content-Type-Options: nosniff 冲突导致脚本被拦。
     *
     * @return Response
     */
    public function manifest()
    {
        $pack = self::currentPack();
        $js = JsLangExporter::ensureCachedLangScript('front', $pack);
        $hash = JsLangExporter::manifestHash('front', $pack);

        if (function_exists('header_remove')) {
            header_remove('Content-Type');
            header_remove('Cache-Control');
            header_remove('Pragma');
            header_remove('Expires');
            header_remove('Last-Modified');
        }

        return $this->response($js, 200, array(
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"' . $hash . '"',
        ));
    }

    /**
     * 当前请求语言包标识（与 Init 同口径：多语言激活取 locale 包，否则站点默认语言）。
     *
     * @return string
     */
    public static function currentPack()
    {
        if (function_exists('locale') && locale()->isActive()) {
            return (string) locale()->pack();
        }

        return (string) Config::get('site.language', 'zh_cn');
    }
}
