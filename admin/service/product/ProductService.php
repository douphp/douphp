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

namespace Dou\Admin\Service\Product;

use Dou\Admin\Model\Product\Product;
use Dou\Admin\Model\Product\ProductCategory;
use Dou\Admin\Service\Concerns\DecoratesAdminMetadata;
use Dou\Admin\Service\Concerns\SavesMetadata;
use Dou\Admin\Service\Language\LanguageDraftService;
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Image;
use Dou\Core\Filesystem\Storage;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Service\Pricing\PricingService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\UploadedFile;
use Dou\Vendor\Excel\Excel;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台商品业务。
 *
 * 职责：
 * - 组织商品列表/表单展示数据（供 Controller assign）。
 * - 承载新增、更新、删除、批量操作等业务流程。
 * - 协调 Model、文件处理与后台日志；请求校验由 FormRequest / Controller 承担。
 */
class ProductService extends BaseService
{
    use SavesMetadata;
    use DecoratesAdminMetadata;
    /** @var \Dou\Core\Filesystem\Disk */
    private $disk;

    /** @var PricingService */
    private $pricingService;

    /** @var MarkdownRenderer */
    private $markdown;

    /** @var LanguageDraftService */
    private $languageDraft;

    /**
     * @param PricingService $pricingService
     * @param MarkdownRenderer $markdown
     * @param LanguageDraftService $languageDraft
     */
    public function __construct(PricingService $pricingService, MarkdownRenderer $markdown, LanguageDraftService $languageDraft)
    {
        $this->disk = Storage::disk('product');
        $this->pricingService = $pricingService;
        $this->markdown = $markdown;
        $this->languageDraft = $languageDraft;
    }

    /**
     * 属性模块是可选模块：缺失时返回 null，规格选项能力整体降级。
     *
     * @return \Dou\Admin\Service\Attribute\AttributeService|null
     */
    private function attributeService()
    {
        if (!Module::has('attribute') || !class_exists('Dou\\Admin\\Service\\Attribute\\AttributeService')) {
            return null;
        }

        return app('Dou\\Admin\\Service\\Attribute\\AttributeService');
    }

    /**
     * 列表页数据：分页查询并补齐模板展示字段（分类名、图片、会员价档位等）。
     *
     * @param int $catId
     * @param string $keyword
     * @param int $page
     * @return array list、pager
     */
    public function buildProductListData($catId, $keyword, $page, $sort = '', $order = '')
    {
        $pageUrl = route('admin.product');
        $catId = (int) $catId;
        if ($catId > 0) {
            $pageUrl .= '&category_id=' . $catId;
        }
        if ($keyword !== '') {
            $pageUrl .= '&keyword=' . $keyword;
        }
        if ($sort !== '') {
            $pageUrl .= '&sort=' . rawurlencode($sort);
        }
        if ($order !== '') {
            $pageUrl .= '&order=' . rawurlencode($order);
        }

        $result = Product::with('category')
            ->filterByCategory($catId)
            ->filterByKeyword($keyword)
            ->field('id, title, price, level_price, promote_price, image, category_id, stock, point, sort, status, created_at, metadata')
            ->applyListOrder($sort, $order, array('id', 'title', 'price', 'stock', 'sort', 'status', 'created_at'))
            ->paginate(30, $page, Util::normalizeQueryString($pageUrl));
        $userLevelOption = Module::make('user.user_level_option_builder');

        $productList = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $productList[] = array(
                'id' => $row['id'],
                'category_id' => $row['category_id'],
                'category' => isset($row['category']) ? $row['category'] : array(),
                'title' => $row['title'],
                'price' => $row['price'] > 0 ? Util::formatPrice($row['price']) : '',
                'price_raw' => $row['price'],
                'promote_price' => $row['promote_price'] > 0 ? Util::formatPrice($row['promote_price']) : '',
                'level_price' => $userLevelOption !== null ? $userLevelOption->options(null, unserialize($row['level_price'])) : array(),
                'image' => $row['image'],
                'stock' => $row['stock'],
                'point' => $row['point'] ? $row['point'] : '-',
                'sort' => $row['sort'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'metadata' => isset($row['metadata']) ? $row['metadata'] : '',
            );
        }

        $metadataSchema = $this->decorateAdminMetadataRows('product', $productList);

        return array(
            'list' => $productList,
            'pager' => $result['pager'],
            'metadata_schema' => $metadataSchema,
        );
    }

