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

namespace Dou\Core\Service;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 服务基类。
 *
 * 运行时依赖通过 helper / 门面就近解析：
 *   - 数据库：`DB::`（`use Dou\Core\Facade\DB;`）
 *   - 请求上下文：HTTP 输入（ip / routeAction / baseUrl / 输入字段等）由 shell 层
 *     （admin / front / api）从 Request 取好作为方法参数显式传入；core 服务自身不读 Request
 *   - 当前语言：`locale()`；译串表：`lang('key')` / `lang_set('k', V)` / `lang_has` / `lang_all`
 *   - 安全：`csrf()` / `xss()`；通用校验：`Check::xxx()`（`use Dou\Core\Support\Check;`）
 *   - 端门面：`auth('admin'|'front'|'api')`（仅 shell 层调用） / `language()` / `message()`
 *   - 视图：`View::` / `view($tpl, $data)`（`use Dou\Core\Facade\View;`）
 *   - 资源：`Storage::` / `attachment()` / `Image::` / `Zip::` / `route()`
 *   - 模块：`plugin()` / `audit()` / `user()` / `data()` / `other()`
 *   - 站点态：`Config::get('site.*')` / `Config::get('app.licensed')` / `Config::get('features.*')`
 *
 * ORM 访问采用静态门面：
 *   - **Model 不入参**：业务 Model（`Article` / `Product` / `User` 等）不进 Service 构造函数；
 *     表网关方法以 `Xxx::method()` 静态调用，由 `core/orm/Model::__callStatic` 代理到 Query Builder。
 *   - **写**：新增统一走 `Xxx::create($insertData)`；按主键更新走 hydrated 实例
 *     `Xxx::find($id)->fill($update)->save()`，或直接 `Xxx::whereKey($id)->update($update)`。
 *   - **读**：`Xxx::with('relation')->find() / first() / get() / paginate()`；命中 hydrated
 *     Model / Collection 后实例方法（关系 / 访问器 / mutators）由 ORM 自动接通。
 *   - **复杂查询 / 聚合**：抽取到 `*Reader` / `*Query` / `*Core` 服务类，按常规 DI 注入。
 *
 * Service 构造注入示例（复杂查询服务）：
 * <pre>
 * class ArticleService extends BaseService {
 *     private $reader;
 *     public function __construct(ArticleReader $reader) {
 *         $this->reader = $reader;
 *     }
 *     public function publish($payload) {
 *         return Article::create($payload);   // 写：静态门面
 *     }
 *     public function detail($id) {
 *         return Article::with('category')->find((int) $id); // 读：静态门面
 *     }
 * }
 * </pre>
 */
abstract class BaseService
{
}
