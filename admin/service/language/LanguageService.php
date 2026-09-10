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
        return array(
            'language_id' => 0,
            'name' => '',
            'language_pack' => '',
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
