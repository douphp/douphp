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

namespace Dou\Admin\Controller\Language;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Language\LanguageFormRequest;
use Dou\Admin\Service\Language\LanguageService;
use Dou\Admin\Service\Setting\SettingService;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台语言管理控制器
 */
class LanguageController extends BaseController
{
    /** @var LanguageService */
    private $languageService;

    /** @var SettingService */
    private $settingService;

    /**
     * @param LanguageService $languageService
     * @param SettingService $settingService
     */
    public function __construct(LanguageService $languageService, SettingService $settingService)
    {
        $this->languageService = $languageService;
        $this->settingService = $settingService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'language',
        );
    }

    /**
     * @return Response
     */
    public function index()
    {
        return $this->view('language.htm', [
            'ur_here' => lang('language'),
            'page_actions' => array(
                array('href' => route('admin.language.create'), 'text' => lang('language_create'), 'style' => 'add'),
            ),
            'rec' => 'default',
            'language_list' => $this->languageService->buildLanguageListRows(),
        ]);
    }

    /**
     * @return Response
     */
    public function create()
    {
        return $this->view('language.htm', [
            'ur_here' => lang('language_create'),
            'page_actions' => array(
                array('href' => route('admin.language'), 'text' => lang('language'), 'style' => ''),
            ),
            'rec' => 'create',
            'language_pack' => $this->languageService->getLanguagePack(),
            'language' => $this->languageService->buildLanguageCreateData(),
        ]);
    }

    /**
     * @param LanguageFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(LanguageFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $files = isset($_FILES['site_logo']) ? $_FILES['site_logo'] : array();
        $newId = $this->languageService->insert($data, $files);
        return redirect(route('admin.language.edit', array('id' => $newId)))
            ->with('success', lang('language_create') . lang('success'), route('admin.language'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $languageId = $request->integer('id', 0);
        $language = DB::table('language')->where('id', $languageId)->find();
        if (!$language) {
            throw new DomainException(lang('illegal'), route('admin.language'));
        }
        $language['language_id'] = (int) $language['id'];
        $language['site_logo'] = 'theme/' . Config::get('site.site_theme', '') . '/images/' . $this->languageService->siteLogoForLang($language['language_pack']);

        return $this->view('language.htm', [
            'ur_here' => lang('language_edit'),
            'page_actions' => array(
                array('href' => route('admin.language'), 'text' => lang('language'), 'style' => ''),
            ),
            'rec' => 'edit',
            'language_pack' => $this->languageService->getLanguagePack($language['language_pack']),
            'item_id' => $languageId,
            'language' => $language,
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function system(Request $request)
    {
        $languagePack = $request->languagePack('language_pack');
        $languageId = DB::table('language')->where('language_pack', $languagePack)->value('id');
        $tabList = array('main', 'customer', 'display', 'seo', 'defined', 'mail');
        if (DB::table('config')->where('tab', 'system_param')->where('name', 'core')->value('value')) {
            array_splice($tabList, 1, 0, array('system_param'));
        }
        $cfg = array();
        foreach ($tabList as $tab) {
            $cfg[] = array(
                'name' => $tab,
                'lang' => lang('setting_' . $tab),
                'list' => $this->settingService->buildConfigList($tab),
            );
        }
        $parameterList = DB::table('parameter')->where('group', 'system')->order('`group` DESC, sort ASC, id ASC')->select();
        $language = DB::table('language')->where('id', intval($languageId))->find();
        $language['language_id'] = (int) $language['id'];
        $language['site_logo'] = 'theme/' . Config::get('site.site_theme', '') . '/images/' . $this->languageService->siteLogoForLang($language['language_pack']);

        return $this->view('language.htm', [
            'ur_here' => lang('language_edit'),
            'page_actions' => array(
                array('href' => route('admin.language'), 'text' => lang('language'), 'style' => ''),
            ),
            'rec' => 'system',
            'cfg' => $cfg,
            'parameter_list' => $parameterList,
            'lang_list' => language()->buildLangList($languagePack),
            'language_pack' => $this->languageService->getLanguagePack($language['language_pack']),
            'item_id' => $languageId,
            'language' => $language,
            'sms_tab' => (bool) Config::get('features.sms', false),
        ]);
    }

    /**
     * @param LanguageFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(LanguageFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $files = isset($_FILES['site_logo']) ? $_FILES['site_logo'] : array();
        $systemUrl = $this->languageService->update($data, $files);
        if ($systemUrl !== '') {
            return redirect($systemUrl)->with('success', lang('language_edit') . lang('succes'));
        }
        return redirect(route('admin.language.edit', array('id' => (int) $data['language_id'])))
            ->with('success', lang('language_edit') . lang('succes'), route('admin.language'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $languageId = $request->integer('id', 0);
        if ($languageId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.language'));
        }
        $result = $this->languageService->delete($languageId, (array) $request->post());
        return $this->respondDeleteResult($result);
    }
}
