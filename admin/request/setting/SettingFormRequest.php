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

namespace Dou\Admin\Request\Setting;

use Dou\Admin\Model\Setting\Setting;
use Dou\Core\Domain\Currency\CurrencyCatalog;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 设置表单（update 场景）。
 *
 * 字段白名单由 {@see Setting::fetchAllConfigFieldRules()} 与
 * {@see Setting::fetchParameterPrefixedRules()} 动态生成；
 * language 在非 developer Tab 下必填，规则与 Setting::persistFromValidated 一致。
 */
class SettingFormRequest extends FormRequest
{
    /**
     * @return Setting
     */
    protected function settingModel()
    {
        return new Setting();
    }

    /**
     * 校验失败时附带设置返回链接（与 `message->respond()` 第二参 url 一致）。
     *
     * @return array
     */
    public function validated()
    {
        try {
            $data = parent::validated();
        } catch (DomainException $e) {
            if ($e->getBackUrl() !== '') {
                throw $e;
            }

            throw new DomainException(
                $e->getMessage(),
                route('admin.setting'),
                $e->getTimer(),
                $e->getConfirmUrl(),
                $e->hasErrors() ? $e->getErrors() : array()
            );
        }

        // 基准币 = 默认语言货币：小数位不能超过该币种 ISO 小数位（如 JPY 只能 0 位）
        if (isset($data['currency_code']) && $data['currency_code'] !== '') {
            $code = strtoupper(trim((string) $data['currency_code']));
            $decimals = isset($data['currency_decimal']) ? $data['currency_decimal'] : '';
            if (!CurrencyCatalog::has($code) || !CurrencyCatalog::isValidDecimals($code, $decimals)) {
                throw new DomainException(lang('config_currency_invalid'), route('admin.setting'));
            }
        }

        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        $model = $this->settingModel();
        $rules = array_merge(
            $model->fetchAllConfigFieldRules(),
            $model->fetchParameterPrefixedRules()
        );

        $tabRoute = '';
        if (request() !== null) {
            $tabRoute = trim((string) request()->input('tab', ''));
        }

        if ($tabRoute === 'developer') {
            $rules['language'] = '';
        } else {
            $rules['language'] = 'required|regex:/^[a-z_]+$/';
        }

        // 仅当站点存在对应 config 行时校验，避免未升级站点保存设置被必填卡住
        $configNames = array_keys($model->fetchAllConfigFieldRules());
        if (in_array('currency_code', $configNames, true)) {
            $rules['currency_code'] = 'required|regex:/^[A-Z]{3}$/';
        }
        if (in_array('currency_decimal', $configNames, true)) {
            $rules['currency_decimal'] = 'required|integer';
        }

        return $rules;
    }
}
