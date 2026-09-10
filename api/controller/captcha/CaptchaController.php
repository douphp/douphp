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

namespace Dou\Api\Controller\Captcha;

use Dou\Api\Controller\BaseController;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Infra\Security\Captcha;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\User\RegistrationService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 验证码控制器
 *
 */
class CaptchaController extends BaseController
{
    /** @var RegistrationService */
    private $registrationService;

    /** @var Captcha */
    private $captcha;

    /**
     * @param RegistrationService $registrationService
     * @param Captcha $captcha
     */
    public function __construct(RegistrationService $registrationService, Captcha $captcha)
    {
        $this->registrationService = $registrationService;
        $this->captcha = $captcha;
    }

    /**
     * @return Response
     */
    public function index()
    {
        return $this->token();
    }

    /**
     * 颁发验证码表单 token 对（`route=captcha/token`）。
     *
     * @return Response
     */
    public function token()
    {
        $pair = $this->registrationService->createApiVerificationToken();
        return ApiResponse::success(array(
            'captcha_token' => $pair['captcha_token'],
            'storage_captcha_token' => $pair['storage_captcha_token'],
        ));
    }

    /**
     * 下发验证码（`route=captcha/verification`）。
     *
     * @param Request $request
     * @return Response
     */
    public function verification(Request $request)
    {
        $result = $this->captcha->sendCaptcha(
            (string) $request->post('type', ''),
            (string) $request->post('account', ''),
            (string) $request->post('captcha_token', ''),
            (string) $request->post('check', 'no_allow_phone_exist'),
            (string) $request->post('storage_captcha_token', '')
        );
        // {@see \Dou\Core\Infra\Security\Captcha::sendCaptcha} 契约：返回 array{code:'success'|'fail', msg:string, verification?:array}。
        if (!is_array($result) || !isset($result['code']) || $result['code'] !== 'success') {
            $message = (is_array($result) && !empty($result['msg'])) ? (string) $result['msg'] : (string) lang('illegal');
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, $message, array(), 422);
        }
        $data = (isset($result['verification']) && is_array($result['verification'])) ? $result['verification'] : array();
        return ApiResponse::success($data);
    }
}
