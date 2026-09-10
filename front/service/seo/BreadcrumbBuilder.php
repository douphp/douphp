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

namespace Dou\Front\Service\Seo;

use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Naming;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台面包屑 ViewModel 构建。
 *
 * 返回数组结构供 Controller `assign('ur_here', ...)`；
 * SchemaService 的 BreadcrumbList JSON-LD 由 {@see self::schema()} 包装，
 * Controller 自行 `assign('breadcrumb', $builder->schema($urHere))`。
 */
class BreadcrumbBuilder extends BaseService
{
    /** @var SchemaService */
    private $schema;

    /**
     * @param SchemaService $schema
     */
    public function __construct(SchemaService $schema)
    {
        $this->schema = $schema;
    }

    /**
     * 由已构建的 ur_here 派生 BreadcrumbList JSON-LD（供模板 `{$breadcrumb nofilter}`）。
     *
     * @param array $urHere {@see self::build()} 的返回值
     * @return string JSON-LD HTML 片段或空串
     */
    public function schema(array $urHere)
    {
        $result = $this->schema->breadcrumb($urHere);

        return is_string($result) ? $result : '';
    }

    /**
     * 构建面包屑 ViewModel。
     *
     * @param string $module
     * @param string|int $class 分类 id 或 lang 键
     * @param string $title 详情标题
     * @param array $archive 归档参数（{@see \Dou\Core\Support\Util::parseArchive()}）；非空时 class 槽改为日期归档段（名「2026年01月」、链接归档列表）
     * @return array { module:array, class:array, title:string|null }
     */
    public function build($module = '', $class = '', $title = '', array $archive = array())
    {
        $urHere = array();
        $baseModule = Naming::baseModule($module);

        if ($module && $module != 'page' && $module != 'item' && $module != 'item_category') {
            $urHere['module']['name'] = lang($module);
            $urHere['module']['url'] = route($baseModule);
        }

        if ($archive) {
            $urHere['class']['name'] = Util::archiveLabel($archive);
            $urHere['class']['url'] = $this->archiveUrl($baseModule, $archive);
        } elseif ($class) {
            if (is_numeric($class)) {
                $urHere['class']['name'] = DB::table($module)->where('id', intval($class))->value('name');
                if (locale()->isActive()) {
                    $urHere['class']['name'] = language()->langValue($urHere['class']['name'], $module, $class, 'name');
                }
            } else {
                $urHere['class']['name'] = lang($class);
            }
            if (is_numeric($class)) {
                $urHere['class']['url'] = route($baseModule . '.category', array('category_id' => $class));
            } else {
                $urHere['class']['url'] = route($baseModule . '.' . $class);
            }
        }

        if ($title) {
            $urHere['title'] = $title;
        }

        if (!isset($urHere['module'])) {
            $urHere['module'] = array();
        }
        if (!isset($urHere['class'])) {
            $urHere['class'] = array();
        }
        if (!isset($urHere['title'])) {
            $urHere['title'] = null;
        }

        return $urHere;
    }

    /**
     * 归档列表 URL（栏目根 + /年[/月]）。
     *
     * 月份两位补零以匹配风格7 的 {month:\d{2}} 规则。
     *
     * @param string $baseModule 数据库模块名（已去 _category 后缀）
     * @param array $archive 归档参数
     * @return string
     */
    private function archiveUrl($baseModule, array $archive)
    {
        $url = route($baseModule . '.category') . '/' . sprintf('%04d', (int) $archive['year']);
        if (!empty($archive['month'])) {
            $url .= '/' . sprintf('%02d', (int) $archive['month']);
        }

        return $url;
    }
}
