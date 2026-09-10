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
 * 写入 config/module.php 与 storage/install.lock，完成安装收尾。
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
    }
}
