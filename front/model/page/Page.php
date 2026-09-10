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

namespace Dou\Front\Model\Page;

use Dou\Core\Model\Concerns\HasPageTree;
use Dou\Core\Orm\Model;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台单页表（page）只读与轻量写（点击量等）。
 *
 * 单页树（pageTree / pageNolevel）由 HasPageTree 静态门面承接；
 * about() 是首页 / 公共区域 about 装配位的 specialty composition 终结方法。
 */
class Page extends Model
{
    use HasPageTree;

    /**
     * @var string
     */
    protected $table = 'page';

    /**
     * 首页 / 公共区域 about 装配位数据：含 name、content、link（页面 URL）、page_list（子页树）。
     *
     * 优先取 slug='about'，缺省回退 id=1；多语言覆写 name / content / description，
     * description 非空时用 description 作为 content，否则用 Markdown 渲染后 Str::excerpt 300 字摘要。
     *
     * @return array
     */
    public static function about()
    {
        $aboutModel = static::where('slug', 'about')->first();
        if (!$aboutModel) {
            $aboutModel = static::find(1);
        }
        $about = $aboutModel ? $aboutModel->getAttributes() : null;
        if (!is_array($about)) {
            return array(
                'name' => '',
                'content' => '',
                'description' => '',
                'link' => route('page.show', ['id' => 1]),
                'page_list' => array(),
            );
        }

        $about = language()->langBox($about, 'page', 'name, content, description');

        $rendered = app(MarkdownRenderer::class)->toHtml($about['content']);
        $about['content'] = $about['description'] ? $about['description'] : Str::excerpt($rendered, 300, false);
        $about['link'] = route('page.show', ['id' => $about['id']]);
        $about['page_list'] = static::pageTree($about['id']);

        return $about;
    }
}
