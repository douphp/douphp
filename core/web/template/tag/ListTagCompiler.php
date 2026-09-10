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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * {@see \Dou\Core\Web\Template\Ast\NodeType::LIST_} 标签编译器：
 * {list module=... limit=... sort=... item=... key=... name=... offset=...} + {listelse}。
 *
 * 数据源为 {@see \Dou\Core\Facade\Portal::listFor()}：按 module.column_module /
 * module.single_module（须 moduleSchema listable）白名单分流 columnList / singleList，
 * 卸载模块 / features 关闭时返回空数组走 {listelse} 分支。
 * 循环层：item / key / name / offset（offset 取数后 array_slice）；
 * Portal props：catId（仅栏目型）/ limit（SQL LIMIT）/ sort / excerpt。
 */
class ListTagCompiler extends AbstractPortalLoopTagCompiler
{
    /**
     * @inheritDoc
     */
    protected function tagName()
    {
        return 'list';
    }

    /**
     * @inheritDoc
     */
    protected function portalMethod()
    {
        return 'listFor';
    }
}
