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

namespace Dou\Core\Web\Http;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 携带 Response 的异常，供 Service 等无法 return 到管道时短路到内核 send。
 */
class HttpResponseException extends \Exception
{
    /** @var Response */
    private $response;

    /**
     * @param Response $response
     * @param string $message
     * @param int $code
     * @param \Exception|null $previous
     */
    public function __construct(Response $response, $message = '', $code = 0, $previous = null)
    {
        // 隐式 nullable 兼容（5.6 无 ?Type、8.4 弃用隐式 nullable）：去 \Exception 提示，体内守护。
        // 7+ 的 \Error 属 \Throwable 但非 \Exception，也放行；非 Throwable 归一为 null。
        if ($previous !== null && !($previous instanceof \Exception) && !($previous instanceof \Throwable)) {
            $previous = null;
        }
        parent::__construct((string) $message, (int) $code, $previous);
        $this->response = $response;
    }

    /**
     * @return Response
     */
    public function getResponse()
    {
        return $this->response;
    }
}
