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

namespace Dou\Core\Web\Template\Prefilter;

use Dou\Core\Web\Template\Contract\PrefilterContext;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台模板预过滤（静态方法供 DouView::registerPrefilter 注册）
 */
class AdminPrefilter
{
    /**
     * 后台：模板目录为 admin/view/，编译后相对站点根访问需带 view/（css、js、images）；还原注释中的标签
     *
     * @param string $source 模板源码
     * @param PrefilterContext $engine 编译前上下文（仅读 assign）
     * @return string
     */
    public static function apply($source, PrefilterContext $engine)
    {
        $source = preg_replace('/^<meta\shttp-equiv=["|\']Content-Type["|\']\scontent=["|\']text\/html;\scharset=(?:.*?)["|\'][^>]*?>\r?\n?/i', '', $source);
        // 静态资源改为绝对地址（{$admin_url} 渲染期解析，含 host），后台深路径（伪静态）下不受相对基准目录影响。
        $source = preg_replace('/href\s*=\s*(["\'])css\//i', 'href=$1{$admin_url}view/css/', $source);
        $source = preg_replace('/src\s*=\s*(["\'])js\//i', 'src=$1{$admin_url}view/js/', $source);
        $source = preg_replace('/src\s*=\s*(["\'])images\//i', 'src=$1{$admin_url}view/images/', $source);
        // 模板内硬编码的 href / action 裸 index.php?route= 链接绝对化前缀（深路径下仍可用；
        // 仅限 href/action 属性，避免误伤 {url ... back='index.php?route=...'} 这类标签参数）。
        $source = preg_replace('/(href|action)\s*=\s*(["\'])index\.php\?route=/i', '$1=$2{$admin_url}index.php?route=', $source);
        $source = preg_replace('/<!--.*{(.*)}.*-->/U', '{$1}', $source);

        return $source;
    }
}
