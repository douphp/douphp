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
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Image;
use Dou\Core\Filesystem\Storage;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Service\Pricing\PricingService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\UploadedFile;

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
    /** @var \Dou\Core\Filesystem\Disk */
    private $disk;

    /** @var PricingService */
    private $pricingService;

    /** @var MarkdownRenderer */
    private $markdown;

    /**
     * @param PricingService $pricingService
     * @param MarkdownRenderer $markdown
     */
    public function __construct(PricingService $pricingService, MarkdownRenderer $markdown)
    {
        $this->disk = Storage::disk('product');
        $this->pricingService = $pricingService;
        $this->markdown = $markdown;
    }

    /**
     * 列表页数据：分页查询并补齐模板展示字段（分类名、图片、会员价档位等）。
     *
     * @param int $catId
     * @param string $keyword
     * @param int $page
     * @return array list、pager
     */
    public function buildProductListData($catId, $keyword, $page)
    {
        $pageUrl = route('admin.product');
        $catId = (int) $catId;
        if ($catId > 0) {
            $pageUrl .= '&category_id=' . $catId;
        }
        if ($keyword !== '') {
            $pageUrl .= '&keyword=' . $keyword;
        }

        $result = Product::with('category')
            ->filterByCategory($catId)
            ->filterByKeyword($keyword)
            ->field('id, title, price, level_price, promote_price, image, category_id, stock, point, sort, status, created_at')
            ->applyDefaultOrder()
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
                'promote_price' => $row['promote_price'] > 0 ? Util::formatPrice($row['promote_price']) : '',
                'level_price' => $userLevelOption !== null ? $userLevelOption->options(null, unserialize($row['level_price'])) : array(),
                'image' => $row['image'],
                'stock' => $row['stock'],
                'point' => $row['point'] ? $row['point'] : '-',
                'sort' => $row['sort'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
            );
        }

        return array(
            'list' => $productList,
            'pager' => $result['pager'],
        );
    }

    /**
     * 新增页默认数据：初始化自定义参数模板与图库列表 HTML。
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
            'defined' => '',
            'model_list' => '',
            'img_list_html' => attachment()->gallery('product', $itemId, 'gallery'),
        );

        if (!empty(Config::get('defined.product'))) {
            $defined = explode(',', Config::get('defined.product'));
            $definedProduct = '';
            foreach ($defined as $row) {
                $definedProduct .= $row . ":\n";
            }
            $product['defined'] = trim($definedProduct);
        }

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
     * @return int 新增记录主键（供 Controller 跳转编辑页）
     */
    public function insert(array $data, $draftToken, $adminId)
    {
        $draftToken = (string) $draftToken;
        $adminId = (int) $adminId;
        if ($draftToken === '' || $adminId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.product'));
        }

        $dou_user = (Config::get('features.user', false) && user()) ? user() : null;
        if (!empty($data['level_price']) && $dou_user) {
            $data['level_price'] = $this->pricingService->levelPrice($data['level_price']);
        }

        $content = isset($data['content']) ? xss()->content($data['content']) : '';
        if (!empty($data['content_remote_image_local'])) {
            $content = attachment()->storeDraftContentImages('product', $content, 'admin', $adminId, $draftToken, 'content', '');
        }
        $data['content'] = $content;
        $data['created_at'] = date('Y-m-d H:i', time());

        $data['operator_type'] = 'admin';
        $data['operator_id'] = $adminId;

        $product = Product::create($data);
        if (!$product) {
            throw new DomainException(lang('illegal'), route('admin.product'));
        }
        $newId = (int) $product->getKey();

        $image = attachment()->store('product', $newId, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withThumbnail(Config::get('site.thumb_width', 0), Config::get('site.thumb_height', 0))->withUploader('admin', $adminId));
        if ($image !== '') {
            Product::whereKey($newId)->update(array('image' => $image));
        }

        attachment()->claimByToken('product', $draftToken, $newId, 'admin', $adminId);

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
        $product['model_list'] = $this->buildProductModelHtml($product['model'], $id);
        $product['item_content'] = $this->markdown->toHtml($product['content']);

        if (!empty(Config::get('defined.product')) || !empty($product['defined'])) {
            $defined = !empty(Config::get('defined.product')) ? explode(',', Config::get('defined.product')) : array();
            $defined_product = '';
            foreach ($defined as $row) {
                $defined_product .= $row . ":\n";
            }
            $product['defined'] = $product['defined'] ? str_replace(',', "\n", $product['defined']) : trim($defined_product);
        }

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

        $image = attachment()->store('product', $id, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withThumbnail(Config::get('site.thumb_width', 0), Config::get('site.thumb_height', 0))->withUploader('admin', $adminId));
        $dou_user = (Config::get('features.user', false) && user()) ? user() : null;
        if (!empty($data['level_price']) && $dou_user) {
            $data['level_price'] = $this->pricingService->levelPrice($data['level_price']);
        }

        $content = isset($data['content']) ? xss()->content($data['content']) : '';
        if (!empty($data['content_remote_image_local'])) {
            $content = attachment()->storeContentImages('product', $id, $content, 'content', '', AttachmentUploadOptions::create()->withUploader('admin', $adminId));
        }
        $data['content'] = $content;
        if ($image) {
            $data['image'] = $image;
        }

        $product->fill($data, 'update')->save();
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
     * 批量操作：del_all 批量删除，category_move 批量改分类。
     *
     * @param array $data action、checkbox、new_cat_id 等
     * @return array{message: string, back_url: string}
     * @throws DomainException 未勾选或不支持的动作
     */
    public function action(array $data)
    {
        $action = Arr::get($data, 'action', '');

        if (empty($data['checkbox']) || !is_array($data['checkbox'])) {
            throw new DomainException(lang('product_select_empty'), route('admin.product'));
        }

        $ids = Check::intIds($data['checkbox']);
        if (empty($ids)) {
            throw new DomainException(lang('product_select_empty'), route('admin.product'));
        }

        if ($action === 'del_all') {
            Product::destroy($ids);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:PRODUCT (' . count($ids) . ' items)', 'product');
            return array('message' => lang('del_succes'), 'back_url' => route('admin.product'));
        }

        if ($action === 'category_move') {
            Product::whereIn('id', $ids)->update(array('category_id' => (int) Arr::get($data, 'new_cat_id', 0)));
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'category_move:PRODUCT (' . count($ids) . ' items)', 'product');
            return array('message' => lang('category_move_batch_succes'), 'back_url' => route('admin.product'));
        }

        throw new DomainException(lang('select_empty'), route('admin.product'));
    }
}
