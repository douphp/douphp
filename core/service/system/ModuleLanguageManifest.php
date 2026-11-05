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

namespace Dou\Core\Service\System;

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 模块语言包清单（Domain 读模型）。
 *
 * 按显式 `$langPack` 路径汇总语言包文件：
 * - `languages/{$langPack}/*.lang.php`
 * - 各模块 `languages/{$langPack}/{module}.lang.php`
 * - 站点自定义语言文件 `languages/{$langPack}/_custom.lang.php`（追加到清单末尾，
 *   新增与覆盖均可用，保证同名 `$_LANG` 键优先级最高）
 *
 * 完全使用显式参数表达运行时差异（前台/后台/语言切换由调用方传入 langPack），
 * 不依赖 `defined('IS_ADMIN')` 等隐式分支。
 */
class ModuleLanguageManifest extends BaseService
{
    /**
     * 站点自定义语言文件名（单文件约定）。
     *
     * 为何带 `_` 前缀：
     * - 模块语言文件名即模块 id（如 article.lang.php），id 约定不以 `_` 开头，
     *   故本文件名不可能与任何现有或未来的模块文件重名；
     * - 因此不需要按主题编号另起文件名（如 m147.lang.php），一个通用名即可；
     * - 文件管理器里 `_` 排在字母前，便于运营一眼定位定制文件。
     *
     * 职责：站点级自定义语言的唯一入口，新增键与覆盖已有键都放这里。
     * 加载顺序与文件名无关：glob 会把本文件一并捞入且排在最前（`_` 早于字母），
     * 故 build() 中先剔除、再显式追加末尾，保证其优先级最高。
     * 站点定制通常只有个位数个键，单文件足够，不做多文件或子目录拆分。
     */
    const CUSTOM_FILENAME = '_custom.lang.php';

    /**
     */
    public function __construct()
    {
    }

    /**
     * 构造模块语言包文件列表。
     *
     * @param array $modules 模块 id 列表（column_module + single_module 合并后)
     * @param string $langPack 当前生效的语言包路径（如 zh_cn / zh_cn/admin / en_us）
     * @return array 语言文件绝对路径列表
     */
    public function build(array $modules, $langPack)
    {
        $langPack = (string) $langPack;

        $customFile = ROOT_PATH . 'languages/' . $langPack . '/' . self::CUSTOM_FILENAME;

        $langList = glob(ROOT_PATH . 'languages/' . $langPack . '/' . '*.lang.php');
        $langList = is_array($langList) ? $langList : array();
        // 自定义文件名也匹配 *.lang.php，glob 会把它一起捞回来；
        // 因 '_' (0x5F) 在 ASCII 中早于字母，glob 字典序会把它排到主清单最前，
        // 反被后续模块语言文件覆盖，达不到「优先级最高」的语义。
        // 这里先从主清单剔除，再在最末尾按需追加，确保 require 顺序最后。
        $langList = array_values(array_filter($langList, function ($file) {
            return basename((string) $file) !== self::CUSTOM_FILENAME;
        }));

        $moduleIds = array_unique($modules);
        foreach ($moduleIds as $moduleId) {
            if (!is_string($moduleId) || $moduleId === '') {
                continue;
            }
            $moduleLangFile = ROOT_PATH . 'languages/' . $langPack . '/' . $moduleId . '.lang.php';
            if (is_file($moduleLangFile) && !in_array($moduleLangFile, $langList, true)) {
                $langList[] = $moduleLangFile;
            }
        }

        if (is_file($customFile)) {
            $langList[] = $customFile;
        }

        return $langList;
    }
}
