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
use Dou\Core\Foundation\Exception\DomainException;
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
     * 关键目录读写检测结果（storage 运行时存储全目录 + 图片 / 模板目录），供环境自检页展示。
     *
     * storage 根及其全部子目录均要求可读写：部分子目录由功能在运行时按需生成，
     * 这里先确保必需目录存在，再按磁盘实况递归逐个检测，避免对缺失目录误报。
     *
     * @return array
     */
    protected function buildWriteableListRows()
    {
        $check_dirs = array();

        // 运行时存储：state 目录承载 admin_dir.php / cdkey.php 等运行时状态，先确保存在
        if (!is_dir(STORAGE_PATH . 'state')) {
            @mkdir(STORAGE_PATH . 'state', 0777, true);
        }

        $check_dirs[] = array(
            'note' => '运行时存储根目录，需要“读写权限”，如果权限不足将造成网站无法运行',
            'dir' => 'storage',
        );
        foreach ($this->collectStorageSubDirs(STORAGE_PATH) as $sub) {
            $check_dirs[] = array(
                'note' => '运行时存储子目录',
                'dir' => 'storage/' . $sub,
            );
        }

        $check_dirs[] = array(
            'note' => '图片目录.首页幻灯广告',
            'dir' => 'images/slide',
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
     * 递归收集目录下全部已存在的子目录（相对路径，/ 分隔，按名排序）。
     *
     * @param string $base 绝对路径（以 / 结尾）
     * @param string $prefix 递归时拼在返回值前的相对前缀
     * @return array 子目录相对路径列表（不含 $base 本身）
     */
    private function collectStorageSubDirs($base, $prefix = '')
    {
        $dirs = array();
        foreach ((array) glob($base . '*', GLOB_ONLYDIR | GLOB_NOSORT) as $entry) {
            $name = basename($entry);
            $rel = $prefix === '' ? $name : $prefix . '/' . $name;
            $dirs[] = $rel;
            $dirs = array_merge($dirs, $this->collectStorageSubDirs($entry . '/', $rel));
        }
        sort($dirs);

        return $dirs;
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
     * 自定义后台目录页数据。
     *
     * @return array action_link
     */
    public function buildCustomAdminDirPageData()
    {
        // 顺带兜底清理历史残留的引导脚本（正常流程执行后自删）
        $this->removeStaleRelocateScripts();

        return array(
            'action_link' => array(
                'text' => lang('setting_developer'),
                'href' => route('admin.setting', array(), array('query' => array('dou' => ''))),
            ),
        );
    }

    /**
     * 后台目录更名准备（两阶段，兼容 Windows）。
     *
     * Windows 下当前请求进程持有 admin/index.php 句柄，同请求内 rename 后台目录必然失败，
     * 因此真正的 rename 必须延后到独立请求执行。本方法只做校验，并把校验通过的目标目录
     * 固化进一次性引导脚本（storage/cache/admin_dir_relocate_{token}.php），由浏览器 302
     * 跳转触发执行；引导脚本不在被改名目录内、执行时句柄已释放，Windows/Linux 均可成功。
     *
     * 目录名只接受「字母、数字、点、下划线、横杠」（新名不允许大写字母），并排除会与
     * 站内既有目录冲突的名字。
     *
     * @param string $oldDir 当前后台目录名
     * @param string $newDir 目标后台目录名
     * @return string 引导脚本 URL；新旧目录同名时返回空串（无需改名）
     * @throws DomainException 名称非法、含大写、目标已存在或脚本落盘失败时
     */
    public function prepareAdminDirRename($oldDir, $newDir)
    {
        $backUrl = route('admin.tool.custom_admin_dir');
        $oldDir = trim((string) $oldDir);
        $newDir = trim((string) $newDir);

        if (!$this->isValidAdminDirName($oldDir) || !$this->isValidAdminDirName($newDir)) {
            throw new DomainException(lang('tool_custom_admin_dir_cue'), $backUrl);
        }

        // 新目录名不允许大写字母：Windows 文件系统不区分大小写，禁止大写可避免
        // 「仅大小写不同」的目录在目标存在性判断上出现跨平台行为不一致。
        if ($newDir !== strtolower($newDir)) {
            throw new DomainException(lang('tool_custom_admin_dir_lowercase'), $backUrl);
        }

        if ($oldDir === $newDir) {
            return '';
        }

        $oldPath = ROOT_PATH . $oldDir;
        $newPath = ROOT_PATH . $newDir;
        if (!is_dir($oldPath)) {
            throw new DomainException(lang('illegal'), $backUrl);
        }
        if (file_exists($newPath)) {
            throw new DomainException(lang('tool_custom_admin_dir_occupied'), $backUrl);
        }

        $this->removeStaleRelocateScripts();

        $token = Str::randomHex(16);
        $scriptPath = STORAGE_PATH . 'cache/admin_dir_relocate_' . $token . '.php';
        if (@file_put_contents($scriptPath, self::buildRelocateScript($oldDir, $newDir, $token)) === false) {
            throw new DomainException(lang('tool_custom_admin_dir_write_fail'), $backUrl);
        }

        // token 需同时作为 query 参数回传（脚本据此校验执行权限）
        return ROOT_URL . 'storage/cache/admin_dir_relocate_' . $token . '.php?token=' . $token;
    }

    /**
     * 清理超过 1 小时未被执行的残留引导脚本（浏览器未跟随跳转时的兜底）。
     *
     * @return void
     */
    public function removeStaleRelocateScripts()
    {
        $expired = time() - 3600;
        foreach ((array) glob(STORAGE_PATH . 'cache/admin_dir_relocate_*.php') as $file) {
            if (is_file($file) && (int) filemtime($file) < $expired) {
                @unlink($file);
            }
        }
    }

    /**
     * 生成后台目录一次性引导脚本源码。
     *
     * 脚本自包含、零请求输入（old/new/token 已固化）：token 双重校验（文件名 + query，
     * hash_equals）→ 1 小时有效期 → 目标冲突复查 → rename 后台目录（失败重试 3 次，
     * 应对杀软/索引服务瞬时锁）→ 同步改名模板编译目录 → 写 storage/state/admin_dir.php
     * → 自删除 → 输出极简结果页（meta refresh + 手动链接兜底）。
     *
     * @param string $oldDir 当前后台目录名（已过框架校验）
     * @param string $newDir 目标后台目录名（已过框架校验）
     * @param string $token 一次性执行令牌
     * @return string 脚本源码
     */
    private static function buildRelocateScript($oldDir, $newDir, $token)
    {
        return '<?php
// 后台目录一次性引导脚本（系统自动生成，执行后自删除，请勿手工修改）。
$OLD = ' . var_export($oldDir, true) . ';
$NEW = ' . var_export($newDir, true) . ';
$TOKEN = ' . var_export($token, true) . ';
$BORN = ' . time() . ';

if (!isset($_GET["token"]) || !hash_equals($TOKEN, (string) $_GET["token"])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Denied");
}
if (time() - $BORN > 3600) {
    @unlink(__FILE__);
    header("HTTP/1.1 410 Gone");
    exit("Expired");
}

$root = dirname(dirname(__DIR__));
$storage = dirname(__DIR__);

$ok = false;
for ($i = 0; $i < 3 && !$ok; $i++) {
    $ok = @rename($root . "/" . $OLD, $root . "/" . $NEW);
    if (!$ok) {
        usleep(300000);
    }
}

if ($ok) {
    $compile = $storage . "/cache/template/";
    if (is_dir($compile . $OLD) && !file_exists($compile . $NEW)) {
        @rename($compile . $OLD, $compile . $NEW);
    }
    $stateDir = $storage . "/state/";
    if (!is_dir($stateDir)) {
        @mkdir($stateDir, 0777, true);
    }
    @file_put_contents($stateDir . "admin_dir.php", "<?php\n\$admining = " . var_export($NEW, true) . ";\n");
}

@unlink(__FILE__);

// SCRIPT_NAME 首次 dirname 剥去文件名，再到站点根共需三层
$base = rtrim(str_replace("\\\\", "/", dirname(dirname(dirname($_SERVER["SCRIPT_NAME"])))), "/");
$target = $base . "/" . ($ok ? $NEW : $OLD) . "/";
header("Content-type: text/html; charset=utf-8");
echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>" . ($ok ? "修改成功" : "修改失败") . "</title>";
echo "<meta http-equiv=\"refresh\" content=\"3; url=" . $target . "\"></head><body>";
echo "<p>" . ($ok ? "后台目录修改成功，3 秒后跳转到新后台地址……" : "后台目录修改失败（目录可能被占用），请稍后重试，3 秒后返回原后台……") . "</p>";
echo "<p><a href=\"" . $target . "\">如未自动跳转，请点此进入</a></p>";
echo "</body></html>";
';
    }

    /**
     * 后台目录名是否合法。
     *
     * 仅允许「字母、数字、点、下划线、横杠」，且不得为 `.`/`..`、不得占用站内既有顶级目录。
     *
     * @param string $dir
     * @return bool
     */
    private function isValidAdminDirName($dir)
    {
        if ($dir === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $dir)) {
            return false;
        }
        if ($dir === '.' || $dir === '..') {
            return false;
        }

        $reserved = array('core', 'config', 'storage', 'images', 'theme', 'front', 'api', 'install', 'upgrade', 'languages', 'miniprogram', 'plugin');

        return !in_array(strtolower($dir), $reserved, true);
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
