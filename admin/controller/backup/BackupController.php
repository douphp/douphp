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

namespace Dou\Admin\Controller\Backup;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Service\Backup\BackupService;
use Dou\Core\Facade\DB;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 数据备份与恢复
 */
class BackupController extends BaseController
{
    /** @var BackupService */
    private $backupService;

    /**
     * @param BackupService $backupService
     */
    public function __construct(BackupService $backupService)
    {
        $this->backupService = $backupService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'backup',
        );
    }

    /**
     * 备份首页（表列表）
     *
     * @return Response
     */
    public function index()
    {
        @set_time_limit(0);
        $data = $this->backupService->buildTableList(DB::getPrefix());

        return $this->view('backup.htm', [
            'ur_here' => lang('backup'),
            'page_actions' => array(
                array('href' => route('admin.backup.restore'), 'text' => lang('backup_restore'), 'style' => ''),
            ),
            'rec' => 'default',
            'table_list' => $data['table_list'],
            'totalsize' => $data['totalsize'],
            'filename' => 'D' . date('Ymd') . 'T' . date('His'),
        ]);
    }

    /**
     * 执行备份（分卷、ZIP）
     *
     * @param Request $request
     * @return Response
     */
    public function backup(Request $request)
    {
        @set_time_limit(0);
        $result = $this->backupService->runBackup(
            DB::getPrefix(),
            $request->all()
        );

        return message()->respond(
            $result['message'],
            $result['back_url'],
            '',
            isset($result['timeout']) ? $result['timeout'] : '',
            isset($result['confirm_url']) ? $result['confirm_url'] : ''
        );
    }

    /**
     * 恢复列表
     *
     * @return Response
     */
    public function restore()
    {
        return $this->view('backup.htm', [
            'ur_here' => lang('backup_restore'),
            'page_actions' => array(
                array('href' => route('admin.backup'), 'text' => lang('backup'), 'style' => ''),
            ),
            'rec' => 'restore',
            'file_list' => $this->backupService->buildRestoreFileList(),
        ]);
    }

    /**
     * 导入 SQL
     *
     * @param Request $request
     * @return Response
     */
    public function import(Request $request)
    {
        $result = $this->backupService->runImport($request->all());

        return message()->respond(
            $result['message'],
            $result['back_url'],
            '',
            isset($result['timeout']) ? $result['timeout'] : '',
            isset($result['confirm_url']) ? $result['confirm_url'] : ''
        );
    }

    /**
     * 删除备份文件
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $result = $this->backupService->runDelete($request->all(), $request->post());

        return message()->respond(
            $result['message'],
            $result['back_url'],
            '',
            isset($result['timeout']) ? $result['timeout'] : '',
            isset($result['confirm_url']) ? $result['confirm_url'] : ''
        );
    }

    /**
     * 下载备份文件
     *
     * @param Request $request
     * @return void
     */
    public function down(Request $request)
    {
        $this->backupService->streamDownload($request->all());
    }
}
