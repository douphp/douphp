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

namespace Dou\Install\Service;

use Dou\Core\Support\Arr;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 写入 config/module.php 与 storage/state/install.lock，完成安装收尾。
 *
 * 与旧版安装阶段保持最小集；云模块安装时再追加 link_* 等键。
 */
class FinishService
{
    /** @var InstallLockService */
    private $lockService;

    /**
     * @param InstallLockService $lockService
     */
    public function __construct(InstallLockService $lockService)
    {
        $this->lockService = $lockService;
    }

    /**
     * 落盘 config/module.php 并写入安装锁。
     *
     * @return void
     */
    public function finalize()
    {
        $moduleFile = ROOT_PATH . 'config/module.php';
        $setting = array(
            'column_module' => array('product', 'article'),
            'single_module' => array('data', 'ai', 'language'),
            'link_ai' => array('product', 'article'),
            'no_show_menu' => array('data'),
            'no_show_nav' => array('data'),
        );
        $content = "<?php\nreturn " . Arr::export($setting) . ";\n";
        file_put_contents($moduleFile, $content);

        $this->lockService->lock();

        // 埋点：站点首次安装完成后上报安装记录（匿名基础遥测，落库 site / site_event）。
        // 安装器上下文无 app() 容器助手，直接 new；版本取自 install 阶段暂存的 $_SESSION
        // （finish 为独立 HTTP 请求且未绑定 DB）。SiteReportService 内部对开关与异常全兜底，
        // 此处再包一层 try/catch，绝不阻断安装收尾。
        try {
            $version = isset($_SESSION['douphp_version']) ? (string) $_SESSION['douphp_version'] : '';
            $reporter = new \Dou\Core\Service\Cloud\SiteReportService();
            $reporter->reportSystem('install', $version);
        } catch (\Exception $e) {
            // 上报失败静默忽略。
        } catch (\Throwable $e) {
            // PHP 7+ 的 \Error（如类加载失败）同样静默忽略。
        }
    }
}
