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

namespace Dou\Admin\Service\Data;

use Dou\Admin\Model\Data\Data;
use Dou\Admin\Service\Concerns\SavesLanguageDrafts;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Data\DataTemplate;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Str;
use Dou\Core\Web\Http\UploadedFile;
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台「数据」碎片化内容管理（`index.php?route=data/...`，表 `data`）。
 *
 * 职责：
 * - 分组列表、增删改、图片与 code 维护、主题下图片目录。
 * - 锁定编辑。
 */
class DataService extends BaseService
{
    use SavesLanguageDrafts;

    /**
     * 不参与「站内页」判定的数据分组（横幅/公用/首页/小程序）。
     *
     * @return array 分组名列表
     */
    private function noInPageList()
    {
        return array('index', 'common', 'banner', 'miniprogram');
    }

    /**
     * 按数据分组推导侧边栏高亮标识 cur。
     *
     * 全局分组（index/common/banner/box）及虚拟分组 all 归属「站点首页」聚合菜单，
     * 返回 'data'；小程序分组（miniprogram）归属小程序模块菜单，返回 'miniprogram'；
     * 模块归属分组（page/article/...）返回分组名本身，以便侧边栏高亮对应模块菜单
     * 而非 site_home。
     *
     * @param string $dataGroup 数据分组
     * @return string
     */
    private function moduleCur($dataGroup)
    {
        $dataGroup = (string) $dataGroup;
        if ($dataGroup === 'miniprogram') {
            return 'miniprogram';
        }

        if ($dataGroup === '' || $dataGroup === 'all' || $dataGroup === 'box' || in_array($dataGroup, $this->noInPageList(), true)) {
            return 'data';
        }

        return $dataGroup;
    }

    /**
     * 数据分组所属的模板标识（写入 / 校验 dou_data.theme 用）。
     *
     * 小程序分组关联当前启用的小程序模板包（site.miniprogram_code，如 default / shop），
     * 其余分组关联当前网站主题，见 {@see DataTemplate::codeForGroup()}。
     *
     * @param string $dataGroup 数据分组
     * @return string
     */
    private function themeForGroup($dataGroup)
    {
        return DataTemplate::codeForGroup($dataGroup);
    }

    /**
     * data 主图在 images/data/ 下的相对子目录。
     *
     * 小程序分组按 `miniprogram/<模板标识>` 落盘：站点主题与小程序模板包同名时（如都叫
     * default），两者共用 `data/<模板标识>/` 会互相覆盖；其余分组沿用历史布局 `<模板标识>/`。
     *
     * @param string $dataGroup 数据分组
     * @param string $theme     记录所属模板标识
     * @return string
     */
    private function uploadDirectory($dataGroup, $theme)
    {
        if ((string) $dataGroup === DataTemplate::MINIPROGRAM_GROUP) {
            return DataTemplate::MINIPROGRAM_GROUP . '/' . (string) $theme;
        }

        return (string) $theme;
    }

    /**
     * 按 code 查父级记录（新增子项 / 编辑页展示父级用）。
     *
     * 优先在目标分组所属模板里找，找不到再依次回落到网站主题、小程序模板，
     * 兼容子项入口未携带 group 参数的历史链接。
     *
     * @param string $parentCode 父级 code
     * @param string $dataGroup  目标数据分组（可能为空）
     * @return \Dou\Core\Orm\Model|null
     */
    private function findParentByCode($parentCode, $dataGroup)
    {
        $themes = array($this->themeForGroup($dataGroup));
        $candidates = array(
            Config::get('site.site_theme', ''),
            Config::get('site.miniprogram_code', ''),
        );
        foreach ($candidates as $candidate) {
            $candidate = (string) $candidate;
            if ($candidate !== '' && !in_array($candidate, $themes, true)) {
                $themes[] = $candidate;
            }
        }

        foreach ($themes as $theme) {
            if ((string) $theme === '') {
                continue;
            }
            $parent = Data::findByCodeAndTheme($parentCode, (string) $theme);
            if ($parent) {
                return $parent;
            }
        }

        return null;
    }

    /**
     * 生成全局唯一的随机 code（跨主题不重复）。冲突则递归重试。
     *
     * @return string
     */
    public function createCode()
    {
        $code = Str::randomByType('letter');
        if (Data::existsByCode($code)) {
            return $this->createCode();
        }

        return $code;
    }

