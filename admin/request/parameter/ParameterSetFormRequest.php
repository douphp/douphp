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

namespace Dou\Admin\Request\Parameter;

use Dou\Admin\Model\Parameter\Parameter;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 参数值批量保存（save）：按当前分组动态生成可提交字段白名单。
 */
class ParameterSetFormRequest extends FormRequest
{
    /** @var Parameter */
    private $parameterModel;

    /**
     * @param string $scene
     */
    public function __construct($scene = '')
    {
        parent::__construct($scene);
        $this->parameterModel = new Parameter();
    }

    /**
     * @return array
     */
    public function rules()
    {
        $post = request() ? (array) request()->post() : array();
        $groupRaw = isset($post['group']) ? (string) $post['group'] : '';
        $group = '';
        if ($groupRaw !== '' && Check::letter($groupRaw)) {
            $group = $groupRaw;
        }

        $allowedNames = $this->parameterModel->fetchNamesForSetWhitelist($group);

        $rules = array(
            'token' => 'required',
            'group' => '',
        );

        if (is_array($allowedNames)) {
            foreach ($allowedNames as $name) {
                if ($name === '' || $name === 'token' || $name === 'group') {
                    continue;
                }
                $rules[$name] = '';
            }
        }

        return $rules;
    }
}
