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

namespace Dou\Api\Service\Product;

use Dou\Admin\Model\Product\Product as AdminProductModel;
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Url;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Pricing\PricingService;
use Dou\Core\Support\Str;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序工作端商品业务服务。
 *
 * 与后台 Admin\Service\Product\ProductService 类似，但仅暴露工作端所需能力：
 * - 列表（仅上架商品，分页 15）
 * - 新增 / 编辑表单数据准备
 * - 提交保存（持久化层使用 Admin\Model\Product\ProductModel）
 * - 图片上传 / 删除
 *
 * 请求级字段校验由 Api\Request\Product\WorkProductFormRequest 完成；
 * 此处只负责业务流程与持久化协调。
 */
class WorkProductService extends BaseService
{
    /** @var PricingService */
    private $pricingService;

    /**
     * @param PricingService $pricingService
     */
    public function __construct(PricingService $pricingService)
    {
        $this->pricingService = $pricingService;
    }

    /**
     * 工作端商品列表数据。
     *
     * 列表收敛为「仅当前工作台拥有的商品」：scope ownedByWork 强制按
     * operator_type='work' AND operator_id=$workId 过滤；$workId 由控制器
     * 自 auth('api')->workId() 取得并显式传入。
     *
     * @param int $page
     * @param int|null $userId 当前工作端会员 id（用于会员价计算）
     * @param int $workId 当前工作台 id（权限边界）
     * @return array product_list、pager
     */
    public function buildWorkProductListData($page, $userId, $workId)
    {
        $result = AdminProductModel::ownedByWork($workId)
            ->with('category')
            ->published()
            ->order('sort ASC, id DESC')
            ->paginate(15, (int) $page);

        $productList = array();
        foreach ($result['list'] as $model) {
            $row = $model->getAttributes();
            $catName = '';
            $category = $model->category;
            if ($category instanceof \Dou\Core\Orm\Model) {
                $catName = (string) $category->getRawAttribute('name');
            }
            $description = $row['description'] ? $row['description'] : Str::excerpt($row['content'], 150, false);
            $total = (int) $row['stock'] + (int) $row['sales'];

            $productList[] = array(
                'id' => $row['id'],
                'category_id' => $row['category_id'],
                'title' => $row['title'],
                'defined' => Util::parseDefinedPairs($row['defined']),
                'price' => $row['price'] > 0 ? Util::formatPrice($row['price']) : lang('price_discuss'),
                'sale_price' => $this->pricingService->salePrice('product', $row['id'], $userId),
                'thumb' => attachment()->url($row['image'], true),
                'image' => attachment()->url($row['image']),
                'stock' => $row['stock'],
                'sales' => $row['sales'],
                'sales_percentage' => $total > 0 ? intval(($row['sales'] / $total) * 100) : 0,
                'created_at' => date('Y-m-d', strtotime($row['created_at'])),
                'description' => $description,
                'url' => Url::urlMini('product', $row['id']),
                'cate_info' => array(
                    'category_id' => $row['category_id'],
                    'name' => $catName,
                    'url' => Url::urlMini('product_category', $row['category_id']),
                ),
            );
        }

        return array(
            'product_list' => $productList,
            'pager' => isset($result['pager']) ? $result['pager'] : array(),
        );
    }

    /**
     * 新增页默认数据：预分配 id、初始化自定义字段与图库。
     *
     * @return array 含 item_id、product、img_list
     */
    public function buildWorkProductDefaultData()
    {
        $product = array(
            'id' => 0,
            'category_id' => 0,
            'name' => '',
            'stock' => 100,
        );

        $defined = Config::get('defined.product', '');
        if (!empty($defined)) {
            $definedText = '';
            foreach (explode(',', $defined) as $row) {
                $definedText .= $row . "：\n";
            }
            $product['defined'] = trim($definedText);
        }

        return array(
            'item_id' => 0,
            'product' => $product,
            'img_list' => array(),
        );
    }

