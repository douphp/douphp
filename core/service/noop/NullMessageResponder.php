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

namespace Dou\Core\Service\Noop;

use Dou\Core\Contract\MessageResponderInterface;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 端兜底的「提示页 + 跳转」实现：API 不渲染 HTML 提示页，统一抛错避免被误用。
 *
 * API 业务里几乎不会触达 message()；唯一可能的边界是核心服务跨端复用时调用 message()->respond()
 * 在 API 端不应当发生。Null 实现以异常抛出让问题在最早期暴露。
 */
class NullMessageResponder implements MessageResponderInterface
{
    /**
     * {@inheritDoc}
     */
    public function respond($text = '', $url = '', $out = '', $time = 3, $check = '', $btnValue = '', $checkMethod = '')
    {
        throw new \RuntimeException(
            'message()->respond() is not available in API shell. '
            . 'Replace it with ApiResponse::success/error or a structured payload.'
        );
    }
}
