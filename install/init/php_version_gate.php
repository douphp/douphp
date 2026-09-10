<?php

/**
 * DouPHP — install 端 PHP 最低版本门禁（须为 PHP 5.2 可解析语法）
 * ------------------------------------------------------------------------------------
 * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)
 *
 * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-08
 */

if (!function_exists('douphp_require_min_php')) {
    /**
     * 当前 PHP 低于 $minVersion 时输出提示并终止。
     *
     * @param string $minVersion 如 5.6.0
     * @return void
     */
    function douphp_require_min_php($minVersion)
    {
        if (version_compare(PHP_VERSION, $minVersion, '<')) {
            $min = htmlspecialchars($minVersion, ENT_QUOTES, 'UTF-8');
            $cur = htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html>';
            echo '<html lang="zh-CN"><head><meta charset="utf-8">';
            echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
            echo '<title>DouPHP - PHP 版本过低</title>';
            echo '<style>';
            echo 'html,body{height:100%;margin:0}';
            echo 'body{display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;';
            echo 'background-color:#eeeeee;font:14px/1.7 "Microsoft Yahei","\\5FAE\\8F6F\\96C5\\9ED1",Arial,sans-serif;color:#333}';
            echo '.wrap{width:100%;max-width:480px}';
            echo '.logo{text-align:center;margin-bottom:16px}';
            echo '.logo img{height:36px;vertical-align:middle}';
            echo '.card{background:#fff;border:1px solid #ddd;overflow:hidden}';
            echo '.hd{background-color:#0072c6;color:#fff;padding:14px 20px;font-size:18px;font-weight:bold}';
            echo '.bd{padding:24px 20px 28px}';
            echo '.bd h1{margin:0 0 12px;font-size:16px;font-weight:bold;color:#0574c7}';
            echo '.bd p{margin:0 0 8px;font-size:13px;color:#666;line-height:1.8}';
            echo '.bd p strong{color:#0072c6;font-weight:bold}';
            echo '.ver{margin-top:18px;padding:10px 12px;background:#f5f5f5;border:1px solid #e8e8e8;';
            echo 'font-size:12px;color:#666}';
            echo '.ver em{color:#c00;font-style:normal;font-weight:bold}';
            echo '</style></head><body>';
            echo '<div class="wrap">';
            echo '<div class="logo"><img src="view/assets/logo.gif" alt="DouPHP"></div>';
            echo '<div class="card">';
            echo '<div class="hd">环境检测提示</div>';
            echo '<div class="bd">';
            echo '<h1>PHP 版本过低</h1>';
            echo '<p>请升级到 PHP <strong>' . $min . '</strong> 或更高版本后再安装 DouPHP。</p>';
            echo '<div class="ver">当前版本：<em>' . $cur . '</em></div>';
            echo '</div></div></div></body></html>';
            die();
        }
    }
}
