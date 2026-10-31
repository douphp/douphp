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

namespace Dou\Admin\Service\Language;

use Dou\Admin\Model\Language\Language;
use Dou\Core\Domain\Currency\CurrencyCatalog;
use Dou\Core\Facade\DB;
use Dou\Core\Filesystem\Storage;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;
use Dou\Core\Support\FileHelper;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台语言管理（LanguageController）私有业务服务。
 *
 * 承担后台「language 模块 CRUD」职责（语言包列表 / 新增 / 编辑 / 删除 / 设置切换），
 * 不参与三端通用 language() 调用面（那是 LanguageContract 的实现）。
 */
class LanguageService extends BaseService
{
    /** @var \Dou\Core\Filesystem\Disk */
    private $themeLogoDisk;

    public function __construct()
    {
        $this->themeLogoDisk = Storage::build('theme/' . Config::get('site.site_theme', '') . '/images/');
    }

    /**
     * @return array
     */
    public function buildLanguageListRows()
    {
        $rows = DB::table('language')->order('sort ASC, id ASC')->select();
        $languageList = array();
        foreach ((array) $rows as $row) {
            $languageList[] = array(
                'language_id' => $row['id'],
                'name' => $row['name'],
                'language_pack' => $row['language_pack'],
                'site_logo' => $row['site_logo']
                    ? 'theme/' . Config::get('site.site_theme', '') . '/images/' . $this->siteLogoForLang($row['language_pack'])
                    : '',
                'sort' => $row['sort'],
            );
        }
        return $languageList;
    }

    /**
     * 根据语言包解析站点 logo 文件名。
     *
     * @param string $languagePack
     * @return string
     */
    public function siteLogoForLang($languagePack = '')
    {
        $siteLogo = DB::table('config')->where('name', 'site_logo')->value('value');
        if ($languagePack !== '') {
            $langLogo = DB::table('language')->where('language_pack', $languagePack)->value('site_logo');
            if ($langLogo) {
                $siteLogo = $langLogo;
            }
        }
        return $siteLogo ? $siteLogo : '';
    }

    /**
     * 添加页表单默认值（与 insert 字段、language.htm 一致）
     *
     * @return array
     */
    public function buildLanguageCreateData()
    {
        $baseCode = $this->baseCode();
        return array(
            'language_id' => 0,
            'name' => '',
            'language_pack' => '',
            'currency_code' => '',
            'currency_name' => CurrencyCatalog::name($baseCode),
            'currency_symbol' => CurrencyCatalog::symbol($baseCode),
            'currency_rate' => 1,
            'currency_decimal' => $this->baseDecimals(),
            'currency_enabled' => false,
            'site_name' => '',
            'site_title' => '',
            'site_keywords' => '',
            'site_description' => '',
            'site_logo' => '',
            'address' => '',
            'tel' => '',
            'fax' => '',
            'email' => '',
            'sort' => 50,
        );
    }

    /**
     * @param string $current
     * @return array
     */
    public function getLanguagePack($current = '')
    {
        $packSelected = Language::distinctValues('language_pack');
        $packSelected = is_array($packSelected) ? $packSelected : array();
        $packList = FileHelper::getSubdirs(ROOT_PATH . 'languages');
        $languagePack = array();
        foreach ($packList as $pack) {
            $selected = false;
            foreach ($packSelected as $row) {
                if (in_array($pack, (array) $row)) {
                    $selected = true;
                    break;
                }
            }
            $languagePack[] = array(
                'value' => $pack,
                'cur' => $pack === $current,
                'selected' => $selected,
            );
        }
        return $languagePack;
    }

    /**
     * 货币下拉选项（含符号与 ISO 小数位，供表单切换币种时自动带出）。
     *
     * @return array
     */
    public function buildCurrencyOptions()
    {
        $options = array();
        foreach (CurrencyCatalog::all() as $code => $row) {
            $options[] = array(
                'value' => $code,
                'name' => $code . ' ' . $row['name'],
                'symbol' => $row['symbol'],
                'decimals' => $row['decimals'],
            );
        }
        return $options;
    }

    /**
     * 基准货币上下文（= 默认语言货币，来自 config.currency_code）。
     *
     * @return array
     */
    public function buildCurrencyBase()
    {
        $code = $this->baseCode();
        return array(
            'code' => $code,
            'name' => CurrencyCatalog::name($code),
            'symbol' => CurrencyCatalog::symbol($code),
            'decimals' => $this->baseDecimals(),
        );
    }

