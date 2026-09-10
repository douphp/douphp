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

namespace Dou\Front\Service\Llms;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Module\ContentTypeRegistry;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Str;
use Dou\Front\Model\Page\Page;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台 llms.txt 文本业务层
 */
class LlmsService extends BaseService
{
    /** @var ContentTypeRegistry */
    private $types;

    /**
     * @param ContentTypeRegistry $types 内容类型注册表（按 llms 通道筛出导出 Model）
     */
    public function __construct(ContentTypeRegistry $types)
    {
        $this->types = $types;
    }

    /**
     * 生成 llms.txt 全文（Markdown）
     *
     * @return string
     */
    public function buildLlmsText()
    {
        $output = '# ' . Config::get('site.site_name', '') . "\n\n";
        $output .= '> ' . Config::get('site.site_description', '') . "\n\n";

        // 语言切换链接
        $lang_links = array();

        // 默认语言
        $default_url = Config::get('site.rewrite', false) ? ROOT_URL . 'llms.txt' : ROOT_URL . 'llms.php';
        $lang_links[] = '[' . (lang('cur_language') ? lang('cur_language') : 'Default') . '](' . $default_url . ')';

        // 其他语言
        $langMenu = language()->getLangMenu();
        if ($langMenu) {
            foreach ($langMenu as $lang) {
                $lang_code = str_replace('_', '-', $lang['language_pack']);
                $lang_url = Config::get('site.rewrite', false) ? ROOT_URL . $lang_code . '/llms.txt' : ROOT_URL . 'llms.php?lang=' . $lang['language_pack'];
                $lang_links[] = '[' . $lang['name'] . '](' . $lang_url . ')';
            }
        }

        if (!empty($lang_links)) {
            $output .= implode(' | ', $lang_links) . "\n\n";
        }

        $output .= $this->readItem();

        return $output;
    }

    /**
     * @return string
     */
    protected function readItem()
    {
        $output = '';

        // 单页面列表
        $pages = Page::pageNolevel();
        if (!empty($pages)) {
            $output .= '## ' . lang('page') . "\n\n";
            foreach ($pages as $row) {
                $desc = $row['description'] ? Str::excerpt($row['description'], 100) : '';
                $output .= '- [' . $row['name'] . '](' . $row['url'] . ')' . ($desc ? ': ' . $desc : '') . "\n";
            }
            $output .= "\n";
        }

        // 内容模块（column 按分类分组；single 单组）
        foreach ($this->types->forLlms() as $cls) {
            $schema = $cls::moduleSchema();
            $module = $schema['module'];
            $kind = isset($schema['kind']) ? $schema['kind'] : 'column';

            if ($kind === 'column') {
                $cats = $cls::categoriesForExport();
                if (empty($cats)) {
                    continue;
                }

                foreach ($cats as $cat) {
                    $output .= '## ' . $cat['name'] . "\n\n";
                    $list = $cls::listForExport($cat['id'], 20);
                    foreach ((array) $list as $row) {
                        $title = $row['title'] ? $row['title'] : $row['name'];
                        $desc = $row['description'] ? Str::excerpt($row['description'], 100) : '';
                        $output .= '- [' . $title . '](' . $row['url'] . ')' . ($desc ? ': ' . $desc : '') . "\n";
                    }
                    $output .= "\n";
                }
            } else {
                $list = $cls::listForExport();
                if (empty($list)) {
                    continue;
                }

                $output .= '## ' . lang($module) . "\n\n";
                foreach ($list as $row) {
                    $title = $row['title'] ? $row['title'] : $row['name'];
                    $desc = $row['description'] ? Str::excerpt($row['description'], 100) : '';
                    $output .= '- [' . $title . '](' . $row['url'] . ')' . ($desc ? ': ' . $desc : '') . "\n";
                }
                $output .= "\n";
            }
        }

        return $output;
    }
}
