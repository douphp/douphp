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
 * Release Date: 2026-10-16
 */

namespace Dou\Core\Service\Upgrade;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 升级执行上下文（双端统一）：由 ContextFactory 装配，在 Runner 与各步骤服务间传递。
 *
 * - 云渠道（_update/action.php 载荷）与向导渠道（upgrade/ 向导）共用本对象；
 * - view / csrf / lang / db 为向导专属字段，仅向导装配（云窗口经 DB 门面访问数据库）。
 */
class UpgradeContext
{
    /** @var string 站点根目录（绝对路径，结尾带 /） */
    public $rootPath = '';

    /** @var string 升级物料根目录（U_PATH / UPGRADE_PATH，结尾带 /）：内含 version.php 与 resources/ */
    public $materialsRoot = '';

    /** @var string 渠道标识：cloud（后台云更新载荷）| wizard（/upgrade/ 向导） */
    public $channel = 'wizard';

    /** @var array dou_config 全量快照（升级启动时读取，任何写入之前） */
    public $siteConfig = array();

    /** @var string 数据表前缀 */
    public $prefix = '';

    /** @var string 数据库主机 */
    public $dbhost = '';

    /** @var string 数据库名 */
    public $dbname = '';

    /** @var string 数据库用户名 */
    public $dbuser = '';

    /** @var string 数据库密码 */
    public $dbpass = '';

    /** @var string 站点根 URL（hash_code 计算与 domain 回填） */
    public $rootUrl = '';

    /** @var string 升级目标版本串（来自物料根 version.php 的 version 字段，单一来源） */
    public $newVersion = '';

    /** @var string 升级前版本串（boot 快照 dou_config.douphp_version） */
    public $fromVersion = '';

    /** @var int 升级前版本 release 日期（8 位数字；解析失败为 0，用于一次性修正判定） */
    public $releaseDate = 0;

    /** @var string 解析后的 DOU_APP_KEY（缺失时生成并 define，本请求内保持稳定） */
    public $appKey = '';

    /** @var bool 解析后的后台伪静态开关 ADMIN_REWRITE（含老站值迁移链） */
    public $adminRewrite = false;

    /** @var string[] 实际执行的版本列表（Runner 回填） */
    public $executedVersions = array();

    /** @var array 扩展选项（如快照步参数；渠道可选传入） */
    public $options = array();

    /** @var \Dou\Core\Infra\Database\Connection|null 数据库连接（仅向导/独立脚本装配；云载荷经 DB 门面） */
    public $db;

    /** @var \Dou\Core\Web\Template\DouView|null 模板引擎（仅向导装配） */
    public $view;

    /** @var \Dou\Core\Foundation\Csrf\CsrfManager|null 令牌工具（仅向导装配） */
    public $csrf;

    /** @var array 语言串（仅向导装配） */
    public $lang = array();
}
