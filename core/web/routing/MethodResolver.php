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

namespace Dou\Core\Web\Routing;

use Dou\Core\Support\Check;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 路径动作段 → 控制器方法名解析器（三端共用）
 *
 * 保持 snake_case ↔ camelCase ↔ 横线转驼峰的双形态兼容：先查保留字别名表（class / use / get
 * / default），再按候选顺序匹配控制器上存在的方法名，最后兜底同名方法，皆未命中则回退 'index'。
 */
class MethodResolver
{
    /**
     * 路径动作段 → 控制器 PHP 方法名。
     *
     * @param string $pathAction route 路径解析出的动作段（非查询参数 rec）
     * @param string $controllerClass
     * @return string
     */
    public static function resolve($pathAction, $controllerClass)
    {
        $pathAction = trim((string) $pathAction);

        // 横线转驼峰：custom-admin-path → customAdminPath
        if (strpos($pathAction, '-') !== false) {
            $pathAction = lcfirst(str_replace('-', '', ucwords($pathAction, '-')));
        }

        // 路径动作段 → 控制器方法名的「优先表」，在候选之前匹配（如 default → index）。
        // list 是 PHP 保留字不能作方法名，统一映射 listing。
        $map = array(
            'default' => 'index',
            'list' => 'listing',
        );

        $pathActionLower = strtolower($pathAction);
        if (isset($map[$pathActionLower])) {
            return $map[$pathActionLower];
        }

        foreach (self::actionMethodCandidates($pathAction) as $candidate) {
            if ($candidate !== '' && method_exists($controllerClass, $candidate)) {
                return $candidate;
            }
        }

        // 兜底：动作段本身是合法标识（小写+下划线）且控制器上存在同名方法时，直接用作方法名。
        if (Check::routeActionSegment($pathActionLower) && method_exists($controllerClass, $pathActionLower)) {
            return $pathActionLower;
        }

        return 'index';
    }

    /**
     * 路径动作段（支持 camelCase/snake_case）→ 候选 PHP 方法名。
     * class / use 等保留字相关动作优先走安全别名；下划线和驼峰可双向兼容。
     *
     * @param string $action
     * @return array
     */
    private static function actionMethodCandidates($action)
    {
        $action = trim((string) $action);
        $actionLower = strtolower($action);
        if ($action === '' || $actionLower === 'default') {
            return array();
        }
        if ($actionLower === 'use') {
            return array('use_money', 'useMoney');
        }
        if ($actionLower === 'class') {
            return array('classAction');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $action) !== 1) {
            return array();
        }

        $candidates = array();
        $candidates[] = $action;

        if (strpos($action, '_') !== false) {
            $snake = strtolower($action);
            $candidates[] = self::snakeCaseToCamelCase($snake);
            $candidates[] = $snake;
        } else {
            $camel = lcfirst($action);
            $snake = self::camelCaseToSnakeCase($camel);
            $candidates[] = $camel;
            $candidates[] = $snake;
            $candidates[] = strtolower($action);
        }

        $result = array();
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if (!in_array($candidate, $result, true)) {
                $result[] = $candidate;
            }
        }

        return $result;
    }

    /**
     * 路径段 snake_case（如 apply_post）→ 控制器方法名 camelCase（applyPost）。
     *
     * @param string $snake 小写 + 下划线
     * @return string
     */
    private static function snakeCaseToCamelCase($snake)
    {
        $parts = explode('_', $snake);
        if (count($parts) === 1) {
            return $parts[0];
        }
        $first = array_shift($parts);
        foreach ($parts as $i => $p) {
            $parts[$i] = ucfirst($p);
        }

        return $first . implode('', $parts);
    }

    /**
     * camelCase 动作名转 snake_case（如 applyPost → apply_post）。
     *
     * @param string $camel
     * @return string
     */
    private static function camelCaseToSnakeCase($camel)
    {
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $camel);
        return strtolower($snake);
    }
}
