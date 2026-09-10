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

namespace Dou\Admin\Service\Index;

use Dou\Admin\Service\Cache\CacheClearService;
use Dou\Admin\Service\Manager\ManagerService;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\FileHelper;
use Dou\Core\Web\I18n\JsLangExporter;
use Dou\Core\Web\Manifest\ManifestCacheGeneration;
use Dou\Core\Web\Routing\JsRouteExporter;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台控制台首页数据（IndexController）。
 */
class IndexService extends BaseService
{
    /** @var CacheClearService */
    private $cacheClear;

    /**
     * @param CacheClearService $cacheClear
     */
    public function __construct(CacheClearService $cacheClear)
    {
        $this->cacheClear = $cacheClear;
    }

    /**
     * @return void
     */
    public function clearAllCache()
    {
        ManifestCacheGeneration::bump();
        $this->cacheClear->clearCache(STORAGE_PATH . 'cache/template');
        JsRouteExporter::clearCachedManifests();
        JsLangExporter::clearCachedLangScripts();
    }

    /**
     * 域名缓存文件维护（storage/cache/domain.php）
     *
     * @return array redirectUrl 非空时需跳转处理；hadDomainCacheFile 为真时模板显示 cache_root_url_cue
     */
    public function runDomainCacheMaintenance()
    {
        $result = array(
            'redirect_url' => '',
            'had_domain_cache_file' => false,
        );
        $domain_cache_file = STORAGE_PATH . 'cache/domain.php';
        if (file_exists($domain_cache_file)) {
            $result['had_domain_cache_file'] = true;
            include_once $domain_cache_file;
            if (isset($_DOMAIN) && $_DOMAIN != ROOT_URL) {
                @unlink($domain_cache_file);
                $result['redirect_url'] = route('admin.index');
            }
            return $result;
        }

        $this->cacheClear->clearCache(STORAGE_PATH . 'cache/template');

        $domain_val = (Config::get('site.domain', '') !== '') ? Config::get('site.domain', '') : ROOT_URL;
        $domain_text = '<?php' . "\r\n";
        $domain_text .= '$_DOMAIN = \'' . str_replace("'", "\\'", $domain_val) . '\';' . "\r\n";
        $domain_text .= '?>';
        $cacheDir = dirname($domain_cache_file);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        file_put_contents($domain_cache_file, $domain_text);

        return $result;
    }

    /**
     * 站点网址未写入 config 时，用当前 ROOT_URL 更新 domain
     *
     * @return void
     */
    public function syncEmptyConfigDomainToRootUrl()
    {
        if (empty(Config::get('site.domain', ''))) {
            DB::table('config')->where('name', 'domain')->update(array('value' => ROOT_URL));
        }
    }

    /**
     * 配置的站点网址与当前访问 ROOT_URL 不一致时提示设置
     *
     * @return bool
     */
    public function shouldCueSetDomainFromConfig()
    {
        return !empty(Config::get('site.domain', '')) && rtrim((string) Config::get('site.domain', ''), '/') !== rtrim(ROOT_URL, '/');
    }

