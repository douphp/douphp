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

namespace Dou\Admin\Controller\Backup;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Service\Backup\MaintenanceModeService;
use Dou\Admin\Service\Backup\RestoreService;
use Dou\Admin\Service\Backup\SnapshotPageService;
use Dou\Core\Facade\Zip;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Snapshot\SnapshotRepository;
use Dou\Core\Service\Snapshot\SnapshotService;
use Dou\Core\Service\Upgrade\RunLockService;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 升级还原点（快照）管理：列表 / 恢复 / 下载 / 删除 / 急救链接 / 维护模式恢复。
 *
 * 恢复为 dou_msg 链式续跑：确认（POST）→ 启动跳板页（立即响应，1 秒后自动带 start=1
 * 跳转）→ 每次请求推进一段（GET + token 续跑链接），直至完成；还原执行链本体在
 * 同域 {@see RestoreService}（与模块更新失败自动回滚共用）。
 * 本页属技术人员补救入口，按钮在「恢复备份」页以灰色样式低调提供。
 */
class SnapshotController extends BaseController
{
    /** @var SnapshotPageService */
    private $pageService;

    /**
     * @param SnapshotPageService $pageService
     */
    public function __construct(SnapshotPageService $pageService)
    {
        $this->pageService = $pageService;
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
     * 还原点列表页。
     *
     * @return Response
     */
    public function index()
    {
        $data = $this->pageService->buildIndexData();
        $cue = '<p>' . lang('snapshot_page_cue') . '</p>';
        if (!empty($data['maintenance'])) {
            $cue .= '<p>' . lang('snapshot_maintenance_banner');
            if (!empty($data['active_restore'])) {
                $cue .= ' <a href="' . $data['active_restore_resume_url'] . '">' . lang('snapshot_btn_resume') . '</a> |';
            }
            $cue .= ' <a href="' . $data['maintenance_off_url'] . '">' . lang('snapshot_maintenance_off_btn') . '</a></p>';
        }

        return $this->view('snapshot.htm', array(
            'ur_here' => lang('snapshot_page_title'),
            'page_cue' => $cue,
        ) + $data);
    }

    /**
     * 执行还原（dou_msg 链：确认 → 启动跳板 → 分段推进 → 完成）。
     *
     * @param Request $request
     * @return Response
     */
    public function snapshotRestore(Request $request)
    {
        @set_time_limit(0);
        $dirName = (string) $request->input('snapshot', '');
        $repository = new SnapshotRepository(ROOT_PATH);
        if (!$repository->isValidName($dirName)) {
            throw new DomainException(lang('illegal'), route('admin.backup.snapshot'));
        }

        $confirm = (string) $request->post('confirm', '');
        $start = (string) $request->query('start', '');
        $restore = new RestoreService(ROOT_PATH);
        $active = $restore->activeDir();

        // 界面态（尚未进入实际还原）：未带 start 启动标记，且无本目录进行中的还原。
        // 未确认 → 二次确认页；已确认 → 启动跳板页（均不在此执行实际工作，见下）。
        if ($start === '' && $active !== $dirName) {
            $row = $repository->row($dirName);
            if ($row === null) {
                throw new DomainException(str_replace('d%', $dirName, lang('snapshot_missing')), route('admin.backup.snapshot'));
            }
            $title = !empty($row['manifest']['title']) ? (string) $row['manifest']['title'] : $dirName;

            // 二次确认页。
            // respond() 第三参是「裸页布局」开关而非附注（dou_msg 正文按纯文本渲染，勿放 HTML）；
            // 第六参覆盖确认按钮文案（默认是「确认删除」，恢复动作不能用）
            if ($confirm === '') {
                return message()->respond(
                    str_replace('d%', $title, lang('snapshot_restore_confirm')) . ' ' . lang('snapshot_restore_confirm_cue'),
                    route('admin.backup.snapshot'),
                    '',
                    '30',
                    route('admin.backup.snapshot_restore', array(), array('query' => array('snapshot' => $dirName))),
                    lang('snapshot_btn_restore_submit')
                );
            }

            // 启动跳板页：点击「确认恢复」后立即响应（本请求不执行任何实际工作），
            // 1 秒后自动带 start=1 跳回本动作开始执行——还原单次请求最长耗时十几秒，
            // 若在确认请求内直接执行，长时间空白等待会被误认为「没有点上」而重复提交。
            return message()->respond(
                str_replace('d%', $title, lang('snapshot_restore_starting')),
                route('admin.backup.snapshot_restore', array(), array('query' => array('snapshot' => $dirName, 'token' => csrf()->token(), 'start' => '1'))),
                '',
                '1'
            );
        }

        // 执行态：start=1 首跑 / 续跑 GET / 进行中重复确认，统一走还原执行链。
        // 全局互斥锁（与云更新 / 向导共用）：还原链持有并逐步刷新，完成后释放
        $holder = 'snapshot:' . $dirName;
        $runLock = new RunLockService(ROOT_PATH);
        $locked = $runLock->acquire($holder);
        if (empty($locked['ok'])) {
            return message()->respond((string) $locked['error'], route('admin.backup.snapshot'));
        }

        if ($active !== $dirName) {
            $begin = $restore->begin($dirName);
            if (empty($begin['ok'])) {
                $runLock->release($holder);
                return message()->respond((string) $begin['error'], route('admin.backup.snapshot'));
            }
        }

        $done = false;
        $error = '';
        $logs = array();
        $deadline = time() + 15;
        while (true) {
            $step = $restore->step(array('max_seconds' => 8));
            foreach ((array) $step['logs'] as $line) {
                $logs[] = (string) $line;
            }
            if (empty($step['ok'])) {
                $error = (string) $step['error'];
                break;
            }
            if (!empty($step['done'])) {
                $done = true;
                break;
            }
            if (time() > $deadline) {
                break;
            }
        }

        if ($error !== '') {
            $runLock->release($holder);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::RESTORE, 0, $dirName . '：' . $error, 'snapshot');
            return message()->respond(str_replace('d%', $error, lang('snapshot_restore_failed')), route('admin.backup.snapshot'));
        }

        if ($done) {
            $runLock->release($holder);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::RESTORE, 1, $dirName, 'snapshot');
            $last = $logs ? (string) end($logs) : '';

            return message()->respond(
                lang('snapshot_restore_success') . ($last !== '' ? '（' . $last . '）' : ''),
                route('admin.backup.snapshot')
            );
        }