    /**
     * 新增页默认数据：初始化表单字段默认值与图库列表 HTML。
     *
     * 分类、品牌、会员价等周边数据由 Controller 另行 assign。
     *
     * @param string|int $itemId 预分配的商品 id（与上传图库等一致）
     * @return array
     */
    public function buildProductDefaultData($itemId)
    {
        $product = array(
            'id' => '',
            'title' => '',
            'category_id' => '',
            'slug' => '',
            'keywords' => '',
            'description' => '',
            'image' => '',
            'sort' => '50',
            'price' => '0',
            'promote_price' => '0',
            'brand_id' => '',
            'stock' => '100',
            'point' => '0',
            'model_list' => '',
            'img_list_html' => attachment()->gallery('product', $itemId, 'gallery'),
            'attach_list_html' => '',
        );

        return $product;
    }

    /**
     * 新增提交：处理主图、正文（可选远程图片本地化）、会员价序列化后入库并记日志。
     *
     * 字段白名单与校验规则见 ProductFormRequest；Action 可按方法名绑定 scene=store。
     *
     * @see \Dou\Admin\Request\Product\ProductFormRequest::rules()
     *
     * @param array $data
     * @param string $draftToken
     * @param int $adminId
     * @param bool $unpublished 可信导入链路指定下架创建，不从表单数据读取
     * @return int 新增记录主键（供 Controller 跳转编辑页）
     */
    public function insert(array $data, $draftToken, $adminId, $unpublished = false)
    {
        $draftToken = (string) $draftToken;
        $adminId = (int) $adminId;
        if ($draftToken === '' || $adminId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.product'));
        }

        // 先解析规格选项（含分类校验），再进入主图/正文/多语言等有副作用的处理
        $attributeService = $this->attributeService();
        $attributePlan = $attributeService ? $attributeService->beginPending('product', $data, 0) : null;
        unset($data['attribute_value'], $data['attribute_value_lang']);

        $dou_user = (Config::get('features.user', false) && user()) ? user() : null;
        if (!empty($data['level_price']) && $dou_user) {
            $data['level_price'] = $this->pricingService->levelPrice($data['level_price']);
        }

        $content = isset($data['content']) ? $this->markdown->toStore($data['content']) : '';
        if (!empty($data['content_remote_image_local'])) {
            $content = attachment()->storeDraftContentImages('product', $content, 'admin', $adminId, $draftToken, 'content', '');
        }
        $data['content'] = $content;
        $data['created_at'] = date('Y-m-d H:i', time());

        $data['operator_type'] = 'admin';
        $data['operator_id'] = $adminId;

        $image = attachment()->reserve('product', UploadedFile::fromGlobals('image'), 'admin', $adminId, 'main', AttachmentUploadOptions::create()->withThumbnail(Config::get('site.thumb_width', 0), Config::get('site.thumb_height', 0)));
        $data['image'] = $image;
        $metadataPlan = $this->metadataBegin('product', $data, 0, '', $adminId);
        if ($metadataPlan !== null) {
            $data['metadata'] = $metadataPlan['json'];
        }
        $languagePlan = $this->languageDraft->begin('product', $data, 0, $adminId);
        unset($data['language_draft']);

        try {
            $newId = (int) DB::transaction(function () use ($data, $image, $draftToken, $adminId, $metadataPlan, $languagePlan, $attributeService, $attributePlan, $unpublished) {
                $product = Product::create($data);
                if (!$product) {
                    throw new DomainException(lang('illegal'), route('admin.product'));
                }
                $newId = (int) $product->getKey();
                if ($newId <= 0) {
                    throw new DomainException(lang('operation_failed'));
                }
                // AI 导入的商品需人工检查后上架，状态不接受模型输出。
                if ($unpublished) {
                    if (DB::table('product')->where('id', $newId)->data(array('status' => 0))->update() === false) {
                        throw new DomainException(lang('operation_failed'));
                    }
                }

                $this->languageDraft->commit($languagePlan, $newId, $adminId);
                if ($attributeService && $attributePlan) {
                    $attributeService->commitPending($attributePlan, $newId);
                }
                attachment()->claimByToken('product', $draftToken, $newId, 'admin', $adminId);
                $this->metadataCommit($metadataPlan, $newId, $adminId);
                if ($image !== '' && attachment()->adopt('product', $image, $newId, 'admin', $adminId) < 1) {
                    throw new DomainException(lang('file_claim_failed'));
                }

                return $newId;
            });
        } catch (\Exception $e) {
            $this->languageDraft->abort($languagePlan);
            throw $e;
        }

        $this->metadataPurge($metadataPlan, $newId);
        $this->languageDraft->purge($languagePlan, $newId);
        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, (string) $data['title']);

