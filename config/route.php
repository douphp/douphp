<?php

/**
 * DouPHP
 * ------------------------------------------------------------------------------------
 * 版权所有 2013-2026 漳州豆壳网络科技有限公司，并保留所有权利。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在遵守授权协议前提下对程序代码进行修改和使用；
 * 不允许对程序代码以任何形式任何目的的再发布。
 * 授权协议：http://www.douphp.com/license.html
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-04
 */

/**
 * +----------------------------------------------------------
 * 路由风格规则配置（按风格分组）
 * 自定义路由风格规则配置的方法：复制此文件为 route_custom.php（与 route.php 同级目录），只需要定义需要覆盖的风格，不需要复制所有规则，可以添加全新的风格类型
 * +----------------------------------------------------------
 * $name 风格显示名称（后台使用）
 * $rules 该风格下的所有规则数组，每条规则格式如下：
 * $pattern URL模式，支持 {param}、{param:regex}、[/optional] 语法
 * $params 参数默认正则（可选，pattern内嵌正则优先）
 * $target 目标文件名模板（可选，默认 {module}.php）
 * $module_fixed 固定模块名（可选，用于URL不含模块段的情况）
 * 规则命名参数会原样进入 params（由统一路由层解释）
 * +----------------------------------------------------------
 */
return [
    /**
     * +----------------------------------------------------------
     * PAGE — 单页面（固定加载 page.php）
     * +----------------------------------------------------------
     */
    'page' => [
        'suffix' => [
            'name' => '<p><i>有后缀</i>{root_url}abc.html</p>',
            'rules' => [
                [
                    'pattern' => '{slug}.html',
                    'params' => ['slug' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                    'module_fixed' => 'page',
                ],
            ],
        ],
        'prefixed' => [
            'name' => '<p><i>无后缀</i>{root_url}page/abc</p>',
            'rules' => [
                [
                    'pattern' => 'page/{slug}',
                    'params' => ['slug' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                    'module_fixed' => 'page',
                ],
            ],
        ],
        'id' => [
            'name' => '<p><i>数字ID</i>{root_url}page/123</p>',
            'rules' => [
                [
                    'pattern' => 'page/{id:\d+}',
                    'module_fixed' => 'page',
                ],
            ],
        ],
    ],

    /**
     * +----------------------------------------------------------
     * COLUMN — 栏目模块（product / article / doc 等）
     * +----------------------------------------------------------
     */
    'column' => [
        // 风格1：分类别名 + 详情嵌套别名及ID后缀
        'alias_nested_suffix' => [
            'name' => '<p><i>分类页</i>{root_url}module/fenlei</p><p><i>详情页</i>{root_url}module/fenlei/123.html</p>',
            'rules' => [
                [
                    'pattern' => '{module}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}/{category_slug}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+', 'category_slug' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}[/{category_slug}]/{id:\d+}.html',
                    'params' => ['module' => '[a-z]+', 'category_slug' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                ],
            ],
        ],

        // 风格2：分类前缀 collections + 详情 slug
        'prefixed_alias' => [
            'name' => '<p><i>分类页</i>{root_url}module/collections/fenlei</p><p><i>详情页</i>{root_url}module/slug-slug-slug-slug</p>',
            'rules' => [
                [
                    'pattern' => '{module}/collections/{category_slug}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}/{slug}',
                    'params' => ['module' => '[a-z]+', 'slug' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                ],
            ],
        ],

        // 风格3：分类别名 + 详情ID后缀
        'alias_id_suffix' => [
            'name' => '<p><i>分类页</i>{root_url}module/fenlei</p><p><i>详情页</i>{root_url}module/123.html</p>',
            'rules' => [
                [
                    'pattern' => '{module}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}/{category_slug}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+', 'category_slug' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}/{id:\d+}.html',
                    'params' => ['module' => '[a-z]+'],
                ],
            ],
        ],

        // 风格4：分类别名 + 详情嵌套别名无后缀
        'alias_nested' => [
            'name' => '<p><i>分类页</i>{root_url}module/fenlei</p><p><i>详情页</i>{root_url}module/fenlei/123</p>',
            'rules' => [
                [
                    'pattern' => '{module}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}/{category_slug}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+', 'category_slug' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}[/{category_slug}]/{id:\d+}',
                    'params' => ['module' => '[a-z]+', 'category_slug' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                ],
            ],
        ],

        // 风格5：分类前缀ID + 详情ID后缀
        'prefixed_id_suffix' => [
            'name' => '<p><i>分类页</i>{root_url}module/category/fenlei</p><p><i>详情页</i>{root_url}module/123</p>',
            'rules' => [
                [
                    'pattern' => '{module}/category/{category_slug}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}/{id:\d+}',
                    'params' => ['module' => '[a-z]+'],
                ],
            ],
        ],

        // 风格6：分类前缀ID + 详情ID无后缀
        'prefixed_id' => [
            'name' => '<p><i>分类页</i>{root_url}module/category/123</p><p><i>详情页</i>{root_url}module/123</p>',
            'rules' => [
                [
                    'pattern' => '{module}/category/{id:\d+}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}/{id:\d+}',
                    'params' => ['module' => '[a-z]+'],
                ],
            ],
        ],

        // 风格7：分类别名 + 详情日期归档带后缀
        'alias_dated_suffix' => [
            'name' => '<p><i>分类页</i>{root_url}module/fenlei</p><p><i>详情页</i>{root_url}module/2026/01/123.html</p>',
            'rules' => [
                [
                    'pattern' => '{module}/{year:\d{4}}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}/{year:\d{4}}/{month:\d{2}}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}/{category_slug}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+', 'category_slug' => '[a-zA-Z][a-zA-Z0-9_-]*'],
                    'target' => '{module}_category',
                ],
                [
                    'pattern' => '{module}/{year:\d{4}}/{month:\d{2}}/{id:\d+}.html',
                    'params' => ['module' => '[a-z]+'],
                ],
            ],
        ],
    ],

    /**
     * +----------------------------------------------------------
     * SIMPLE — 简单模块（user / service / book 等）
     * +----------------------------------------------------------
     */
    'simple' => [
        'alias_nested' => [
            'name' => '<p><i>列表页</i>{root_url}module</p><p><i>分组页</i>{root_url}module/class/fenlei</p><p><i>操作页</i>{root_url}module/action/sub-action</p>',
            'rules' => [
                [
                    'pattern' => '{module}/class/{class}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+', 'class' => '[a-zA-Z0-9\-_\x{4e00}-\x{9fa5}]+'],
                ],
                [
                    'pattern' => '{module}/{action}/{sub_action}',
                    'params' => ['module' => '[a-z]+', 'action' => '[a-z_-]+', 'sub_action' => '[a-z_-]+'],
                ],
                [
                    'pattern' => '{module}/{action}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+', 'action' => '[a-z_-]+'],
                ],
                [
                    'pattern' => '{module}/{id:\d+}',
                    'params' => ['module' => '[a-z]+'],
                ],
                [
                    'pattern' => '{module}[/o{page:\d*}]',
                    'params' => ['module' => '[a-z]+'],
                ],
            ],
        ],
    ],
];
