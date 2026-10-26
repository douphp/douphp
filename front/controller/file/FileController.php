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

namespace Dou\Front\Controller\File;

use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\File\FileService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台通用文件上传控制器（route=file/...）
 *
 * 薄壳：只做参数提取、身份校验与响应输出；上传/删除/渲染全部在 {@see FileService}。
 * 游客在路由层已被 auth_modes['file'] = 'required' 拦下，此处再判一次会员 ID 作为纵深防御。
 */
class FileController extends BaseController
{
    /** @var FileService */
    private $fileService;

    /**
     * @param FileService $fileService
     */
    public function __construct(FileService $fileService)
    {
        $this->fileService = $fileService;
    }

    /**
     * 上传文件，返回列表 HTML。
     *
     * @param Request $request
     * @return Response
     */
    public function box(Request $request)
    {
        $userId = (int) auth('front')->id();
        if ($userId <= 0) {
            return ApiResponse::error(ApiCodes::AUTH_REQUIRED, lang('file_login_required'), array(), 401);
        }

        try {
            $html = $this->fileService->upload(
                $request->basicString('module'),
                $request->basicString('prefix'),
                $request->enum('type', array('main', 'gallery', 'attach'), ''),
                (string) $request->input('item_id', ''),
                (string) $request->post('draft_token', ''),
                $userId,
                array(
                    'img_width' => $request->digits('img_width', 0),
                    'extensions' => (string) $request->input('extensions', ''),
                    'max_size' => (int) $request->input('max_size', 0),
                )
            );
        } catch (DomainException $e) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, $e->getMessage(), array(), 422);
        }

        return $this->response($html);
    }

    /**
     * 删除文件，返回剩余列表 HTML。
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $userId = (int) auth('front')->id();
        if ($userId <= 0) {
            return ApiResponse::error(ApiCodes::AUTH_REQUIRED, lang('file_login_required'), array(), 401);
        }

        try {
            $html = $this->fileService->delete(
                (string) $request->input('number', ''),
                $request->basicString('prefix'),
                $userId
            );
        } catch (DomainException $e) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, $e->getMessage(), array(), 422);
        }

        return $this->response($html);
    }
}
