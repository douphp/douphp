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

namespace Dou\Core\Web\Routing;

use Dou\Core\Facade\View;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * create / edit 表单提交目标装配器（front / admin 共用）。
 *
 * 仅资源条目（路由名形如 `module.sub.action`）参与：create 提交到 base.store（POST），
 * edit 提交到 base.update（PUT）。base 为命中路由名去掉末段动作。
 */
class FormTargetBinder
{
    /**
     * 把 form_action / form_method 写入视图引擎全局。
     *
     * @param string $action 命中动作（仅 create / edit 装配）
     * @param string $routeName 命中路由名
     * @param array $params 路径捕获的具名参数（edit 的成员 id）
     * @return void
     */
    public static function assign($action, $routeName, array $params)
    {
        if ($action !== 'create' && $action !== 'edit') {
            return;
        }

        $name = (string) $routeName;
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return;
        }
        $base = substr($name, 0, $dot);

        if ($action === 'create') {
            View::assign('form_action', route($base . '.store'));
            View::assign('form_method', '');
            return;
        }

        View::assign('form_action', route($base . '.update', $params));
        View::assign('form_method', 'PUT');
    }
}
