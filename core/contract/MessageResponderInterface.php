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

namespace Dou\Core\Contract;

use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 三端通用「提示页 + 跳转」契约。
 *
 * 端实现：
 * - 前台 {@see \Dou\Front\Http\FrontMessageResponder} → 渲染 dou_msg.dwt
 * - 后台 {@see \Dou\Admin\Http\AdminMessageResponder}  → 渲染 dou_msg.htm
 *
 * 签名以后台实现为超集（admin 全部位置参数 + 前台需要的子集），
 * 前台实现忽略 $out / $check / $btnValue。
 *
 * 返回 {@see Response}；由调用方 `return ...` 上传至入口统一发送，
 * 或包入 {@see \Dou\Core\Web\Http\HttpResponseException} 短路到入口 catch
 * （Service 等无法 return 上去的位置使用）。
 */
interface MessageResponderInterface
{
    /**
     * 构造消息提示页响应（不发送、不终止）。
     *
     * @param string $text 提示文案；前台传 'page_wrong' 表示 404 类响应
     * @param string $url 跳转地址（空串表示无 URL）
     * @param string $out 后台样式标志（'out' 等）；前台忽略
     * @param int|string $time 倒计时秒数（接受字符串形态）
     * @param string $check 后台二次确认 URL；前台忽略
     * @param string $btnValue 后台确认按钮文本；前台忽略
     * @param string $checkMethod 后台二次确认表单的 HTTP 方法伪装值（如 'DELETE'）；空串走原生 POST；前台忽略
     * @return Response
     */
    public function respond($text = '', $url = '', $out = '', $time = 3, $check = '', $btnValue = '', $checkMethod = '');
}
