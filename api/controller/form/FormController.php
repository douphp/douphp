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

namespace Dou\Api\Controller\Form;

use Dou\Api\Controller\BaseController;
use Dou\Core\Facade\Url;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Form\FormService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 表单控制器（复用 front 端 FormService）
 *
 * 小程序两套主题分别读 `field_list` 与 `elements_list`，`show()` 同时返回两个键
 * （同数据别名），避免为兼容再改小程序模板。
 */
class FormController extends BaseController
{
    /** @var FormService */
    private $formService;

    /**
     * @param FormService $formService
     */
    public function __construct(FormService $formService)
    {
        $this->formService = $formService;
    }

    /**
     * 表单列表
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $result = $this->formService->paginateFormList($request->integer('page', 1), 0, route('form'));
        $pager = isset($result['pager']) ? (array) $result['pager'] : array();

        $formList = array();
        foreach ((array) $result['list'] as $row) {
            $formList[] = array(
                'form_id' => $row['form_id'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'content' => $row['content'],
                // 老主题仍遍历 image：原 dou_form.image 已退役，固定回空串
                'image' => '',
                'field_count' => $row['field_count'],
                'url' => Url::urlMini('form', $row['form_id']),
            );
        }

        return ApiResponse::success(array(
            'title' => lang('form'),
            'form_list' => $formList,
            'total' => isset($pager['record_count']) ? (int) $pager['record_count'] : count($formList),
            'pager' => $pager,
        ));
    }

    /**
     * 表单填写页
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $id = $request->integer('id', 0);
        $result = $this->formService->getShowPageData($id);
        if ($result === null) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('page_wrong'), array(), 404);
        }

        $form = $result['form'];
        $form['elements_list'] = $form['field_list'];

        return ApiResponse::success(array(
            'title' => lang('form'),
            'form' => $form,
            'field_count' => $result['field_count'],
            'post_data' => $this->mergePostData($result['post_data'], $request->input('post_data')),
        ));
    }

    /**
     * 表单提交（Ajax）
     *
     * 小程序把整张表单序列化成 JSON 放在 `post` 参数里，这里与常规 POST 字段合并后
     * 交给 processSubmit，再按 `{ok, message, redirect_url, errors}` 映射成 API 响应。
     *
     * @param Request $request
     * @return Response
     */
    public function submit(Request $request)
    {
        $post = $this->collectPost($request);

        $rawFormId = $request->input('form_id');
        if (!Check::number($rawFormId) && isset($post['form_id'])) {
            $rawFormId = $post['form_id'];
        }
        if (!Check::number($rawFormId)) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('page_wrong'), array(), 422);
        }

        $result = $this->formService->processSubmit((int) $rawFormId, $post, (string) $request->ip(), array(
            'user_id' => (int) auth('api')->id(),
            'draft_token' => isset($post['draft_token']) ? (string) $post['draft_token'] : '',
            'source_url' => isset($post['source_url']) ? (string) $post['source_url'] : '',
        ));

        if (empty($result['ok'])) {
            $errors = isset($result['errors']) ? (array) $result['errors'] : array();

            return ApiResponse::error(
                empty($errors) ? ApiCodes::BUSINESS_RULE_VIOLATION : ApiCodes::VALIDATION_FAILED,
                (string) $result['message'],
                $errors,
                422
            );
        }

        return ApiResponse::success(array(
            'message' => (string) $result['message'],
            'redirect_url' => (string) $result['redirect_url'],
        ), (string) $result['message']);
    }

    /**
     * 提交数据：常规 POST 字段 + 小程序 JSON（`post`）合并。
     *
     * @param Request $request
     * @return array
     */
    private function collectPost(Request $request)
    {
        $post = (array) $request->post();

        $raw = $request->input('post');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = $this->decodeJson($raw);
            if (is_array($decoded)) {
                $post = array_merge($post, $decoded);
            }
        }

        // `post` / `post_data` 是小程序传输信封而非表单字段，必须剔除；
        // 否则会被当成模板自定义字段写进 form_data.data
        unset($post['post'], $post['post_data']);

        return $post;
    }

    /**
     * 解析小程序 JSON 入参：先按原样解析，失败才去掉过度转义重试。
     *
     * 不能无条件 stripslashes：合法 JSON 中的 `\\`、`\"`（如 Windows 路径）会被破坏成
     * 非法 JSON，导致整张表单字段丢失。
     *
     * @param string $raw
     * @return array|null
     */
    private function decodeJson($raw)
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $decoded = json_decode(stripslashes($raw), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 详情页默认值回填：小程序把已填值序列化成 `post_data` 带上，重新进入时原样回显。
     *
     * @param array $defaults 字段默认值
     * @param mixed $raw post_data 原始入参
     * @return array
     */
    private function mergePostData(array $defaults, $raw)
    {
        if (!is_string($raw) || trim($raw) === '') {
            return $defaults;
        }

        $decoded = $this->decodeJson($raw);
        if (!is_array($decoded)) {
            return $defaults;
        }

        $skipKeys = array('form_id' => 1, 'token' => 1, 'captcha' => 1, 'draft_token' => 1, 'source_url' => 1, 'post' => 1, 'post_data' => 1);
        $count = 0;

        // 回显时同样剔除传输信封键，并对键数 / 字符串长度做上与提交一致的限制
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || $key === '' || isset($skipKeys[$key])) {
                continue;
            }
            if (is_string($value)) {
                $value = mb_substr($value, 0, 5000);
            } elseif (is_array($value)) {
                $value = array_slice($value, 0, 50, true);
            } elseif (!is_scalar($value)) {
                continue;
            }
            $defaults[$key] = $value;
            if (++$count >= 50) {
                break;
            }
        }

        return $defaults;
    }
}
