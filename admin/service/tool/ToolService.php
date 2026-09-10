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

namespace Dou\Admin\Service\Tool;

use Dou\Admin\Model\Tool\Tool;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\FileHelper;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台杂项工具（`index.php?route=tool/...`）。
 *
 * 职责：目录可写检测、正文域批量换域名、后台目录更名辅助脚本源码、Ajax 布尔字段文案、编辑器切换、排序模式。
 */
class ToolService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * @return Tool
     */
    protected function toolModel()
    {
        return new Tool();
    }

    /**
     * 目录权限检测页数据。
     *
     * @return array 含键 writeable_list
     */
    public function buildDirectoryCheckData()
    {
        return array(
            'writeable_list' => $this->buildWriteableListRows(),
        );
    }

    /**
     * 关键目录读写检测结果（缓存、data、images、theme 等），供环境自检页展示。
     *
     * @return array
     */
    protected function buildWriteableListRows()
    {
        $check_dirs = array();
        $check_dirs[] = array(
            'note' => '运行时存储目录，需要“读写权限”，如果权限不足将造成网站无法运行',
            'dir' => 'storage',
        );
        $check_dirs[] = array(
            'note' => '模板编译目录.后台',
            'dir' => 'storage/cache/template/' . ADMIN_DIR,
        );
        if (file_exists(ROOT_PATH . M_DIR)) {
            $check_dirs[] = array(
                'note' => '模板编译目录.手机版',
                'dir' => 'storage/cache/template/' . M_DIR,
            );
        }
        if (file_exists(ROOT_PATH . MINIPROGRAM_DIR)) {
            $check_dirs[] = array(
                'note' => '模板编译目录.小程序',
                'dir' => 'storage/cache/template/' . MINIPROGRAM_DIR,
            );
        }
        $check_dirs[] = array(
            'note' => '图片目录.首页幻灯广告',
            'dir' => 'images/slide',
        );
        $check_dirs[] = array(
            'note' => '运行时存储目录.数据备份',
            'dir' => 'storage/backup',
        );
        $check_dirs[] = array(
            'note' => '文件目录，需要“读写权限”，如果缺少写入权限，将无法上传产品图片、文章图片以及其他扩展模块图片上传（其它扩展模块不 会在这里做出提示，但如果出现问题，以此类推排除目录问题）',
            'dir' => 'images',
        );
        $check_dirs[] = array(
            'note' => '文件目录.文章',
            'dir' => 'images/article',
        );
        $check_dirs[] = array(
            'note' => '文件目录.产品',
            'dir' => 'images/product',
        );
        $check_dirs[] = array(
            'note' => '模板目录，需要“读写权限”，如果缺少写入权限，将无法在线下载模板',
            'dir' => 'theme',
        );
        if (file_exists(ROOT_PATH . M_DIR)) {
            $check_dirs[] = array(
                'note' => '模板目录.手机版',
                'dir' => M_DIR . '/theme',
            );
        }

        $writeable_list = array();
        foreach ($check_dirs as $row) {
            $full_dir = ROOT_PATH . $row['dir'];
            $check_writeable = FileHelper::permission($full_dir);
            if ($check_writeable == 'write') {
                $status_text = lang('write');
                $class = 'write';
            } elseif ($check_writeable == 'no_write') {
                $status_text = lang('no_write');
                $class = 'noWrite';
            } else {
                $status_text = lang('not_exist');
                $class = 'noWrite';
            }

            $writeable_list[] = array(
                'note' => $row['note'],
                'dir' => $row['dir'],
                'status_text' => $status_text,
                'class' => $class,
            );
        }

        return $writeable_list;
    }

    /**
     * 编辑器网址替换页展示数据。
     *
     * @return array
     */
    public function buildReplaceUrlPageData()
    {
        return array(
            'action_link' => array(
                'text' => lang('setting_developer'),
                'href' => route('admin.setting', array(), array('query' => array('dou' => ''))),
            ),
        );
    }

    /**
     * 提交网址替换（字段已由 {@see \Dou\Admin\Request\Tool\ToolFormRequest} 校验与白名单）。
     *
     * @param array $validated
     * @return void
     */
    public function storeReplaceUrl(array $validated)
    {
        // 原始值直传：Tool::replaceUrlInContentTables 经 setField 的 $binds 参数化绑定，
        // 不再做 SQL 字面量转义（否则会双重转义把 \ ' 等写进正文）。
        $old_url = (string) $validated['old_url'];
        $new_url = (string) $validated['new_url'];

        $column = (array) Config::get('module.column_module', array());
        $single = (array) Config::get('module.single_module', array());

        $this->toolModel()->replaceUrlInContentTables($old_url, $new_url, $column, $single);
    }

    /**
     * 生成 storage/state 下自定义后台路径更名临时脚本 PHP 源码（落地写文件由 Controller 负责）。
     *
     * 脚本由浏览器直接访问 storage/state/custom_admin_dir.candel.php 触发；
     * 内部需要把：①站点根 ②管理员模板编译目录 ③$admining 配置文件
     * 三处的绝对路径 / URL 与本仓库 storage 布局保持一致。
     *
     * @param string $session_key 会话校验键名
     * @param string $session_value 传入值占位（源码内拼接）
     * @return string 完整脚本文本
     */
    public function buildCustomAdminDirScriptSource($session_key, $session_value)
    {
        $text = '<?php
            session_start();
            error_reporting(E_ALL ^ (E_NOTICE | E_WARNING));
            header(\'Content-type: text/html; charset=utf-8\');
            $site_path = str_replace(\'storage/state/custom_admin_dir.candel.php\', \'\', str_replace(\'\\\\\', \'/\', __FILE__));
            $root_url = str_replace(\'storage/state\', \'\', dirname(\'http://\' . $_SERVER[\'HTTP_HOST\'] . $_SERVER[\'PHP_SELF\']));
            $old_dir = preg_match("/^[A-Za-z0-9._-]+$/", $_REQUEST[\'old_dir\']) ? trim($_REQUEST[\'old_dir\'], ".php") : "";
            $new_dir = preg_match("/^[A-Za-z0-9._-]+$/", $_REQUEST[\'new_dir\']) ? trim($_REQUEST[\'new_dir\'], ".php") : "";

            if (isset($_SESSION[\'' . $session_key . '\']) && isset($_REQUEST[\'' . $session_key . '\'])) {
                if ($_SESSION[\'' . $session_key . '\'] != $_REQUEST[\'' . $session_key . '\']) {
                    header("Location: " . $root_url);
                    exit;
                }
            } else {
                header("Location: " . $root_url);
                exit;
            }

            // 重命名后台目录与对应的模板编译目录
            if ($old_dir && $new_dir && @rename($site_path . $old_dir, $site_path . $new_dir)) {
                @rename($site_path . \'storage/cache/template/\' . $old_dir, $site_path . \'storage/cache/template/\' . $new_dir);
                echo "修改成功 3 秒后跳转到新后台地址……";
                file_put_contents($site_path . "config/admin_dir.php", \'<?php $admining = \' . "\'" . $new_dir . "\'" . \' ?>\');
                $path = $new_dir;
            } else {
                echo "修改失败 3 秒后跳转回原后台地址……";
                $path = $old_dir;
            }
            unset($_SESSION[\'' . $session_key . '\']);
            @unlink($site_path . \'storage/state/custom_admin_dir.candel.php\');
            header("refresh:3; url=" . $root_url . $path);
            exit;
            ?>';

        return $text;
    }

    /**
     * 自定义后台目录页：会话校验值、更名脚本源码、开发者入口链接。
     *
     * @return array session_key, session_value, script_source, action_link
     */
    public function buildCustomAdminDirPageData()
    {
        $session_key = Str::randomByType('letter', 6);
        $session_value = Str::randomByType('number', 6);
        $_SESSION[$session_key] = $session_value;

        $script_source = $this->buildCustomAdminDirScriptSource($session_key, $session_value);

        return array(
            'session_key' => $session_key,
            'session_value' => $session_value,
            'script_source' => $script_source,
            'action_link' => array(
                'text' => lang('setting_developer'),
                'href' => route('admin.setting', array(), array('query' => array('dou' => ''))),
            ),
        );
    }

    /**
     * 将表中某布尔字段 0/1 翻转（供 Ajax）。
     *
     * @param string $module 表/模块逻辑名
     * @param string $item_id 主键
     * @param string $field 字段名
     * @return array {value:int 新值, label:string 展示文案}
     */
    public function toggleTableField($module, $item_id, $field)
    {
        $model = $this->toolModel();
        $value = $model->getTableFieldValue($module, $item_id, $field);
        $new_value = $value ? 0 : 1;

        $model->updateTableField($module, $item_id, $field, $new_value);

        $key = $module . '_' . $field . '_' . $new_value;
        $label = lang($key) !== '' ? lang($key) : lang('status_' . $new_value);

        return array('value' => (int) $new_value, 'label' => (string) $label);
    }

    /**
     * 在传统 editor 与 vditor 间切换配置值。
     *
     * @param string $currentEditorValue config.editor 当前值
     * @return string 切换后的 editor 配置值
     */
    public function getToggledEditorValue($currentEditorValue)
    {
        return $currentEditorValue == 'editor' ? 'vditor' : 'editor';
    }

    /**
     * 写入 config.editor 切换结果。
     *
     * @return void
     */
    public function persistEditorToggle()
    {
        $editor = $this->getToggledEditorValue(Config::get('site.editor', ''));
        $this->toolModel()->updateConfigValueByName('editor', $editor);
    }

    /**
     * 列表拖拽排序辅助：reset 将 module 全部 sort 置 50；open/close 写入会话开关。
     *
     * @param string $act reset|open|close
     * @param string $module 表/模块逻辑名（reset 时用）
     * @param string $douSessionId 会话键名后缀
     * @return void
     */
    public function applySortTool($act, $module, $douSessionId)
    {
        if ($act === 'reset') {
            DB::table($module)->where('id', '>', 0)->update(array('sort' => 50));
        } else {
            $_SESSION[$douSessionId]['sort'] = ($act === 'open') ? true : false;
        }
    }
}
