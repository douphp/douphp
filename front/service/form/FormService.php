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

namespace Dou\Front\Service\Form;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Infra\Security\Captcha;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Form\FormFieldSchema;
use Dou\Core\Service\Form\FormService as FormCore;
use Dou\Core\Web\Validation\Validator;
use Dou\Front\Service\File\FileService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台表单业务。
 *
 * 职责三层：
 *   - 详情页数据：字段结构 + 默认值（供模板与 API 复用）
 *   - 提交处理：表单级开关 → 字段清洗校验 → 验证码 → 防刷 → 附件归集 → 入库 → 认领草稿
 *   - 列表数据：直接转发核心引擎（表单定义由 core FormService 统一持有）
 *
 * 提交结果统一为 `{ok, message, redirect_url, errors}`：控制器与 API 各自映射成响应，
 * 不再靠嗅探文案里的「成功」二字判断成败。
 */
class FormService extends BaseService
{
    /** @var FormCore 表单引擎（定义与字段结构） */
    private $form;

    /** @var Captcha 图形验证码 */
    private $captcha;

    /** @var array 保留键：不写入 data JSON（post / post_data 是小程序传输信封） */
    private static $reservedKeys = array('form_id', 'token', 'captcha', 'draft_token', 'source_url', 'post', 'post_data');

    /** @var array 模板自定义字段的收纳上限 */
    private static $customLimits = array('count' => 50, 'length' => 5000, 'bytes' => 204800);

    /**
     * @param FormCore $form
     * @param Captcha $captcha
     */
    public function __construct(FormCore $form, Captcha $captcha)
    {
        $this->form = $form;
        $this->captcha = $captcha;
    }

    /**
     * 表单列表（API 与后台复用）。
     *
     * @return array
     */
    public function getFormList()
    {
        return $this->form->getFormList(true);
    }

    /**
     * 表单列表分页（API 小程序上拉加载）。
     *
     * @param int $page
     * @param int $pageSize 0 取分页配置
     * @param string $pageUrl
     * @return array list / pager
     */
    public function paginateFormList($page = 1, $pageSize = 0, $pageUrl = '')
    {
        $pageSize = (int) $pageSize;
        if ($pageSize <= 0) {
            $pageSize = (int) Config::get('pagination.form', 10);
        }

        return $this->form->paginateFormList($page, $pageSize > 0 ? $pageSize : 10, $pageUrl);
    }

    /**
     * 详情页数据（按主键）。
     *
     * API 端仍以 form/{id} 寻址，聚合逻辑与前台按 slug 入口共用 {@see buildShowPageData}。
     *
     * 返回的 `form` 带 `field_list`（顺序字段列表），模板既可用 `$form.field_list` 整页渲染，
     * 也可用控制器合并后的 `$form.{slug}.{字段 slug}` 手写单字段。
     *
     * @param int|string $formId
     * @return array|null form / field_count / post_data
     */
    public function getShowPageData($formId)
    {
        $formId = (int) $formId;
        if ($formId <= 0) {
            return null;
        }

        $row = DB::table('form')->where('id', $formId)->where('status', 1)->find();
        if (empty($row)) {
            return null;
        }

        return $this->buildShowPageData($row, $formId);
    }

