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

namespace Dou\Admin\Request\Product;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台商品批量操作请求校验（route=product/action）。
 *
 * 支持 del_all / category_move / excel / excel_all 四种动作；
 * excel_all 允许 checkbox 为空，具体勾选校验由 ProductService 按动作判定。
 */
class ProductActionFormRequest extends FormRequest
{
    /**
     * 输入规则（请求层）：
     * - action 必填，限定为 del_all/category_move/excel/excel_all；
     * - checkbox 可选数组，excel_all 时无需勾选条目；
     * - new_cat_id 仅 category_move 使用，必须是整数。
     *
     * @return array
     */
    public function rules()
    {
        return array(
            'action' => 'required|in:del_all,category_move,excel,excel_all',
            'checkbox' => 'array',
            'new_cat_id' => 'integer',
        );
    }
}
