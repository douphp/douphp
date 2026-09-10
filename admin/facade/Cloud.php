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

namespace Dou\Admin\Facade;

use Dou\Admin\Service\Cloud\InstallService;
use Dou\Admin\Service\Cloud\UpdateStateService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台云端门面：聚合多处复用的云端状态能力。
 *
 * 聚合云端载荷、更新时间、模块卸载、小程序同步等能力，
 * 内部转发到 {@see InstallService} 与 {@see UpdateStateService}。
 */
class Cloud
{
    /** @var InstallService */
    private $installService;

    /** @var UpdateStateService */
    private $updateState;

    /**
     * 构造门面。
     *
     * {@see InstallService} 经容器注入（含 CacheClearService 等依赖）；
     * {@see UpdateStateService} 为无状态轻量服务，在此就近 new 即可，
     * 无需走容器。
     *
     * @param InstallService $installService 云端安装流程服务
     */
    public function __construct(InstallService $installService)
    {
        $this->installService = $installService;
        $this->updateState = new UpdateStateService();
    }

    /**
     * 取站点侧序列化载荷（urlencode(serialize(...))）。
     *
     * 用于云端检测扩展更新：把站点的 update_date / cloud_account / ROOT_URL /
     * SYSTEM_SIGN 打包后回传云端，由云端比对版本差异。
     *
     * @param string $type 子类型筛选：theme / module / miniprogram / plugin 等；
     *                     空字符串表示打包全部 update_date
     * @return string urlencode 后的 serialize 串
     */
    public function localSitePayload($type = '')
    {
        return $this->updateState->localSitePayload($type);
    }

    /**
     * 取系统环境序列化载荷（urlencode(serialize(...))）。
     *
     * 包含内核版本 / 语言 / PHP 版本 / MySQL 版本 / 操作系统 / Web Server /
     * 字符集 / 当前主题 / 站点 URL / 服务器 IP / SYSTEM_SIGN 等环境信息，
     * 供云端按系统环境做版本路由（如下载域解析）。
     *
     * @return string urlencode 后的 serialize 串
     */
    public function localSystemPayload()
    {
        return $this->updateState->localSystemPayload();
    }

    /**
     * 写入扩展 / 系统的最近更新日期到 `config.update_date`。
     *
     * `$type === 'system'` 时取 `$cloudId` 末 8 位作为日期，按 `$mode`
     * 落到 `system.update` 或 `system.patch`；其它类型按 `Ymd` 时间戳
     * 落到 `$type.$cloudId`。`$del=true` 表示反向删除该条记录。
     *
     * @param string $type 扩展类型：theme / module / plugin / miniprogram / system
     * @param string $cloudId 云端资源 ID（system 类型为版本字符串，末 8 位为日期）
     * @param bool $del 是否反向删除该条记录
     * @param string $mode 仅 type=system 时使用，区分 update / patch
     * @return void
     */
    public function changeUpdateDate($type, $cloudId, $del = false, $mode = '')
    {
        $this->updateState->changeUpdateDate($type, $cloudId, $del, $mode);
    }

    /**
     * 卸载模块：按 installed 清单回滚已拷贝文件、DROP 表、重置 module.php / display / nav。
     *
     * 读取 `storage/installed/{cloudId}.installed.php` 中的
     * `$installed_file_list` / `$installed_sql_list` 回滚安装动作，
     * 末尾顺带 {@see changeMiniprogramConfigFile} 重写小程序 app.json。
     *
     * @param string $cloudId 云端模块 ID
     * @return void
     */
    public function clearModule($cloudId)
    {
        $this->installService->clearModule($cloudId);
    }

    /**
     * 同步小程序代码包配置：app.json（pages / tabBar）+ 路由表 + dou.ts / runtime.json。
     *
     * 仅写入 `default` 模板包与当前启用包（`site.miniprogram_code`），
     * 其它代码包目录不动。`$miniprogramDomain` 为空时按
     * `param.miniprogram_domain` → `ROOT_URL` 顺序回退。
     *
     * @param string $miniprogramDomain 小程序业务域名；空串走配置回退
     * @return bool|null 全部代码包都缺 app.json 时返回 false；正常走完返回 null
     */
    public function changeMiniprogramConfigFile($miniprogramDomain = '')
    {
        return $this->installService->changeMiniprogramConfigFile($miniprogramDomain);
    }
}