    /**
     * 按父级 code 生成顺序子 code：{parent}_a、{parent}_b …（超过 z 后进位为 aa、ab …）。
     *
     * 仅统计目标模板下形如 {parent}_{字母} 的 code，取最大序号的下一个；
     * 无同类记录时从 _a 开始。已删除的字母不复用，避免与历史链接/缓存冲突。
     *
     * @param string $parentCode 父级 code
     * @param string $theme      目标模板标识（空则取当前网站主题）
     * @return string
     */
    public function createChildCode($parentCode, $theme = '')
    {
        $parentCode = (string) $parentCode;
        if ($parentCode === '') {
            return $this->createCode();
        }

        $theme = (string) $theme !== '' ? (string) $theme : (string) Config::get('site.site_theme', '');
        $pattern = '/^' . preg_quote($parentCode, '/') . '_([a-z]+)$/i';
        $max = 0;
        foreach (Data::findAllCodesByTheme($theme) as $code => $id) {
            if (preg_match($pattern, (string) $code, $matches)) {
                $number = $this->alphaToNumber(strtolower($matches[1]));
                if ($number > $max) {
                    $max = $number;
                }
            }
        }

        return $parentCode . '_' . $this->numberToAlpha($max + 1);
    }

    /**
     * 字母序号转数字（a=1、z=26、aa=27 …）。
     *
     * @param string $alpha 小写字母串
     * @return int
     */
    private function alphaToNumber($alpha)
    {
        $number = 0;
        $length = strlen($alpha);
        for ($i = 0; $i < $length; $i++) {
            $number = $number * 26 + (ord($alpha[$i]) - 96);
        }

        return $number;
    }

    /**
     * 数字转字母序号（1=a、26=z、27=aa …）。
     *
     * @param int $number
     * @return string
     */
    private function numberToAlpha($number)
    {
        $alpha = '';
        $number = (int) $number;
        while ($number > 0) {
            $number--;
            $alpha = chr(97 + $number % 26) . $alpha;
            $number = (int) ($number / 26);
        }

        return $alpha;
    }

    /**
     * 后台编辑页「返回」链接（按 data_group / data_item 推导）。
     *
     * 输出路径式路由（与 admin/init/route.php、Router 约定一致）。
     *
     * @param mixed  $dataGroup 数据分组（如 common / banner / index / miniprogram / 业务模块名）
     * @param string $dataItem  数据子项标识（页面 slug 或业务表主键）
     * @return string 形如 index.php?route=data 或 index.php?route=article/12/edit
     */
    public function backUrl($dataGroup, $dataItem = '')
    {
        if (!$dataGroup) {
            return route('admin.data');
        }

        if ($dataGroup == 'index') {
            return route('admin.site_home');
        }

        if (in_array($dataGroup, array('common', 'banner', 'miniprogram'), true)) {
            return route('admin.data', array('group' => $dataGroup));
        }

        $id = '';
        if ($dataItem) {
            if ($dataGroup == 'page') {
                $id = DB::table('page')->where('slug', $dataItem)->value('id');
            } else {
                $id = $dataItem;
            }
        }

        if ($id) {
            return route('admin.' . $dataGroup . '.edit', array('id' => $id));
        }

        return route('admin.' . $dataGroup);
    }

    /**
     * 某分组下列表页 assign 数据（标题、新建链接、条目列表）。
     *
     * @param string $group 数据分组标识
     * @return array ur_here、action_link、group、data_list、in_page
     */
    public function buildDataListData($group)
    {
        $groupName = lang('data_' . $group);
        // 小程序分组单独命名，避免列表标题与 PC 主题的「内容片段」混淆
        if ((string) $group === DataTemplate::MINIPROGRAM_GROUP) {
            $miniprogramListName = lang('data_miniprogram_list');
            if ($miniprogramListName) {
                $groupName = $miniprogramListName;
            }
        }
        $dataList = data()->query($group);
        $noInPageList = $this->noInPageList();

        return array(
            'ur_here' => $groupName ? $groupName : lang('data_list'),
            'action_link' => array(
                'text' => lang('data_create'),
                'href' => route('admin.data.create', array('group' => $group)),
            ),
            'group' => $group,
            'data_list' => $dataList,
            'in_page' => !in_array($group, $noInPageList, true),
            'cur' => $this->moduleCur($group),
        );
    }