        return $newId;
    }

    /**
     * 编辑页数据：读取记录并转为模板展示格式（图片 URL、型号列表、自定义字段换行）。
     *
     * 模板周边下拉等由 Controller assign。
     *
     * @param string $id
     * @return array|null 不存在返回 null
     */
    public function buildProductEditData($id)
    {
        $productModel = Product::find($id);
        $product = $productModel ? $productModel->getAttributes() : null;
        if (!$product) {
            return null;
        }

        $product['file_number'] = $product['image'];
        $product['image'] = attachment()->url($product['image']);
        $product['img_list_html'] = attachment()->gallery('product', $id, 'gallery');
        $product['attach_list_html'] = attachment()->attachList('product', $id, 'attach');
        $product['model_list'] = $this->buildProductModelHtml($product['model'], $id);
        $product['item_content'] = $this->markdown->toHtml($product['content']);

        return $product;
    }

    /**
     * 更新提交：校验目标存在后主图/正文处理与入库，写后台日志。
     *
     * 字段白名单见 ProductFormRequest；Action 可按方法名绑定 scene=update。
     *
     * @see \Dou\Admin\Request\Product\ProductFormRequest::rules()
     *
     * @param array $data 须含有效 id
     * @param int $adminId
     * @return void
     */
    public function update(array $data, $adminId)
    {
        $adminId = (int) $adminId;
        if (!isset($data['id']) || $adminId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.product'));
        }

        $id = (int) $data['id'];
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.product'));
        }

        $product = Product::find($id);
        if (!$product) {
            throw new DomainException(lang('item_not_exist'), route('admin.product'));
        }
        $productAttributes = $product->getAttributes();
        $oldImageNumber = isset($productAttributes['image']) ? (string) $productAttributes['image'] : '';

        // 先解析译文（校验 value_id 归属），再进入主图/正文等有副作用的处理
        $attributeService = $this->attributeService();
        $attributePlan = $attributeService ? $attributeService->beginPending('product', $data, $id) : null;
        unset($data['attribute_value'], $data['attribute_value_lang']);

        $metadataPlan = $this->metadataBegin('product', $data, $id, $this->metadataExistingRaw($product), $adminId);
        $languagePlan = $this->languageDraft->begin('product', $data, $id, $adminId);
        unset($data['language_draft']);
        $image = attachment()->store('product', $id, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withThumbnail(Config::get('site.thumb_width', 0), Config::get('site.thumb_height', 0))->withUploader('admin', $adminId));
        $deleteImage = ($image === '' && !empty($data['image_delete']));
        $dou_user = (Config::get('features.user', false) && user()) ? user() : null;
        if (!empty($data['level_price']) && $dou_user) {
            $data['level_price'] = $this->pricingService->levelPrice($data['level_price']);
        }

        $content = isset($data['content']) ? $this->markdown->toStore($data['content']) : '';
        if (!empty($data['content_remote_image_local'])) {
            $content = attachment()->storeContentImages('product', $id, $content, 'content', '', AttachmentUploadOptions::create()->withUploader('admin', $adminId));
        }
        $data['content'] = $content;
        if ($image) {
            $data['image'] = $image;
        } elseif ($deleteImage) {
            $data['image'] = '';
        }
        if ($metadataPlan !== null) {
            $data['metadata'] = $metadataPlan['json'];
        }

        try {
            DB::transaction(function () use ($product, $data, $metadataPlan, $id, $adminId, $languagePlan, $attributeService, $attributePlan) {
                $product->fill($data, 'update')->save();
                $this->languageDraft->commit($languagePlan, $id, $adminId);
                if ($attributeService && $attributePlan) {
                    $attributeService->commitPending($attributePlan, $id);
                }
                $this->metadataCommit($metadataPlan, $id, $adminId);
            });
        } catch (\Exception $e) {
            $this->metadataAbort($metadataPlan);
            $this->languageDraft->abort($languagePlan);
            throw $e;
        }

        $this->metadataPurge($metadataPlan, $id);
        $this->languageDraft->purge($languagePlan, $id);
        if ($deleteImage && $oldImageNumber !== '') {
            attachment()->delete($oldImageNumber);
        }
        audit()->writeAdminLog($adminId, AdminLogAction::UPDATE, 1, (string) $data['title']);
    }

    /**
     * 缩略图重生成页数据：进度遮罩与待批量处理的文件查询结果。
     *
     * @param array $data 可含 confirm 等前端状态
     * @return array mask、mask_tag、query
     */
    public function buildThumbData(array $data)
    {
        $list = Product::getThumbFileQueryList();
        $query = $list['query'];
        $count = $list['count'];
        $mask = array();
        $mask['count'] = preg_replace('/d%/Ums', $count, lang('product_thumb_count'));
        $mask_tag = '<i></i>';
        $mask['confirm'] = Arr::get($data, 'confirm', '');
        $mask['bg'] = '';
        for ($i = 1; $i <= $count; $i++) {
            $mask['bg'] .= $mask_tag;
        }
        return array('mask' => $mask, 'mask_tag' => $mask_tag, 'query' => $query);
    }

    /**
     * 批量重写缩略图：在模板已输出前提下逐条 thumb 并通过 JS 更新进度遮罩。
     *
     * @param resource $query 图片文件查询结果
     * @param string $maskTag 传给 mask() 的标签片段
     * @return void
     */
    public function thumbFlush($query, $maskTag)
    {
        $thumbSub = (string) $this->disk->getConfig('thumb_directory', '');
        $quality = (int) $this->disk->getConfig('image_quality', 0);
        $width = (int) Config::get('site.thumb_width', 0);
        $height = (int) Config::get('site.thumb_height', 0);

        echo ' ';
        while ($row = DB::fetchArray($query)) {
            $rel = ltrim(str_replace('\\', '/', $row['file']), '/');
            $srcAbs = str_replace('\\', '/', rtrim(ROOT_PATH, '/\\') . '/' . $rel);
            $thumbAbs = $this->resolveThumbAbsolute($srcAbs, $thumbSub);
            Image::thumb($srcAbs, $thumbAbs, $width, $height, $quality);
            echo "<script type=\"text/javascript\">mask('" . $maskTag . "');</script>";
            flush();
            ob_flush();
        }
        echo "<script type=\"text/javascript\">success();</script>\n</body>\n</html>";
    }

    /**
     * 由原图绝对路径生成缩略图绝对路径（与 AttachmentService 命名规则保持一致）。
     *
     * @param string $absolutePath
     * @param string $thumbSub
     * @return string
     */
    private function resolveThumbAbsolute($absolutePath, $thumbSub)
    {
        $dir = dirname($absolutePath);
        $name = basename($absolutePath);
        $dot = strrpos($name, '.');
        $base = $dot !== false ? substr($name, 0, $dot) : $name;
        $ext = $dot !== false ? substr($name, $dot + 1) : '';
        $sub = $thumbSub === '' ? '' : rtrim($thumbSub, '/') . '/';
        $thumbName = $base . '_thumb' . ($ext !== '' ? '.' . $ext : '');

        return $dir . '/' . $sub . $thumbName;
    }

    /**
     * 型号字符串关联：add 写入子项型号，del 清除指定或整组，返回最新型号列表 HTML。
     *
     * @param string $mode add|del
     * @param string $id 当前商品 id
     * @param string $action_id 关联操作目标 id
     * @return string HTML 片段
     */
    public function model($mode, $id, $action_id)
    {
        $model = Product::ensureOrCreateModelNumber($id);

        if ($mode === 'add') {
            Product::setModelById($action_id, $model);
        } else {
            if ($id == $action_id) {
                Product::clearModelByModelString($model);
            } else {
                Product::clearModelById($action_id);
            }
        }

        return $this->buildProductModelHtml($model, $id);
    }

    /**
     * 后台表单：商品「型号」关联条目 `<li>` 列表片段。
     *
     * 由控制器 model() Ajax 与 buildProductEditData 调用；承认其本质属于后台表单
     * UI 片段，故下沉到本端。
     *
     * @param string $model 商品型号字符串（同 model 字段的其它商品归为一组）
     * @param int|string $currentId 当前商品 id，用于「X」按钮绑定与排除自身
     * @return string HTML
     */
    public function buildProductModelHtml($model, $currentId)
    {
        if (!$model) {
            return '';
        }

        $rows = DB::table('product')
            ->field('id, image')
            ->where('model', $model)
            ->order('sort ASC, id DESC')
            ->select();
        $html = '';
        foreach ((array) $rows as $row) {
            $html .= '<li><img src="' . attachment()->url($row['image']) . '" /><span onclick="' . "modelBox('del', '$currentId', '" . $row['id'] . "');" . '" class="del">X</span></li>';
        }

        return $html;
    }

    /**
     * 单条删除：按 confirm 分支执行二次确认或实际删除。
     *
     * @param string $id
     * @param array $data 含 confirm 表示已确认
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException 记录不存在时抛出
     */
    public function delete($id, array $data)
    {
        $title = Product::whereKey($id)->value('title');
        if ($title === null || $title === false || $title === '') {
            throw new DomainException(lang('item_not_exist'), route('admin.product'));
        }

        // 已产生订单的商品禁止硬删除：订单明细与售后库存回补仍引用该商品 ID。
        if ($this->hasOrderItems($id)) {
            throw new DomainException(lang('product_del_has_order'), route('admin.product'));
        }

        if (isset($data['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $title);
            Product::destroy($id);
            return array(
                'message' => lang('product_del_succes'),
                'back_url' => route('admin.product'),
            );
        }

        $delCheck = preg_replace('/d%/Ums', $title, lang('del_check'));
        return array(
            'message' => $delCheck,
            'back_url' => route('admin.product'),
            'timeout' => '30',
            'confirm_url' => route('admin.product.destroy', array('id' => $id)),
        );
    }

    /**
     * 商品是否已被订单明细引用（order 模块未装时视为未引用）。
     *
     * @param int|string $id
     * @return bool
     */
    private function hasOrderItems($id)
    {
        if (!DB::tableExist('order_item')) {
            return false;
        }

        return (bool) DB::table('order_item')
            ->where('module', 'product')
            ->where('item_id', (int) $id)
            ->exists();
    }

    /**
     * 批量操作：del_all 批量删除，category_move 批量改分类，excel / excel_all 导出。
     *
     * @param array $data action、checkbox、new_cat_id 等
     * @return array|null array 表示 happy-path 消息；excel/excel_all 分支由 Excel::export 直出响应后返回 null
     * @throws DomainException 未勾选或不支持的动作
     */
    public function action(array $data)
    {
        $action = Arr::get($data, 'action', '');
        $checkbox = (isset($data['checkbox']) && is_array($data['checkbox'])) ? $data['checkbox'] : array();

        if ($action === 'excel') {
            $ids = Check::intIds($checkbox);
            if (empty($ids)) {
                throw new DomainException(lang('product_select_empty'), route('admin.product'));
            }
            Excel::export('product', $this->buildExcelProductList($ids));

            return null;
        }

        if ($action === 'excel_all') {
            Excel::export('product', $this->buildExcelProductList(array()));

            return null;
        }

        if (empty($checkbox)) {
            throw new DomainException(lang('product_select_empty'), route('admin.product'));
        }

        $ids = Check::intIds($checkbox);
        if (empty($ids)) {
            throw new DomainException(lang('product_select_empty'), route('admin.product'));
        }

        if ($action === 'del_all') {
            $deletable = array();
            $skipped = 0;
            foreach ($ids as $id) {
                if ($this->hasOrderItems($id)) {
                    $skipped++;
                    continue;
                }
                $deletable[] = $id;
            }
            if (empty($deletable)) {
                throw new DomainException(lang('product_del_has_order'), route('admin.product'));
            }

            Product::destroy($deletable);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:PRODUCT (' . count($deletable) . ' items)' . ($skipped > 0 ? ', skipped:' . $skipped : ''), 'product');
            $message = $skipped > 0
                ? sprintf(lang('product_del_skip_has_order'), $skipped)
                : lang('del_succes');

            return array('message' => $message, 'back_url' => route('admin.product'));
        }

        if ($action === 'category_move') {
            Product::whereIn('id', $ids)->update(array('category_id' => (int) Arr::get($data, 'new_cat_id', 0)));
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'category_move:PRODUCT (' . count($ids) . ' items)', 'product');
            return array('message' => lang('category_move_batch_succes'), 'back_url' => route('admin.product'));
        }

        throw new DomainException(lang('select_empty'), route('admin.product'));
    }

    /**
     * 组装商品导出数据（head + list）。
     *
     * 分类名与附件相对路径均批量预取，避免逐行查询。
     * 价格输出原始数值（不附加货币符号），保证导出结果可直接回导。
     *
     * @param array $checkbox 勾选的商品 ID；空数组表示全部
     * @return array head, list
     */
    public function buildExcelProductList(array $checkbox = array())
    {
        $query = Product::query()
            ->field('id, title, category_id, price, promote_price, stock, point, model, sort, status, created_at, image, sales, slug, keywords, description, content')
            ->applyDefaultOrder();

        if (!empty($checkbox)) {
            $ids = Check::intIds($checkbox);
            if (!empty($ids)) {
                $query->whereIn('id', $ids);
            }
        }

        $rows = array();
        $imageNumbers = array();
        foreach ($query->get() as $model) {
            $row = $model->getAttributes();
            $rows[] = $row;
            if (isset($row['image']) && (string) $row['image'] !== '') {
                $imageNumbers[] = (string) $row['image'];
            }
        }

        $categoryNames = $this->productCategoryNames();
        $imagePaths = empty($imageNumbers) ? array() : attachment()->relativePathBatch($imageNumbers);

        $excelList = array('head' => ProductExcelDefinition::head(), 'list' => array());
        foreach ($rows as $row) {
            $categoryId = isset($row['category_id']) ? (int) $row['category_id'] : 0;
            $imageNumber = isset($row['image']) ? (string) $row['image'] : '';

            $excelList['list'][] = ProductExcelDefinition::row(array(
                'id' => isset($row['id']) ? (int) $row['id'] : 0,
                'title' => isset($row['title']) ? $row['title'] : '',
                'category_name' => isset($categoryNames[$categoryId]) ? $categoryNames[$categoryId] : '',
                'price' => isset($row['price']) ? $row['price'] : '',
                'promote_price' => isset($row['promote_price']) ? $row['promote_price'] : '',
                'stock' => isset($row['stock']) ? (int) $row['stock'] : 0,
                'point' => isset($row['point']) ? (int) $row['point'] : 0,
                'model' => isset($row['model']) ? $row['model'] : '',
                'sort' => isset($row['sort']) ? (int) $row['sort'] : 0,
                'status_format' => (isset($row['status']) && (int) $row['status'] === 1) ? lang('status_1') : lang('status_0'),
                'created_at' => isset($row['created_at']) ? $row['created_at'] : '',
                'image' => array(
                    'image' => isset($imagePaths[$imageNumber]) ? $imagePaths[$imageNumber] : '',
                    'width' => 60,
                    'height' => 60,
                    'fallback' => '',
                ),
                'sales' => isset($row['sales']) ? (int) $row['sales'] : 0,
                'slug' => isset($row['slug']) ? $row['slug'] : '',
                'keywords' => isset($row['keywords']) ? $row['keywords'] : '',
                'description' => isset($row['description']) ? (string) $row['description'] : '',
                'content' => isset($row['content']) ? (string) $row['content'] : '',
            ));
        }

        return $excelList;
    }

    /**
     * 导入商品（受限：仅标量字段）。
     *
     * 列顺序见 {@see ProductExcelDefinition::importLabels()}，首行为表头。
     * 不支持图库、自定义字段 defined、会员价 level_price、多语言 metadata、促销起止时间。
     *
     * @return array ok / code / row（失败行号）/ created（新增条数）
     */
    public function importFromExcel()
    {
        $fields = ProductExcelDefinition::importFields();
        $read = Excel::read('file', array(
            'header_rows' => 1,
            'max_size' => 5242880,
            'max_rows' => 2000,
            'expected_columns' => count($fields),
        ));

        if (!$read['ok']) {
            return array('ok' => false, 'code' => $read['code'], 'row' => (int) $read['row'], 'created' => 0);
        }

        $categoryMap = $this->productImportCategoryMap();
        $adminId = (int) auth('admin')->id();
        $now = date('Y-m-d H:i:s');

        $dataList = array();
        foreach ($read['rows'] as $index => $cells) {
            $rowNumber = $index + 2; // 第 1 行为表头
            $item = array();
            foreach ($fields as $offset => $field) {
                $value = isset($cells[$offset]) ? $cells[$offset] : '';
                if (is_float($value)) {
                    // 避免 12.0 被写成 "12" 以外的科学计数法表现形式
                    $value = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
                }
                $item[$field] = is_scalar($value) ? trim((string) $value) : '';
            }

            if ($item['title'] === '') {
                return array('ok' => false, 'code' => 'invalid_title', 'row' => $rowNumber, 'created' => 0);
            }
            if (!$this->isNonNegativeNumber($item['price'], true)) {
                return array('ok' => false, 'code' => 'invalid_price', 'row' => $rowNumber, 'created' => 0);
            }
            if ($item['promote_price'] !== '' && !$this->isNonNegativeNumber($item['promote_price'])) {
                return array('ok' => false, 'code' => 'invalid_promote_price', 'row' => $rowNumber, 'created' => 0);
            }
            if (!$this->isNonNegativeInteger($item['stock'])) {
                return array('ok' => false, 'code' => 'invalid_stock', 'row' => $rowNumber, 'created' => 0);
            }
            if (!$this->isNonNegativeInteger($item['point'])) {
                return array('ok' => false, 'code' => 'invalid_point', 'row' => $rowNumber, 'created' => 0);
            }
            if (!$this->isNonNegativeInteger($item['sort'])) {
                return array('ok' => false, 'code' => 'invalid_sort', 'row' => $rowNumber, 'created' => 0);
            }

            $status = $this->parseImportStatus($item['status']);
            if ($status === null) {
                return array('ok' => false, 'code' => 'invalid_status', 'row' => $rowNumber, 'created' => 0);
            }

            if ($item['slug'] !== '' && !Check::slug($item['slug'])) {
                return array('ok' => false, 'code' => 'invalid_slug', 'row' => $rowNumber, 'created' => 0);
            }

            $categoryId = $this->parseImportCategoryId($item['category'], $categoryMap);
            if ($categoryId === null) {
                return array('ok' => false, 'code' => 'invalid_category', 'row' => $rowNumber, 'created' => 0);
            }

            $dataList[] = array(
                'title' => $item['title'],
                'category_id' => $categoryId,
                'operator_type' => 'admin',
                'operator_id' => $adminId,
                'price' => $item['price'],
                'promote_price' => $item['promote_price'] === '' ? '0.00' : $item['promote_price'],
                'stock' => $item['stock'] === '' ? 0 : (int) $item['stock'],
                'point' => $item['point'] === '' ? 0 : (int) $item['point'],
                'model' => $item['model'],
                'sort' => $item['sort'] === '' ? 50 : (int) $item['sort'],
                'status' => $status,
                'slug' => $item['slug'],
                'created_at' => $now,
            );
        }

        if (empty($dataList)) {
            return array('ok' => false, 'code' => 'empty', 'row' => 0, 'created' => 0);
        }

        $created = 0;
        DB::beginTransaction();
        try {
            foreach ($dataList as $data) {
                if (DB::table('product')->insert($data) === false) {
                    throw new \RuntimeException('product import insert failed');
                }
                $created++;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Admin product import failed', array(
                'channel' => 'product',
                'context' => array('message' => $e->getMessage()),
            ));

            return array('ok' => false, 'code' => 'db_error', 'row' => 0, 'created' => 0);
        }

        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, 'import:PRODUCT (' . $created . ' items)', 'product');

        return array('ok' => true, 'code' => 'success', 'row' => 0, 'created' => $created);
    }

    /**
     * 分类 ID → 名称映射（导出与导入共用）。
     *
     * @return array
     */
    private function productCategoryNames()
    {
        $map = array();
        foreach ((array) ProductCategory::flat() as $category) {
            $id = isset($category['id']) ? (int) $category['id'] : 0;
            if ($id > 0) {
                $map[$id] = isset($category['name']) ? trim((string) $category['name']) : '';
            }
        }

        return $map;
    }

    /**
     * 导入用分类映射：names 为 ID → 名称，ids 为名称 → ID（重名歧义记 -1）。
     *
     * @return array
     */
    private function productImportCategoryMap()
    {
        $names = $this->productCategoryNames();
        $ids = array();
        foreach ($names as $id => $name) {
            if ($name === '') {
                continue;
            }
            if (!isset($ids[$name])) {
                $ids[$name] = $id;
            } elseif ($ids[$name] !== $id) {
                $ids[$name] = -1;
            }
        }

        return array('names' => $names, 'ids' => $ids);
    }

    /**
     * 解析导入行分类：支持分类 ID 或精确分类名。
     *
     * @param string $raw
     * @param array $map productImportCategoryMap() 结果
     * @return int|null 0 表示未分类；null 表示无法解析
     */
    private function parseImportCategoryId($raw, array $map)
    {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '0') {
            return 0;
        }
        if (preg_match('/^\d+$/', $raw)) {
            $id = (int) $raw;

            return isset($map['names'][$id]) ? $id : null;
        }
        if (isset($map['ids'][$raw]) && $map['ids'][$raw] > 0) {
            return (int) $map['ids'][$raw];
        }

        return null;
    }

    /**
     * 解析导入行状态：空值默认上架，支持 1/0 与当前语言的显示/隐藏文案。
     *
     * @param string $raw
     * @return int|null null 表示无法识别
     */
    private function parseImportStatus($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '1' || $raw === lang('status_1')) {
            return 1;
        }
        if ($raw === '0' || $raw === lang('status_0')) {
            return 0;
        }

        return null;
    }

    /**
     * 非负数字校验（空值按 $required 决定是否放行）。
     *
     * @param mixed $value
     * @param bool $required
     * @return bool
     */
    private function isNonNegativeNumber($value, $required = false)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return !$required;
        }

        return is_numeric($value) && (float) $value >= 0;
    }

    /**
     * 非负整数校验（空值放行）。
     *
     * @param mixed $value
     * @return bool
     */
    private function isNonNegativeInteger($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return true;
        }

        return preg_match('/^\d+$/', $value) === 1;
    }
}
