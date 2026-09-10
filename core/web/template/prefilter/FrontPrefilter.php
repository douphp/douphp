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
 * 前台模板预过滤（静态方法供 DouView::registerPrefilter 注册）
 *
 * 奇异行为锁定：运行期 theme_path 在编译期被字面烘焙进编译源，故 front 编译产物含绝对主题路径、
 * 编译缓存与主题强绑定；切主题 / 升级引擎须清缓存（靠 COMPILE_REVISION 自动失效）。
 */
class FrontPrefilter
{
    /**
     * 前台：主题静态资源改为绝对路径；还原注释中的标签
     *
     * @param string $source 模板源码
     * @param PrefilterContext $engine 编译前上下文（仅读 assign）
     * @return string
     */
    public static function apply($source, PrefilterContext $engine)
    {
        $theme_path = '';
        $site = $engine->getAssigned('site');
        $assignedThemePath = $engine->getAssigned('theme_path');
        if (isset($GLOBALS['_THEME_PATH']) && $GLOBALS['_THEME_PATH'] !== '') {
            $theme_path = rtrim($GLOBALS['_THEME_PATH'], '/');
        } elseif ($assignedThemePath !== null && $assignedThemePath !== '') {
            $theme_path = rtrim($assignedThemePath, '/');
        } elseif (!empty($site['site_theme']) && defined('ROOT_URL')) {
            $theme_path = rtrim(ROOT_URL . 'theme/' . $site['site_theme'] . '/', '/');
        }

        if ($theme_path !== '') {
            $source = preg_replace('/\"(..\/)*images\//Ums', "\"$theme_path/images/", $source);
            $source = preg_replace('/\(images\//Ums', "($theme_path/images/", $source);
            $source = preg_replace('/href\=\"(..\/)*(css\/){0,1}([A-Za-z0-9\/._-]+)\.css/Ums', "href=\"$theme_path/$2$3.css", $source);
            $source = preg_replace('/src=\"(..\/)*js\/([A-Za-z0-9\/._-]+)\.js/Ums', "src=\"$theme_path/js/$2.js", $source);
        }

        $source = preg_replace('/^<meta\shttp-equiv=["|\']Content-Type["|\']\scontent=["|\']text\/html;\scharset=(?:.*?)["|\'][^>]*?>\r?\n?/i', '', $source);
        $source = preg_replace('/<!--.*{(.*)}.*-->/U', '{$1}', $source);

        return $source;
    }
}
