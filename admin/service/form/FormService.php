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

namespace Dou\Admin\Service\Form;

use Dou\Admin\Model\Form\Form;
use Dou\Admin\Service\Concerns\SavesLanguageDrafts;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Form\FormTemplateRegistry;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台表单主信息（route=form/...）。
 *
 * 与 {@see FormFieldService}（字段）、{@see FormDataService}（提交数据）三分，本类只负责：
 *   - 子菜单（每个表单一项 + 未读角标）
 *   - 表单主表 CRUD
 *   - 预置模板引导页数据与一键创建（config/form.php）
 *
 * 模板变量保留键校验在此集中处理：表单 slug 不得占用详情页顶层的 meta 键，冲突时提示具体键名。
 */
class FormService extends BaseService
{
    use SavesLanguageDrafts;

    /**
     * 详情页顶层保留键：表单 slug 不得与之重名（show() 会 array_merge 表单定义）。
     *
     * @var array
     */
    private static $pageReserved = array(
        'form_id',
        'name',
        'content',
        'field_list',
        'slug',
        'field_count',
    );

    /** @var FormFieldService */
    private $fieldService;

    /** @var FormDataService */
    private $dataService;

    /**
     * @param FormFieldService $fieldService
     * @param FormDataService $dataService
     */
    public function __construct(FormFieldService $fieldService, FormDataService $dataService)
    {
        $this->fieldService = $fieldService;
        $this->dataService = $dataService;
    }

    // =================================================================
    // 子菜单 / 导航
    // =================================================================

    /**
     * 表单子菜单数据：每个表单一项，附未读数，当前项 cur。
     *
     * 数据列表必须带 form_id（不存在「全部表单」汇总视图），因此这里不再生成
     * form_id=0 的虚拟项，排序后的第一个表单即子菜单首项。
     *
     * DB 查询失败时 select() 返回 false，模板 foreach 归一化后会多出一条空项，
     * 故此处跳过所有非法主键，模板侧另有空态兜底。
     *
     * @param int $currentFormId 当前表单 ID，负数 = 不高亮任何项（新建页）
     * @return array
     */
    public function buildFormNavList($currentFormId = 0)
    {
        $currentFormId = (int) $currentFormId;
        $rows = DB::table('form')->field('id, name, status')->order('sort ASC, id ASC')->select();
        $counts = $this->countDataByForm();
        $unreadCounts = $this->countUnreadDataByForm();

        $list = array();
        foreach ((array) $rows as $row) {
            $formId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($formId <= 0) {
                continue;
            }
            $isActive = $formId === $currentFormId ? 1 : 0;
            $list[] = array(
                'form_id' => $formId,
                'name' => language()->langValue(isset($row['name']) ? $row['name'] : '', 'form', $formId, 'name'),
                'status' => isset($row['status']) ? (int) $row['status'] : 1,
                'total' => isset($counts[$formId]) ? $counts[$formId] : 0,
                'unread' => isset($unreadCounts[$formId]) ? $unreadCounts[$formId] : 0,
                'url' => route('admin.form.data', array('form_id' => $formId)),
                'is_active' => $isActive,
                'cur' => $isActive === 1,
            );
        }

        return $list;
    }

    /**
     * 排序最靠前的表单 ID（数据列表默认入口用）。
     *
     * @return int 无表单时返回 0
     */
    public function getFirstFormId()
    {
        $id = DB::table('form')->where('status', 1)->order('sort ASC, id ASC')->value('id');
        if (empty($id)) {
            $id = DB::table('form')->order('sort ASC, id ASC')->value('id');
        }

        return (int) $id;
    }

    // =================================================================
    // 表单主表
    // =================================================================

