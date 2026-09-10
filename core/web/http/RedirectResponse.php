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

use Dou\Core\Facade\Session;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * HTTP 重定向响应（Location）。
 */
class RedirectResponse extends Response
{
    /**
     * @param string $url
     * @param int $statusCode 301 或 302
     */
    public function __construct($url, $statusCode = 302)
    {
        $statusCode = (int) $statusCode;
        if ($statusCode !== 301 && $statusCode !== 302) {
            $statusCode = 302;
        }
        parent::__construct('', $statusCode);
        $this->setHeader('Location', Util::absolutizeEntryUrl((string) $url));
    }

    /**
     * @param string $url
     * @param int $statusCode
     * @return static
     */
    public static function create($url, $statusCode = 302)
    {
        return new static($url, $statusCode);
    }

    /**
     * @return string
     */
    public function getTargetUrl()
    {
        $loc = $this->getHeader('Location');

        return $loc !== null ? $loc : '';
    }

    /**
     * 在 302 上挂载一次性 flash 消息（PRG 反馈语法糖）。
     *
     * flash 在本方法调用瞬间写入 Session（不依赖 Response 发送时序）；
     * 下一次目标页 view 渲染时由 BaseController::layoutVars() 经 Session::pullAllFlashes() 取出后清除。
     *
     * 两种用法：
     * - 简单文案：`return redirect($url)->with('success', lang('xxx_succes'));`
     *   底层落 string，模板渲染时由 normalizeFlashes/normalizeOne 升格为
     *   `array('message' => $msg, 'back_url' => '', 'back_text' => '')`。
     * - 含返回链接（WordPress notice 风）：
     *   `return redirect($editUrl)->with('success', $msg, $listUrl, lang('back_to_list'));`
     *   底层落 array，模板按 `$f.back_url` 非空时渲染「← 返回列表」。
     *
     * `$back_url` 为空字符串时模板不渲染返回链接（与简单文案分支等价）。
     *
     * @param string $key flash 槽位名（约定：success / error / info / warning）
     * @param mixed $message 文案或任意可序列化值
     * @param string|null $back_url 可选返回链接 URL；为 null 时走简单文案分支
     * @param string|null $back_text 可选返回链接文案；与 $back_url 配对使用
     * @return $this
     */
    public function with($key, $message, $back_url = null, $back_text = null)
    {
        if ($back_url === null && $back_text === null) {
            Session::setFlash((string) $key, $message);
        } else {
            Session::setFlash((string) $key, array(
                'message' => $message,
                'back_url' => Util::absolutizeEntryUrl((string) $back_url),
                'back_text' => (string) ($back_text !== null ? $back_text : ''),
            ));
        }
        return $this;
    }
}
