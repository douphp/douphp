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

namespace Dou\Core\Facade;

use Dou\Core\Foundation\Facade\StaticFacade;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 视图静态门面：底层为 DouView（{@see \Dou\Core\Web\Template\DouView}）容器单例。
 *
 * DouView 是 DouPHP 自研模板引擎产品名；本门面对标 Laravel View，供业务 assign / fetch。
 *
 * @method static void assign(string $tpl_var, $value = null)
 * @method static void setEscapeHtml(bool $enable = true)
 * @method static void display(string $template)
 * @method static string fetch(string $template)
 */
class View extends StaticFacade
{
    /**
     * 容器中以 DouView FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return \Dou\Core\Web\Template\DouView::class;
    }
}
