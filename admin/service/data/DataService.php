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
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
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
            $parent = Data::findByCodeAndTheme($parentCode, Config::get('site.site_theme', ''));
            $dataGroup = $parent ? $parent['data_group'] : '';
            $dataItem = $parent ? $parent['data_item'] : '';
        } else {
            $dataGroup = $dataGroupInput;
            $dataItem = $dataItemInput;
        }

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

        $newCode = $this->createCode();
        $lang = lang_all();
        $langArray = array('name', 'code', 'image', 'text', 'link', 'class');
        foreach ($langArray as $value) {
            if (isset($lang['data_' . $value . '_cue'])) {
                $lang['data_' . $value . '_cue'] = preg_replace(
                    '/.唯一标记/Ums',
                    '.' . $newCode,
                    $lang['data_' . $value . '_cue']
                );
            }
        }

        return array(
            'ur_here' => lang('data_create'),
            'action_link' => array(
                'text' => lang('back') . (lang($dataGroup . '_edit')),
                'href' => $this->backUrl($dataGroup, $dataItem),
            ),
            'data' => array(
                'data_group' => $dataGroup,
                'data_item' => $dataItem,
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
            'btn_lang' => language()->buildLangButtons('data', '', 'name, image, text, link'),
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
        $this->assertDataCodeUnique($code, 0);

        $insertData = array(
            'parent_code' => Arr::get($post, 'parent_code', ''),
            'theme' => Config::get('site.site_theme', ''),
            'data_group' => Arr::get($post, 'data_group', ''),
            'data_item' => Arr::get($post, 'data_item', ''),
            'name' => $name,
            'code' => $code,
            'text' => Arr::get($post, 'text', ''),
            'image' => '',
            'link' => Arr::get($post, 'link', ''),
            'is_class' => Arr::get($post, 'is_class', '0'),
        );

        $newId = (int) Data::insertData($insertData);

        $image = attachment()->store('data', $newId, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withBasename($code)->withDirectory(Config::get('site.site_theme', ''))->withUploader('admin', (int) auth('admin')->id()));
        if ($image !== '') {
            Data::updateById($newId, array('image' => $image));
        }

        $backUrl = $this->backUrl(Arr::get($post, 'data_group', ''), Arr::get($post, 'data_item', ''));
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $name);
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

        $data['parent'] = array(
            'id' => 0,
            'code' => '',
            'name' => '',
        );
        if ($data['parent_code'] !== '' && $data['parent_code'] !== null) {
            $parentRow = Data::findByCodeAndTheme($data['parent_code'], Config::get('site.site_theme', ''));
            if ($parentRow) {
                $data['parent'] = array(
                    'id' => isset($parentRow['id']) ? $parentRow['id'] : 0,
                    'code' => isset($parentRow['code']) ? $parentRow['code'] : '',
                    'name' => isset($parentRow['name']) ? $parentRow['name'] : '',
                );
            }
        }

        $lang = lang_all();
        $langArray = array('name', 'code', 'image', 'text', 'link', 'class');
        foreach ($langArray as $value) {
            if (isset($lang['data_' . $value . '_cue'])) {
                $lang['data_' . $value . '_cue'] = preg_replace(
                    '/.唯一标记/Ums',
                    '.' . $data['code'],
                    $lang['data_' . $value . '_cue']
                );
            }
        }

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
            'btn_lang' => language()->buildLangButtons('data', $id, 'name, image, text, link'),
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
        $this->assertDataCodeUnique($code, $id);

        $row = Data::findById($id, 'data_group, data_item, code, image');
        if (!$row) {
            throw new DomainException(lang('illegal'), route('admin.data'));
        }

        if ($code !== $row['code']) {
            attachment()->renameStoredFile($row['image'], $code);
        }
        $image = attachment()->store('data', $id, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withBasename($code)->withDirectory(Config::get('site.site_theme', ''))->withUploader('admin', (int) auth('admin')->id()));

        $updateData = array(
            'name' => $name,
            'code' => $code,
            'text' => Arr::get($post, 'text', ''),
            'link' => Arr::get($post, 'link', ''),
            'is_class' => Arr::get($post, 'is_class', '0'),
        );
        if ($image) {
            $updateData['image'] = $image;
        }

        Data::updateById($id, $updateData);
        $backUrl = $this->backUrl($row['data_group'], $row['data_item']);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $name);
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
     * @param string $code
     * @param int $excludeId
     * @return void
     */
    private function assertDataCodeUnique($code, $excludeId)
    {
        $exists = Data::existsByCodeAndTheme(
            (string) $code,
            Config::get('site.site_theme', ''),
            (int) $excludeId
        );

        if ($exists) {
            throw new DomainException(lang('data_code_existed'), route('admin.data'));
        }
    }
}
