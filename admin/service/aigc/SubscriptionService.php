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

namespace Dou\Admin\Service\Aigc;

use Dou\Admin\Model\Aigc\AigcPackage;
use Dou\Admin\Model\Aigc\AigcSubscription;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员订阅（aigc_subscription，route=aigc/subscription/...）。
 */
class SubscriptionService extends BaseService
{
    /**
     * 列表页数据。
     *
     * @param array $req 筛选请求（username / package_id / order_status / order_sn / page）
     * @return array subscription_list、pager、package_list
     */
    public function getAdminIndexPageData(array $req)
    {
        $username = isset($req['username']) ? trim((string) $req['username']) : '';
        $packageId = isset($req['package_id']) ? (int) $req['package_id'] : 0;
        $orderStatusRaw = isset($req['order_status']) ? trim((string) $req['order_status']) : '';
        $orderSn = isset($req['order_sn']) ? trim((string) $req['order_sn']) : '';
        $page = isset($req['page']) ? $req['page'] : 1;

        $pageUrl = route('admin.aigc.subscription');
        $userId = 0;
        if ($username !== '') {
            $userId = user() !== null ? (int) user()->findUserId($username) : 0;
            $pageUrl .= '&username=' . rawurlencode($username);
        }
        if ($packageId > 0) {
            $pageUrl .= '&package_id=' . $packageId;
        }
        if ($orderStatusRaw !== '') {
            $pageUrl .= '&order_status=' . rawurlencode($orderStatusRaw);
        }
        if ($orderSn !== '') {
            $pageUrl .= '&order_sn=' . rawurlencode($orderSn);
        }

        $result = AigcSubscription::filterByUserId($userId)
            ->filterByPackageId($packageId)
            ->filterByOrderStatus($orderStatusRaw)
            ->filterByOrderSn($orderSn)
            ->applyDefaultOrder()
            ->paginate(15, $page, Util::normalizeQueryString($pageUrl));

        $subscription_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $addTs = Util::toTimestamp($model->getRawAttribute('created_at'));
            $startTs = Util::toTimestamp($model->getRawAttribute('start_at'));
            $endTs = Util::toTimestamp($model->getRawAttribute('end_at'));

            $user_info = (user() !== null ? user()->format($row['user_id']) : false);
            $package_info = AigcSubscription::findPackageRow($row['package_id']);

            $tokens_percent = $row['tokens_limit'] > 0 ? round(($row['tokens_used'] / $row['tokens_limit']) * 100, 2) : 0;
            $requests_percent = $row['requests_limit'] > 0 ? round(($row['requests_used'] / $row['requests_limit']) * 100, 2) : 0;

            $subscription_list[] = array(
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'user' => $user_info,
                'package_name' => $package_info && isset($package_info['name']) ? $package_info['name'] : '',
                'order_sn' => $row['order_sn'],
                'price' => $row['price'],
                'tokens_limit' => $row['tokens_limit'],
                'tokens_used' => $row['tokens_used'],
                'tokens_percent' => $tokens_percent,
                'requests_limit' => $row['requests_limit'],
                'requests_used' => $row['requests_used'],
                'requests_percent' => $requests_percent,
                'start_at' => $startTs ? date('Y-m-d H:i:s', $startTs) : '',
                'end_at' => $endTs ? date('Y-m-d H:i:s', $endTs) : '',
                'is_expired' => $endTs > 0 && $endTs < time(),
                'order_status' => language()->dataLangFormat(
                    'aigc_subscription_order_status_',
                    (string) $model->getRawAttribute('order_status')
                ),
                'created_at' => $addTs !== null ? date('Y-m-d H:i:s', $addTs) : '',
            );
        }

