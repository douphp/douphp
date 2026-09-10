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

namespace Dou\Admin\Controller;

use Dou\Admin\Service\Ai\AiToolbarBuilder;
use Dou\Admin\Service\User\UserCenterNavBuilder as AdminUserCenterNavBuilder;
use Dou\Core\Controller\BaseController as CoreBaseController;
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台控制器基类
 *
 * 在 {@see \Dou\Core\Controller\BaseController} 之上提供后台版 view() 与 buildLinkUserCenter()。
 * 后台控制器（`admin/controller/.../*Controller.php`）统一继承本类。
 *
 * 服务调用一律走 helper / 门面：`DB::` / `auth('admin')` / `language()` / `message()` / `route()` …
 * 后台模块账本数据通过 `Config::get('module', ...)` 取；模板/视图引擎实例通过
 * `View::` 门面或容器解析 {@see \Dou\Core\Web\Template\DouView} 取；商业授权状态通过 `Config::get('app.licensed', false)` 取。
 *
 * 后台 `language()` 由容器解析到 {@see \Dou\Admin\Contract\AdminLanguageContract}
 * 的具体实现（real 实现含 `buildLangButtons` / `buildLangList` / `deleteLang`；
 * features.language 关闭或模块卸载时回退到 {@see \Dou\Core\Service\Noop\NullLanguageService}
 * 的 no-op 实现），调用面始终非空。
 */
abstract class BaseController extends CoreBaseController
{
    /**
     * 构造视图响应（后台模板 + layout 公共变量合并）。
     *
     * 合并语义为 PHP 数组 `+` 的"左操作数优先"：
     *   action data > layoutVars()
     *
     * 即：action 自带键覆盖 layout 公共项，layout 公共项作为默认值。
     *
     * @param string $template
     * @param array $data 本页专属变量
     * @param int $statusCode HTTP 状态码（默认 200）
     * @return \Dou\Core\Web\Http\ViewResponse
     */
    protected function view($template, array $data = array(), $statusCode = 200)
    {
        return new \Dou\Core\Web\Http\ViewResponse(
            app(\Dou\Core\Web\Template\TemplateRendererInterface::class),
            $template,
            $this->absolutizeActionUrls($this->injectAiToolbar($data + $this->layoutVars())),
            (int) $statusCode
        );
    }

    /**
     * 把 page_actions / page_sub_actions 各按钮的 href 补成绝对地址。
     *
     * 这些 href 由模板原样渲染为 `<a href>` / `douPost('...')` / `douDelete('...')`，
     * 在伪静态深路径下相对 `index.php?route=...` 会失效；route() 生成的绝对地址原样透传
     * （{@see Util::absolutizeEntryUrl} 仅对 index.php 开头的相对链接补绝对）。
     *
     * @param array $data 合并 layoutVars + AI toolbar 后的视图数据
     * @return array
     */
    private function absolutizeActionUrls(array $data)
    {
        foreach (array('page_actions', 'page_sub_actions') as $key) {
            if (empty($data[$key]) || !is_array($data[$key])) {
                continue;
            }
            foreach ($data[$key] as $idx => $btn) {
                if (!is_array($btn)) {
                    continue;
                }
                if (isset($btn['href'])) {
                    $data[$key][$idx]['href'] = Util::absolutizeEntryUrl((string) $btn['href']);
                }
                if (isset($btn['confirm'])) {
                    $data[$key][$idx]['confirm'] = Util::absolutizeEntryUrl((string) $btn['confirm']);
                }
                if (!empty($btn['attrs']) && is_array($btn['attrs'])) {
                    foreach ($btn['attrs'] as $ak => $av) {
                        $data[$key][$idx]['attrs'][$ak] = Util::absolutizeEntryUrl((string) $av);
                    }
                }
            }
        }
        return $data;
    }