    /**
     * 表单管理列表。
     *
     * @param string $keyword
     * @param int $page
     * @return array form_list, pager, keyword
     */
    public function buildFormListPage($keyword, $page)
    {
        $get = '';
        $pageBase = route('admin.form.form_list');
        $query = Form::order('sort ASC, id ASC');
        if ($keyword !== '') {
            $query->where('name', 'LIKE', '%' . $keyword . '%');
            $get = '&keyword=' . rawurlencode($keyword);
        }
        $result = $query->paginate(25, $page, $pageBase, $get);

        $fieldCounts = $this->countFieldByForm();
        $dataCounts = $this->countDataByForm();

        $formList = array();
        foreach ($result['list'] as $rowModel) {
            $row = $rowModel->getAttributes();
            $formId = (int) $row['id'];
            $status = isset($row['status']) ? (int) $row['status'] : 1;
            $name = language()->langValue(isset($row['name']) ? $row['name'] : '', 'form', $formId, 'name');
            $formList[] = array(
                'form_id' => $formId,
                'name' => $name,
                'slug' => (string) $row['slug'],
                'field_count' => isset($fieldCounts[$formId]) ? $fieldCounts[$formId] : 0,
                'data_count' => isset($dataCounts[$formId]) ? $dataCounts[$formId] : 0,
                'status' => $status,
                'status_text' => lang('form_status_' . $status),
                'url' => route('form.show', array('slug' => (string) $row['slug'])),
                'edit_url' => route('admin.form.edit', array('id' => $formId)),
                'data_url' => route('admin.form.data', array('form_id' => $formId)),
                'field_url' => route('admin.form.field', array('form_id' => $formId)),
                'delete_url' => route('admin.form.destroy', array('id' => $formId)),
                'created_at' => !empty($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : '',
                'embed_code' => $this->buildEmbedCode($formId, (string) $row['slug'], $name),
            );
        }

        return array(
            'form_list' => $formList,
            'pager' => $result['pager'],
            'keyword' => $keyword,
        );
    }

    /**
     * 嵌入代码：可复制的原生 HTML 示例 + 末尾的前台脚本调用行。
     *
     * 依赖前台全局 `$form`（slug 索引，框架已注入）：标签与提示统一走
     * `{$form.{slug}.{字段}.name}`，随站点语言切换；控件按字段类型给出最简写法，
     * 类型只约束服务端校验，模板可自行改写（见 form.md 6.3）。
     *
     * @param int    $formId
     * @param string $slug
     * @param string $name
     * @return string
     */
    public function buildEmbedCode($formId, $slug, $name)
    {
        $formId = (int) $formId;
        $slug = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $slug));
        if ($slug === '') {
            $slug = 'form' . $formId;
        }
        $node = '$form.' . $slug;

        // HTML 注释内不能出现连续的 --，否则整段注释会被提前闭合
        $title = str_replace('--', '-', (string) $name);

        $lines = array(
            '<!-- ' . $title . '：粘到任意前台 .dwt 模板即可，' . $node . ' 由框架自动注入 -->',
            '<form x-data="douForm()" @submit.prevent="submit" action="{url link=\'form.submit\'}" method="post">',
            '',
        );

        $fields = $this->fieldService->buildFieldList($formId);
        if (empty($fields)) {
            $lines[] = ' <!-- ' . lang('form_embed_no_field') . ' -->';
        }

        foreach ($fields as $field) {
            $lines = array_merge($lines, $this->embedFieldLines($node, $field));
        }

        // 三条隐藏域统一放尾部：form_id / token 交给 CSRF 校验，source_url 由 js/form.js 自动填当前页地址
        $lines[] = ' <input type="hidden" name="form_id" value="{' . $node . '.form_id}" />';
        $lines[] = ' <input type="hidden" name="token" value="{$token}" />';
        $lines[] = ' <input type="hidden" name="source_url" value="" />';
        $lines[] = ' <button type="submit" class="btn" :disabled="loading">{$lang.btn_submit}</button>';
        $lines[] = ' <p class="cue" x-show="message" x-text="message"></p>';
        $lines[] = '</form>';
        $lines[] = '';
        $lines[] = '<!-- 前台脚本：默认主题 js/ 已自带，换模板时把 theme/default/js 下这些文件复制过去 -->';
        $lines = array_merge($lines, $this->embedScriptLines($fields));