    /**
     * 基准币代码（config.currency_code 归一）。
     *
     * @return string
     */
    private function baseCode()
    {
        $code = strtoupper(trim((string) Config::get('site.currency_base_code', '')));
        if ($code === '' || !CurrencyCatalog::has($code)) {
            $code = strtoupper(trim((string) Config::get('site.currency_code', '')));
        }
        if ($code === '' || !CurrencyCatalog::has($code)) {
            $code = 'CNY';
        }
        return $code;
    }

    /**
     * 基准币展示小数位。
     *
     * @return int
     */
    private function baseDecimals()
    {
        $code = $this->baseCode();
        $decimals = (int) Config::get('site.currency_base_decimal', CurrencyCatalog::defaultDecimals($code));
        return CurrencyCatalog::isValidDecimals($code, $decimals) ? $decimals : CurrencyCatalog::defaultDecimals($code);
    }

    /**
     * 该语言行是否启用了独立货币（后台表单显隐用）。
     *
     * 判定与前台装配同源：{@see CurrencyCatalog::isIndependent()}。
     *
     * @param string $code 语言行 currency_code
     * @return bool
     */
    public function isIndependentCurrency($code)
    {
        return CurrencyCatalog::isIndependent($code, $this->baseCode());
    }

    /**
     * 补齐语言行的「独立货币」表单状态：未启用时把币种归一为空，
     * 避免下拉框回显为基准币而实际又处于未启用状态。
     *
     * @param array $language language 表行（含视图需要的 language_id / site_logo）
     * @return array
     */
    public function decorateCurrencyState(array $language)
    {
        $language['currency_enabled'] = $this->isIndependentCurrency(
            isset($language['currency_code']) ? $language['currency_code'] : ''
        );
        if (!$language['currency_enabled']) {
            $language['currency_code'] = '';
        }

        // decimal(18,8) 取回为 "1.00000000"，归一后表单回显更直观（1 / 0.14）
        if (isset($language['currency_rate'])) {
            $language['currency_rate'] = (float) $language['currency_rate'];
        }
        if (isset($language['currency_decimal'])) {
            $language['currency_decimal'] = (int) $language['currency_decimal'];
        }

        return $language;
    }

    /**
     * 归一语言货币字段：未启用独立货币 → 跟随基准币（汇率 1、小数位同基准币）。
     *
     * 「启用独立货币」= currency_code 非空且不等于基准币；此时才校验并保留
     * 汇率与价格小数位数，否则一律复位，避免隐藏字段的旧值残留在库里。
     *
     * @param array $data 经校验的表单字段
     * @param array|null $existing 编辑时的现有行（表单未提交该字段时沿用）
     * @return array currency_code / currency_rate / currency_decimal
     * @throws DomainException
     */
    private function resolveCurrencyFields(array $data, $existing = null)
    {
        $baseCode = $this->baseCode();

        if (array_key_exists('currency_code', $data)) {
            $code = strtoupper(trim((string) $data['currency_code']));
        } elseif (is_array($existing) && isset($existing['currency_code'])) {
            $code = strtoupper(trim((string) $existing['currency_code']));
        } else {
            $code = '';
        }
        if ($code !== '' && !CurrencyCatalog::has($code)) {
            throw new DomainException(lang('language_currency_invalid'));
        }

        if (array_key_exists('currency_rate', $data)) {
            $rate = (float) $data['currency_rate'];
        } elseif (is_array($existing) && isset($existing['currency_rate'])) {
            $rate = (float) $existing['currency_rate'];
        } else {
            $rate = 1;
        }

        if (array_key_exists('currency_decimal', $data)) {
            $decimals = (int) $data['currency_decimal'];
        } elseif (is_array($existing) && isset($existing['currency_decimal'])) {
            $decimals = (int) $existing['currency_decimal'];
        } else {
            $decimals = $this->baseDecimals();
        }

        if (!CurrencyCatalog::isIndependent($code, $baseCode)) {
            return array(
                'currency_code' => '',
                'currency_rate' => 1,
                'currency_decimal' => $this->baseDecimals(),
            );
        }

        if ($rate <= 0) {
            throw new DomainException(lang('language_currency_rate_invalid'));
        }
        if (!CurrencyCatalog::isValidDecimals($code, $decimals)) {
            throw new DomainException(lang('language_currency_decimal_invalid'));
        }

        return array(
            'currency_code' => $code,
            'currency_rate' => $rate,
            'currency_decimal' => $decimals,
        );
    }

