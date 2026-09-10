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

namespace Dou\Core\Web\Template\Tag;

use Dou\Core\Web\Template\TagCompileContext;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * {@see \Dou\Core\Web\Template\Ast\NodeType::CATEGORY} 标签编译器：
 * {category module=... item=... key=... name=... offset=...}（纯分类树）/
 * {category module=... with="items" perCat=... item=...}（分类树 + 每类内容，内容行在节点 list 字段）
 * + {categoryelse}。
 *
 * 数据源为 {@see \Dou\Core\Facade\Portal::categoryFor()}：仅栏目型模块（module.column_module），
 * with="items" 走 categoryTreeWithItems，否则 categoryTree。with 仅接受字面量 "items"。
 * 循环层：item / key / name / offset；Portal props：perCat / children / cur / excerpt。
 */
class CategoryTagCompiler extends AbstractPortalLoopTagCompiler
{
    /**
     * @inheritDoc
     */
    protected function tagName()
    {
        return 'category';
    }

    /**
     * @inheritDoc
     */
    protected function portalMethod()
    {
        return 'categoryFor';
    }

    /**
     * @inheritDoc
     */
    protected function extraReservedAttrs()
    {
        return array('with' => true);
    }

    /**
     * with 仅接受字面量 "items"，编译期固化进 props。
     *
     * @inheritDoc
     */
    protected function buildExtraProps(array $attrs, TagCompileContext $ctx)
    {
        if (!isset($attrs['with'])) {
            return array();
        }
        $with = $ctx->expr->dequote($attrs['with']);
        if ($with !== 'items') {
            $ctx->expr->syntaxError("category: 'with' only accepts literal \"items\"");
        }

        return array("'with' => 'items'");
    }
}