        return array(
            'subscription_list' => $subscription_list,
            'pager' => $result['pager'],
            'package_list' => AigcPackage::selectActivePackages(),
        );
    }

    /**
     * 详情页数据。
     *
     * @param mixed $id 订阅主键
     * @return array|null subscription
     */
    public function getViewBundle($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $model = AigcSubscription::find($id);
        if (!$model) {
            return null;
        }
        $subscription = $model->getAttributes();
        $orderStatusRaw = $model->getRawAttribute('order_status');

        $subscription['user'] = (user() !== null ? user()->format($subscription['user_id']) : false);
        $package_info = AigcSubscription::findPackageRow($subscription['package_id']);
        $subscription['package_name'] = $package_info && isset($package_info['name']) ? $package_info['name'] : '';

        $add_ts = Util::toTimestamp(isset($subscription['created_at']) ? $subscription['created_at'] : null);
        $start_ts = Util::toTimestamp(isset($subscription['start_at']) ? $subscription['start_at'] : null);
        $end_ts_raw = Util::toTimestamp(isset($subscription['end_at']) ? $subscription['end_at'] : null);

        $subscription['created_at'] = $add_ts !== null ? date('Y-m-d H:i:s', $add_ts) : '';
        $subscription['start_at'] = $start_ts !== null ? date('Y-m-d H:i:s', $start_ts) : '';
        $subscription['end_at'] = $end_ts_raw !== null ? date('Y-m-d H:i:s', $end_ts_raw) : '';

        $subscription['tokens_remaining'] = $subscription['tokens_limit'] - $subscription['tokens_used'];
        $subscription['tokens_percent'] = $subscription['tokens_limit'] > 0
            ? round(($subscription['tokens_used'] / $subscription['tokens_limit']) * 100, 2) : 0;
        $subscription['requests_remaining'] = $subscription['requests_limit'] - $subscription['requests_used'];
        $subscription['requests_percent'] = $subscription['requests_limit'] > 0
            ? round(($subscription['requests_used'] / $subscription['requests_limit']) * 100, 2) : 0;

        $subscription['is_expired'] = $end_ts_raw > 0 && $end_ts_raw < time();
        $subscription['order_status_format'] = language()->dataLangFormat(
            'aigc_subscription_order_status_',
            $orderStatusRaw
        );

        if (!empty($subscription['sale_price_type'])) {
            $subscription['sale_price_type_format'] = language()->dataLangFormat(
                'aigc_subscription_sale_price_type_',
                $subscription['sale_price_type']
            );
        }

        return array(
            'subscription' => $subscription,
        );
    }

    /**
     * 单条删除（二次确认）。
     *
     * @param mixed $id 订阅主键
     * @param array $post 含 confirm 时表示用户已确认
     * @return array{message: string, back_url: string, timeout?: string, confirm_url?: string}
     * @throws DomainException
     */
    public function delete($id, array $post)
    {
        $id = (int) $id;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.subscription'));
        }
        if (!AigcSubscription::find($id)) {
            throw new DomainException(lang('illegal'), route('admin.aigc.subscription'));
        }

        if (isset($post['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'ID:' . $id, 'aigc_subscription');
            AigcSubscription::destroy($id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.aigc.subscription'),
            );
        }

        $msg = preg_replace('/d%/Ums', 'ID: ' . $id, lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.aigc.subscription'),
            'timeout' => '30',
            'confirm_url' => route('admin.aigc.subscription.destroy', array('id' => $id)),
        );
    }

    /**
     * 列表批量操作。
     *
     * @param array $post checkbox、action 等表单字段
     * @return array{message: string, back_url: string}
     * @throws DomainException
     */
    public function action(array $post)
    {
        if (empty($post['checkbox']) || !is_array($post['checkbox'])) {
            throw new DomainException(lang('aigc_subscription_select_empty'), route('admin.aigc.subscription'));
        }

        $action = Arr::get($post, 'action', '');
        if ($action === 'del_all') {
            $ids = Check::intIds($post['checkbox']);
            if (empty($ids)) {
                throw new DomainException(lang('aigc_subscription_select_empty'), route('admin.aigc.subscription'));
            }
            AigcSubscription::whereIn('id', $ids)->delete();
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:AigcSubscription (' . count($ids) . ' items)', 'aigc_subscription');
            return array('message' => lang('del_succes'), 'back_url' => route('admin.aigc.subscription'));
        }

        throw new DomainException(lang('select_empty'), route('admin.aigc.subscription'));
    }
}