    /**
     * 新增语言：使用经请求层校验后的字段写入。
     *
     * @param array $data 来自 LanguageFormRequest::validated()
     * @param array $files site_logo 文件数组，可为空
     * @return int 新增记录主键
     */
    public function insert(array $data, array $files)
    {
        if (!Check::languagePack($data['language_pack'])) {
            throw new DomainException(lang('illegal'));
        }
        if (DB::table('language')->where('language_pack', $data['language_pack'])->find()) {
            throw new DomainException(lang('language_language_pack_exists'));
        }

        $insert = array(
            'name' => $data['name'],
            'language_pack' => $data['language_pack'],
            'site_name' => $data['site_name'],
            'site_title' => $data['site_title'],
            'site_keywords' => isset($data['site_keywords']) ? (string) $data['site_keywords'] : '',
            'site_description' => isset($data['site_description']) ? (string) $data['site_description'] : '',
            'site_logo' => '',
            'address' => isset($data['address']) ? (string) $data['address'] : '',
            'tel' => isset($data['tel']) ? (string) $data['tel'] : '',
            'fax' => isset($data['fax']) ? (string) $data['fax'] : '',
            'email' => isset($data['email']) ? (string) $data['email'] : '',
            'sort' => isset($data['sort']) ? (int) $data['sort'] : 50,
        );
        $insert = array_merge($insert, $this->resolveCurrencyFields($data));
        if (!empty($files['name'])) {
            $insert['site_logo'] = attachment()->storeToDirectory(UploadedFile::fromGlobals('site_logo'), $this->themeLogoDisk, '', 'logo_' . $data['language_pack'], 'main');
        }

        $model = Language::create($insert);
        $newId = (int) $model->getKey();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $data['name']);

        return $newId;
    }

    /**
     * 更新语言：使用经请求层校验后的字段写回。
     *
     * @param array $data 来自 LanguageFormRequest::validated()
     * @param array $files site_logo 文件数组，可为空
     * @return string 跳转地址
     */
    public function update(array $data, array $files)
    {
        $languageId = isset($data['language_id']) ? (int) $data['language_id'] : 0;
        $languageModel = Language::find($languageId);
        $language = $languageModel ? $languageModel->getAttributes() : null;
        if (!$language) {
            throw new DomainException(lang('illegal'), route('admin.language'));
        }
        if (!Check::languagePack($data['language_pack'])) {
            throw new DomainException(lang('illegal'));
        }
        if (
            DB::table('language')->where('language_pack', $data['language_pack'])->find()
            && $data['language_pack'] != $language['language_pack']
        ) {
            throw new DomainException(lang('language_language_pack_exists'));
        }

        $update = array(
            'name' => $data['name'],
            'language_pack' => $data['language_pack'],
            'site_name' => $data['site_name'],
            'site_title' => $data['site_title'],
            'site_keywords' => isset($data['site_keywords']) ? (string) $data['site_keywords'] : '',
            'site_description' => isset($data['site_description']) ? (string) $data['site_description'] : '',
            'address' => isset($data['address']) ? (string) $data['address'] : '',
            'tel' => isset($data['tel']) ? (string) $data['tel'] : '',
            'fax' => isset($data['fax']) ? (string) $data['fax'] : '',
            'email' => isset($data['email']) ? (string) $data['email'] : '',
            'sort' => isset($data['sort']) ? (int) $data['sort'] : 50,
        );
        $update = array_merge($update, $this->resolveCurrencyFields($data, $language));
        if (!empty($files['name'])) {
            $update['site_logo'] = attachment()->storeToDirectory(UploadedFile::fromGlobals('site_logo'), $this->themeLogoDisk, '', 'logo_' . $data['language_pack'], 'main');
        }

        $languageModel->fill($update, 'update')->save();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $data['name']);

        if (isset($data['mode']) && $data['mode'] === 'system') {
            return route('admin.language.system', array('language_pack' => rawurlencode($data['language_pack'])));
        }
        return '';
    }

    /**
     * 单条删除：按 confirm 分支执行二次确认或实际删除。
     *
     * @param string $languageId
     * @param array $data 来自 Request::post()
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException 记录不存在时抛出
     */
    public function delete($languageId, array $data)
    {
        $name = Language::whereKey((int) $languageId)->value('name');
        if ($name === null || $name === false || $name === '') {
            throw new DomainException(lang('illegal'), route('admin.language'));
        }
        if (isset($data['confirm'])) {
            $siteLogo = Language::whereKey((int) $languageId)->value('site_logo');
            attachment()->delete($siteLogo);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $name);
            Language::destroy((int) $languageId);
            return array(
                'message' => lang('language_delete') . lang('success'),
                'back_url' => route('admin.language'),
            );
        }
        $msg = preg_replace('/d%/Ums', $name, lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.language'),
            'timeout' => '30',
            'confirm_url' => route('admin.language.destroy', array('id' => $languageId)),
        );
    }
}