    /**
     * 编辑页数据：读取记录并补齐模板/小程序所需展示字段。
     *
     * 通过 scope ownedByWork 把读取边界收敛到当前工作台；非本工作台商品
     * 一律视为「不存在」（返回 null，控制器统一回 404），避免存在性枚举。
     *
     * @param int $id
     * @param int $workId 当前工作台 id（权限边界）
     * @return array|null
     */
    public function buildWorkProductEditData($id, $workId)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        $productModel = AdminProductModel::ownedByWork($workId)
            ->whereKey($id)
            ->first();
        $product = $productModel ? $productModel->getAttributes() : null;
        if (!$product) {
            return null;
        }

        $product['image'] = attachment()->url($product['image']);
        $product['model_list'] = $this->buildProductModelHtml($product['model'], $id, $workId);

        $defined = Config::get('defined.product', '');
        if (!empty($defined) || !empty($product['defined'])) {
            $definedText = '';
            foreach (explode(',', $defined) as $row) {
                $definedText .= $row . "：\n";
            }
            $product['defined'] = $product['defined'] ? str_replace(',', "\n", $product['defined']) : trim($definedText);
        }

        return array(
            'product' => $product,
            'img_list' => attachment()->gallery('product', $id, 'gallery', true),
        );
    }

    /**
     * 新增提交：会员价序列化、defined 换行归一化后入库。
     *
     * 字段白名单与校验规则定义在 WorkProductFormRequest::rules()。
     *
     * @param array $data 已通过校验的字段
     * @param int $workId 当前工作台 ID（operator_id）
     * @return int 新记录主键
     */
    public function store(array $data, $workId)
    {
        unset($data['id']);
        $data['level_price'] = !empty($data['level_price']) ? $this->pricingService->levelPrice($data['level_price']) : '';
        $data['defined'] = $this->normalizeDefined(isset($data['defined']) ? $data['defined'] : '');
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['operator_type'] = 'work';
        $data['operator_id'] = (int) $workId;

        $model = AdminProductModel::create($data);

        return (int) $model->getKey();
    }

    /**
     * 更新提交：与新增同样的字段加工流程；image 仅在传入时覆盖。
     *
     * 字段白名单与校验规则定义在 WorkProductFormRequest::rules()。
     * 通过 scope ownedByWork 把更新边界收敛到当前工作台；非本工作台商品
     * 视为「不存在」直接 return false（不覆盖创建者 operator_*）。
     *
     * @param array $data 已通过校验的字段，须含有效 id
     * @param int $workId 当前工作台 id（权限边界）
     * @return bool 受影响行数 > 0 视为成功
     */
    public function update(array $data, $workId)
    {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id <= 0) {
            return false;
        }

        $product = AdminProductModel::ownedByWork($workId)
            ->whereKey($id)
            ->first();
        if (!$product) {
            return false;
        }

        $data['level_price'] = !empty($data['level_price']) ? $this->pricingService->levelPrice($data['level_price']) : '';
        $data['defined'] = $this->normalizeDefined(isset($data['defined']) ? $data['defined'] : '');

        if (isset($data['image']) && $data['image'] === '') {
            unset($data['image']);
        }

        return (bool) $product->fill($data, 'update')->save();
    }

    /**
     * 工作端文件上传：thumb（主图）或 content（编辑器内嵌图片）。
     *
     * 写库前通过 scope ownedByWork 验证 $itemId 归属当前工作台；
     * 非本工作台商品返回空（image=''），控制器据此回 404。
     *
     * @param int $itemId
     * @param string $type thumb|content
     * @param int $imgWidth content 模式下的目标宽度
     * @param int $workId 当前工作台 id（权限边界）
     * @return array image（文件号）、file_url（可访问 URL）
     */
    public function uploadImage($itemId, $type, $imgWidth, $workId)
    {
        $itemId = (int) $itemId;
        $type = (string) $type;

        if ($itemId > 0) {
            $owned = AdminProductModel::ownedByWork($workId)
                ->whereKey($itemId)
                ->exists();
            if (!$owned) {
                return array('image' => '', 'file_url' => '');
            }
        }

        if ($type === 'content') {
            $imgWidth = (int) $imgWidth > 0 ? (int) $imgWidth : (int) Config::get('site.img_width', 0);
            $customFilename = $itemId . '_content_' . Str::randomByType('number', 6, time());
            $image = attachment()->store('product', $itemId, UploadedFile::fromGlobals('image'), 'content', AttachmentUploadOptions::create()->withBasename($customFilename)->withImageWidth($imgWidth)->withWatermark(Config::get('site.watermark', false))->withBusinessField('image')->withUploader('work', (int) $workId));
        } else {
            $image = attachment()->store('product', $itemId, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withThumbnail(Config::get('site.thumb_width', 0), Config::get('site.thumb_height', 0))->withUploader('work', (int) $workId));
        }

        if ($image !== '' && $itemId > 0) {
            AdminProductModel::whereKey($itemId)->update(array('image' => $image));
        }

        return array(
            'image' => $image,
            'file_url' => attachment()->url($image),
        );
    }

    /**
     * 工作端删除：清理图库文件、多语言图片/记录后删主表。
     *
     * 通过 scope ownedByWork 验证 $id 归属当前工作台；非本工作台商品
     * 直接 return false，控制器据此回错。
     *
     * @param int $id
     * @param int $workId 当前工作台 id（权限边界）
     * @return bool
     */
    public function delete($id, $workId)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $owned = AdminProductModel::ownedByWork($workId)
            ->whereKey($id)
            ->exists();
        if (!$owned) {
            return false;
        }

        $fileList = AdminProductModel::getGalleryFileNumberRows($id);
        foreach ((array) $fileList as $row) {
            attachment()->delete($row['number']);
        }

        if (!empty(Config::get('features.language', false))) {
            $langImages = AdminProductModel::getLanguageImageValues($id);
            foreach ((array) $langImages as $row) {
                attachment()->delete($row['value']);
            }
            AdminProductModel::deleteLanguageValues($id);
        }

        AdminProductModel::destroy($id);
        return true;
    }

    /**
     * 小程序工作端表单：商品「型号」关联条目 `<li>` 列表片段。
     *
     * 与 admin {@see \Dou\Admin\Service\Product\ProductService::buildProductModelHtml}
     * 平行维护，承担小程序工作端的型号 UI 片段。同型号关联列表收敛到当前工作台。
     *
     * @param string $model 商品型号字符串（同 model 字段的其它商品归为一组）
     * @param int|string $currentId 当前商品 id，用于「X」按钮绑定与排除自身
     * @param int $workId 当前工作台 id（权限边界）
     * @return string HTML
     */
    public function buildProductModelHtml($model, $currentId, $workId)
    {
        if (!$model) {
            return '';
        }

        $rows = DB::table('product')
            ->field('id, image')
            ->where('model', $model)
            ->where('operator_type', 'work')
            ->where('operator_id', (int) $workId)
            ->order('sort ASC, id DESC')
            ->select();
        $html = '';
        foreach ((array) $rows as $row) {
            $html .= '<li><img src="' . attachment()->url($row['image']) . '" /><span onclick="' . "modelBox('del', '$currentId', '" . $row['id'] . "');" . '" class="del">X</span></li>';
        }

        return $html;
    }

    /**
     * 将多行 defined 文本统一为逗号分隔单行写入。
     *
     * @param string $defined
     * @return string
     */
    private function normalizeDefined($defined)
    {
        if ($defined === '' || $defined === null) {
            return '';
        }

        return str_replace("\r\n", ',', str_replace("\n", ',', $defined));
    }
}
