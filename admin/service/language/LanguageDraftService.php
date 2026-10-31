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

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\Attachment\LanguagePath;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Service\Language\LanguageImageCleaner;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 原生字段多语言本地草稿：加载现有翻译、随主表单统一落库。
 *
 * 抽屉只改 hidden 草稿；create/update 事务内由业务服务调用 begin/commit，
 * 事务提交后再调用 purge 回收被替换/清空的图片。
 */
class LanguageDraftService extends BaseService
{
    /** @var MarkdownRenderer */
    private $markdown;

    /** @var array 请求内状态缓存：module|itemId => formState */
    private static $stateCache = array();

    /**
     * @param MarkdownRenderer $markdown
     */
    public function __construct(MarkdownRenderer $markdown)
    {
        $this->markdown = $markdown;
    }

    /**
     * 构造某个模块表单所需的多语言按钮与草稿初值。
     *
     * @param string $module
     * @param int|string $itemId
     * @return array {btn_lang: array, lang_drafts: array}
     */
    public function buildFormState($module, $itemId = 0)
    {
        $module = (string) $module;
        $itemId = (int) $itemId;
        $cacheKey = $module . '|' . $itemId;
        if (isset(self::$stateCache[$cacheKey])) {
            return self::$stateCache[$cacheKey];
        }

        $fields = LanguageDraftFields::fields($module);
        if (empty($fields) || !Config::get('features.language', false)) {
            $emptyButtons = array();
            foreach (array_keys($fields) as $emptyField) {
                $emptyButtons[$emptyField] = '';
            }

            return self::$stateCache[$cacheKey] = array('btn_lang' => $emptyButtons, 'lang_drafts' => array());
        }

        $this->cleanupDrafts($module);

        $rows = $this->rows($module, $itemId, array_keys($fields));
        $hasValue = array();
        foreach (array_keys($fields) as $hasField) {
            $hasValue[$hasField] = array();
        }
        $previews = array();
        $groups = array();
        foreach ($rows as $row) {
            $pack = (string) $row['language_pack'];
            if ($pack === $this->defaultLanguage() || !Check::languagePack($pack)) {
                continue;
            }
            $field = (string) $row['field'];
            $kind = isset($fields[$field]) ? $fields[$field] : '';
            if ($kind === '') {
                continue;
            }
            $value = (string) $row['value'];
            if ($kind === LanguageDraftFields::KIND_EDITOR) {
                $value = $this->markdown->toHtml($value);
            }
            if (!isset($groups[$pack])) {
                $groups[$pack] = array('language_pack' => $pack, 'fields' => array());
            }
            $groups[$pack]['fields'][] = array('field' => $field, 'value' => $value);
            $hasValue[$field][$pack] = true;
            if ($kind === LanguageDraftFields::KIND_IMAGE) {
                $previews[$field][$pack] = attachment()->url((string) $row['value']);
            }
        }

        $targets = $this->buildTargets($fields);
        $uploadUrl = '';
        $releaseUrl = '';
        try {
            $uploadUrl = (string) route('admin.language.draft.upload');
            $releaseUrl = (string) route('admin.language.draft.release');
        } catch (\Exception $e) {
            // CLI 烟测等未加载 admin 路由的场景：按钮仍可渲染，图片草稿端点缺省
        }
        $buttons = language()->buildLangButtons($module, $itemId, array_keys($fields), array(
            'draft' => true,
            'draft_create' => true,
            'default_pack' => $this->defaultLanguage(),
            'exclude_pack' => $this->defaultLanguage(),
            'targets' => $targets,
            'labels' => array('image' => lang('thumb')),
            'has_value' => $hasValue,
            'previews' => $previews,
            // 未添加译文时弹框保持为空，不再代入主语言内容
            'blank_when_missing' => true,
            'upload_url' => $uploadUrl,
            'release_url' => $releaseUrl,
        ));

        return self::$stateCache[$cacheKey] = array(
            'btn_lang' => is_array($buttons) ? $buttons : array(),
            'lang_drafts' => array_values($groups),
        );
    }

    /**
     * 清空请求内状态缓存（CLI 烟测在一次进程内多次构造时使用）。
     *
     * @return void
     */
    public static function clearStateCache()
    {
        self::$stateCache = array();
    }

    /**
     * 编辑页草稿初值（兼容旧调用）。
     *
     * @param string $module
     * @param int $itemId
     * @param array $fields 已废弃：字段以 registry 为准
     * @return array
     */
    public function load($module, $itemId, array $fields = array())
    {
        $state = $this->buildFormState($module, $itemId);

        return $state['lang_drafts'];
    }

