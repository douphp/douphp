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

namespace Dou\Install\Foundation\Context;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * install 运行上下文：在 Init / Router / Controller 之间传递。
 *
 * 安装阶段尚未连接数据库，不持有 Connection；DB 相关操作由
 * {@see \Dou\Install\Service\DatabaseInstallService} 内部按需建立。
 */
class InstallContext
{
    /** @var \Dou\Core\Web\Template\DouView DouView 模板引擎实例 */
    public $view;

    /** @var \Dou\Core\Foundation\Csrf\CsrfManager token 工具（仅 generate/token/verify） */
    public $csrf;

    /** @var array 语言串 */
    public $lang = array();
}
