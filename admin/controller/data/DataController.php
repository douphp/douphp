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

namespace Dou\Admin\Controller\Data;

use Dou\Admin\Controller\BaseController;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Admin\Request\Data\DataFormRequest;
use Dou\Admin\Service\Data\DataService;
use Dou\Admin\Service\Menu\AdminNavResolver;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Routing\RouteEntry;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台数据控制器
 *
 * 参数读取与安全校验在控制器完成，业务由 Service 承载。
 */
class DataController extends BaseController
{
    /** @var DataService */
    private $dataService;

    /**
     * @param DataService $dataService
     */
    public function __construct(DataService $dataService)
    {
        $this->dataService = $dataService;
    }

    /**
     * 用本页记录所属数据组重算导航态（$nav 契约）。
     *
     * data 页的子菜单族 / 激活项 / 侧栏节点由**记录所属数据组**决定（编辑页 URL 不带 group
     * 参数，引擎级解析无法预知），因此以页面级 override 重新调用 {@see AdminNavResolver::resolve}，
     * 并作为 view() 合并的最左操作数压过 {@see BaseController::navFallbackVars()} 的兜底值。
     *
     * 覆盖映射（与旧 data.htm 选族及 site_home / miniprogram 子菜单的字符串比较逐条对齐）：
     *   - miniprogram 组 → 小程序族 + data 项；
     *   - banner 组 → 站点首页族 + data_banner 项；index 组 → 站点首页族 + site_home 项；
     *   - site_home / show / fragment / box 组 → 站点首页族 + 同名项；
     *   - common / all 组 → 站点首页族 + data 项（与中央解析缺省一致）；
     *   - 其余（业务模块数据组或空组）→ 站点首页族但无激活项。
     * 侧栏节点：聚合数据组归「站点首页」，小程序数据组归「小程序」，
     * 模块数据组（cur=模块名）归模块自身节点。
     *
     * @param array $bundle DataService 产出的页面数据（含 cur / group 键）
     * @return array
     */
    private function navVars(array $bundle)
    {
        $dataGroup = isset($bundle['group']) ? (string) $bundle['group'] : '';
        $cur = isset($bundle['cur']) ? (string) $bundle['cur'] : 'data';

        $overrides = array();
        if ($dataGroup === 'miniprogram') {
            $overrides['sub_menu'] = 'miniprogram';
            $overrides['sub_item'] = 'data';
        } elseif ($dataGroup === 'banner') {
            $overrides['sub_item'] = 'data_banner';
        } elseif ($dataGroup === 'index') {
            $overrides['sub_item'] = 'site_home';
        } elseif (in_array($cur, array('site_home', 'show', 'fragment', 'box'), true)) {
            $overrides['sub_item'] = $cur;
        } elseif ($cur === 'data' && in_array($dataGroup, array('common', 'all'), true)) {
            $overrides['sub_item'] = 'data';
        } else {
            // 业务模块数据组（cur 为模块名）或空组：渲染站点首页族但无激活项
            $overrides['sub_item'] = null;
        }

        $sideMap = array(
            'data' => 'site_home', 'site_home' => 'site_home', 'show' => 'site_home',
            'box' => 'site_home', 'fragment' => 'site_home', 'miniprogram' => 'miniprogram',
        );
        $overrides['side'] = isset($sideMap[$cur]) ? $sideMap[$cur] : $cur;

        $entry = request()->routeEntry();
        $routeName = $entry instanceof RouteEntry ? (string) $entry->name : '';

        return array(
            'cur' => $cur,
            'nav' => AdminNavResolver::resolve($routeName, $overrides),
        );
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $group = $request->rec('group', 'common');
        $bundle = $this->dataService->buildDataListData($group);
        $navVars = $this->navVars($bundle);

        $pageSubActions = array();
        if ($bundle['group'] === 'all' || $bundle['group'] === 'common') {
            $pageSubActions[] = $bundle['group'] === 'all'
                ? array('href' => route('admin.data'),           'text' => lang('data_all_hidden'))
                : array('href' => route('admin.data', ['group' => 'all']), 'text' => lang('data_all'));
        }

        return $this->view('data.htm', $navVars + [
            'ur_here' => $bundle['ur_here'],
            'page_actions' => array(
                array('href' => $bundle['action_link']['href'], 'text' => $bundle['action_link']['text'], 'style' => ''),
            ),
            'page_sub_actions' => $pageSubActions,
            'rec' => 'default',
            'data_list' => $bundle['data_list'],
            'group' => $bundle['group'],
            'in_page' => $bundle['in_page'],
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function create(Request $request)
    {
        $group = $request->rec('group', 'common');
        $parentCode = $request->slug('parent_code');
        $dataGroup = $request->alpha('group');
        $dataItem = $request->slug('item');
        $bundle = $this->dataService->buildDataDefaultData($group, $parentCode, $dataGroup, $dataItem);
        $navVars = $this->navVars($bundle);

        return $this->view('data.htm', $navVars + [
            'ur_here' => $bundle['ur_here'],
            'page_actions' => array(
                array('href' => $bundle['action_link']['href'], 'text' => $bundle['action_link']['text'], 'style' => ''),
            ),
            'rec' => 'create',
            'btn_lang' => $bundle['btn_lang'],
            'data' => $bundle['data'],
            'lang' => $bundle['lang'],
            'group' => $bundle['group'],
            'in_page' => $bundle['in_page'],
        ]);
    }

    /**
     * @param DataFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(DataFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        return redirect($this->dataService->insert($data));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.data'));
        }

        $bundle = $this->dataService->buildDataEditData($id);
        if (!$bundle) {
            throw new DomainException(lang('illegal'), route('admin.data'));
        }
        $navVars = $this->navVars($bundle);

        $data = $bundle['data'];
        $pageActions = array(
            array('href' => $bundle['action_link']['href'], 'text' => $bundle['action_link']['text'], 'style' => ''),
            array(
                'href'  => route('admin.data.lock', array('id' => $data['id'])),
                'text'  => $data['is_locked'] ? lang('data_unlock') : lang('data_lock'),
                'style' => 'gray',
                'post'  => true,
            ),
        );
        if (!$data['is_locked']) {
            $pageActions[] = array(
                'href'   => route('admin.data.destroy', array('id' => $data['id'])),
                'text'   => lang('del'),
                'style'  => 'gray',
                'delete' => true,
            );
        }

        return $this->view('data.htm', $navVars + [
            'ur_here' => $bundle['ur_here'],
            'page_actions' => $pageActions,
            'rec' => 'edit',
            'data' => $data,
            'btn_lang' => $bundle['btn_lang'],
            'lang' => $bundle['lang'],
            'group' => $bundle['group'],
            'in_page' => $bundle['in_page'],
        ]);
    }

    /**
     * @param DataFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(DataFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        return redirect($this->dataService->update($data));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function copy(Request $request)
    {
        $rules = array(
            'module' => 'required|alpha',
            'id' => 'required|integer',
        );
        $request->validate($rules);

        $module = trim((string) $request->input('module', ''));
        $itemId = $request->integer('id', 0);
        return redirect($this->dataService->copy($module, $itemId));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function lock(Request $request)
    {
        $rules = array(
            'id' => 'required|integer',
        );
        $request->validate($rules);

        $id = $request->integer('id', 0);
        return redirect($this->dataService->lock($id));
    }

    /**
     * @return Response
     */
    public function transform()
    {
        $result = $this->dataService->transform();
        return redirect($result['back_url'])->with('success', $result['message']);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $rules = array(
            'id' => 'required|integer',
        );
        $request->validate($rules);

        $id = $request->integer('id', 0);
        return redirect($this->dataService->delete($id));
    }

}
