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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * JSON 响应。
 *
 * 默认带 JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES：中文按 UTF-8 直出（不转 \uXXXX）、
 * 斜杠不转义（不写成 \/），输出更小且与现代 API 客户端预期一致。调用方可显式传 $encodeOptions 覆盖。
 */
class JsonResponse extends Response
{
    /** @var mixed */
    protected $data;

    /** @var int */
    protected $encodeOptions;

    /**
     * @param mixed $data 可 json_encode 的数据
     * @param int $statusCode
     * @param int $encodeOptions json_encode 选项位，默认 UNESCAPED_UNICODE|UNESCAPED_SLASHES
     */
    public function __construct($data, $statusCode = 200, $encodeOptions = null)
    {
        if ($encodeOptions === null) {
            $encodeOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        }
        $this->data = $data;
        $this->encodeOptions = (int) $encodeOptions;
        parent::__construct('', (int) $statusCode);
        $charset = defined('DOU_CHARSET') ? DOU_CHARSET : 'utf-8';
        $this->setHeader('Content-Type', 'application/json; charset=' . $charset);
    }

    /**
     * @return string
     */
    public function encodeContent()
    {
        $json = json_encode($this->data, $this->encodeOptions);
        if ($json === false) {
            return '{"code":500,"msg":"json_encode_failed"}';
        }

        return $json;
    }

    /**
     * @return void
     */
    public function send()
    {
        $this->setContent($this->encodeContent());
        parent::send();
    }
}