    /**
     * 新增表单数据：解析父级 code、生成新 code、替换语言包提示中的占位并计算 in_page。
     *
     * @param string $group          路由分组
     * @param string $parentCode     父条目 code（可选）
     * @param string $dataGroupInput 数据分组（无父级时）
     * @param string $dataItemInput  数据子项标识（无父级时）
     * @return array ur_here、action_link、data、btn_lang、lang、group、in_page
     */
    public function buildDataDefaultData($group, $parentCode, $dataGroupInput, $dataItemInput)
    {
        $noInPageList = $this->noInPageList();
        $parent = null;

        if ($parentCode) {
            $parent = $this->findParentByCode($parentCode, $dataGroupInput);
            $dataGroup = $parent ? $parent['data_group'] : '';
            $dataItem = $parent ? $parent['data_item'] : '';
        } else {
            $dataGroup = $dataGroupInput;
            $dataItem = $dataItemInput;
        }

        // 归属模板：子项继承父级所在模板，否则按分组解析（小程序分组 → miniprogram_code）
        $theme = $parent && (string) $parent['theme'] !== ''
            ? (string) $parent['theme']
            : $this->themeForGroup($dataGroup);

        $parentForTpl = array(
            'id' => 0,
            'code' => '',
            'name' => '',
        );
        if ($parent) {
            $parentForTpl = array(
                'id' => isset($parent['id']) ? $parent['id'] : 0,
                'code' => isset($parent['code']) ? $parent['code'] : '',
                'name' => isset($parent['name']) ? $parent['name'] : '',
            );
        }

        $newCode = $parentCode ? $this->createChildCode($parentCode, $theme) : $this->createCode();
        // 小程序端记录：提示改用 WXML 调用代码（{{data.code.field}}），且不提供多语言入口
        $isMiniprogram = DataTemplate::isMiniprogramRecord($dataGroup);
        $lang = $this->buildCueLang($newCode, $isMiniprogram);
        $languageForm = $isMiniprogram
            ? $this->emptyLanguageFormState()
            : $this->languageDraftService()->buildFormState('data', 0);

        return array(
            'ur_here' => lang('data_create'),
            'action_link' => array(
                'text' => lang('back') . (lang($dataGroup . '_edit')),
                'href' => $this->backUrl($dataGroup, $dataItem),
            ),
            'data' => array(
                'data_group' => $dataGroup,
                'data_item' => $dataItem,
                'theme' => $theme,
                'is_miniprogram' => $isMiniprogram,
                'parent' => $parentForTpl,
                'code' => $newCode,
                'name' => '',
                'text' => '',
                'link' => '',
                'image' => '',
                'file' => '',
                'is_class' => '0',
                'is_locked' => '0',
            ),
            'btn_lang' => $languageForm['btn_lang'],
            'lang_drafts' => $languageForm['lang_drafts'],
            'lang' => $lang,
            'group' => $dataGroup,
            'in_page' => !in_array($dataGroup, $noInPageList, true),
            'cur' => $this->moduleCur($dataGroup),
        );
    }