    /**
     * 后台首页系统信息
     *
     * @return array
     */
    public function buildSysInfo()
    {
        $yes = lang('yes', 'Yes');
        $no = lang('no', 'No');

        $warning = array();
        if (FileHelper::permission(ROOT_PATH . 'install') != 'no_exist') {
            $warning[] = lang('warning_install_exists');
        }
        if (FileHelper::permission(ROOT_PATH . 'upgrade') != 'no_exist') {
            $warning[] = lang('warning_upgrade_exists');
        }
        $candel = STORAGE_PATH . 'state/custom_admin_path.candel.php';
        if (file_exists($candel)) {
            @unlink($candel);
        }

        $check_dirs = array(
            STORAGE_PATH . 'cache/template',
            STORAGE_PATH,
            ROOT_PATH . 'images',
        );
        foreach ($check_dirs as $dir) {
            if (!is_dir($dir)) {
                $warning[] = $dir . ' not found';
            } elseif (!is_writable($dir)) {
                $warning[] = $dir . ' not writable';
            }
        }
        $warning = array_values(array_filter($warning));

        $build_date = '';
        if (!empty(Config::get('site.build_date', 0))) {
            $build_date = date('Y-m-d', (int) Config::get('site.build_date', 0));
        } else {
            $install_lock = STORAGE_PATH . 'install.lock';
            $build_date = file_exists($install_lock) ? date('Y-m-d', filemtime($install_lock)) : '';
        }

        $update = '';
        $patch = '';
        if (!empty(Config::get('site.update_date', ''))) {
            $update_date = @unserialize(Config::get('site.update_date', ''));
            if (is_array($update_date) && isset($update_date['system']) && is_array($update_date['system'])) {
                $update = isset($update_date['system']['update']) ? $update_date['system']['update'] : '';
                $patch = isset($update_date['system']['patch']) ? $update_date['system']['patch'] : '';
            }
        }

        $logo = '';
        if (!empty(Config::get('site.site_theme', '')) && Config::get('site.site_logo', '') !== '') {
            $logo = ROOT_URL . 'theme/' . Config::get('site.site_theme', '') . '/images/' . Config::get('site.site_logo', '');
        }

        $timezone = function_exists('date_default_timezone_get')
            ? date_default_timezone_get()
            : (lang('no_timezone'));

        $safe_mode = @ini_get('safe_mode');
        $safe_mode_gid = @ini_get('safe_mode_gid');

        return array(
            'folder_exists' => $warning,
            'count_module' => $this->buildModuleCounts(),
            'charset' => strtoupper(DOU_CHARSET),
            'build_date' => $build_date,
            'update' => $update,
            'patch' => $patch,
            'logo' => $logo,
            'php_ver' => PHP_VERSION,
            'max_filesize' => ini_get('upload_max_filesize'),
            'gd' => extension_loaded('gd') ? $yes : $no,
            'zlib' => function_exists('gzclose') ? $yes : $no,
            'timezone' => $timezone,
            'socket' => function_exists('fsockopen') ? $yes : $no,
            'mysql_ver' => DB::version(),
            'os' => PHP_OS,
            'ip' => Arr::get($_SERVER, 'SERVER_ADDR', ''),
            'web_server' => Arr::get($_SERVER, 'SERVER_SOFTWARE', ''),
            'safe_mode' => $safe_mode ? $yes : $no,
            'safe_mode_gid' => $safe_mode_gid ? $yes : $no,
        );
    }

    /**
     * 最近管理员日志：非超级管理员仅看本人；固定取 4 条
     *
     * @return array
     */
    public function getRecentAdminLogs()
    {
        $admin = auth('admin')->user();
        $filter_admin_id = '';
        if (is_array($admin) && isset($admin['action_list']) && $admin['action_list'] !== 'ALL') {
            if (isset($admin['admin_id'])) {
                $filter_admin_id = (int) $admin['admin_id'];
            } elseif (isset($admin['user_id'])) {
                $filter_admin_id = (int) $admin['user_id'];
            }
        }

        $logs = $this->getAdminLog($filter_admin_id, 4);
        return is_array($logs) ? $logs : array();
    }

    /**
     * 获取管理员操作日志列表
     *
     * @param string $admin_id
     * @param string $num
     * @return array
     */
    private function getAdminLog($admin_id = '', $num = '')
    {
        $log_list = array();
        $query = \Dou\Admin\Model\Manager\ManagerAdminLog::query();
        if ($admin_id) {
            $query->where('admin_id', $admin_id);
        }
        $query->order('id DESC');
        if ($num) {
            $query->limit($num);
        }

        $rows = $query->get();
        foreach ($rows as $model) {
            $log_list[] = ManagerService::renderAdminLogRow($model->toArray());
        }

        return $log_list;
    }

