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

use Dou\Admin\Service\Ai\Context\SiteKnowledgeBuilder;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 按 task_type / placement 两维组装 AI 消息：
 * system 取内置 system[task_type]；user 为材料 → instruction[placement] → 应用 default_prompt（个性化补充）→ 当次输入。
 */
class PromptComposer extends BaseService
{
    /** @var PromptCatalog */
    private $catalog;

    /** @var SiteKnowledgeBuilder */
    private $knowledge;

    /**
     * @param PromptCatalog $catalog
     * @param SiteKnowledgeBuilder $knowledge
     */
    public function __construct(PromptCatalog $catalog, SiteKnowledgeBuilder $knowledge)
    {
        $this->catalog = $catalog;
        $this->knowledge = $knowledge;
    }

    /**
     * @param array $app 应用行
     * @param array $options placeholders / user_prompt / form_snapshot / current_value / source_text
     * @return array
     */
    public function compose(array $app, array $options = array())
    {
        $placement = isset($app['placement']) ? trim((string) $app['placement']) : '';
        $taskType = isset($app['task_type']) ? trim((string) $app['task_type']) : '';
        $placeholders = isset($options['placeholders']) && is_array($options['placeholders'])
            ? $options['placeholders']
            : array();

        $systemParts = array();
        $systemParts[] = $this->catalog->system($taskType);

        $userParts = array();
        $siteText = $this->knowledge->build();
        if ($siteText !== '') {
            $userParts[] = $this->catalog->label('site_knowledge') . "\n" . $siteText;
        }

        if ($placement === 'assist') {
            $snapshot = isset($options['form_snapshot']) && is_array($options['form_snapshot'])
                ? $options['form_snapshot']
                : array();
            $formatted = $this->formatFormSnapshot($snapshot);
            if ($formatted !== '') {
                $userParts[] = $this->catalog->label('form_snapshot') . "\n" . $formatted;
            }

            $currentValue = isset($options['current_value']) ? trim((string) $options['current_value']) : '';
            $fieldLabel = isset($placeholders['field_label']) ? (string) $placeholders['field_label'] : '';
            if ($currentValue !== '') {
                $currentLabel = str_replace('{field_label}', $fieldLabel, $this->catalog->label('current_field'));
                $userParts[] = $currentLabel . "\n" . $currentValue;
            }
        }

        if ($placement === 'translate') {
            $source = isset($options['source_text']) ? trim((string) $options['source_text']) : '';
            if ($source !== '') {
                $userParts[] = $this->catalog->label('source_text') . "\n" . $source;
            }
        }

        $instruction = $this->catalog->instruction($placement, $placeholders);
        if ($instruction !== '') {
            $userParts[] = $instruction;
        }

        $defaultPrompt = isset($app['default_prompt']) ? trim((string) $app['default_prompt']) : '';
        if ($defaultPrompt !== '') {
            $userParts[] = $this->catalog->label('app_task') . "\n" . $defaultPrompt;
        }

        $userPrompt = isset($options['user_prompt']) ? trim((string) $options['user_prompt']) : '';
        if ($userPrompt !== '') {
            $userParts[] = $this->catalog->label('user_prompt') . "\n" . $userPrompt;
        }

        return array(
            array('role' => 'system', 'content' => implode("\n\n", $systemParts)),
            array('role' => 'user', 'content' => implode("\n\n", $userParts)),
        );
    }

    /**
     * 形态 instruction（异步任务在用户未填 prompt 时作为兜底）。
     *
     * @param string $placement
     * @param array $vars
     * @return string
     */
    public function instruction($placement, array $vars = array())
    {
        return $this->catalog->instruction($placement, $vars);
    }

