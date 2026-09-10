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

namespace Dou\Install\Support;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * install 模块通用辅助函数。
 *
 * 与 admin 的 `Dou` 门面解耦：install 没有 Dou 门面与 Container，统一用静态方法渲染
 * view/dou_msg.htm 并 exit。
 */
class Helper
{
    /**
     * 渲染信息提示页（dou_msg.htm）并退出脚本。
     *
     * @param \Dou\Core\Web\Template\DouView $view
     * @param array  $lang
     * @param string $text
     * @param string $url
     * @param string $title
     * @param int    $time
     * @return void
     */
    public static function douMsg($view, array $lang, $text, $url = '', $title = '', $time = 3)
    {
        if ($text === '' || $text === null) {
            $text = isset($lang['dou_msg_success']) ? $lang['dou_msg_success'] : '';
        }
        if ($title === '') {
            $title = isset($lang['douphp']) ? $lang['douphp'] : '';
        }

        $view->assign('text', $text);
        $view->assign('url', $url);
        $view->assign('title', $title);
        $view->assign('time', (int) $time);
        $view->display('dou_msg.htm');
        exit;
    }

    /**
     * 跳转至 install 内部 URL。
     *
     * @param string $url
     * @return void
     */
    public static function redirect($url)
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * 渲染并退出：返回一个不依赖 DouView 的纯文本错误页。
     *
     * 仅在 DouView 未就绪、或模板编译目录不可写等极少情况下使用。
     *
     * @param string $text
     * @return void
     */
    public static function plainExit($text)
    {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>DouPHP Install</title>';
        echo '<div style="max-width:640px;margin:80px auto;font:14px/1.6 sans-serif;color:#333">';
        echo '<h3>DouPHP Install</h3><p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '</div>';
        exit;
    }
}
