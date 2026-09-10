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

namespace Dou\Api\Service\Init;

use Dou\Core\Facade\Portal;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序包扩展加载器。
 *
 * 负责加载当前启用小程序包内的 `inc/..from_miniprogram.php`：在 API 路由已知后、
 * 控制器执行之前调用，以便扩展脚本能基于当前路由分支决定要塞什么 JSON 字段。
 *
 * 扩展脚本既可用 include 作用域内的 `$routeModule` / `$routeAction` / `$assigns` 读写数据，
 * 也可经静态门面 {@see Portal}（`Portal::routeModule()` / `Portal::assign()` 等）访问；
 * include 前 {@see Portal::boot()} 注入本加载器为赋值目标，`Portal::assign()` 最终写入
 * `$this->assigns`，由 `takeAssigns()` 取出合并进 JSON 响应。
 */
class MiniprogramExtensionLoader extends BaseService
{
    /** @var array */
    private $assigns = array();

    /**
     * 供 Portal 静态门面委托写入：把键值塞进 $this->assigns，最终由 takeAssigns() 取出合并进 JSON 响应。
     *
     * @param string|array $key
     * @param mixed $value
     * @return void
     */
    public function assign($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->assigns[$k] = $v;
            }
            return;
        }
        $this->assigns[$key] = $value;
    }

    /**
     * @param string $routeModule
     * @param string $routeAction
     * @return void
     */
    public function loadForRoute($routeModule = '', $routeAction = '')
    {
        $this->assigns = array();

        $slug = Config::get('site.miniprogram_code', 'default');
        $slug = is_string($slug) && $slug !== '' ? $slug : 'default';
        $path = ROOT_PATH . MINIPROGRAM_DIR . '/' . $slug . '/inc/..from_miniprogram.php';

        if (!file_exists($path)) {
            return;
        }
        if (!Util::isSqlSafePhpFile($path)) {
            return;
        }

        $assigns = &$this->assigns;
        $routeModule = (string) $routeModule;
        $routeAction = (string) $routeAction;

        Portal::boot($routeModule, $routeAction, $this);

        include_once($path);
    }

    /**
     * @return array
     */
    public function takeAssigns()
    {
        $assigns = $this->assigns;
        $this->assigns = array();

        return $assigns;
    }
}
