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

namespace Dou\Core\Web\Template\Contract;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前置过滤器上下文契约：编译前 Prefilter 仅需读取已 assign 的上下文（如 site / theme_path）。
 *
 * 把 Prefilter 的依赖从具体 {@see \Dou\Core\Web\Template\DouView} 收窄到本接口，使其只看见
 * 「读上下文」这一项能力，与引擎的编译 / 渲染细节解耦。
 */
interface PrefilterContext
{
    /**
     * 读取已 assign 的上下文变量。
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function getAssigned($key, $default = null);
}