    /**
     * 图像应用提示词：任务形态画面基调 + 表单快照主题 + 应用默认要求 + 用户当次要求，拼为单段文本。
     *
     * 文生图端点只接收一段 prompt（无 system/user 消息结构），
     * 基调取内置 system[task_type]（如 image / banner），
     * 标题/分类/关键词作为画面主题材料，正文不参与（太长且与画面无关）。
     *
     * @param array $app 应用行
     * @param array $options user_prompt / form_snapshot / banner
     *                       banner.material_mode 非空时追加对应的参考图用法指令
     * @return string 全空时返回空串（调用方兜底报错）
     */
    public function imagePrompt(array $app, array $options = array())
    {
        $parts = array();

        $taskType = isset($app['task_type']) ? trim((string) $app['task_type']) : '';
        $parts[] = $this->catalog->system($taskType);

        if ($taskType === 'banner') {
            $layout = $this->bannerLayout($app, isset($options['banner']) && is_array($options['banner']) ? $options['banner'] : array());
            if ($layout !== '') {
                $parts[] = $layout;
            }

            $bannerOptions = isset($options['banner']) && is_array($options['banner']) ? $options['banner'] : array();
            $style = $this->catalog->bannerStyle(isset($bannerOptions['style']) ? $bannerOptions['style'] : '');
            if ($style !== '') {
                $parts[] = $style;
            }

            $titleText = $this->bannerTextInstruction($bannerOptions);
            if ($titleText !== '') {
                $parts[] = $titleText;
            }

            $materialMode = isset($bannerOptions['material_mode']) ? trim((string) $bannerOptions['material_mode']) : '';
            if ($materialMode !== '') {
                $parts[] = $this->catalog->bannerMaterial($materialMode);
            }
        }

        $snapshot = isset($options['form_snapshot']) && is_array($options['form_snapshot'])
            ? $options['form_snapshot']
            : array();
        foreach (array('title', 'category', 'keywords') as $key) {
            $value = isset($snapshot[$key]) ? trim((string) $snapshot[$key]) : '';
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        $defaultPrompt = isset($app['default_prompt']) ? trim((string) $app['default_prompt']) : '';
        if ($defaultPrompt !== '') {
            $parts[] = $defaultPrompt;
        }

        $userPrompt = isset($options['user_prompt']) ? trim((string) $options['user_prompt']) : '';
        if ($userPrompt !== '') {
            $parts[] = $userPrompt;
        }

        return trim(implode('，', $parts));
    }

    /**
     * banner 主/副标题画入画面指令：文字整块垂直居中，水平方位按弹窗选择的对齐方式。
     *
     * @param array $options banner 弹窗参数（title / subtitle / content_align）
     * @return string 空标题返回空串
     */
    private function bannerTextInstruction(array $options)
    {
        $title = isset($options['title']) ? trim((string) $options['title']) : '';
        $subtitle = isset($options['subtitle']) ? trim((string) $options['subtitle']) : '';
        if ($title === '' && $subtitle === '') {
            return '';
        }

        $texts = array();
        if ($title !== '') {
            $texts[] = '主标题「' . $title . '」';
        }
        if ($subtitle !== '') {
            $texts[] = '副标题「' . $subtitle . '」';
        }

        $align = isset($options['content_align']) ? trim((string) $options['content_align']) : '';
        if (!in_array($align, array('left', 'center', 'right'), true)) {
            $align = 'center';
        }
        $place = array(
            'left' => '整块文字靠画面左侧排布',
            'right' => '整块文字靠画面右侧排布',
            'center' => '整块文字在画面水平居中',
        );

        return '画面须清晰渲染以下文字：' . implode('、', $texts)
            . '，主标题字号大、副标题字号小，用字准确、字形规整；文字在画面垂直方向居中，'
            . $place[$align]
            . '，与画布边缘留出安全边距；除这些文字外画面不出现其它文字';
    }

    /**
     * banner 画布指令：出图尺寸，以及成品需从垂直中部裁切时的安全区。
     *
     * 数据来源（后者覆盖前者）：
     * - 应用 config：canvas_size（弹窗未传尺寸时的默认画布）
     * - options：target_size（弹窗最终尺寸）/ gen_size（底层实际出图画布）/
     *   band_ratio（> 0 表示极端比例，出图后要从垂直中部裁出成品）
     *
     * @param array $app 应用行
     * @param array $options 弹窗当次生成参数（见上）
     * @return string
     */
    private function bannerLayout(array $app, array $options = array())
    {
        $config = isset($app['config']) && $app['config'] ? json_decode((string) $app['config'], true) : array();
        if (!is_array($config)) {
            $config = array();
        }

        $target = isset($options['target_size']) ? trim((string) $options['target_size']) : '';
        $gen = isset($options['gen_size']) ? trim((string) $options['gen_size']) : '';
        $bandRatio = isset($options['band_ratio']) ? (float) $options['band_ratio'] : 0.0;
        if ($target === '' && $gen === '') {
            $gen = isset($config['canvas_size']) ? trim((string) $config['canvas_size']) : '';
        }

        if ($target !== '' && $gen !== '' && $bandRatio > 0) {
            return '出图画布 ' . $gen . ' 像素（宽x高），生成后从画面垂直正中央裁出 ' . $target . ' 像素成品，'
                . '标题文字须全部落在这条垂直居中的裁切区内，其上下只画可裁掉的延展背景';
        }
        if ($gen !== '') {
            return '画布尺寸 ' . $gen . ' 像素（宽x高，严格按此尺寸出图）';
        }

        return '';
    }

    /**
     * @param array $snapshot
     * @return string
     */
    private function formatFormSnapshot(array $snapshot)
    {
        $map = array(
            'title' => 'snapshot_title',
            'category' => 'snapshot_category',
            'content' => 'snapshot_content',
            'keywords' => 'snapshot_keywords',
            'description' => 'snapshot_description',
        );
        $lines = array();
        foreach ($map as $key => $labelKey) {
            $value = isset($snapshot[$key]) ? trim((string) $snapshot[$key]) : '';
            if ($value === '') {
                continue;
            }
            $lines[] = $this->catalog->label($labelKey) . '：' . $value;
        }

        return implode("\n", $lines);
    }
}
