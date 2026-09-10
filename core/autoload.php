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
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

// -----------------------------------------------------------------------
// Dou\ 命名空间类自动注册入口
// 当前按类别依次自动注册：
// 1) 插件类（Dou\Plugin\* -> plugin/*/src/*）
// 2) Core 框架类（Dou\Core\* -> core/*）
// 3) Core 模块类（Dou\Core\Module\*，按安装模块构建映射）
// 4) Core Vendor 适配类（Dou\Vendor\*）
// 5) 端侧（Dou\{Admin|Front|Api}\{Init|Lib|Middleware|Http|Controller|Model|Service|Request|Facade|Foundation}\*）
// 6) Core 目录兜底（按命名空间片段映射）
// -----------------------------------------------------------------------
// 自动注册类别：Core 模块类（仅注册当前安装模块）
$coreModuleClassMap = array();
$douModuleMap = defined('DOU_MODULE_MAP') ? unserialize(DOU_MODULE_MAP) : array();
$installedModules = isset($douModuleMap['all_module']) && is_array($douModuleMap['all_module']) ? $douModuleMap['all_module'] : array();
foreach ($installedModules as $moduleId) {
    $className = douModuleToClassName($moduleId);
    if ($className === '') {
        continue;
    }
    $classFile = CORE_PATH . 'module/' . $className . '.php';
    if (file_exists($classFile)) {
        $coreModuleClassMap['Dou\\Core\\Module\\' . $className] = $classFile;
    }
}

spl_autoload_register(function ($className) use (
    $coreModuleClassMap
) {
    if (strncmp($className, 'Dou\\', 4) !== 0) {
        return;
    }

    // 自动注册类别：插件类（Dou\Plugin\*）
    $pluginClassFile = douResolvePluginClassFile($className);
    if ($pluginClassFile !== '' && douLoadClassFile($pluginClassFile)) {
        return;
    }
    $namespaceSuffix = substr($className, 4);
    $namespaceParts = explode('\\', $namespaceSuffix);
    $classBasename = array_pop($namespaceParts);
    $partCount = count($namespaceParts);

    // Dou\Core 动态解析：Dou\Core\Xxx\Yyy -> core/xxx/Yyy.php
    if ($partCount >= 2 && $namespaceParts[0] === 'Core') {
        $coreParts = array_slice($namespaceParts, 1);
        $coreDirParts = array_map('strtolower', $coreParts);
        $classFile = CORE_PATH . implode('/', $coreDirParts) . '/' . $classBasename . '.php';
        if (douLoadClassFile($classFile)) {
            return;
        }
    }

    // 端侧动态解析：Dou\{Admin|Front|Api}\{Init|Lib|Middleware|Http|Controller|Model|Service|Request|Facade|Foundation}\...
    if ($partCount >= 2) {
        $entryName = $namespaceParts[0];
        $layerName = $namespaceParts[1];
        if ($entryName === 'Admin' || $entryName === 'Front' || $entryName === 'Api') {
            $entryBasePath = '';
            if ($entryName === 'Admin') {
                $entryBasePath = ROOT_PATH . trim(ADMIN_DIR, '/') . '/';
            } elseif ($entryName === 'Front') {
                $entryBasePath = FRONT_PATH;
            } elseif ($entryName === 'Api') {
                $entryBasePath = API_PATH;
            }

            if ($entryBasePath !== '') {
                if ($layerName === 'Init' || $layerName === 'Lib' || $layerName === 'Middleware') {
                    $classFile = douResolveEndpointBaseClassFile($entryBasePath, $layerName, $classBasename);
                    if (douLoadClassFile($classFile)) {
                        return;
                    }
                } elseif ($layerName === 'Http' || $layerName === 'Controller' || $layerName === 'Model' || $layerName === 'Service' || $layerName === 'Request' || $layerName === 'Facade' || $layerName === 'Foundation' || $layerName === 'Contract') {
                    $subNamespaceParts = $partCount >= 3 ? array_slice($namespaceParts, 2) : array();
                    $classFile = douResolveEndpointClassFile($entryBasePath, strtolower($layerName), $subNamespaceParts, $classBasename);
                    if (douLoadClassFile($classFile)) {
                        return;
                    }
                }
            }
        }
    }
    // Core 模块映射：Dou\Core\Module\*
    if (isset($coreModuleClassMap[$className]) && douLoadClassFile($coreModuleClassMap[$className])) {
        return;
    }
    // Core Vendor 约定解析：Dou\Vendor\*
    $vendorClassFile = douResolveVendorClassFile($className);
    if ($vendorClassFile !== '' && douLoadClassFile($vendorClassFile)) {
        return;
    }

    // 兜底：按命名空间片段映射到 core 下同名目录
    $dirParts = array_map('strtolower', $namespaceParts);
    $classFile = CORE_PATH . implode('/', $dirParts) . '/' . $classBasename . '.php';
    douLoadClassFile($classFile);
});

