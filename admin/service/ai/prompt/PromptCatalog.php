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

namespace Dou\Admin\Service\Ai\Prompt;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 读取 Config::get('ai.prompts') 中按 task_type / placement 两维区分的内置提示词。
 *
 * - system 按 task_type（任务形态）取，空值兜底按 assist；
 * - instruction 按 placement（应用形态）取，替换 {key} 占位符；
 * - labels 全局共享；banner_styles / banner_material 为 banner 图像专用短语。
 */
class PromptCatalog extends BaseService
{
    /** @var array|null */
    private $data;

    /**
     * 任务形态对应的 system 提示词；空值兜底按 assist。
     *
     * @param string $taskType
     * @return string
     */
    public function system($taskType)
    {
        $taskType = trim((string) $taskType);
        if ($taskType === '') {
            $taskType = 'assist';
        }

        return $this->sectionValue('system', $taskType);
    }

    /**
     * 应用形态对应的 instruction，替换 {key} 占位符。
     *
     * @param string $placement
     * @param array $vars
     * @return string
     */
    public function instruction($placement, array $vars = array())
    {
        $text = $this->sectionValue('instruction', $placement);
        foreach ($vars as $key => $value) {
            $text = str_replace('{' . $key . '}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * banner 弹窗风格预设短语；未登记的 key 返回空串（弹窗值异常时按不指定处理）。
     *
     * @param string $key
     * @return string
     */
    public function bannerStyle($key)
    {
        $this->load();
        $key = trim((string) $key);
        if ($key === ''
            || !isset($this->data['banner_styles'])
            || !is_array($this->data['banner_styles'])
            || !isset($this->data['banner_styles'][$key])
        ) {
            return '';
        }

        return trim((string) $this->data['banner_styles'][$key]);
    }

    /**
     * banner 参考图用法指令；正本缺键或为空时抛错。
     *
     * @param string $mode background 整张作底图 / subject 抠主体贴新底图
     * @return string
     */
    public function bannerMaterial($mode)
    {
        $this->load();
        $mode = trim((string) $mode);
        if ($mode === ''
            || !isset($this->data['banner_material'])
            || !is_array($this->data['banner_material'])
            || !isset($this->data['banner_material'][$mode])
            || trim((string) $this->data['banner_material'][$mode]) === ''
        ) {
            throw new DomainException(lang('ai_prompt_catalog_missing'));
        }

        return trim((string) $this->data['banner_material'][$mode]);
    }

    /**
     * 用户消息块标题等共享标签。
     *
     * @param string $key
     * @return string
     */
    public function label($key)
    {
        $this->load();
        $labels = isset($this->data['labels']) && is_array($this->data['labels'])
            ? $this->data['labels']
            : array();
        if (!isset($labels[$key]) || trim((string) $labels[$key]) === '') {
            throw new DomainException(lang('ai_prompt_catalog_missing'));
        }

        return (string) $labels[$key];
    }

    /**
     * @param string $section system / instruction
     * @param string $key task_type 或 placement 键
     * @return string
     */
    private function sectionValue($section, $key)
    {
        $this->load();
        $key = trim((string) $key);
        if ($key === ''
            || !isset($this->data[$section])
            || !is_array($this->data[$section])
            || !isset($this->data[$section][$key])
            || trim((string) $this->data[$section][$key]) === ''
        ) {
            throw new DomainException(lang('ai_prompt_catalog_missing'));
        }

        return (string) $this->data[$section][$key];
    }

    /**
     * @return void
     */
    private function load()
    {
        if (is_array($this->data)) {
            return;
        }

        $loaded = Config::get('ai.prompts');
        if (!is_array($loaded) || !$loaded) {
            throw new DomainException(lang('ai_prompt_catalog_missing'));
        }

        $this->data = $loaded;
    }
}
