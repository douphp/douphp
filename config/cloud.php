<?php

/**
 * DouPHP
 * ------------------------------------------------------------------------------------
 * 版权所有 2013-2026 漳州豆壳网络科技有限公司，并保留所有权利。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在遵守授权协议前提下对程序代码进行修改和使用；
 * 不允许对程序代码以任何形式任何目的的再发布。
 * 授权协议：http://www.douphp.com/license.html
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-04
 */
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 主站与豆壳云服务 API（api.douphp.com）对接的唯一配置点。
 *
 * 推荐生产值：`https://api.douphp.com`（末尾不带斜杠，由 CloudApi 内部统一拼接）。
 * 若留空或未配置该项：主站不向云服务发起请求（CloudApi 返回空 URL / null），在线安装等不可用。
 *
 * 所有路径常量集中在 \Dou\Core\Web\Http\CloudApi，请使用其方法获取最终 URL。
 */
return [
    'cloud' => [
        // 云服务 API 基础地址（无末尾斜杠）。本地开发可按需修改。
        'api_base' => 'https://api.douphp.com',
        // 下载白名单：仅用于校验 install-resolve 返回的 download_url（scheme/host/port），不参与本地拼 URL。
        'download_base' => 'http://download.douphp.com',
    ],
];