    /**
     * 从 POST 数据提取可保存草稿。
     *
     * 兼容 product 经 FormRequest 白名单透传的 $data；其它模块未单独加白名单时
     * 直接从请求读取（内容仍按 registry + 图片 draft 归属严格校验）。
     *
     * @param array $data
     * @param string $module
     * @return array pack => field => value
     */
    public function drafts(array $data, $module)
    {
        if (!Config::get('features.language', false)) {
            return array();
        }

        $raw = isset($data['language_draft']) && is_array($data['language_draft'])
            ? $data['language_draft']
            : $this->requestDrafts();
        $fields = LanguageDraftFields::fields($module);
        if (empty($raw) || empty($fields)) {
            return array();
        }

        $default = $this->defaultLanguage();
        $drafts = array();
        foreach ($raw as $pack => $values) {
            $pack = (string) $pack;
            if ($pack === $default || !Check::languagePack($pack) || !is_array($values)) {
                continue;
            }
            foreach ($values as $field => $value) {
                $field = (string) $field;
                if (!isset($fields[$field])) {
                    continue;
                }
                $drafts[$pack][$field] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $drafts;
    }

    /**
     * 生成保存计划：校验图片草稿归属，收集待认领号与待回收旧号。
     *
     * @param string $module
     * @param array $data
     * @param int|string $itemId
     * @param int $adminId
     * @return array|null
     */
    public function begin($module, array $data, $itemId, $adminId = 0)
    {
        $module = (string) $module;
        $itemId = (int) $itemId;
        $adminId = (int) $adminId > 0 ? (int) $adminId : $this->currentAdminId();
        $drafts = $this->drafts($data, $module);
        if (empty($drafts)) {
            return null;
        }

        $plan = array(
            'module' => $module,
            'admin_id' => $adminId,
            'drafts' => array(),
            'reservations' => array(),
            'purge' => array(),
        );

        foreach ($drafts as $pack => $values) {
            foreach ($values as $field => $value) {
                $kind = LanguageDraftFields::kind($module, $field);
                if ($kind === '') {
                    continue;
                }
                $value = (string) $value;
                $existing = $this->existingRow($module, $itemId, $pack, $field);
                $old = $existing ? (string) $existing['value'] : '';

                if ($kind === LanguageDraftFields::KIND_IMAGE) {
                    if ($value === $old) {
                        continue;
                    }
                    if ($value === '') {
                        $plan['drafts'][$pack][$field] = '';
                        if ($old !== '') {
                            $plan['purge'][] = $old;
                        }
                        continue;
                    }
                    if (!$this->isDraftNumber($module, $value, $adminId)) {
                        throw new DomainException(lang('illegal'));
                    }
                    $plan['drafts'][$pack][$field] = $value;
                    $plan['reservations'][] = $value;
                    if ($old !== '' && $old !== $value) {
                        $plan['purge'][] = $old;
                    }
                    continue;
                }

                $plan['drafts'][$pack][$field] = $value;
            }
        }

        if (empty($plan['drafts']) && empty($plan['reservations']) && empty($plan['purge'])) {
            return null;
        }

        $plan['reservations'] = array_values(array_unique($plan['reservations']));
        $plan['purge'] = array_values(array_unique($plan['purge']));

        return $plan;
    }

    /**
     * 事务内提交草稿：写 language_value 并认领图片草稿。
     *
     * @param array|null $plan
     * @param int|string $itemId
     * @param int $adminId
     * @return void
     */
    public function commit($plan, $itemId, $adminId = 0)
    {
        if (empty($plan) || empty($plan['drafts'])) {
            return;
        }

        $module = (string) $plan['module'];
        $itemId = (int) $itemId;
        $adminId = (int) $adminId > 0 ? (int) $adminId : (int) $plan['admin_id'];

        foreach ($plan['drafts'] as $pack => $values) {
            foreach ($values as $field => $value) {
                $kind = LanguageDraftFields::kind($module, $field);
                if ($kind === '') {
                    continue;
                }
                $value = (string) $value;
                if ($kind === LanguageDraftFields::KIND_EDITOR) {
                    $value = $this->markdown->toStore($value);
                }
                if ($value === '') {
                    $this->deleteRow($module, $itemId, $pack, $field);
                    continue;
                }
                $this->writeRow($module, $itemId, $pack, $field, $value, $this->typeOf($kind));
            }
        }

        foreach ((array) $plan['reservations'] as $number) {
            if (attachment()->adopt($module, $number, $itemId, 'admin', $adminId) < 1) {
                throw new DomainException(lang('file_claim_failed', '附件认领失败'));
            }
        }
    }

    /**
     * 事务失败时释放本次新上传的草稿。
     *
     * @param array|null $plan
     * @return void
     */
    public function abort($plan)
    {
        if (empty($plan) || empty($plan['reservations'])) {
            return;
        }
        $module = (string) $plan['module'];
        $adminId = (int) $plan['admin_id'];
        foreach ((array) $plan['reservations'] as $number) {
            try {
                attachment()->release($module, $number, 'admin', $adminId);
            } catch (\Exception $e) {
                // 过期草稿 GC 仍会兜底
            }
        }
    }

    /**
     * 事务提交后回收被替换/清空的旧图片。
     *
     * @param array|null $plan
     * @param int|string $itemId
     * @return void
     */
    public function purge($plan, $itemId)
    {
        if (empty($plan) || empty($plan['purge'])) {
            return;
        }
        LanguageImageCleaner::purge((string) $plan['module'], (int) $itemId, (array) $plan['purge']);
    }

    /**
     * 直接写入/清空单条翻译（不经过 language_draft 表单结构）。
     *
     * 供子表记录（如 attribute_value）随主记录事务批量落库时复用幂等写入语义：
     * 空字符串删除该翻译行，非空则查最新行、去重后更新或插入。
     *
     * @param string $module
     * @param int|string $itemId
     * @param string $pack
     * @param string $field
     * @param string $value
     * @param string $type
     * @return void
     */
    public function saveTranslation($module, $itemId, $pack, $field, $value, $type = 'text')
    {
        $module = (string) $module;
        $field = (string) $field;
        $pack = (string) $pack;
        $itemId = (int) $itemId;

        if (!Config::get('features.language', false) || $module === '' || $field === '' || $itemId <= 0) {
            return;
        }
        if ($pack === $this->defaultLanguage() || !Check::languagePack($pack)) {
            return;
        }

        if ((string) $value === '') {
            $this->deleteRow($module, $itemId, $pack, $field);
            return;
        }

        $this->writeRow($module, $itemId, $pack, $field, (string) $value, (string) $type);
    }

    /**
     * 抽屉内图片草稿上传。
     *
     * @param array $data module/slug/file
     * @return array
     */
    public function upload(array $data)
    {
        $module = isset($data['module']) && Check::basicString($data['module']) ? (string) $data['module'] : '';
        $field = isset($data['slug']) && Check::basicString($data['slug']) ? (string) $data['slug'] : '';
        if ($module === '' || $field === '' || !LanguageDraftFields::isImage($module, $field)) {
            throw new DomainException(lang('illegal'));
        }

        $adminId = $this->currentAdminId();
        if ($adminId <= 0) {
            throw new DomainException(lang('illegal'));
        }

        $file = UploadedFile::fromGlobals('file');
        if (!($file instanceof UploadedFile) || !$file->isValid()) {
            throw new DomainException(lang('file_upload_error', '文件上传失败'));
        }

        attachment()->cleanupUserDrafts('admin', $adminId, $module);
        $draftToken = attachment()->newDraftToken('admin', $adminId);
        $languagePack = LanguagePath::normalize(isset($data['language_pack']) ? $data['language_pack'] : '');
        $options = AttachmentUploadOptions::create()->withUploader('admin', $adminId);
        // 缩略图仅用于产品主图（前台商品列表按 image 取 _thumb）；
        // 文章、幻灯、链接等其它模块的语言图片不生成缩略图。
        if ($module === 'product' && $field === 'image') {
            $options->withThumbnail(Config::get('site.thumb_width', 0), Config::get('site.thumb_height', 0));
        }
        $options->withLanguagePack($languagePack);
        $number = attachment()->storeDraft($module, $file, 'admin', $adminId, $draftToken, 'language:image', $options);
        if ($number === '') {
            throw new DomainException(lang('file_upload_error', '文件上传失败'));
        }

        return array(
            'ok' => true,
            'number' => $number,
            'url' => attachment()->url($number),
        );
    }

    /**
     * 释放抽屉内新上传、但用户已清除/替换的图片草稿。
     *
     * @param array $data module/number
     * @return array
     */
    public function release(array $data)
    {
        $module = isset($data['module']) && Check::basicString($data['module']) ? (string) $data['module'] : '';
        $number = isset($data['number']) && Check::fileNumber($data['number']) ? (string) $data['number'] : '';
        if ($module === '' || $number === '' || !LanguageDraftFields::exists($module)) {
            throw new DomainException(lang('illegal'));
        }

        $adminId = $this->currentAdminId();
        if ($adminId <= 0) {
            throw new DomainException(lang('illegal'));
        }

        $row = DB::table('file')
            ->where('number', $number)
            ->where('module', $module)
            ->where('uploader_type', 'admin')
            ->where('uploader_id', $adminId)
            ->where('status', 'draft')
            ->where('type', 'language:image')
            ->find();
        if ($row) {
            attachment()->release($module, $number, 'admin', $adminId);
        }

        return array('ok' => true);
    }

    /**
     * @param array $fields field => kind
     * @return array
     */
    private function buildTargets(array $fields)
    {
        $targets = array();
        foreach ($fields as $field => $kind) {
            $target = array(
                'kind' => $kind,
                'target' => 'language_draft[%s][' . $field . ']',
                'source' => $field,
            );
            if ($kind === LanguageDraftFields::KIND_IMAGE) {
                $target['accept'] = 'image/*';
            } else {
                $target['ai_field'] = $field;
                $target['ai_type'] = $kind === LanguageDraftFields::KIND_EDITOR ? 'content' : $kind;
            }
            $targets[$field] = $target;
        }

        return $targets;
    }

    /**
     * @param string $module
     * @param int $itemId
     * @param array $fields
     * @return array
     */
    private function rows($module, $itemId, array $fields)
    {
        if ($itemId <= 0 || empty($fields)) {
            return array();
        }

        return DB::table('language_value')
            ->where('module', (string) $module)
            ->where('item_id', $itemId)
            ->where('field', 'IN', array_values($fields))
            ->order('id ASC')
            ->select();
    }

    /**
     * @return array
     */
    private function requestDrafts()
    {
        if (!function_exists('request') || !request()) {
            return array();
        }
        $raw = request()->post('language_draft', array());

        return is_array($raw) ? $raw : array();
    }

    /**
     * @param string $module
     * @param int $itemId
     * @param string $pack
     * @param string $field
     * @return array|null
     */
    private function existingRow($module, $itemId, $pack, $field)
    {
        if ($itemId <= 0) {
            return null;
        }

        $row = DB::table('language_value')
            ->where('language_pack', (string) $pack)
            ->where('module', (string) $module)
            ->where('item_id', $itemId)
            ->where('field', (string) $field)
            ->order('id DESC')
            ->find();

        return $row ? $row : null;
    }

    /**
     * @param string $module
     * @param int $itemId
     * @param string $pack
     * @param string $field
     * @param string $value
     * @param string $type
     * @return void
     */
    private function writeRow($module, $itemId, $pack, $field, $value, $type)
    {
        $existing = $this->existingRow($module, $itemId, $pack, $field);
        if ($existing) {
            // 唯一键不含 type 的历史重复行只保留最新一条
            DB::table('language_value')
                ->where('language_pack', (string) $pack)
                ->where('module', (string) $module)
                ->where('item_id', $itemId)
                ->where('field', (string) $field)
                ->where('id', '<>', (int) $existing['id'])
                ->delete();

            DB::table('language_value')->where('id', (int) $existing['id'])->data(array(
                'value' => (string) $value,
                'type' => (string) $type,
            ))->update();

            return;
        }

        DB::table('language_value')->insert(array(
            'language_pack' => (string) $pack,
            'module' => (string) $module,
            'item_id' => $itemId,
            'field' => (string) $field,
            'value' => (string) $value,
            'type' => (string) $type,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * @param string $module
     * @param int $itemId
     * @param string $pack
     * @param string $field
     * @return void
     */
    private function deleteRow($module, $itemId, $pack, $field)
    {
        if ($itemId <= 0) {
            return;
        }
        DB::table('language_value')
            ->where('language_pack', (string) $pack)
            ->where('module', (string) $module)
            ->where('item_id', $itemId)
            ->where('field', (string) $field)
            ->delete();
    }

    /**
     * @param string $module
     * @param string $number
     * @param int $adminId
     * @return bool
     */
    private function isDraftNumber($module, $number, $adminId)
    {
        if ($adminId <= 0 || $number === '') {
            return false;
        }

        return (bool) DB::table('file')
            ->where('number', (string) $number)
            ->where('module', (string) $module)
            ->where('uploader_type', 'admin')
            ->where('uploader_id', (int) $adminId)
            ->where('status', 'draft')
            ->where('type', 'language:image')
            ->find();
    }

    /**
     * @param string $kind
     * @return string
     */
    private function typeOf($kind)
    {
        if ($kind === LanguageDraftFields::KIND_EDITOR) {
            return 'content';
        }
        if ($kind === LanguageDraftFields::KIND_IMAGE) {
            return 'image';
        }

        return $kind === LanguageDraftFields::KIND_TEXTAREA ? 'textarea' : 'text';
    }

    /**
     * 清理当前管理员在该模块下过期的图片草稿。
     *
     * @param string $module
     * @return void
     */
    private function cleanupDrafts($module)
    {
        $adminId = $this->currentAdminId();
        if ($adminId > 0) {
            attachment()->cleanupUserDrafts('admin', $adminId, $module);
        }
    }

    /**
     * @return int
     */
    private function currentAdminId()
    {
        if (!function_exists('auth')) {
            return 0;
        }
        try {
            $admin = auth('admin');

            return $admin ? (int) $admin->id() : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * @return string
     */
    private function defaultLanguage()
    {
        return (string) Config::get('site.language', 'zh_cn');
    }
}
