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
use Dou\Core\Filesystem\Storage;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台多语言字段 AJAX 业务服务
 */
class LanguageValueService extends BaseService
{
    /** @var MarkdownRenderer */
    private $markdown;

    /**
     * @param MarkdownRenderer $markdown
         */
    public function __construct(
        MarkdownRenderer $markdown
    ) {
        $this->markdown = $markdown;
    }

    /**
     * 存储一项多语言字段。
     *
     * @param array $data 来自 LanguageValueFormRequest::validated()
     * @return array { action: 'add'|'edit', id: string }
     * @throws DomainException 当上传或表单字段不合法时抛出
     */
    public function saveValue(array $data)
    {
        $languagePack = Check::languagePack($data['language_pack']) ? $data['language_pack'] : '';
        $module = Check::basicString($data['module']) ? $data['module'] : '';
        $itemId = Check::number($data['item_id']) ? $data['item_id'] : '';
        $field = Check::basicString($data['field']) ? $data['field'] : '';
        $type = Check::letter($data['type']) ? $data['type'] : '';
        if ($languagePack === '' || $module === '' || $itemId === '' || $field === '' || $type === '') {
            throw new DomainException(lang('illegal'));
        }

        $disk = Storage::build('images/' . $module . '/');
        if ($type === 'file') {
            $value = attachment()->store($module, $itemId, UploadedFile::fromGlobals('value'), 'main', AttachmentUploadOptions::create()->withBusinessField('value')->withUploader('admin', (int) auth('admin')->id()));
        } else {
            $value = isset($data['value']) ? (string) $data['value'] : '';
        }
        if (!$value) {
            throw new DomainException(lang('language_empty'));
        }
        if ($type === 'content' && !empty($data['content_remote_image_local'])) {
            $value = attachment()->storeContentImages($module, $itemId, $value, 'content', '', AttachmentUploadOptions::create()->withUploader('admin', (int) auth('admin')->id()));
        }

        $haveValue = DB::table('language_value')
            ->where('language_pack', $languagePack)
            ->where('module', $module)
            ->where('item_id', $itemId)
            ->where('field', $field)
            ->where('type', $type)
            ->order('id DESC')
            ->value('value');

        $rawInput = isset($data['value']) ? (string) $data['value'] : '';
        if ($haveValue) {
            DB::table('language_value')
                ->where('language_pack', $languagePack)
                ->where('module', $module)
                ->where('item_id', $itemId)
                ->where('field', $field)
                ->where('type', $type)
                ->data(array('value' => $value))
                ->update();
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $rawInput);
            $action = 'edit';
        } else {
            DB::table('language_value')->insert(array(
                'language_pack' => $languagePack,
                'module' => $module,
                'item_id' => $itemId,
                'field' => $field,
                'value' => $value,
                'type' => $type,
                'created_at' => date('Y-m-d H:i:s'),
            ));
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $rawInput);
            $action = 'add';
        }

        return array(
            'action' => $action,
            'id' => $languagePack . '_' . $field,
        );
    }

    /**
     * 读取一项多语言字段。
     *
     * @param array $data 形如 ['language_pack','module','item_id','field','type']
     * @return array { paid_use: bool, value: string }
     * @throws DomainException 当字段不合法时抛出
     */
    public function buildValueData(array $data)
    {
        $languagePack = Check::languagePack($data['language_pack']) ? $data['language_pack'] : '';
        $module = Check::basicString($data['module']) ? $data['module'] : '';
        $itemId = Check::number($data['item_id']) ? $data['item_id'] : '';
        $field = Check::basicString($data['field']) ? $data['field'] : '';
        $type = Check::letter($data['type']) ? $data['type'] : '';
        if ($languagePack === '' || $module === '' || $itemId === '' || $field === '' || $type === '') {
            throw new DomainException(lang('illegal'));
        }

        $value = DB::table('language_value')
            ->where('language_pack', $languagePack)
            ->where('module', $module)
            ->where('item_id', $itemId)
            ->where('field', $field)
            ->where('type', $type)
            ->order('id DESC')
            ->value('value');
        if ($field === 'file' || $field === 'image' || $field === 'show_img') {
            $value = attachment()->url($value);
        }
        if ($type === 'content') {
            $value = $this->markdown->toHtml($value);
        }

        return array(
            'paid_use' => ($module === 'item') && Config::get('param.item_paid_use'),
            'value' => $value ? $value : '',
        );
    }
}