    /**
     * @return array Smarty assign 用 backup 结构
     */
    public function buildBackupAssign()
    {
        $backup_files = array_merge(
            (array) glob(STORAGE_PATH . 'backup/*.sql'),
            (array) glob(STORAGE_PATH . 'backup/*.zip')
        );
        if ($backup_files && count($backup_files) > 0) {
            return $this->buildBackupSummary($backup_files);
        }

        $placeholder = array(
            'maketime' => '-',
            'filename' => '-',
            'filesize' => '-',
        );

        return array(
            'new' => $placeholder,
            'old' => $placeholder,
            'msg' => lang('backup_action_cue_never'),
            'light' => true,
            'back_url' => route('admin.index'),
        );
    }

    /**
     * @return void
     */
    public function deleteInstallDirectory()
    {
        $install_path = ROOT_PATH . 'install';
        if (!is_dir($install_path)) {
            return;
        }
        FileHelper::delDir($install_path);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'install_dir');
    }

    /**
     * 关闭控制台「快速开始」标记文件
     *
     * @return void
     */
    public function clearQuickStartFlag()
    {
        $path = STORAGE_PATH . 'quick.start.dou';
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * 读取后台首页快捷入口 storage/quick.start.dou。
     *
     * @return array
     */
    public function buildQuickStartItems()
    {
        $quick_start = array();
        if (file_exists($file = STORAGE_PATH . 'quick.start.dou')) {
            $content = file($file);
            foreach ((array) $content as $line) {
                $line = trim($line);
                if (strpos($line, '|') !== false) {
                    $arr = explode('|', $line);
                    $quick_start[] = array(
                        'text' => isset($arr[0]) ? $arr[0] : '',
                        'link' => isset($arr[1]) ? $arr[1] : '',
                        'target' => isset($arr[2]) ? $arr[2] : '',
                    );
                }
            }

            return $quick_start;
        }

        return $quick_start;
    }

    /**
     * 控制台系统信息区：各业务表条目数摘要。
     *
     * @return array
     */
    private function buildModuleCounts()
    {
        $rows = array();

        if (DB::tableExist('page')) {
            $rows[] = array(
                'name' => str_replace('列表', '', lang('page_list')) . lang('number'),
                'number' => DB::table('page')->count(),
            );
        }

        foreach ((array) Config::get('module.column_module') as $value) {
            if (!in_array($value, (array) Config::get('module.no_show_menu')) && DB::tableExist($value)) {
                $rows[] = array(
                    'name' => str_replace('列表', '', (lang($value) ? lang($value) : lang($value . '_category'))) . lang('number'),
                    'number' => DB::table($value)->count(),
                );
            }
        }

        foreach ((array) Config::get('module.single_module') as $value) {
            if (!in_array($value, (array) Config::get('module.no_show_menu')) && !in_array($value, (array) Config::get('system.admin_hidden_single', array()), true) && DB::tableExist($value)) {
                $rows[] = array(
                    'name' => lang($value) . lang('number'),
                    'number' => DB::table($value)->count(),
                );
            }
        }

        return $rows;
    }

    /**
     * 根据备份目录文件列表生成最新/最旧项与提示文案（供首页备份区块）。
     *
     * @param array $files glob 得到的完整路径列表
     * @return array
     */
    private function buildBackupSummary($files)
    {
        $infos = FileHelper::buildFileListByTime($files);

        if (is_array($infos)) {
            $backup['new'] = current($infos);
            $backup['old'] = end($infos);
            $over_day = floor((time() - strtotime($backup['new']['maketime'])) / (3600 * 24));
        }

        if (!isset($over_day)) {
            $backup['msg'] = lang('backup_action_cue_never');
            $backup['light'] = true;
        } elseif ($over_day == 0) {
            $backup['msg'] = lang('backup_action_cue_today');
        } elseif ($over_day > 30) {
            $backup['msg'] = lang('backup_action_cue_overday_a') . $over_day . lang('backup_action_cue_overday_b');
            $backup['light'] = true;
        } else {
            $backup['msg'] = $over_day . lang('backup_action_cue_day');
        }

        if (!isset($backup['light'])) {
            $backup['light'] = false;
        }

        $backup['back_url'] = route('admin.index');

        return $backup;
    }
}
