<?php

namespace Dou\Api\Controller\Bootstrap;

use Dou\Api\Controller\BaseController;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\I18n\JsLangExporter;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 语言包接口控制器（小程序独立拉取语言包）
 *
 * 对应路由：route=lang。输出当前请求已加载的译串全表（lang_all()）作为 data，供
 * commonStore.lang 合并消费。与 bootstrap 拆分后可独立缓存：bootstrap 仅下发 lang_v 指纹，
 * 客户端据其变化决定是否重新拉取本接口。
 *
 * 内容与 {@see JsLangExporter} 缓存 JSON 同源；按内容指纹下发 ETag / 长缓存头。
 *
 * 调用端读取约定：
 *   parsed = parseApiResponse(res)
 *   parsed.data // { key => 译文 }
 */
class LangController extends BaseController
{
    /**
     * @return Response
     */
    public function index()
    {
        $pack = (string) Config::get('site.language', 'zh_cn');
        $json = JsLangExporter::ensureCachedLangJson('api', $pack);
        $hash = JsLangExporter::manifestHash('api', $pack);
        $lang = json_decode($json, true);

        if (!is_array($lang)) {
            $lang = function_exists('lang_all') ? (array) lang_all() : array();
        }

        if (function_exists('header_remove')) {
            header_remove('Content-Type');
            header_remove('Cache-Control');
            header_remove('Pragma');
            header_remove('Expires');
            header_remove('Last-Modified');
        }

        $response = ApiResponse::success($lang);
        $response->setHeader('Cache-Control', 'public, max-age=31536000, immutable');
        $response->setHeader('ETag', '"' . $hash . '"');

        return $response;
    }
}
