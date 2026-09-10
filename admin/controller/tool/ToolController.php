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

namespace Dou\Admin\Controller\Tool;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Tool\ToolFormRequest;
use Dou\Admin\Service\Tool\ToolService;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\I18n\JsLangExporter;
use Dou\Core\Web\Routing\JsRouteExporter;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 系统工具（目录检测、URL 替换等）
 *
 * 网址替换 POST 使用 {@see ToolFormRequest}（scene=store）；业务由 {@see ToolService} 承载。
 */
class ToolController extends BaseController
{
    /** @var ToolService */
    private $toolService;

    /**
     * @param ToolService $toolService
     */
    public function __construct(ToolService $toolService)
    {
        $this->toolService = $toolService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'tool',
        );
    }

    /**
     * @return Response
     */
    public function directoryCheck()
    {
        $bundle = $this->toolService->buildDirectoryCheckData();

        return $this->view('tool.htm', [
            'ur_here' => lang('tool_directory_check'),
            'rec' => 'directory_check',
            'writeable_list' => $bundle['writeable_list'],
        ]);
    }

    /**
     * @return Response
     */
    public function replaceUrl()
    {
        $bundle = $this->toolService->buildReplaceUrlPageData();

        return $this->view('tool.htm', [
            'ur_here' => lang('tool_replace_url'),
            'page_actions' => array(
                array('href' => $bundle['action_link']['href'], 'text' => $bundle['action_link']['text'], 'style' => ''),
            ),
            'rec' => 'replace_url',
        ]);
    }

    /**
     * 提交编辑器网址批量替换。
     *
     * 字段规则见 {@see ToolFormRequest::rules()}；Action 注入 scene=store。
     *
     * @param ToolFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(ToolFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->toolService->storeReplaceUrl($data);

        return redirect(route('admin.index'))->with('success', lang('tool_replace_url') . lang('success'));
    }

    /**
     * @return Response
     */
    public function customAdminDir()
    {
        $bundle = $this->toolService->buildCustomAdminDirPageData();

        $stateDir = STORAGE_PATH . 'state/';
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0777, true);
        }
        file_put_contents($stateDir . 'custom_admin_dir.candel.php', $bundle['script_source']);

        return $this->view('tool.htm', [
            'ur_here' => lang('tool_custom_admin_dir'),
            'page_actions' => array(
                array('href' => $bundle['action_link']['href'], 'text' => $bundle['action_link']['text'], 'style' => ''),
            ),
            'rec' => 'custom_admin_dir',
            'session_key' => $bundle['session_key'],
            'session_value' => $bundle['session_value'],
            'admin_dir' => ADMIN_DIR,
            'developer_mode' => true,
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function sort(Request $request)
    {
        $act = $request->rec('act', 'close');
        // slug 允许下划线（如 ai_provider / ai_model），alpha 仅纯字母会把这些模块名过滤成默认值
        $module = $request->slug('module', 'article');

        $this->toolService->applySortTool($act, $module, DOU_ID);

        return redirect(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : route('admin.index'));
    }

    /**
     * Ajax：切换列表布尔字段（0/1 取反），返回新值与展示文案（非法请求静默返回）。
     *
     * @param Request $request
     * @return Response
     */
    public function changeField(Request $request)
    {
        $module = $request->input('module', '');
        if (!Check::letter($module)) {
            return $this->response('');
        }
        $item_id = $request->input('item_id', '');
        if (!Check::number($item_id)) {
            return $this->response('');
        }
        $field = $request->input('field', 'status');
        if (!Check::letter($field)) {
            $field = 'status';
        }

        $result = $this->toolService->toggleTableField($module, $item_id, $field);

        if ($request->wantsJson()) {
            return ApiResponse::success($result, (string) $result['label']);
        }

        return $this->response($result['label']);
    }

    /**
     * @return Response
     */
    public function editor()
    {
        $this->toolService->persistEditorToggle();

        return redirect(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : route('admin.index'));
    }

    /**
     * 后台命名路由 manifest 脚本（window.__douRouteManifest = {...};）。
     *
     * 由 javascript.tpl 以 `<script src="{route('admin.tool.routes_js')}?v={hash}">` 阻塞加载，
     * route.js 之前就位。内容只是 name => pattern 映射，按内容指纹 immutable 缓存；路由表变更时
     * Init 生成的 ?v= 指纹随之变化触发浏览器重新拉取。
     *
     * 早期 Init 已下发 text/html 与 no-cache 头，这里先 header_remove 再以 application/javascript
     * 重写，避免与 SecurityHeaders 的 X-Content-Type-Options: nosniff 冲突导致脚本被拦。
     *
     * @return Response
     */
    public function routesJs()
    {
        $js = JsRouteExporter::ensureCachedManifest('Admin');
        $hash = JsRouteExporter::manifestHash('Admin');

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
     * 后台语言包 manifest 脚本（window.__douLang = {...};）。
     *
     * 与 {@see routesJs()} 同模式：javascript.tpl 以 `<script src="{route('admin.tool.lang_js')}?v={hash}">`
     * 在 common.js 之前阻塞加载，供 js/lang.js 的 lang() 取译串。内容为当前请求已加载的
     * 译串全表（lang_all()），按内容指纹 immutable 缓存；译串变更时 Init 生成的 ?v= 指纹随之变化。
     *
     * @return Response
     */
    public function langJs()
    {
        $pack = (string) Config::get('site.language', 'zh_cn') . '/admin';
        $js = JsLangExporter::ensureCachedLangScript('admin', $pack);
        $hash = JsLangExporter::manifestHash('admin', $pack);

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
}
