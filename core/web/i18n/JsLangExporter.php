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

namespace Dou\Core\Web\I18n;

use Dou\Core\Web\Manifest\ManifestCacheGeneration;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 将当前请求已加载的语言包译串表（LangBag / lang_all()）导出为浏览器端可消费的
 * 静态脚本（window.__douLang = {...};）或裸 JSON（小程序 GET lang）。
 *
 * 与 {@see \Dou\Core\Web\Routing\JsRouteExporter} 同模式：内容指纹 immutable 缓存，
 * 由各端 lang_js / lang 端点以 application/javascript / application/json 输出，
 * 内容变更时调用方据 hash 变化触发 <script src> ?v= 重新拉取。
 *
 * 语言内容来源单一：当前请求 Init 阶段 loadLanguageFiles() 已写入 LangBag，本类不重复加载，
 * 也不感知 admin / front 差异——$shell / $pack 仅用于缓存文件命名与去重，hash 取自实际内容。
 */
class JsLangExporter
{
    /**
     * 当前请求语言包译串表的 JSON 字面量。
     *
     * @return string
     */
    public static function langJson()
    {
        $items = function_exists('lang_all') ? (array) lang_all() : array();

        return self::encode($items);
    }

    /**
     * 按语言包文件清单加载并序列化为 JSON（与 langJson 同格式，供小程序同步期种子生成）。
     *
     * 按清单顺序 require 各 *.lang.php，后者覆盖前者同名键；与 Init::loadLanguageFiles 语义一致。
     *
     * @param array $files 语言文件绝对路径列表（来自 ModuleLanguageManifest::build）
     * @return string
     */
    public static function langJsonForFiles(array $files)
    {
        return self::encode(self::langItemsFromFiles($files));
    }

    /**
     * 按文件清单合并译串表。
     *
     * @param array $files
     * @return array
     */
    public static function langItemsFromFiles(array $files)
    {
        $items = array();
        foreach ((array) $files as $file) {
            if (!is_string($file) || $file === '' || !is_file($file)) {
                continue;
            }
            $_LANG = array();
            require $file;
            if (is_array($_LANG) && $_LANG) {
                $items = array_merge($items, $_LANG);
            }
        }

        return $items;
    }

    /**
     * 语言包内容指纹（用于 ETag / 小程序 lang_v；HTML ?v= 见 manifestUrlVersion）。
     *
     * @param string $shell admin | front | api（仅参与文件命名，不改变内容）
     * @param string $pack 当前语言包标识（如 zh_cn、zh_cn/admin）
     * @return string
     */
    public static function manifestHash($shell, $pack)
    {
        return substr(md5(self::langJson()), 0, 12);
    }

    /**
     * Web 端 script src 的 ?v= 查询参数（内容指纹 + 清空世代）。
     *
     * @param string $shell admin | front
     * @param string $pack 当前语言包标识
     * @return string
     */
    public static function manifestUrlVersion($shell, $pack)
    {
        return ManifestCacheGeneration::urlVersion(self::manifestHash($shell, $pack));
    }

    /**
     * 生成可作 <script src> 直接执行的语言包 JS（window.__douLang = {...};）。
     *
     * 命中缓存则直接复用；缺失则现算并落盘。返回 JS 字符串，由各端 lang_js 端点以
     * application/javascript 输出（不暴露 storage 直链）。
     *
     * @param string $shell admin | front
     * @param string $pack 当前语言包标识
     * @return string
     */
    public static function ensureCachedLangScript($shell, $pack)
    {
        $js = 'window.__douLang = ' . self::langJson() . ';' . "\n";
        self::writeCacheFile(self::langFilePath($shell, $pack, 'js'), $js);

        return $js;
    }

    /**
     * 生成可作 GET lang 响应体的裸 JSON（小程序 commonStore.lang 合并源）。
     *
     * @param string $shell front | api
     * @param string $pack 当前语言包标识
     * @return string
     */
    public static function ensureCachedLangJson($shell, $pack)
    {
        $json = self::langJson();
        self::writeCacheFile(self::langFilePath($shell, $pack, 'json'), $json);

        return $json;
    }

    /**
     * 删除所有已缓存的语言包文件（清缓存链路调用）。
     *
     * @return void
     */
    public static function clearCachedLangScripts()
    {
        foreach ((array) glob(self::cacheDir() . '*-lang-*') as $file) {
            @unlink($file);
        }
    }

    /**
     * 缓存文件绝对路径（固定文件名）：{shell}-lang-{packSlug}.{ext}。
     *
     * pack 尾段与 shell 同名时去重（zh_cn/admin + admin -> zh_cn），
     * 避免 admin-lang-zh_cn_admin 这类重复命名。
     *
     * @param string $shell
     * @param string $pack
     * @param string $ext js | json
     * @return string
     */
    private static function langFilePath($shell, $pack, $ext)
    {
        $shellSlug = self::slug($shell);
        $packSlug = self::slug($pack);

        if ($shellSlug !== '' && substr($packSlug, -strlen($shellSlug)) === $shellSlug) {
            $packSlug = substr($packSlug, 0, -strlen($shellSlug) - 1);
        }

        return self::cacheDir() . $shellSlug . '-lang-' . $packSlug . '.' . $ext;
    }

    /**
     * 落盘（目录缺失时创建；固定文件名，内容变化时直接覆盖）。
     *
     * @param string $file
     * @param string $content
     * @return void
     */
    private static function writeCacheFile($file, $content)
    {
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($file, $content, LOCK_EX);
    }

    /**
     * 文件名安全化：非字母数字一律转下划线（zh_cn/admin -> zh_cn_admin）。
     *
     * @param string $value
     * @return string
     */
    private static function slug($value)
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', strtolower((string) $value));

        return trim((string) $slug, '_');
    }

    /**
     * 内部缓存目录（storage/cache/js，与 routes manifest 同级）。
     *
     * @return string
     */
    private static function cacheDir()
    {
        $base = defined('STORAGE_PATH') ? STORAGE_PATH : (defined('ROOT_PATH') ? ROOT_PATH . 'storage/' : '');

        return $base . 'cache' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR;
    }

    /**
     * 译串表 JSON 编码（与 GET lang / lang_js 输出一致）。
     *
     * @param array $items
     * @return string
     */
    private static function encode(array $items)
    {
        $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '{}';
    }
}
