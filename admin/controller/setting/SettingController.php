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

namespace Dou\Admin\Controller\Setting;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Setting\SettingFormRequest;
use Dou\Admin\Service\Setting\SettingService;
use Dou\Core\Filesystem\Storage;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Http\UploadedFile;
use Dou\Vendor\Mail\Mail;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 设置
 */
class SettingController extends BaseController
{
    /** @var SettingService */
    private $settingService;

    /** @var \Dou\Core\Filesystem\Disk */
    private $themeDisk;

    /** @var \Dou\Core\Filesystem\Disk */
    private $miniLogoDisk;

    /** @var \Dou\Core\Filesystem\Disk */
    private $siteRootDisk;

    /** @var \Dou\Core\Filesystem\Disk */
    private $uploadDisk;

    /**
     * @param SettingService $settingService
     */
    public function __construct(SettingService $settingService)
    {
        $this->settingService = $settingService;
        $theme = Config::get('site.site_theme', '');
        $this->themeDisk = Storage::build($theme ? 'theme/' . $theme . '/images/' : '');
        $miniprogramCode = Config::get('site.miniprogram_code', '');
        $this->miniLogoDisk = Storage::build($miniprogramCode ? MINIPROGRAM_DIR . '/' . $miniprogramCode . '/images/' : '');
        $this->siteRootDisk = Storage::build('');
        $this->uploadDisk = Storage::build('images/upload/');
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'setting',
        );
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $douParam = $request->input('dou');
        $this->settingService->applyDeveloperToggle($douParam);
        $this->settingService->syncDomainFromRoot($request->input('domain'));

        if (SYSTEM_SIGN === 'api') {
            lang_set('site_name', '小程序名称');
            lang_set('site_logo', '小程序LOGO');
            lang_set('site_logo_other', '小程序LOGO.另一个');
        }

        $data = $this->settingService->buildSettingIndexData($douParam);

        $smarty_light = $request->rec('light');

        $smsTab = (bool) Config::get('features.sms', false);

        if ($data['is_developer_tab']) {
            return $this->view('setting_developer.htm', [
                'ur_here' => lang('setting_developer'),
                'page_actions' => array(
                    array('href' => route('admin.setting'), 'text' => lang('setting'), 'style' => ''),
                ),
                'page_cue' => '<em>' . lang('setting_developer_cue') . '</em>',
                'rec' => 'default',
                'light' => $smarty_light,
                'config_list' => $data['config_list'],
                'parameter_system_list' => $data['parameter_system_list'],
                'parameter_customer_list' => $data['parameter_customer_list'],
                'lang_list' => $data['lang_list'],
                'sms_tab' => $smsTab,
                'cfg_pure_mode' => $data['cfg_pure_mode'],
                'column_module_list' => $data['column_module_list'],
            ]);
        } else {
            $pageActions = array();
            if (Config::get('site.developer', false)) {
                $pageActions[] = array('href' => route('admin.setting', array(), array('query' => array('dou' => ''))), 'text' => lang('setting_developer'), 'style' => '');
            }
            return $this->view('setting.htm', [
                'ur_here' => lang('setting'),
                'page_actions' => $pageActions,
                'rec' => 'default',
                'light' => $smarty_light,
                'config_list' => $data['config_list'],
                'parameter_system_list' => $data['parameter_system_list'],
                'parameter_customer_list' => $data['parameter_customer_list'],
                'lang_list' => $data['lang_list'],
                'sms_tab' => $smsTab,
            ]);
        }
    }

    /**
     * 保存设置（字段白名单见 SettingFormRequest）。
     *
     * @param SettingFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(SettingFormRequest $formRequest, Request $request)
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
        if ($method !== 'POST') {
            return redirect(route('admin.setting'));
        }

        $data = $formRequest->validated();

        if (!empty($_FILES['site_logo']['name'])) {
            $data['site_logo'] = attachment()->storeToDirectory(UploadedFile::fromGlobals('site_logo'), $this->themeDisk, '', 'logo', 'main');
        }
        if (!empty($_FILES['site_logo_other']['name'])) {
            $data['site_logo_other'] = attachment()->storeToDirectory(UploadedFile::fromGlobals('site_logo_other'), $this->themeDisk, '', 'logo_other', 'main');
        }
        if (!empty($_FILES['site_logo_miniprogram']['name'])) {
            $data['site_logo_miniprogram'] = attachment()->storeToDirectory(UploadedFile::fromGlobals('site_logo_miniprogram'), $this->miniLogoDisk, '', 'logo', 'main');
        }
        if (!empty($_FILES['site_favicon']['name'])) {
            $data['site_favicon'] = attachment()->storeToDirectory(UploadedFile::fromGlobals('site_favicon'), $this->siteRootDisk, '', 'favicon', 'main');
        }
        if (!empty($_FILES['weixin_img']['name'])) {
            $data['weixin_img'] = attachment()->storeToDirectory(UploadedFile::fromGlobals('weixin_img'), $this->uploadDisk, '', 'weixin', 'main');
        }

        $this->settingService->persistSettingConfig($data);

        return redirect(route('admin.setting'))->with('success', lang('edit_succes'));
    }

    /**
     * 邮件服务器配置测试发送。
     *
     * 使用当前保存的邮件服务器配置向指定收件邮箱发送一封测试邮件，
     * 用于验证 SMTP / 内置 mail 服务是否可用。
     *
     * @param Request $request
     * @return \Dou\Core\Web\Http\JsonResponse|Response
     */
    public function testMail(Request $request)
    {
        $toEmail = trim((string) $request->input('mail_test_email', ''));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->json(array(
                'success' => false,
                'message' => lang('mail_test_email_invalid'),
                'detail' => '',
            ));
        }

        $subject = lang('mail_test_subject');
        $body = lang('mail_test_body') . '<br/>' . date('Y-m-d H:i:s');
        $altBody = lang('mail_altbody');

        // 收集 SMTP 调试链路，失败时展示给用户定位问题
        $debugTraces = array();
        $result = Mail::sendMailResult($toEmail, $subject, $body, $altBody, $debugTraces);

        if (!empty($result['success'])) {
            return $this->json(array(
                'success' => true,
                'message' => lang('mail_test_success'),
                'detail' => '',
            ));
        }

        $error = (isset($result['error']) && $result['error'] !== '') ? $result['error'] : '';
        $message = lang('mail_send_fail') . ($error !== '' ? '：' . $error : '');
        $detail = '';
        if (!empty($debugTraces)) {
            $detail = implode("\n", $debugTraces);
        } elseif ($error !== '') {
            $detail = $error;
        }

        return $this->json(array(
            'success' => false,
            'message' => $message,
            'detail' => $detail,
        ));
    }
}
