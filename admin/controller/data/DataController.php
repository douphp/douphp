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
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

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

    /** @var string 侧边栏高亮标识，按数据所属模块动态设置（page/article/...），默认 data */
    private $moduleCur = 'data';

    /**
     * @param DataService $dataService
     */
    public function __construct(DataService $dataService)
    {
        $this->dataService = $dataService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => $this->moduleCur,
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
        $this->moduleCur = $bundle['cur'];

        $pageSubActions = array();
        if ($bundle['group'] === 'all' || $bundle['group'] === 'common') {
            $pageSubActions[] = $bundle['group'] === 'all'
                ? array('href' => route('admin.data'),           'text' => lang('data_all_hidden'))
                : array('href' => route('admin.data', ['group' => 'all']), 'text' => lang('data_all'));
        }

        return $this->view('data.htm', [
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
        $this->moduleCur = $bundle['cur'];

        return $this->view('data.htm', [
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
        $this->moduleCur = $bundle['cur'];

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

        return $this->view('data.htm', [
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