        // 未完成：链式续跑（GET + token；快照还原路由在 CSRF getTokenRoutes 名单内）
        $progress = $logs ? (string) end($logs) : '';

        return message()->respond(
            lang('snapshot_restore_running') . ($progress !== '' ? '（' . $progress . '）' : ''),
            route('admin.backup.snapshot_restore', array(), array('query' => array('snapshot' => $dirName, 'token' => csrf()->token()))),
            '',
            '1'
        );
    }

    /**
     * 放弃进行中的还原（清理 restore_state.json + 恢复维护模式原值）。
     *
     * 没有本动作时，卡住的还原任务会永久留下 restore_state.json：该还原点状态恒显「还原中」、
     * 且被仓库层拒绝删除（delete / prune 均保护还原中目录），形成不可清理的残留。
     * 只放弃「当前活跃」的那一个还原任务，不触碰已还原内容。
     *
     * @param Request $request
     * @return Response
     */
    public function snapshotAbandon(Request $request)
    {
        $dirName = (string) $request->query('snapshot', '');
        $repository = new SnapshotRepository(ROOT_PATH);
        if (!$repository->isValidName($dirName)) {
            throw new DomainException(lang('illegal'), route('admin.backup.snapshot'));
        }

        $restore = new RestoreService(ROOT_PATH);
        if ($restore->activeDir() !== $dirName) {
            throw new DomainException(lang('snapshot_abandon_none'), route('admin.backup.snapshot'));
        }

        $restore->abandon($dirName);
        $runLock = new RunLockService(ROOT_PATH);
        $runLock->release('snapshot:' . $dirName);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::RESTORE, 0, $dirName . '：' . lang('snapshot_abandon_done'), 'snapshot');

        return redirect(route('admin.backup.snapshot'))->with('success', lang('snapshot_abandon_done'));
    }

    /**
     * 删除还原点（DELETE，dou_msg 二次确认；活跃 / 还原中的快照由仓库层拒绝）。
     *
     * @param Request $request
     * @return Response
     */
    public function snapshotDestroy(Request $request)
    {
        $dirName = (string) $request->query('snapshot', '');
        $repository = new SnapshotRepository(ROOT_PATH);
        if (!$repository->isValidName($dirName)) {
            throw new DomainException(lang('illegal'), route('admin.backup.snapshot'));
        }

        $post = $request->post();
        if (isset($post['confirm'])) {
            if (!$repository->delete($dirName)) {
                throw new DomainException(str_replace('d%', $dirName, lang('snapshot_delete_fail')), route('admin.backup.snapshot'));
            }
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, $dirName, 'snapshot');

            return $this->respondDeleteResult(array(
                'message' => str_replace('d%', $dirName, lang('snapshot_delete_success')),
                'back_url' => route('admin.backup.snapshot'),
                'timeout' => '3',
                'confirm_url' => '',
            ));
        }

        return $this->respondDeleteResult(array(
            'message' => str_replace('d%', $dirName, lang('snapshot_delete_confirm')),
            'back_url' => route('admin.backup.snapshot'),
            'timeout' => '30',
            'confirm_url' => route('admin.backup.snapshot_destroy', array(), array('query' => array('snapshot' => $dirName))),
        ));
    }

    /**
     * 下载还原点（整目录打包为单 zip 流式输出）。
     *
     * @param Request $request
     * @return void
     */
    public function snapshotDown(Request $request)
    {
        $dirName = (string) $request->query('snapshot', '');
        $repository = new SnapshotRepository(ROOT_PATH);
        $row = $repository->row($dirName);
        if ($row === null) {
            throw new DomainException(lang('illegal'), route('admin.backup.snapshot'));
        }

        @set_time_limit(0);
        $tmpDir = STORAGE_PATH . 'tmp/';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0777, true);
        }
        $tmpZip = $tmpDir . 'snapshot-' . $dirName . '.zip';
        @unlink($tmpZip);

        $created = Zip::create($tmpZip, array(rtrim($row['path'], '/')), $repository->baseDir());
        if (empty($created['ok']) || !is_file($tmpZip)) {
            @unlink($tmpZip);
            throw new DomainException(str_replace('d%', (string) $created['error'], lang('snapshot_download_fail')), route('admin.backup.snapshot'));
        }

        ob_clean();
        $fp = @fopen($tmpZip, 'r');
        if ($fp) {
            header('Content-type: application/zip');
            header('Content-Disposition: attachment; filename=' . $dirName . '.zip');
            header('Accept-Ranges: bytes');
            header('Content-Length:' . filesize($tmpZip));
            header('Content-transfer-encoding: binary');
            while (!@feof($fp)) {
                echo fread($fp, 10240);
            }
            fclose($fp);
        }
        @unlink($tmpZip);
    }

    /**
     * 生成急救链接（一次性令牌；仅 system 域还原点）。
     *
     * @param Request $request
     * @return Response
     */
    public function snapshotRescue(Request $request)
    {
        $dirName = (string) $request->query('snapshot', '');
        $snapshot = new SnapshotService(ROOT_PATH);
        $result = $snapshot->mintRescueToken($dirName);
        if (empty($result['ok'])) {
            throw new DomainException((string) $result['error'], route('admin.backup.snapshot'));
        }

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::RESTORE, 1, $dirName . '：生成急救链接', 'snapshot');

        // 链接仅此一次明文展示（落盘只存 sha256）；60 秒窗口便于复制。
        // dou_msg 正文按纯文本渲染（admin 视图默认转义），故此处不拼 HTML。
        $text = lang('snapshot_rescue_title') . ' '
            . str_replace('d%', date('Y-m-d H:i', (int) $result['expires_at']), lang('snapshot_rescue_ready')) . ' '
            . (string) $result['url'] . ' '
            . lang('snapshot_rescue_keep');

        return message()->respond($text, route('admin.backup.snapshot'), '', '60');
    }

    /**
     * 恢复维护模式原值（还原流程中断导致站点停留关闭状态时的兜底出口）。
     *
     * @return Response
     */
    public function snapshotMaintenanceOff()
    {
        $maintenance = new MaintenanceModeService(ROOT_PATH);
        if (!$maintenance->isActive()) {
            return message()->respond(lang('snapshot_maintenance_none'), route('admin.backup.snapshot'));
        }
        $maintenance->restore();
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'maintenance off', 'snapshot');

        return message()->respond(lang('snapshot_maintenance_off_done'), route('admin.backup.snapshot'));
    }
}
