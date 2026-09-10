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

namespace Dou\Api\Controller\Product;

use Dou\Api\Controller\BaseController;
use Dou\Api\Request\Product\WorkProductFormRequest;
use Dou\Api\Service\Product\WorkProductService;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\Work\WorkService;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Model\Brand\Brand;
use Dou\Front\Model\Product\ProductCategory;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序商品工作端控制器（route=product/work/...）
 *
 * 控制器仅做：登录与工作权限校验、参数取值、调用 WorkProductService、统一响应。
 * 字段校验由 WorkProductFormRequest 完成；持久化与业务流程在 WorkProductService。
 */
class WorkController extends BaseController
{
    /** @var WorkService */
    private $workService;

    /** @var WorkProductService */
    private $workProductService;

    /**
     * @param WorkService $workService
     * @param WorkProductService $workProductService
     */
    public function __construct(
        WorkService $workService,
        WorkProductService $workProductService
    ) {
        $this->workService = $workService;
        $this->workProductService = $workProductService;
    }

    /**
     * 工作端商品列表
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $userId = $this->mustLoginAndPermission();
        $workId = (int) auth('api')->workId();

        $page = $request->integer('page', 1);
        $data = $this->workProductService->buildWorkProductListData($page, $userId, $workId);

        return ApiResponse::success(array(
            'title' => lang('product_list'),
            'product_list' => $data['product_list'],
        ));
    }

    /**
     * 新增表单数据
     *
     * @return Response
     */
    public function add()
    {
        $this->mustLoginAndPermission();

        $bundle = $this->workProductService->buildWorkProductDefaultData();

        return ApiResponse::success(array(
            'title' => lang('product_add'),
            'product' => $bundle['product'],
            'product_category' => ProductCategory::flat(),
            'img_list' => $bundle['img_list'],
            'brand_list' => Config::get('features.brand', false) ? Brand::applyDefaultOrder()->get() : array(),
        ));
    }

    /**
     * 提交新增（POST product/work）
     *
     * 字段白名单与校验规则定义在 WorkProductFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=store（FormRequest 默认按动作名归一化；
     * store 与 insert 同为非 update 场景，规则一致）。
     *
     * @param WorkProductFormRequest $formRequest
     * @return Response
     */
    public function store(WorkProductFormRequest $formRequest)
    {
        $this->mustLoginAndPermission();

        $this->workProductService->store($formRequest->validated(), (int) auth('api')->workId());

        return ApiResponse::success(array(), lang('product_add') . lang('success'));
    }

    /**
     * 编辑表单数据
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $this->mustLoginAndPermission();
        $workId = (int) auth('api')->workId();

        $id = $request->integer('id', 0);
        $bundle = $this->workProductService->buildWorkProductEditData($id, $workId);
        if ($bundle === null) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('item_not_exist'), array(), 404);
        }

        $data = array(
            'title' => lang('product_edit'),
            'product' => $bundle['product'],
            'product_category' => ProductCategory::flat(),
            'img_list' => $bundle['img_list'],
            'brand_list' => Config::get('features.brand', false) ? Brand::applyDefaultOrder()->get() : array(),
        );

        $attribute = Module::make('attribute');
        if ($attribute) {
            $data['attribute_list'] = $attribute->getAttributeList('product', $bundle['product']['category_id'], $id, 'common');
        }

        return ApiResponse::success($data);
    }

    /**
     * 提交更新
     *
     * 字段白名单与校验规则定义在 WorkProductFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=update。
     *
     * @param WorkProductFormRequest $formRequest
     * @return Response
     */
    public function update(WorkProductFormRequest $formRequest)
    {
        $this->mustLoginAndPermission();
        $workId = (int) auth('api')->workId();

        $ok = $this->workProductService->update($formRequest->validated(), $workId);
        if (!$ok) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, lang('item_not_exist'), array(), 422);
        }

        return ApiResponse::success(array(), lang('product_edit') . lang('success'));
    }

    /**
     * 商品图片上传（主图 / 编辑器内嵌）
     *
     * @param Request $request
     * @return Response
     */
    public function upload(Request $request)
    {
        $this->mustLoginAndPermission();
        $workId = (int) auth('api')->workId();

        $itemId = $request->integer('item_id', 0);
        $type = $request->alpha('type', 'thumb');
        if (!$itemId || empty($_FILES['image']['name'])) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, 'invalid_upload', array(), 422);
        }

        $imgWidth = $request->integer('img_width', 0);
        $result = $this->workProductService->uploadImage($itemId, $type, $imgWidth, $workId);
        if ($result['image'] === '') {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('item_not_exist'), array(), 404);
        }

        return ApiResponse::success($result);
    }

    /**
     * 删除商品（DELETE product/work/{id}）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $this->mustLoginAndPermission();
        $workId = (int) auth('api')->workId();

        $id = $request->integer('id', 0);
        if ($id <= 0) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, 'invalid_id', array(), 422);
        }

        $ok = $this->workProductService->delete($id, $workId);
        if (!$ok) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('item_not_exist'), array(), 404);
        }

        return ApiResponse::success(array(), lang('product_del') . lang('success'));
    }

    /**
     * 登录 + 工作端权限校验
     *
     * 校验失败时抛出携带 ApiResponse 的 HttpResponseException 短路，由 api 入口
     * 统一捕获并 send；调用方拿到的恒为合法 int 会员 id，杜绝"返回值未消费仍继续执行"的鉴权旁路。
     *
     * @return int 当前会员 id
     * @throws \Dou\Core\Web\Http\HttpResponseException 未登录 / 无工作端权限时
     */
    private function mustLoginAndPermission()
    {
        $userId = 0;
        if (Config::get('features.user', false)) {
            $userId = (int) auth('api')->id();
        }
        if ($userId <= 0) {
            ApiResponse::throwError(ApiCodes::UNAUTHORIZED, lang('login_timeout'), array(), 401);
        }

        if (empty(Config::get('features.work', false)) || !$this->workService->checkPermission('product', $userId)) {
            ApiResponse::throwError(ApiCodes::FORBIDDEN, lang('work_no_permission'), array(), 403);
        }

        return $userId;
    }
}
