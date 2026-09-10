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

namespace Dou\Front\Controller\Llms;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Llms\LlmsService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * LLMs 文本文件控制器
 */
class LlmsController extends BaseController
{
    /** @var LlmsService */
    private $llmsService;

    /**
     * @param LlmsService $llmsService
     */
    public function __construct(LlmsService $llmsService)
    {
        $this->llmsService = $llmsService;
    }

    public function index()
    {
        if (!intval(Config::get('site.llms', ''))) {
            return $this->response('');
        }

        return $this->response($this->llmsService->buildLlmsText(), 200, array('Content-Type' => 'text/plain; charset=utf-8'));
    }
}