/**
 * 文件存在时加载类文件
 *
 * @param string $classFile
 * @return bool
 */
function douLoadClassFile($classFile)
{
    if ($classFile !== '' && file_exists($classFile)) {
        require_once($classFile);
        return true;
    }
    return false;
}

/**
 * 解析三端 Controller/Model/Service 规范路径
 *
 * @param string $entryBasePath
 * @param string $layer
 * @param array $subNamespaceParts
 * @param string $classBasename
 * @return string
 */
function douResolveEndpointClassFile($entryBasePath, $layer, array $subNamespaceParts, $classBasename)
{
    if (empty($subNamespaceParts)) {
        return $entryBasePath . $layer . '/' . $classBasename . '.php';
    }
    $subPath = array_map('strtolower', $subNamespaceParts);
    return $entryBasePath . $layer . '/' . implode('/', $subPath) . '/' . $classBasename . '.php';
}

/**
 * 解析端侧基础层（Init/Lib/Middleware）规范路径
 *
 * @param string $entryBasePath
 * @param string $layerName
 * @param string $classBasename
 * @return string
 */
function douResolveEndpointBaseClassFile($entryBasePath, $layerName, $classBasename)
{
    if ($layerName === 'Init') {
        return $entryBasePath . 'init/Init.php';
    }
    return $entryBasePath . strtolower($layerName) . '/' . $classBasename . '.php';
}

/**
 * 解析 Core Vendor 规范路径
 *
 * 约定：
 * Dou\Vendor\{Package}\{Class} -> core/library/{strtolower(Package)}/{Class}.php（相对网站根，由 LIBRARY_PATH 拼接）
 * Dou\Vendor\{Package}\{SubNamespace}\{Class} ->
 * core/library/{strtolower(Package)}/{SubNamespace...}/{Class}.php（同上）
 *
 * @param string $className
 * @return string
 */
function douResolveVendorClassFile($className)
{
    if (strncmp($className, 'Dou\\Vendor\\', 11) !== 0) {
        return '';
    }

    $namespaceSuffix = substr($className, 11);
    if ($namespaceSuffix === '') {
        return '';
    }

    $namespaceParts = explode('\\', $namespaceSuffix);
    if (count($namespaceParts) < 2) {
        return '';
    }

    $packageName = array_shift($namespaceParts);
    $classBasename = array_pop($namespaceParts);
    if ($packageName === '' || $classBasename === '') {
        return '';
    }

    $path = LIBRARY_PATH . strtolower($packageName) . '/';
    if (!empty($namespaceParts)) {
        $path .= implode('/', $namespaceParts) . '/';
    }

    return $path . $classBasename . '.php';
}

/**
 * 模块标识转 StudlyClass（snake_case -> StudlyCase）
 *
 * @param string $moduleId
 * @return string
 */
function douModuleToClassName($moduleId)
{
    $moduleId = trim(strtolower((string) $moduleId));
    if ($moduleId === '') {
        return '';
    }
    $parts = explode('_', $moduleId);
    $className = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $className .= ucfirst($part);
        }
    }
    return $className;
}

/**
 * 解析插件命名空间类文件路径
 *
 * @param string $className
 * @return string
 */
function douResolvePluginClassFile($className)
{
    if (strncmp($className, 'Dou\\Plugin\\', 11) !== 0) {
        return '';
    }

    $namespaceSuffix = substr($className, 11);
    if ($namespaceSuffix === '') {
        return '';
    }

    $namespaceParts = explode('\\', $namespaceSuffix);
    if (count($namespaceParts) < 2) {
        return '';
    }

    $pluginName = array_shift($namespaceParts);
    if ($pluginName === '') {
        return '';
    }

    $classBasename = array_pop($namespaceParts);
    if ($classBasename === '') {
        return '';
    }

    $classPath = $classBasename . '.php';
    if (!empty($namespaceParts)) {
        $classPath = implode('/', $namespaceParts) . '/' . $classPath;
    }

    $pluginRoot = ROOT_PATH . 'plugin/' . strtolower($pluginName) . '/';
    $newPath = $pluginRoot . $classPath;
    if (file_exists($newPath)) {
        return $newPath;
    }

    // 兼容把类文件放在 plugin/<name>/src/ 下的目录结构。
    return $pluginRoot . 'src/' . $classPath;
}