    /**
     * 新增一条 data：处理主图、insert 后返回回到上一编辑语境的 URL。
     *
     * @param array $post 表单字段
     * @return string 跳转 URL
     */
    public function insert(array $post)
    {
        $name = Arr::get($post, 'name', '');
        $code = Arr::get($post, 'code', '');
        $dataGroup = Arr::get($post, 'data_group', '');
        $theme = $this->themeForGroup($dataGroup);
        $this->assertDataCodeUnique($code, $theme, 0);

        $insertData = array(
            'parent_code' => Arr::get($post, 'parent_code', ''),
            'theme' => $theme,
            'data_group' => $dataGroup,
            'data_item' => Arr::get($post, 'data_item', ''),
            'name' => $name,
            'code' => $code,
            'text' => Arr::get($post, 'text', ''),
            'image' => '',
            'link' => Arr::get($post, 'link', ''),
            'is_class' => Arr::get($post, 'is_class', '0'),
        );

        $adminId = (int) auth('admin')->id();
        $image = attachment()->reserve('data', UploadedFile::fromGlobals('image'), 'admin', $adminId, 'main', AttachmentUploadOptions::create()->withBasename($code)->withDirectory($this->uploadDirectory($dataGroup, $theme)));
        $insertData['image'] = $image;
        $languagePlan = $this->languageDraftBegin('data', $post, 0, $adminId);

        try {
            $newId = (int) DB::transaction(function () use ($insertData, $image, $adminId, $languagePlan) {
                $newId = (int) Data::insertData($insertData);
                if ($newId <= 0) {
                    throw new DomainException(lang('operation_failed'));
                }
                $this->languageDraftCommit($languagePlan, $newId, $adminId);
                if ($image !== '' && attachment()->adopt('data', $image, $newId, 'admin', $adminId) < 1) {
                    throw new DomainException(lang('file_claim_failed'));
                }

                return $newId;
            });
        } catch (\Exception $e) {
            $this->languageDraftAbort($languagePlan);
            throw $e;
        }

        $this->languageDraftPurge($languagePlan, $newId);
        $backUrl = $this->backUrl(Arr::get($post, 'data_group', ''), Arr::get($post, 'data_item', ''));
        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, (string) $name);
        return $backUrl;
    }

    /**
     * 编辑表单数据：补齐图片 URL、父级信息、语言 cue 替换、in_page。
     *
     * @param string|int $id
     * @return array|null 不存在返回 null
     */
    public function buildDataEditData($id)
    {
        $noInPageList = $this->noInPageList();
        $data = Data::findById((int) $id);
        if (!$data) {
            return null;
        }

        $data = $data->getAttributes();

        $defaults = array(
            'image' => '',
            'text' => '',
            'link' => '',
            'parent_code' => '',
            'is_class' => '0',
            'is_locked' => '0',
            'name' => '',
            'code' => '',
        );
        foreach ($defaults as $key => $val) {
            if (!isset($data[$key])) {
                $data[$key] = $val;
            }
        }

        $data['file_number'] = $data['image'];
        $data['image'] = attachment()->url($data['image']);
        $data['code'] = $data['code'] ? $data['code'] : $this->createCode();
        // 小程序端记录：提示改用 WXML 调用代码，且不提供多语言入口
        $isMiniprogram = DataTemplate::isMiniprogramRecord(
            isset($data['data_group']) ? $data['data_group'] : ''
        );
        $data['is_miniprogram'] = $isMiniprogram;

        $data['parent'] = array(
            'id' => 0,
            'code' => '',
            'name' => '',
        );
        if ($data['parent_code'] !== '' && $data['parent_code'] !== null) {
            $parentRow = Data::findByCodeAndTheme($data['parent_code'], (string) $data['theme']);
            if ($parentRow) {
                $data['parent'] = array(
                    'id' => isset($parentRow['id']) ? $parentRow['id'] : 0,
                    'code' => isset($parentRow['code']) ? $parentRow['code'] : '',
                    'name' => isset($parentRow['name']) ? $parentRow['name'] : '',
                );
            }
        }

        $lang = $this->buildCueLang($data['code'], $isMiniprogram);
        $languageForm = $isMiniprogram
            ? $this->emptyLanguageFormState()
            : $this->languageDraftService()->buildFormState('data', $id);

        return array(
            'ur_here' => lang('data_edit'),
            'action_link' => array(
                'text' => lang('back') . (lang($data['data_group'] . '_edit')),
                'href' => $this->backUrl($data['data_group'], $data['data_item']),
            ),
            'data' => $data,
            'lang' => $lang,
            'group' => $data['data_group'],
            'in_page' => !in_array($data['data_group'], $noInPageList, true),
            'cur' => $this->moduleCur($data['data_group']),
            'btn_lang' => $languageForm['btn_lang'],
            'lang_drafts' => $languageForm['lang_drafts'],
        );
    }

    /**
     * 组装表单提示（cue）：按记录归属端选择 PC 模板标签或小程序 WXML 调用代码，
     * 并把「唯一标记」占位替换为实际 code。
     *
     * 简体占位为「唯一标记」、繁体为「唯一標記」，两者都替换，避免繁体后台提示不带 code。
     * 小程序版 cue 缺失时（如英文语言包未收录）回落 PC 版，不报错。
     *
     * @param string $code          片段唯一标记
     * @param bool   $isMiniprogram 是否小程序端记录（data_group 为 miniprogram）
     * @return array 语言包键值（data_*_cue 已替换）
     */
    private function buildCueLang($code, $isMiniprogram)
    {
        $lang = lang_all();
        $fields = array('name', 'code', 'image', 'text', 'link', 'class');
        foreach ($fields as $field) {
            $key = 'data_' . $field . '_cue';
            if ($isMiniprogram && isset($lang['data_mp_' . $field . '_cue'])) {
                $key = 'data_mp_' . $field . '_cue';
            }
            if (!isset($lang[$key])) {
                continue;
            }
            $lang['data_' . $field . '_cue'] = str_replace(
                array('.唯一标记', '.唯一標記'),
                '.' . (string) $code,
                (string) $lang[$key]
            );
        }

        return $lang;
    }

    /**
     * 小程序端表单不提供多语言按钮与语言草稿：返回空壳，视图据此不渲染按钮。
     *
     * 字段键保留为空串，避免模板未加条件判断时访问到不存在的键。
     *
     * @return array {btn_lang: array, lang_drafts: array}
     */
    private function emptyLanguageFormState()
    {
        return array(
            'btn_lang' => array(
                'name' => '',
                'code' => '',
                'image' => '',
                'text' => '',
                'link' => '',
            ),
            'lang_drafts' => array(),
        );
    }

    /**
     * 更新 data：code 变更时同步物理文件名，再写库并返回 backUrl。
     *
     * @param array $post 含 id、name、code 等
     * @return string 跳转 URL
     */
    public function update(array $post)
    {
        $id = intval(Arr::get($post, 'id', 0));
        $name = Arr::get($post, 'name', '');
        $code = Arr::get($post, 'code', '');

        $row = Data::findById($id, 'data_group, data_item, theme, code, image');
        if (!$row) {
            throw new DomainException(lang('illegal'), route('admin.data'));
        }
        $oldImageNumber = isset($row['image']) ? (string) $row['image'] : '';

        // 已入库记录的归属模板以库中值为准（小程序分组记录存的是 miniprogram_code）
        $theme = (string) $row['theme'] !== '' ? (string) $row['theme'] : $this->themeForGroup($row['data_group']);
        $this->assertDataCodeUnique($code, $theme, $id);

        $adminId = (int) auth('admin')->id();
        $languagePlan = $this->languageDraftBegin('data', $post, $id, $adminId);
        if ($code !== $row['code']) {
            attachment()->renameStoredFile($row['image'], $code);
        }
        $image = attachment()->store('data', $id, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withBasename($code)->withDirectory($this->uploadDirectory($row['data_group'], $theme))->withUploader('admin', $adminId));
        $deleteImage = ($image === '' && !empty($post['image_delete']));

        $updateData = array(
            'name' => $name,
            'code' => $code,
            'text' => Arr::get($post, 'text', ''),
            'link' => Arr::get($post, 'link', ''),
            'is_class' => Arr::get($post, 'is_class', '0'),
        );
        if ($image) {
            $updateData['image'] = $image;
        } elseif ($deleteImage) {
            $updateData['image'] = '';
        }

        try {
            DB::transaction(function () use ($id, $updateData, $adminId, $languagePlan) {
                Data::updateById($id, $updateData);
                $this->languageDraftCommit($languagePlan, $id, $adminId);
            });
        } catch (\Exception $e) {
            $this->languageDraftAbort($languagePlan);
            throw $e;
        }

        $this->languageDraftPurge($languagePlan, $id);
        if ($deleteImage && $oldImageNumber !== '') {
            attachment()->delete($oldImageNumber);
        }
        $backUrl = $this->backUrl($row['data_group'], $row['data_item']);
        audit()->writeAdminLog($adminId, AdminLogAction::UPDATE, 1, (string) $name);
        return $backUrl;
    }

    /**
     * 切换单条 data 的 is_locked（锁定后台编辑）。
     *
     * @param string|int $id
     * @return string 回到该条编辑页
     */
    public function lock($id)
    {
        Data::toggleLockById((int) $id);
        return route('admin.data.edit', array('id' => (int) $id));
    }

    /**
     * 删除单条 data 及其图片文件，返回分组语境下的列表/编辑返回 URL。
     *
     * @param string|int $id
     * @return string 跳转 URL
     */
    public function delete($id)
    {
        $data = Data::findById((int) $id);
        if (!$data) {
            throw new DomainException(lang('illegal'), route('admin.data'));
        }
        attachment()->delete($data['image']);
        $backUrl = $this->backUrl($data['data_group'], $data['data_item']);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $data['name']);
        Data::deleteById((int) $id);
        return $backUrl;
    }

    /**
     * 同一模板内 code 唯一性校验（code 按模板区分，不跨模板）。
     *
     * @param string $code
     * @param string $theme
     * @param int    $excludeId
     * @return void
     */
    private function assertDataCodeUnique($code, $theme, $excludeId)
    {
        $exists = Data::existsByCodeAndTheme(
            (string) $code,
            (string) $theme,
            (int) $excludeId
        );

        if ($exists) {
            throw new DomainException(lang('data_code_existed'), route('admin.data'));
        }
    }
}