    /**
     * AI 创作触点单点注入：fill / batch 仅 link_ai 模块；assist / translate 任意 create/edit 表单；
     * 幻灯（show / miniprogram/show）单页恒含表单，按 banner 面板注入。
     *
     * @param array $data 合并 layoutVars 后的视图数据
     * @return array
     */
    private function injectAiToolbar(array $data)
    {
        if (isset($data['ai_config'])) {
            return $data;
        }
        $data['ai_config'] = '';

        $cur = isset($data['cur']) ? (string) $data['cur'] : '';
        $rec = isset($data['rec']) ? (string) $data['rec'] : '';
        $context = $rec === 'default' ? 'list' : (($rec === 'create' || $rec === 'edit') ? 'form' : '');

        // 幻灯面板（PC show / 小程序 miniprogram/show）：单页恒含表单，一律按 form 上下文注入；
        // 图像应用只挂 task_type=banner 的（内容模块表单反之，不挂 banner 任务应用）
        $banner = false;
        if ($cur === 'show' && $context !== '') {
            $banner = true;
            $context = 'form';
        } elseif ($cur === 'miniprogram' && $rec === 'show') {
            $banner = true;
            $context = 'form';
        }

        if ($context === '') {
            return $data;
        }

        if (!class_exists(AiToolbarBuilder::class)) {
            return $data;
        }

        /** @var AiToolbarBuilder $toolbar */
        $toolbar = app(AiToolbarBuilder::class);
        $mountable = $this->isAiMountableModule($cur);

        if ($context === 'list') {
            if (!$mountable) {
                return $data;
            }
            $actions = $toolbar->listActions($cur);
            if ($actions) {
                $existing = isset($data['page_sub_actions']) && is_array($data['page_sub_actions'])
                    ? $data['page_sub_actions']
                    : array();
                $data['page_sub_actions'] = array_merge($existing, $actions);
            }
            $data['ai_config'] = $toolbar->pageConfig($cur, $context);

            return $data;
        }

        if ($mountable) {
            $actions = $toolbar->formActions($cur);
            if ($actions) {
                $existing = isset($data['page_sub_actions']) && is_array($data['page_sub_actions'])
                    ? $data['page_sub_actions']
                    : array();
                $data['page_sub_actions'] = array_merge($existing, $actions);
            }
        }

        if ($banner) {
            // 幻灯面板统一以 show 为模块名（字段中文名走 dou_show 列的语言包）
            $data['ai_config'] = $toolbar->pageConfig('show', 'form', true);

            return $data;
        }

        $data['ai_config'] = $toolbar->pageConfig($cur, $context);

        return $data;
    }

    /**
     * 模块是否属于 AI 创作可挂载面（link_ai 及其 _category 变体）。
     *
     * 与 {@see AiToolbarBuilder::mountable()} 同语义，在解析 AI 服务前作廉价前置过滤。
     *
     * @param string $module 模块逻辑名
     * @return bool
     */
    private function isAiMountableModule($module)
    {
        $module = trim((string) $module);
        if ($module === '') {
            return false;
        }

        $base = substr($module, -9) === '_category' ? substr($module, 0, -9) : $module;

        return in_array($base, (array) Config::get('module.link_ai'), true);
    }

    /**
     * 后台 layout 公共变量：每个 action 调用 view() 渲染时自动叠加。
     *
     * 默认实现注入 `flashes` 一次性 flash 容器（与 Controller 端 `redirect($url)->with($type, ...)`
     * 配套），由 inc/page_header.tpl 末尾 foreach 渲染为 notice 横条；读一次后自动清除。
     *
     * `flashes` 经 {@see normalizeFlashes()} 归一化为：
     *   array<type, array{message: string, back_url: string, back_text: string}>
     * 仅保留 message 非空的槽位，模板 foreach 时无需再判空。
     * 支持两种写入形态：`with($key, $msg)`（string 升格）与
     * `with($key, $msg, $backUrl, $backText)`（array 直接落地）。
     *
     * 业务 Controller 按需重写并叠加自己的键：
     *
     *   protected function layoutVars()
     *   {
     *       return parent::layoutVars() + array(
     *           'cur' => 'article',
     *           'submenu' => 'article_category',
     *       );
     *   }
     *
     * 懒求值：仅当 action 真正调用 view() 渲染模板时才执行；返回 JSON / 重定向
     * 的 action 不会触发本方法，也就不会误把 flash 在中转响应里消费掉。
     *
     * 本方法及其重写都**不**直接调 `request` helper；如需 HTTP 元信息（如 `rec` / `cur`），
     * 由 action 在 $data 中显式注入（如 `'rec' => $request->routeAction()`）。
     *
     * @return array
     */
    protected function layoutVars()
    {
        return array(
            'flashes' => $this->normalizeFlashes(Session::pullAllFlashes()),
            'page_actions' => array(),
            'page_sub_actions' => array(),
            'page_breadcrumb' => '',
            'page_cue_inline' => '',
            'page_cue' => '',
        );
    }

