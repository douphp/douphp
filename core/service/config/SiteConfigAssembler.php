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

namespace Dou\Core\Service\Config;

use Dou\Core\Domain\Currency\CurrencyCatalog;
use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 站点配置装配器（Domain 读模型）。
 *
 * 输入：rootUrl、当前语言、是否为后台调用方。
 * 输出：站点配置数组（root_url、home_url、theme_url、weixin_img、site_logo、
 *      多语言字段覆盖；前台/API 场景额外解析 qq、net_safe_record_number）。
 *
 * 仅读库与字段拼装，不写入 Config；由 {@see \Dou\Core\Bootstrap\SiteBootstrap} 调用并写入。
 */
class SiteConfigAssembler extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 装配站点配置。
     *
     * 当前请求根 URL（DB.config.domain 为空时的 fallback）由 shell 层 Init 从
     * `Request::baseUrl()` 取好后显式传入，core 层无 HTTP 感知；当前语言从 Locale 单例读取。
     *
     * @param bool $publicFormatting 是否做对外站点的额外格式化（QQ、备案号）；后台传 false
     * @param string $rootUrl 当前请求根 URL，由 shell 层传入；DB.config.domain 为空时作 fallback
     * @return array
     */
    public function assemble($publicFormatting, $rootUrl = '')
    {
        $rootUrl = (string) $rootUrl;
        $currentLang = locale()->toArray();
        $config = array();

        $rows = DB::table('config')->select();
        foreach ((array) $rows as $row) {
            $config[$row['name']] = $row['value'];
        }

        $_ROOT_URL = !empty($config['domain']) ? $config['domain'] : $rootUrl;

        if ($publicFormatting) {
            if (!empty($config['qq'])) {
                $config['qq'] = Util::parseQqList($config['qq']);
            } else {
                $config['qq'] = array();
            }

            if (!empty($config['net_safe_record'])) {
                if (preg_match('/\d+/', $config['net_safe_record'], $arr)) {
                    $config['net_safe_record_number'] = $arr[0];
                }
            }
        }

        $fileUpdateTime = isset($config['file_update_time']) ? $config['file_update_time'] : 0;

        if (!empty($config['weixin_img'])) {
            $config['weixin_img'] = $_ROOT_URL . 'images/upload/' . $config['weixin_img'] . attachment()->cacheTag($fileUpdateTime);
        }

        $langRow = $this->currentLanguageRow();

        $siteLogo = DB::table('config')->where('name', 'site_logo')->value('value');
        if (is_array($langRow) && !empty($langRow['site_logo'])) {
            $siteLogo = $langRow['site_logo'];
        }
        $config['site_logo'] = ($siteLogo ? $siteLogo : '') . attachment()->cacheTag($fileUpdateTime);

        $config['root_url'] = $_ROOT_URL;
        $config['admin_url'] = $_ROOT_URL . ADMIN_DIR . '/';
        $siteTheme = isset($config['site_theme']) ? $config['site_theme'] : '';
        $config['theme_url'] = $_ROOT_URL . 'theme/' . $siteTheme . '/';

        $config = $this->applyBaseCurrency($config);

        if (!empty($currentLang)) {
            if (isset($currentLang['mode']) && $currentLang['mode'] === 'rewrite_open') {
                $config['home_url'] = $_ROOT_URL . $currentLang['sign'] . '/';
            } else {
                $config['home_url'] = $_ROOT_URL . '?lang=' . $currentLang['pack'];
            }

            $langFields = explode(',', 'site_name,site_title,site_keywords,site_description,address,tel,fax,email');
            if (is_array($langRow)) {
                foreach ($langFields as $field) {
                    if (!empty($langRow[$field])) {
                        $config[$field] = $langRow[$field];
                    }
                }
                $config = $this->applyLanguageCurrency($config, $langRow);
            }
        } else {
            $config['home_url'] = $_ROOT_URL;
        }

        return $config;
    }

    /**
     * 当前语言行（默认语言或 language 表缺失时返回 null）。
     *
     * features 在 SiteBootstrap::loadSite() 之后才装配，此处不能依赖；
     * 以「表是否存在 + 当前请求是否选定副语言」为守卫：多语言模块卸载后
     * 表被清理时自然跳过，不会在启动期报错。
     *
     * @return array|null
     */
    private function currentLanguageRow()
    {
        if (!DB::tableExist('language')) {
            return null;
        }

        $locale = locale();
        if (!$locale->isActive()) {
            return null;
        }

        $row = DB::table('language')->where('language_pack', $locale->pack())->find();

        return is_array($row) ? $row : null;
    }

    /**
     * 基准币配置归一（config.currency_code / currency_decimal）。
     *
     * `currency_code` / `currency_decimal` 两个原始键会被改写为「当前展示币」，
     * 基准币原值保留在 `currency_base_*`；副语言激活时再由语言行覆盖展示币。
     *
     * @param array $config
     * @return array
     */
    private function applyBaseCurrency(array $config)
    {
        $baseCode = isset($config['currency_code']) ? strtoupper(trim((string) $config['currency_code'])) : '';
        if ($baseCode === '' || !CurrencyCatalog::has($baseCode)) {
            $baseCode = 'CNY';
        }

        $baseDecimals = isset($config['currency_decimal']) && $config['currency_decimal'] !== ''
            ? (int) $config['currency_decimal']
            : CurrencyCatalog::defaultDecimals($baseCode);
        if (!CurrencyCatalog::isValidDecimals($baseCode, $baseDecimals)) {
            $baseDecimals = CurrencyCatalog::defaultDecimals($baseCode);
        }

        $symbol = CurrencyCatalog::symbol($baseCode);
        $config['currency_base_code'] = $baseCode;
        $config['currency_base_decimal'] = $baseDecimals;
        $config['currency_base_symbol'] = $symbol;

        $config['currency_code'] = $baseCode;
        $config['currency_rate'] = 1;
        $config['currency_decimal'] = $baseDecimals;
        $config['currency_symbol'] = $symbol;

        return $config;
    }

    /**
     * 副语言货币覆盖：未启用独立货币时完全跟随基准币。
     *
     * 「启用独立货币」的唯一判定是 currency_code 非空且不等于基准币
     * （见 {@see CurrencyCatalog::isIndependent()}）；未启用时汇率与小数位不参与装配，
     * 全流程等同单币种。
     *
     * @param array $config
     * @param array $langConfig language 表当前语言行
     * @return array
     */
    private function applyLanguageCurrency(array $config, array $langConfig)
    {
        $baseCode = $config['currency_base_code'];
        $code = isset($langConfig['currency_code']) ? strtoupper(trim((string) $langConfig['currency_code'])) : '';
        if (!CurrencyCatalog::isIndependent($code, $baseCode)) {
            return $config;
        }

        $rate = isset($langConfig['currency_rate']) ? (float) $langConfig['currency_rate'] : 0;
        if ($rate <= 0) {
            return $config;
        }

        $decimals = isset($langConfig['currency_decimal']) && $langConfig['currency_decimal'] !== ''
            ? (int) $langConfig['currency_decimal']
            : CurrencyCatalog::defaultDecimals($code);
        if (!CurrencyCatalog::isValidDecimals($code, $decimals)) {
            $decimals = CurrencyCatalog::defaultDecimals($code);
        }

        $config['currency_code'] = $code;
        $config['currency_rate'] = $rate;
        $config['currency_decimal'] = $decimals;
        $config['currency_symbol'] = CurrencyCatalog::symbol($code);

        return $config;
    }

    /**
     * 读取 parameter 表为 name => value 形式（启动期 Config::set('param', ...) 使用）。
     *
     * @return array
     */
    public function parameter()
    {
        $parameter = array();
        $query = DB::query("SELECT name, value, `group` FROM " . DB::tableName('parameter'));
        while ($row = DB::fetchArray($query)) {
            $parameter[$row['name']] = $row['value'];
        }

        return $parameter;
    }
}