    /**
     * 详情页数据（按表单别名）。
     *
     * 前台填写页 URL 为 /form/{slug}：slug 后台必填且唯一（^[a-z][a-z0-9_]{0,59}$），
     * 与旧的数字主键不会混淆，但这里只认别名——按主键访问由 API 端负责。
     *
     * @param string $slug
     * @return array|null
     */
    public function getShowPageDataBySlug($slug)
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return null;
        }

        $row = DB::table('form')->where('slug', $slug)->where('status', 1)->find();
        if (empty($row)) {
            return null;
        }

        return $this->buildShowPageData($row, (int) $row['id']);
    }

    /**
     * 组装详情页数据：字段结构 + 默认值。
     *
     * @param array $row form 主表行
     * @param int $formId
     * @return array
     */
    private function buildShowPageData(array $row, $formId)
    {
        $formId = (int) $formId;

        language()->warmup('form', array($formId), 'name,content');

        $built = $this->form->buildFields($formId);
        $fieldList = $built['field_list'];

        $form = array(
            'form_id' => $formId,
            'slug' => !empty($row['slug']) ? (string) $row['slug'] : 'form' . $formId,
            'name' => language()->langValue(isset($row['name']) ? $row['name'] : '', 'form', $formId, 'name'),
            'content' => language()->langValue(isset($row['content']) ? $row['content'] : '', 'form', $formId, 'content'),
            'captcha' => !empty($row['captcha']) ? 1 : 0,
            'require_login' => !empty($row['require_login']) ? 1 : 0,
            'success_message' => isset($row['success_message']) ? (string) $row['success_message'] : '',
            'success_url' => isset($row['success_url']) ? (string) $row['success_url'] : '',
            'field_count' => count($fieldList),
            'field_list' => $fieldList,
        );

        return array(
            'form' => $form,
            'field_count' => count($fieldList),
            'post_data' => $this->buildPostData($fieldList),
        );
    }

    /**
     * 处理表单提交。
     *
     * @param int|string $formId
     * @param array $post 原始 $_POST
     * @param string $ip 客户端 IP
     * @param array $context user_id / draft_token / source_url（缺省时从当前会话与 $_POST 推断）
     * @return array ok / message / redirect_url / errors
     */
    public function processSubmit($formId, array $post, $ip = '', array $context = array())
    {
        $formId = (int) $formId;
        $userId = isset($context['user_id']) ? (int) $context['user_id'] : (int) auth('front')->id();
        $draftToken = isset($context['draft_token'])
            ? (string) $context['draft_token']
            : (isset($post['draft_token']) ? (string) $post['draft_token'] : '');
        $sourceUrl = isset($context['source_url'])
            ? (string) $context['source_url']
            : (isset($post['source_url']) ? (string) $post['source_url'] : '');

        // 1. 表单必须存在且启用
        $form = DB::table('form')->where('id', $formId)->where('status', 1)->find();
        if (empty($form)) {
            return $this->failure(lang('page_wrong'), $this->homeUrl());
        }

        // 回填页固定用别名寻址：表单页 URL 已改为 /form/{slug}，slug 缺失时退回首页
        $backUrl = !empty($form['slug']) ? route('form.show', array('slug' => (string) $form['slug'])) : $this->homeUrl();

        $built = $this->form->buildFields($formId);
        $fieldList = $built['field_list'];
        if (empty($fieldList)) {
            return $this->failure(lang('page_wrong'), $backUrl);
        }

        // 2. 表单级开关：仅登录会员可提交
        if (!empty($form['require_login']) && $userId <= 0) {
            return $this->failure(lang('form_login_required'), $this->loginUrl());
        }

        // 3. 字段清洗：已定义字段按类型归一，上传类按草稿键回查附件编号
        $values = array();
        $errors = array();
        foreach ($fieldList as $field) {
            $slug = $field['slug'];
            $raw = isset($post[$slug]) ? $post[$slug] : null;

            if ($field['is_upload']) {
                $values[$slug] = $this->resolveFieldUploads($field, $raw, $draftToken, $userId, $errors);
                continue;
            }

            $values[$slug] = FormFieldSchema::normalizeValue($field, $raw);
        }

        // 4. 字段校验：必填 → 类型自带规则 → 长度 / 选项 → 自定义 validate
        $rules = array();
        $messages = array();
        foreach ($fieldList as $field) {
            $slug = $field['slug'];
            $ruleList = $this->buildRuleList($field, $values[$slug]);
            if (empty($ruleList)) {
                continue;
            }

            $rules[$slug] = implode('|', $ruleList);
            $messages[$slug . '.required'] = $field['name'] . lang('is_empty');
        }

        if (!empty($rules)) {
            // 把字段标签合并进语言表，Validator 的 :field 占位符即可输出中文标签
            // 而不至于落到字段 slug（module 传空串，避免误命中 {module}_{field} 存量键）
            $langBag = lang_all();
            foreach ($fieldList as $field) {
                $langBag[$field['slug']] = $field['name'];
            }

            $validator = new Validator(DB::getFacadeRoot(), $langBag, '');
            try {
                $validator->validate($values, $rules, $messages, true);
            } catch (DomainException $e) {
                $validatorErrors = $e->getErrors();
                foreach ((array) $validatorErrors as $key => $message) {
                    $errors[$key] = $message;
                }
            }
        }

        if (!empty($errors)) {
            return $this->failure(lang('check_inputs'), $backUrl, $errors);
        }

        // 5. 验证码：站点总开关与表单开关同时为真才校验
        if (Config::get('site.captcha', false) && !empty($form['captcha'])) {
            $captchaRaw = isset($post['captcha']) ? (string) $post['captcha'] : '';
            if (!$this->captcha->verify($captchaRaw)) {
                return $this->failure(lang('check_inputs'), $backUrl, array('captcha' => lang('captcha_wrong')));
            }
        }

        // 6. 防刷：同 IP 每小时提交上限，0 表示不限
        $spamLimit = isset($form['spam_limit']) ? (int) $form['spam_limit'] : 0;
        if ($spamLimit > 0 && $this->countRecentByIp($formId, (string) $ip) >= $spamLimit) {
            return $this->failure(lang('form_is_water'), $backUrl);
        }

        // 7. 入库数据：已定义字段 + 模板自定义字段（保留键剔除、限长、去标签）
        $data = $values;
        foreach ($this->collectCustomFields($post, $fieldList) as $key => $value) {
            $data[$key] = $value;
        }

        $pendingRelease = array();
        DB::transaction(function () use ($data, $formId, $ip, $sourceUrl, $userId, $fieldList, $values, $draftToken, &$pendingRelease) {
            DB::table('form_data')->insert(array(
                'form_id' => $formId,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'user_id' => $userId,
                'ip' => mb_substr((string) $ip, 0, 45),
                'source_url' => mb_substr($this->resolveSourceUrl($sourceUrl), 0, 255),
                'if_read' => 0,
                'status' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ));
            $dataId = (int) DB::insertId();
            if ($dataId <= 0) {
                throw new DomainException(lang('operation_failed'));
            }

            // 8. 认领草稿附件：字段派生键与上传时一致；认领失败即回滚，不留半截数据
            if ($userId <= 0 || $draftToken === '') {
                return;
            }
            foreach ($fieldList as $field) {
                if (!$field['is_upload'] || $field['target'] === '') {
                    continue;
                }
                $numbers = isset($values[$field['slug']]) && is_array($values[$field['slug']])
                    ? $values[$field['slug']]
                    : array();
                $this->claimFieldUploads($field, $numbers, $dataId, $draftToken, $userId, $pendingRelease);
            }
        });

        // 字段 limit 之外的草稿回收：物理文件删除不可回滚，留到事务提交后
        foreach ($pendingRelease as $number) {
            attachment()->release('form', (string) $number, 'user', $userId);
        }

        // 9. 成功提示 / 跳转：表单配置优先；未配置则落到提交成功提示页
        //    （原地回填原表单页会让用户以为「点了没反应」，提示页再由用户选择返回表单或首页）
        return array(
            'ok' => true,
            'message' => $this->successMessage($form),
            'redirect_url' => !empty($form['success_url'])
                ? (string) $form['success_url']
                : $this->successPageUrl($form),
            'errors' => array(),
        );
    }

    /**
     * 提交成功提示文案：表单配置优先，缺省用系统默认。
     *
     * 提交响应（前台跳转 / API 提示 / 成功页正文）共用同一处产出，避免多端文案漂移。
     *
     * @param array $form form 主表行，或 buildShowPageData() 输出的 form 节点
     * @return string
     */
    public function successMessage(array $form)
    {
        return !empty($form['success_message'])
            ? (string) $form['success_message']
            : (string) lang('form_success', lang('form_submit') . lang('success'));
    }

    /**
     * 提交成功后未配置 success_url 时的落点：/form/{slug}/success 成功提示页。
     *
     * 别名缺失（存量脏数据）时退回首页，避免生成 /form//success 这类无效地址。
     *
     * @param array $form
     * @return string
     */
    private function successPageUrl(array $form)
    {
        $slug = isset($form['slug']) ? (string) $form['slug'] : '';

        return $slug !== '' ? route('form.success', array('slug' => $slug)) : $this->homeUrl();
    }

    /**
     * 字段校验规则：类型元数据统一产出，数组型字段只保留 required。
     *
     * @param array $field
     * @param mixed $value 已清洗的值（area 全空时归一成空数组，便于 required 命中）
     * @return array
     */
    private function buildRuleList(array $field, &$value)
    {
        $type = $field['type'];

        // area 三个子项全空按「未填」处理，否则 required 会把 ['province'=>'', ...] 当有值
        if ($type === 'area' && is_array($value) && implode('', array_map('strval', $value)) === '') {
            $value = array();
        }

        // switch / agree 的「必填」语义是必须勾选：未勾选归一成 '0'，而 Validator 只把 '' 与
        // 空数组视为缺失，'0' 会被当成已填而放行。这里在必填时改写成空串，让 required 生效。
        if (($type === 'switch' || $type === 'agree')
            && (!empty($field['must_fill']) || !empty($field['required']))
            && (string) $value !== '1'
        ) {
            $value = '';
        }

        $ruleList = FormFieldSchema::buildRules($field);

        // 数组型字段（checkbox / area / 上传类）只保留 required：in / max / email 等规则都按
        // 标量实现，直接喂数组会触发 Array to string conversion；选项合法性已由
        // normalizeValue 交集过滤，附件编号长度已由清洗阶段的正则约束。
        if (is_array($value)) {
            return in_array('required', $ruleList, true) ? array('required') : array();
        }

        return $ruleList;
    }

    /**
     * 上传类字段：按草稿键回查附件编号，并按字段定义复核扩展名与体积。
     *
     * @param array $field
     * @param mixed $raw 提交的上传值（正常由前端上传后留空，值以草稿键归集）
     * @param string $draftToken
     * @param int $userId
     * @param array $errors 字段级错误（按引用写入）
     * @return array 附件编号列表
     */
    private function resolveFieldUploads(array $field, $raw, $draftToken, $userId, array &$errors)
    {
        $slug = $field['slug'];

        // 游客提交上传值一律判非法；空值不算提交
        if ($userId <= 0) {
            if (is_array($raw) ? !empty($raw) : ($raw !== null && $raw !== '')) {
                $errors[$slug] = lang('file_login_required');
            }

            return array();
        }

        if ($draftToken === '' || $field['target'] === '') {
            if (!empty($field['must_fill'])) {
                $errors[$slug] = $field['name'] . lang('is_empty');
            }

            return array();
        }

        $rows = DB::table('file')
            ->field('number, file, original_name, size')
            ->where('module', 'form')
            ->where('draft_token', FileService::draftKey($draftToken, $field['target']))
            ->where('uploader_type', 'user')
            ->where('uploader_id', $userId)
            ->where('type', $field['upload_type'])
            ->where('status', 'draft')
            ->order('id ASC')
            ->select();

        $limit = (int) $field['limit'];
        if ($field['type'] === 'image' || $limit <= 0) {
            $limit = 1;
        }

        $allowed = $this->parseExtensions($field['extensions']);
        $maxSize = (int) $field['max_size'] * 1024;

        $numbers = array();
        foreach ((array) $rows as $row) {
            $number = isset($row['number']) ? (string) $row['number'] : '';
            if ($number === '') {
                continue;
            }

            if (!empty($allowed)) {
                $ext = strtolower(pathinfo((string) $row['original_name'], PATHINFO_EXTENSION));
                if ($ext === '') {
                    $ext = strtolower(pathinfo((string) $row['file'], PATHINFO_EXTENSION));
                }
                if (!in_array($ext, $allowed, true)) {
                    $errors[$slug] = lang('file_ext_not_allowed');

                    continue;
                }
            }

            if ($maxSize > 0 && (int) $row['size'] > $maxSize) {
                $errors[$slug] = lang('file_too_large');

                continue;
            }

            $numbers[] = $number;
        }

        $numbers = array_slice($numbers, 0, $limit);

        if (empty($numbers) && !empty($field['must_fill']) && !isset($errors[$slug])) {
            $errors[$slug] = $field['name'] . lang('is_empty');
        }

        return $numbers;
    }

    /**
     * 认领单个上传字段的草稿附件。
     *
     * 按 number 逐条 adopt（四重校验 + CAS）：认领不到即抛错回滚。不能只按 token 全量认领——
     * 那会把字段 limit 之外的文件也置为 owned，既不在 data 里展示，也不再被草稿 GC 回收。
     *
     * @param array $field
     * @param array $numbers 已入选的附件编号
     * @param int $dataId
     * @param string $draftToken
     * @param int $userId
     * @param array $pendingRelease 出参：待回收的多余草稿 number
     * @return void
     * @throws DomainException
     */
    private function claimFieldUploads(array $field, array $numbers, $dataId, $draftToken, $userId, array &$pendingRelease)
    {
        foreach ($numbers as $number) {
            if (attachment()->adopt('form', (string) $number, (string) $dataId, 'user', $userId) < 1) {
                // 被并发填写 / 已过 TTL 删除：回滚整次提交
                throw new DomainException(lang('file_not_exist'));
            }
        }

        // 字段 limit 之外的草稿一并回收（含物理文件），避免永久孤儿
        $extra = DB::table('file')
            ->field('number')
            ->where('module', 'form')
            ->where('draft_token', FileService::draftKey($draftToken, $field['target']))
            ->where('uploader_type', 'user')
            ->where('uploader_id', $userId)
            ->where('type', $field['upload_type'])
            ->where('status', 'draft')
            ->select();
        foreach ((array) $extra as $row) {
            if (!empty($row['number'])) {
                $pendingRelease[] = (string) $row['number'];
            }
        }
    }

    /**
     * 字段扩展名清单（逗号分隔、小写）。
     *
     * @param string $extensions
     * @return array
     */
    private function parseExtensions($extensions)
    {
        $extensions = strtolower(trim((string) $extensions));
        if ($extensions === '') {
            return array();
        }

        $result = array();
        foreach (preg_split('/[,，\s]+/u', $extensions) as $ext) {
            $ext = trim($ext, ". \t\n\r\0\x0B");
            if ($ext !== '') {
                $result[] = $ext;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * 模板自定义字段：剔除保留键与已定义字段，限长、限量、限总量，去标签不转义。
     *
     * @param array $post
     * @param array $fieldList
     * @return array
     */
    private function collectCustomFields(array $post, array $fieldList)
    {
        $known = array();
        foreach ($fieldList as $field) {
            $known[$field['slug']] = true;
        }

        $custom = array();
        $bytes = 0;
        foreach ($post as $key => $value) {
            $key = (string) $key;
            if ($key === '' || isset($known[$key]) || in_array($key, self::$reservedKeys, true)) {
                continue;
            }
            if (count($custom) >= self::$customLimits['count']) {
                break;
            }

            $value = $this->cleanCustomValue($value);
            $size = strlen((string) json_encode($value));
            if ($bytes + $size > self::$customLimits['bytes']) {
                break;
            }

            $custom[$key] = $value;
            $bytes += $size;
        }

        return $custom;
    }

    /**
     * 自定义字段值清洗：数组保留一层键，叶子值去标签并截断。
     *
     * @param mixed $value
     * @return mixed
     */
    private function cleanCustomValue($value)
    {
        if (is_array($value)) {
            $result = array();
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    continue;
                }
                $result[$key] = $this->cleanCustomScalar($item);
            }

            return $result;
        }

        return $this->cleanCustomScalar($value);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function cleanCustomScalar($value)
    {
        $text = (string) xss()->text($value);

        return mb_substr($text, 0, self::$customLimits['length']);
    }

    /**
     * 提交来源：模板隐藏域优先，其次 HTTP_REFERER，都没有存空串。
     *
     * @param string $sourceUrl
     * @return string
     */
    private function resolveSourceUrl($sourceUrl)
    {
        $sourceUrl = trim((string) $sourceUrl);
        if ($sourceUrl === '' && !empty($_SERVER['HTTP_REFERER'])) {
            $sourceUrl = (string) $_SERVER['HTTP_REFERER'];
        }

        // 后台详情把 source_url 直接放进 <a href>，必须限定协议：
        // xss()->text() 只去标签，挡不住 javascript: / data: 协议
        $sourceUrl = (string) xss()->text($sourceUrl);
        if ($sourceUrl !== '' && !preg_match('#^https?://#i', $sourceUrl)) {
            return '';
        }

        return $sourceUrl;
    }

    /**
     * 同 IP 最近一小时提交数。
     *
     * @param int $formId
     * @param string $ip
     * @return int
     */
    private function countRecentByIp($formId, $ip)
    {
        if ($ip === '') {
            return 0;
        }

        return (int) DB::table('form_data')
            ->where('form_id', (int) $formId)
            ->where('ip', $ip)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))
            ->count();
    }

    /**
     * 详情页默认值：select / radio 无默认时取首个选项。
     *
     * @param array $fieldList
     * @return array
     */
    private function buildPostData(array $fieldList)
    {
        $data = array();
        foreach ($fieldList as $field) {
            $default = isset($field['default']) ? (string) $field['default'] : '';
            if ($default === ''
                && in_array($field['type'], array('select', 'radio'), true)
                && !empty($field['option'])
            ) {
                $default = (string) $field['option'][0];
            }
            $data[$field['slug']] = $default;
        }

        return $data;
    }

    /**
     * 会员登录页地址。
     *
     * @return string
     */
    private function loginUrl()
    {
        return route('user.login');
    }

    /**
     * 站点首页地址（CLI 环境无 HOME_URL 常量时回落 ROOT_URL）。
     *
     * @return string
     */
    private function homeUrl()
    {
        if (defined('HOME_URL')) {
            return HOME_URL;
        }

        return defined('ROOT_URL') ? ROOT_URL : '/';
    }

    /**
     * 失败结果。
     *
     * @param string $message
     * @param string $redirectUrl
     * @param array $errors
     * @return array
     */
    private function failure($message, $redirectUrl = '', array $errors = array())
    {
        return array(
            'ok' => false,
            'message' => (string) $message,
            'redirect_url' => (string) $redirectUrl,
            'errors' => $errors,
        );
    }
}