        return implode("\n", $lines);
    }

    /**
     * 表单用到的前台脚本调用行（与各 .dwt 底部写法一致，alpine 带 defer）。
     *
     * form.js 恒定：既负责把 source_url 隐藏域填成当前页地址，也提供 douArea()
     * 与 refreshFormCaptcha()；file.js 仅上传字段需要。jQuery、route()、dou.js 由
     * inc/code_footer.tpl 公共引入，任何标准 .dwt 页面已有，重复引入会重复绑定
     * 事件；bootstrap、online_service 与表单无关，同样不列。
     *
     * @param array $fields buildFieldList() 结果
     * @return array <script> 行数组，按加载顺序
     */
    private function embedScriptLines(array $fields)
    {
        $needFile = false;
        foreach ((array) $fields as $field) {
            $type = isset($field['type']) ? (string) $field['type'] : '';
            if (in_array($type, array('image', 'gallery', 'file'), true)) {
                $needFile = true;
            }
        }

        // alpine 必须先于组件声明加载，且保留 defer（见各 .dwt 底部写法）
        $lines = array(
            '<script type="text/javascript" src="js/alpine.min.js" defer></script>',
            '<script type="text/javascript" src="js/dou.form.js"></script>',
            '<script type="text/javascript" src="js/form.js"></script>',
        );
        if ($needFile) {
            // 图片 / 多图 / 附件的上传控件
            $lines[] = '<script type="text/javascript" src="js/file.js"></script>';
        }

        return $lines;
    }

    /**
     * 单个字段的示例 HTML：标签 + 按类型的最简控件 + 错误提示位。
     *
     * @param string $node 形如 $form.quote
     * @param array  $field
     * @return array 行数组
     */
    private function embedFieldLines($node, array $field)
    {
        $fieldSlug = isset($field['slug']) ? (string) $field['slug'] : '';
        if ($fieldSlug === '') {
            return array();
        }

        $type = isset($field['type']) ? (string) $field['type'] : 'input';
        $path = $node . '.' . $fieldSlug;
        $var = '{' . $path;
        $placeholder = $var . '.placeholder}';

        $lines = array();
        $lines[] = ' <div class="form-item">';
        $lines[] = '  <div class="form-label">' . $var . '.name}' . (empty($field['required']) ? '' : '<i>*</i>') . '</div>';

        switch ($type) {
            case 'textarea':
                $lines[] = '  <textarea name="' . $fieldSlug . '" rows="5" placeholder="' . $placeholder . '"></textarea>';
                break;

            case 'select':
                $lines[] = '  <select name="' . $fieldSlug . '" class="select">';
                // 占位项文案与前台一致：后台填了「输入提示」就用它，否则显示默认「请选择」
                $lines[] = '   <!-- {if ' . $path . '.placeholder} -->';
                $lines[] = '   <option value="">{' . $path . '.placeholder}</option>';
                $lines[] = '   <!-- {else} -->';
                $lines[] = '   <option value="">{$lang.form_select_placeholder}</option>';
                $lines[] = '   <!-- {/if} -->';
                $lines[] = '   <!-- {foreach from=' . $path . '.option item=opt} -->';
                $lines[] = '   <option value="{$opt}">{$opt}</option>';
                $lines[] = '   <!-- {/foreach} -->';
                $lines[] = '  </select>';
                break;

            case 'radio':
                $lines[] = '  <span class="checkbox-group">';
                $lines[] = '   <!-- {foreach from=' . $path . '.option item=opt} -->';
                $lines[] = '   <label><input type="radio" name="' . $fieldSlug . '" value="{$opt}" /> {$opt}</label>';
                $lines[] = '   <!-- {/foreach} -->';
                $lines[] = '  </span>';
                break;

            case 'checkbox':
                $lines[] = '  <span class="checkbox-group">';
                $lines[] = '   <!-- {foreach from=' . $path . '.option item=opt} -->';
                $lines[] = '   <label><input type="checkbox" name="' . $fieldSlug . '[]" value="{$opt}" /> {$opt}</label>';
                $lines[] = '   <!-- {/foreach} -->';
                $lines[] = '  </span>';
                break;

            case 'agree':
                // 单勾选框：文案优先取「输入提示」，未填则回退字段名称
                $lines[] = '  <span class="checkbox-group">';
                $lines[] = '   <label><input type="checkbox" name="' . $fieldSlug . '" value="1" /> ';
                $lines[] = '    <!-- {if ' . $path . '.placeholder} -->{' . $path . '.placeholder}<!-- {else} -->{' . $path . '.name}<!-- {/if} -->';
                $lines[] = '   </label>';
                $lines[] = '  </span>';
                break;

            case 'switch':
                $lines[] = '  <label class="form-switch"><input type="checkbox" name="' . $fieldSlug . '" value="1" /><span class="form-switch-box"></span></label>';
                break;

            case 'area':
                $lines[] = '  <div class="form-area" x-data="douArea()" x-init="init($el)">';
                foreach (array('province', 'city', 'district') as $part) {
                    $lines[] = '   <select name="' . $fieldSlug . '[' . $part . ']" class="select" data-part="' . $part . '"></select>';
                }
                $lines[] = '  </div>';
                break;

            case 'image':
            case 'gallery':
            case 'file':
                $includes = array(
                    'image' => 'file_image.tpl',
                    'gallery' => 'file_gallery.tpl',
                    'file' => 'file_attachment.tpl',
                );
                $target = isset($field['target']) ? (string) $field['target'] : '';
                $lines[] = '  {include file="inc/' . $includes[$type] . '" module="form" prefix="' . $target . '" item_id="" draft_token=$draft_token list_html=""}';
                break;

            default:
                $inputTypes = array(
                    'email' => 'email',
                    'tel' => 'tel',
                    'mobile' => 'tel',
                    'url' => 'url',
                    'number' => 'number',
                    'date' => 'date',
                );
                $inputType = isset($inputTypes[$type]) ? $inputTypes[$type] : 'text';
                // date 的原生控件不渲染 placeholder，改用 title 承载提示文字
                $hintAttr = $inputType === 'date'
                    ? ' title="' . $placeholder . '"'
                    : ' placeholder="' . $placeholder . '"';
                $lines[] = '  <input type="' . $inputType . '"' . ($type === 'number' ? ' step="any"' : '') . ' name="' . $fieldSlug . '"' . $hintAttr . ' />';
                break;
        }

        $lines[] = '  <p class="cue" x-show="hasError(\'' . $fieldSlug . '\')" x-text="errorOf(\'' . $fieldSlug . '\')"></p>';
        $lines[] = ' </div>';
        $lines[] = '';

        return $lines;
    }

    /**
     * 新增表单：只写主表，字段留到字段页维护。
     *
     * @param array $data 来自 FormFormRequest::validated()
     * @return int 新增记录主键（form_id）
     */
    public function insert(array $data)
    {
        $payload = $this->normalizeFormPayload($data);
        $this->assertFormSlug($payload['slug']);
        $payload['created_at'] = date('Y-m-d H:i:s');
        $adminId = (int) auth('admin')->id();
        $languagePlan = $this->languageDraftBegin('form', $data, 0, $adminId);

        $newId = (int) DB::transaction(function () use ($payload, $adminId, $languagePlan) {
            $model = Form::create($payload);
            if (!$model || (int) $model->getKey() <= 0) {
                throw new DomainException(lang('operation_failed'));
            }
            $formId = (int) $model->getKey();
            $this->languageDraftCommit($languagePlan, $formId, $adminId);

            return $formId;
        });

        $this->languageDraftPurge($languagePlan, $newId);

        return $newId;
    }

    /**
     * 编辑页表单数据（原始值，未翻译）。
     *
     * @param int $formId
     * @return array|null
     */
    public function findFormForEdit($formId)
    {
        $formId = (int) $formId;
        $model = Form::find($formId);
        $formInfo = $model ? $model->getAttributes() : null;
        if (empty($formInfo)) {
            return null;
        }

        $fieldCounts = $this->countFieldByForm();
        $dataCounts = $this->countDataByForm();

        $formInfo['form_id'] = (int) $formInfo['id'];
        $formInfo['field_count'] = isset($fieldCounts[$formId]) ? $fieldCounts[$formId] : 0;
        $formInfo['data_count'] = isset($dataCounts[$formId]) ? $dataCounts[$formId] : 0;
        $formInfo['url'] = route('form.show', array('slug' => (string) $formInfo['slug']));
        $formInfo['data_url'] = route('admin.form.data', array('form_id' => $formId));
        $formInfo['field_url'] = route('admin.form.field', array('form_id' => $formId));
        $languageForm = $this->languageDraftService()->buildFormState('form', $formId);
        $formInfo['btn_lang'] = $languageForm['btn_lang'];
        $formInfo['lang_drafts'] = $languageForm['lang_drafts'];

        return $formInfo;
    }

    /**
     * 新增页默认值。
     *
     * @return array
     */
    public function buildFormDefaultData()
    {
        $languageForm = $this->languageDraftService()->buildFormState('form', 0);

        return array(
            'form_id' => '',
            'name' => '',
            'slug' => '',
            'content' => '',
            'success_message' => '',
            'success_url' => '',
            'captcha' => 0,
            'require_login' => 0,
            'spam_limit' => 10,
            'notify_email' => '',
            'status' => 1,
            'sort' => 50,
            'btn_lang' => $languageForm['btn_lang'],
            'lang_drafts' => $languageForm['lang_drafts'],
        );
    }

    /**
     * 更新表单：使用经请求层校验后的字段写回。
     *
     * @param int $formId
     * @param array $data
     * @return void
     */
    public function update($formId, array $data)
    {
        $formId = (int) $formId;
        unset($data['form_id']);

        $payload = $this->normalizeFormPayload($data);
        $this->assertFormSlug($payload['slug'], $formId);

        $model = Form::find($formId);
        if (!$model) {
            throw new DomainException(lang('item_not_exist'), route('admin.form.form_list'));
        }
        $adminId = (int) auth('admin')->id();
        $languagePlan = $this->languageDraftBegin('form', $data, $formId, $adminId);

        try {
            DB::transaction(function () use ($model, $payload, $formId, $adminId, $languagePlan) {
                $model->fill($payload, 'update')->save();
                $this->languageDraftCommit($languagePlan, $formId, $adminId);
            });
        } catch (\Exception $e) {
            $this->languageDraftAbort($languagePlan);
            throw $e;
        }

        $this->languageDraftPurge($languagePlan, $formId);
    }

    /**
     * @param int $formId
     * @return string
     */
    public function getFormName($formId)
    {
        return (string) DB::table('form')->where('id', (int) $formId)->value('name');
    }

    /**
     * 删除表单：连带字段与提交数据。
     *
     * @param int $formId
     * @return void
     */
    public function delete($formId)
    {
        $formId = (int) $formId;

        $numbers = $this->dataService->attachmentNumbers(
            DB::table('form_data')->field('id')->where('form_id', $formId)->select()
        );
        $fieldIds = DB::table('form_field')->field('id')->where('form_id', $formId)->select();

        DB::transaction(function () use ($formId, $fieldIds) {
            foreach ((array) $fieldIds as $fieldRow) {
                language()->deleteLang('form_field', (int) $fieldRow['id']);
            }
            DB::table('form_field')->where('form_id', $formId)->delete();
            DB::table('form_data')->where('form_id', $formId)->delete();
            Form::destroy($formId);
        });

        // 物理文件删除不可回滚，放在事务提交之后
        $this->dataService->purgeAttachmentFiles($numbers);
    }

    // =================================================================
    // 预置模板引导（config/form.php）
    // =================================================================

    /**
     * 引导页模板卡片：一张卡对应一套预置模板，字段名以 chip 形式展示。
     *
     * 空白表单卡不计入返回值，由模板首张静态渲染；已用该模板建过表单的
     * 卡片不再返回（模板建表时 slug 取模板键，重复创建依次追加 _2、_3 …）。
     *
     * @return array [{key, name, content, field_count, field_badge, fields:[{name}]}]
     */
    public function buildTemplateCards()
    {
        $existingSlugs = $this->existingFormSlugs();

        $cards = array();
        foreach (FormTemplateRegistry::all() as $key => $template) {
            if ($this->templateFormExists($key, $existingSlugs)) {
                continue;
            }

            $fields = array();
            foreach ((array) $template['fields'] as $field) {
                if (is_array($field) && isset($field['name'])) {
                    $fields[] = array('name' => (string) $field['name']);
                }
            }
            $cards[] = array(
                'key' => (string) $key,
                'name' => lang('form_template_' . $key, (string) $template['name']),
                'content' => (string) $template['content'],
                'field_count' => count($fields),
                'field_badge' => preg_replace('/d%/Ums', count($fields), lang('form_template_field_badge')),
                'fields' => $fields,
            );
        }

        return $cards;
    }

    /**
     * 现存的全部表单 slug（小写），用于判断模板是否已建过表单。
     *
     * @return array
     */
    private function existingFormSlugs()
    {
        $slugs = array();
        foreach ((array) DB::table('form')->field('slug')->select() as $row) {
            if (!empty($row['slug'])) {
                $slugs[] = strtolower((string) $row['slug']);
            }
        }

        return $slugs;
    }

    /**
     * 该模板是否已存在对应表单：slug 为模板键本身，或模板键 + 数字后缀（重复创建）。
     *
     * @param string $key
     * @param array  $slugs
     * @return bool
     */
    private function templateFormExists($key, array $slugs)
    {
        $key = strtolower(trim((string) $key));
        if ($key === '') {
            return false;
        }

        $pattern = '/^' . preg_quote($key, '/') . '_\d+$/';
        foreach ($slugs as $slug) {
            if ($slug === $key || preg_match($pattern, $slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 一键使用预置模板：建表单 + 播种整套字段。
     *
     * @param string $templateKey config/form.php 的模板键
     * @return int 新增表单 ID
     */
    public function createFromTemplate($templateKey)
    {
        $templateKey = trim((string) $templateKey);
        if ($templateKey === '' || !FormTemplateRegistry::exists($templateKey)) {
            throw new DomainException(lang('illegal'), route('admin.form.template'));
        }

        $template = FormTemplateRegistry::get($templateKey);

        $payload = $this->normalizeFormPayload(array(
            'name' => (string) $template['name'],
            'slug' => $this->uniqueFormSlug($templateKey),
            'content' => (string) $template['content'],
            'success_message' => (string) $template['success_message'],
            'status' => 1,
            'sort' => 50,
        ));
        $payload['created_at'] = date('Y-m-d H:i:s');

        return (int) DB::transaction(function () use ($payload, $template) {
            $model = Form::create($payload);
            if (!$model || (int) $model->getKey() <= 0) {
                throw new DomainException(lang('operation_failed'));
            }
            $formId = (int) $model->getKey();
            if (!empty($template['fields'])) {
                $this->seedTemplateFields($formId, $template['fields']);
            }

            return $formId;
        });
    }

    /**
     * 按模板键生成可用表单 slug：冲突时追加 _2、_3 …
     *
     * @param string $base
     * @return string
     */
    private function uniqueFormSlug($base)
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', (string) $base), '_'));
        $slug = mb_substr($slug, 0, 55);
        if ($slug === '' || !preg_match('/^[a-z]/', $slug)) {
            $slug = 'form_' . $slug;
        }

        $unique = $slug;
        $index = 2;
        while (DB::table('form')->where('slug', $unique)->value('id')) {
            $unique = $slug . '_' . $index;
            $index++;
        }

        return $unique;
    }

    // =================================================================
    // 内部
    // =================================================================

    /**
     * 主表写入值归一（含默认值与 XSS 清洗）。
     *
     * @param array $data
     * @return array
     */
    private function normalizeFormPayload(array $data)
    {
        $spamLimit = isset($data['spam_limit']) ? (int) $data['spam_limit'] : 10;
        if ($spamLimit < 0) {
            $spamLimit = 0;
        }

        return array(
            'name' => mb_substr(trim(isset($data['name']) ? (string) $data['name'] : ''), 0, 150),
            'slug' => strtolower(trim(isset($data['slug']) ? (string) $data['slug'] : '')),
            'content' => isset($data['content']) ? xss()->content((string) $data['content']) : '',
            'success_message' => mb_substr(trim(isset($data['success_message']) ? (string) $data['success_message'] : ''), 0, 255),
            'success_url' => mb_substr(trim(isset($data['success_url']) ? (string) $data['success_url'] : ''), 0, 255),
            'captcha' => !empty($data['captcha']) ? 1 : 0,
            'require_login' => !empty($data['require_login']) ? 1 : 0,
            'spam_limit' => $spamLimit,
            'notify_email' => mb_substr(trim(isset($data['notify_email']) ? (string) $data['notify_email'] : ''), 0, 120),
            'status' => isset($data['status']) && (string) $data['status'] === '0' ? 0 : 1,
            'sort' => isset($data['sort']) ? max(0, (int) $data['sort']) : 50,
        );
    }

    /**
     * 按预置模板写入整套字段。
     *
     * @param int $formId
     * @param array $fields
     * @return void
     */
    private function seedTemplateFields($formId, array $fields)
    {
        $sort = 0;
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            // 显式 sort 时也要推进计数器，避免后继默认值与前者撞号
            $sort = isset($field['sort']) ? (int) $field['sort'] : $sort + 10;
            $field['sort'] = $sort;
            $this->fieldService->insertFromSource((int) $formId, $field);
        }
    }

    /**
     * 表单 slug 保留键与唯一性校验。
     *
     * @param string $slug
     * @param int $formId 编辑时排除自身
     * @return void
     */
    private function assertFormSlug($slug, $formId = 0)
    {
        $slug = (string) $slug;
        if (in_array($slug, self::$pageReserved, true)) {
            throw new DomainException(preg_replace('/d%/Ums', $slug, lang('form_slug_reserved')));
        }

        $query = DB::table('form')->where('slug', $slug);
        if ($formId > 0) {
            $query->where('id', '!=', (int) $formId);
        }
        if ($query->value('id')) {
            throw new DomainException(preg_replace('/d%/Ums', $slug, lang('form_slug_exists')));
        }
    }

    /**
     * 各表单字段数：formId => count。
     *
     * @return array
     */
    private function countFieldByForm()
    {
        $rows = DB::table('form_field')->field('form_id, COUNT(*) AS total')->groupBy('form_id')->select();
        $counts = array();
        foreach ((array) $rows as $row) {
            $counts[(int) $row['form_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * 各表单提交数：formId => count。
     *
     * @return array
     */
    private function countDataByForm()
    {
        $rows = DB::table('form_data')->field('form_id, COUNT(*) AS total')->groupBy('form_id')->select();
        $counts = array();
        foreach ((array) $rows as $row) {
            $counts[(int) $row['form_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * 各表单未读数：formId => count（子菜单角标用）。
     *
     * @return array
     */
    private function countUnreadDataByForm()
    {
        $rows = DB::table('form_data')->field('form_id, COUNT(*) AS total')->where('if_read', 0)->groupBy('form_id')->select();
        $counts = array();
        foreach ((array) $rows as $row) {
            $counts[(int) $row['form_id']] = (int) $row['total'];
        }

        return $counts;
    }
}
