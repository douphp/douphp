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

namespace Dou\Front\Controller\Captcha;

use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Infra\Security\Captcha;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Front\Controller\BaseController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 验证码控制器
 */
class CaptchaController extends BaseController
{
    /** @var Captcha */
    private $captcha;

    /**
     * @param Captcha $captcha
     */
    public function __construct(Captcha $captcha)
    {
        $this->captcha = $captcha;
    }

    public function index()
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $this->captcha->createCaptcha(80, 30);
    }

    /**
     * 下发验证码（`route=captcha/verification`）。
     *
     * @param Request $request
     * @return void
     */
    public function verification(Request $request)
    {
        $result = $this->captcha->sendCaptcha(
            (string) $request->post('type', ''),
            (string) $request->post('account', ''),
            (string) $request->post('captcha_token', ''),
            (string) $request->post('check', 'no_allow_phone_exist')
        );
        // {@see \Dou\Core\Infra\Security\Captcha::sendCaptcha} 契约：返回 array{code:'success'|'fail', msg:string}。
        if (!is_array($result) || !isset($result['code']) || $result['code'] !== 'success') {
            $message = (is_array($result) && !empty($result['msg'])) ? (string) $result['msg'] : (string) lang('illegal');
            ApiResponse::throwError(ApiCodes::BUSINESS_RULE_VIOLATION, $message, array(), 422);
        }
        ApiResponse::throwSuccess(array());
    }
}
