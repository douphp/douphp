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
 * Release Date: 2026-09-09
 */

namespace Dou\Admin\Request\Ai;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 字段及图片生成请求校验。
 */
class GenerateFieldFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'app_id' => 'required|integer|min_value:1',
            'field' => 'required|max:100',
            'prompt' => 'max:4000',
            'current_value' => 'max:20000',
            'form_snapshot' => 'array',
            'module' => 'max:80',
            'size' => 'max:30',
            'content_align' => 'in:left,center,right',
            'banner_title' => 'max:200',
            'banner_subtitle' => 'max:300',
            'banner_style' => 'max:100',
            'image_refs' => 'array',
            'banner_material_mode' => 'in:subject,background',
        );
    }

    /**
     * @return array
     */
    public function validated()
    {
        $data = parent::validated();
        $snapshot = isset($data['form_snapshot']) && is_array($data['form_snapshot'])
            ? $data['form_snapshot'] : array();
        $imageRefs = isset($data['image_refs']) && is_array($data['image_refs'])
            ? $data['image_refs'] : array();

        $materialMax = max(1, (int) Config::get('ai.banner.material_max', 4));
        $uploadMaxBytes = max(1, (int) Config::get('ai.banner.upload_max_bytes', 5242880));
        if (count($snapshot) > 100 || count($imageRefs) > $materialMax) {
            throw new DomainException(lang('ai_generate_input_too_large'));
        }
        foreach ($snapshot as $key => $value) {
            if (!is_scalar($value) || strlen((string) $key) > 100 || strlen((string) $value) > 20000) {
                throw new DomainException(lang('ai_generate_input_too_large'));
            }
        }
        foreach ($imageRefs as $value) {
            if (!is_scalar($value)) {
                throw new DomainException(lang('ai_generate_input_too_large'));
            }
            $value = (string) $value;
            if (strpos($value, 'data:image/') === 0) {
                $maxDataUriBytes = (int) ceil($uploadMaxBytes * 4 / 3) + 128;
                if (strlen($value) > $maxDataUriBytes
                    || !preg_match('#^data:image/(?:jpeg|png|gif|webp);base64,#i', $value)
                ) {
                    throw new DomainException(lang('ai_generate_input_too_large'));
                }
            } elseif (strlen($value) > 500) {
                throw new DomainException(lang('ai_generate_input_too_large'));
            }
        }

        $data['form_snapshot'] = $snapshot;
        $data['image_refs'] = array_values($imageRefs);

        return $data;
    }
}
