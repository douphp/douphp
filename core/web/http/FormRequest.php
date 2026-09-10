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

namespace Dou\Core\Web\Http;

use Dou\Core\Facade\DB;
use Dou\Core\Web\Validation\Validator;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表单请求基类（验证 + 白名单字段提取）
 *
 * 由容器解析时附带 scene 字段（控制器方法名），用于业务场景区分。
 */
abstract class FormRequest
{
    /** @var string 当前场景（如 store/update） */
    protected $scene = '';

    /**
     * @param string $scene
     */
    public function __construct($scene = '')
    {
        $this->scene = $this->normalizeScene(trim((string) $scene));
    }

    /**
     * 返回规则数组，格式 ['field' => 'rule1|rule2']。
     *
     * @return array
     */
    abstract public function rules();

    /**
     * 执行校验并返回白名单字段（仅 POST 数据）。
     *
     * @return array
     */
    public function validated()
    {
        $rules = (array) $this->rules();
        $data = $this->validationData();

        $validator = new Validator(
            DB::getFacadeRoot(),
            lang_all(),
            request()->routeModule()
        );
        $validator->validate($data, $rules);

        if (empty($rules)) {
            return array();
        }

        return array_intersect_key($data, $rules);
    }

    /**
     * 提供校验数据源，默认仅使用 POST。
     *
     * @return array
     */
    protected function validationData()
    {
        return request() ? (array) request()->post() : array();
    }

    /**
     * 归一化场景名称，子类可覆盖实现自定义映射。
     *
     * @param string $scene
     * @return string
     */
    protected function normalizeScene($scene)
    {
        return $scene;
    }
}
