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

namespace Dou\Front\Model\Concerns;

use Dou\Core\Service\Content\MarkdownRenderer;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 内容列表字段统一 accessor：name 回退 title、description 回退正文摘要。
 *
 * accessor 只做数据语义转换（markdown 渲染 + strip_tags 转纯文本），不截字；
 * 截字长度由调用方按场景自行 Str::excerpt() 收口。
 * 取值统一走 translatedRaw()，保证非默认语言下回退生成的摘要也取自当前语言译文。
 */
trait HasContentListFields
{
    /**
     * 列表名称：name 优先，缺省回退 title（均取当前语言值）。
     *
     * @return string
     */
    public function getNameAttribute()
    {
        if (array_key_exists('name', $this->attributes)) {
            return $this->translatedRaw('name');
        }

        return $this->translatedRaw('title');
    }

    /**
     * 列表摘要：原 description 字段非空即返原值；空时把正文经 Markdown 渲染并 strip_tags 转纯文本全文返回（不截字）。
     *
     * @return string
     */
    public function getDescriptionAttribute()
    {
        $description = $this->translatedRaw('description');
        if ($description) {
            return $description;
        }

        $content = $this->translatedRaw('content');
        $rendered = app(MarkdownRenderer::class)->toHtml($content);

        return trim(strip_tags($rendered));
    }
}