    /**
     * 把 {@see Session::pullAllFlashes()} 返回的整张 flash 表归一化为模板可直接消费的结构。
     *
     * 输入：`array<type, string|array|null>`，type 由业务侧 `redirect()->with($type, ...)` 决定。
     * 输出：`array<type, array{message: string, back_url: string, back_text: string}>`，
     *      仅保留 `message` 非空的项，模板 foreach 时无需再判空。
     *
     * @param array<string,mixed> $rawAll
     * @return array<string, array{message: string, back_url: string, back_text: string}>
     */
    private function normalizeFlashes(array $rawAll)
    {
        $out = array();
        foreach ($rawAll as $type => $raw) {
            $one = $this->normalizeOne($raw);
            if ($one['message'] === '') {
                continue;
            }
            $out[(string) $type] = $one;
        }
        return $out;
    }

    /**
     * 把单条 flash 原始值归一化为统一 array 结构。
     *
     * 输入：string（`with($key, $msg)` 写入）/ array（`with(...,$backUrl,$backText)` 写入）/
     * '' / null（未写入）。输出统一为：
     *   array('message' => string, 'back_url' => string, 'back_text' => string)
     *
     * @param mixed $raw
     * @return array{message: string, back_url: string, back_text: string}
     */
    private function normalizeOne($raw)
    {
        if ($raw === '' || $raw === null) {
            return array('message' => '', 'back_url' => '', 'back_text' => '');
        }
        if (is_array($raw)) {
            return array(
                'message' => isset($raw['message']) ? (string) $raw['message'] : '',
                'back_url' => isset($raw['back_url']) ? (string) $raw['back_url'] : '',
                'back_text' => isset($raw['back_text']) ? (string) $raw['back_text'] : '',
            );
        }
        return array(
            'message' => (string) $raw,
            'back_url' => '',
            'back_text' => '',
        );
    }

    /**
     * 删除入口统一响应分流：success 走 302 + flash，二次确认页走原 dou_msg.htm 路径。
     *
     * 与 service 端 array 返回结构约定一致（弱契约：键缺省即默认）：
     * - `confirm_url` 键缺省或为空字符串 → 已确认 / 真删完成 → 走 302 + flash
     * - `confirm_url` 非空 → 尚需用户在 dou_msg.htm 上点 Confirm 按钮二次确认
     * - `timeout` 键缺省或为空字符串 → AdminMessageResponder 兜底为默认 3 秒
     *
     * 用法：
     *
     *   $result = $this->xxxService->delete($id, $request->post());
     *   return $this->respondDeleteResult($result);
     *
     * @param array{message: string, back_url: string, timeout?: string, confirm_url?: string} $result
     * @return Response
     */
    protected function respondDeleteResult(array $result)
    {
        $confirm_url = isset($result['confirm_url']) ? $result['confirm_url'] : '';
        if ($confirm_url === '') {
            return redirect($result['back_url'])->with('success', $result['message']);
        }
        return message()->respond(
            $result['message'],
            $result['back_url'],
            '',
            isset($result['timeout']) ? $result['timeout'] : '',
            $confirm_url,
            '',
            'DELETE'
        );
    }

    /**
     * 行内布尔切换（js-toggle 协议）统一响应分流：
     * AJAX（$.ajax 自带 X-Requested-With，wantsJson 命中）返回 JSON 携带切换后的值，
     * 供前端无刷新翻转状态；普通请求回退 302 + flash 回列表页。
     *
     * @param Request $request
     * @param mixed $value 切换后的值（1/0）
     * @param string $message 结果文案（JSON message / flash message 共用）
     * @param string $backUrl 非 AJAX 时的回跳地址
     * @return Response
     */
    protected function respondToggle($request, $value, $message, $backUrl)
    {
        if ($request->wantsJson()) {
            return ApiResponse::success(array('value' => (int) $value), $message);
        }

        return redirect($backUrl)->with('success', $message);
    }

    /**
     * 后台会员中心子导航 ViewModel；user 模块未装或 Builder 未注册时返回空结构。
     *
     * @param string $currentModule 当前路由模块短名（用于 cur 标记）
     * @return array
     */
    protected function buildLinkUserCenter($currentModule = '')
    {
        if (user() === null) {
            return array();
        }

        if (!app()->has(AdminUserCenterNavBuilder::class)) {
            return array();
        }

        return app(AdminUserCenterNavBuilder::class)->build($currentModule);
    }
}
