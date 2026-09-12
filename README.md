# DouPHP 模块化企业网站管理系统

简洁、安全、模块化的企业建站与电商系统，一套代码同时覆盖 **PC 网站、手机站、微信小程序** 三端。

DouPHP 采用「三端入口 + 共享核心 + 模块化」架构，内置会员中心、工作人员端、订单支付等完整业务体系，并原生面向 AI 时代设计：

- **AI 能力内置**：内置 AI 模块，内容模块可接入 AI 检索与生成，让站点从「建好」升级为「智能」
- **GEO 生成式引擎优化**：自动生成 `llms.txt`（AI 站点地图）与 Schema 结构化数据，让站点内容可被 ChatGPT、豆包、Perplexity 等 AI 搜索引擎高效收录与引用，抢占 AI 搜索流量

既可快速搭建企业官网，也能支撑复杂的在线业务系统。

- **开发语言**：PHP 5.6 – 8.x
- **开源协议**：MIT，可自由使用、修改、分发（含商业用途），详见 [LICENSE](LICENSE)

## 特性

- **三端一体**：前台（front）、后台（admin）、API（api）三端独立入口、共享核心，互不污染
- **模块化设计**：40+ 官方模块按需安装，文章、产品、订单、会员、评论、预约、表单、投票等开箱即用
- **微信小程序**：内置 TypeScript 小程序源码（含 WeUI 组件库），与 API 端控制器一一对应
- **会员中心 & 工作人员端**：前台内置会员中心与工作人员功能区，菜单由模块配置驱动
- **AI 能力**：内置 AI 模块，内容模块可接入 AI 检索与生成
- **支付与登录插件**：微信支付、支付宝、PayPal、连连支付等支付插件，微信 / QQ / 微博第三方登录
- **多语言**：简体中文、繁体中文、英文三语言包，前台支持语言前缀路由（如 `/en/article/1`）
- **安全机制**：CSRF 自动校验、XSS 过滤、登录限流、后台权限分级、安全响应头、审计日志
- **自研模板引擎**：Smarty 风格的 DouView 模板引擎，`.dwt` 模板 + 主题脚本，模板开发简单直观
- **现代 PHP 架构**：DI 容器、Facade 门面、ActiveRecord + Query Builder ORM、PSR-4 自动加载

## 环境要求

- PHP 5.6 – 8.x（建议 PHP 8.x）
- MySQL
- Apache / Nginx（启用 URL 重写）

PHP 需启用或安装以下扩展 / 配置：

- **GD** 扩展（图片处理）
- **MySQL** 扩展（PDO 或 mysqli，数据库连接）
- **OpenSSL** 扩展（部分支付与第三方登录依赖）
- 伪静态：`mod_rewrite`（Apache）/ `try_files` 规则（Nginx）

建议使用平台：Linux + Apache / Nginx + PHP 8.x + MySQL 5.7

## 快速安装

1. 下载源码并部署到网站根目录
2. 访问网站首页，系统自动跳转到安装程序 `install/index.php`
3. 按提示填写数据库信息、设置管理员账号，完成安装
4. 安装完成后系统会在 `storage/install.lock` 写入安装锁，再次访问将不会进入安装程序
5. 后台默认入口为 `admin/`（可在 `config/admin_dir.php` 中自定义以隐藏后台地址）

如需重新安装，删除 `storage/install.lock` 后重新访问安装程序即可。

## 目录结构

```
douphp/
├── admin/            后台（管理员使用，HTML 渲染）
├── api/              HTTP API（JSON，供小程序 / SPA / 第三方调用）
├── front/            前台（访客与会员使用，HTML 渲染）
├── core/             核心层（三端共享：ORM / 容器 / 安全 / 通用服务）
├── config/           站点配置（数据库、模块清单、路由、安全等）
├── languages/        多语言包（zh_cn / zh_tw / en_us，按端 + 模块分文件）
├── theme/            前台主题模板（.dwt 模板 + 主题脚本）
├── miniprogram/      微信小程序源码（TypeScript + WeUI）
├── plugin/           插件（支付、第三方登录等，按子目录独立成包）
├── storage/          运行时存储（cache / log / backup / tmp）
├── images/           站点静态图片资源
├── index.php         前台入口
├── admin/index.php   后台入口
└── api/index.php     API 入口
```

## 模块化

模块是 DouPHP 组织业务功能的基本单位，分为两类：

| 形态       | 特征                | 示例                                          |
| -------- | ----------------- | ------------------------------------------- |
| **栏目模块** | 带分类树，有分类列表页 + 详情页 | product、article、doc、course、download、video 等 |
| **简单模块** | 无分类树，单一实体或业务功能    | order、user、comment、book、form、chat、faq 等     |

默认安装仅含 `product` / `article` / `data` 三个模块，其余在后台「模块管理」中按需安装，模块清单由 `config/module.php` 统一管理。

模块还支持关联标记：接入会员中心（`link_user_center`）、工作人员端（`link_work_center`）、AI 能力（`link_ai`）、可下单购买（`link_order_item`）。

## 二次开发

- 推荐**先阅读** [系统结构分析.md](系统结构分析.md)，它完整描述了系统架构、模块组织、请求生命周期与开发约定
- 新建一个模块 = 在三端按约定落地 controller / service / model / route / request + 语言包 + 模板，详见系统结构分析文档 §10
- 后台模板为 `admin/view/<模块名>/*.htm`，前台模板为 `theme/<主题名>/<模块名>.dwt`
- 编码规范：UTF-8 无 BOM、4 空格缩进、LF 换行，PHP 语法需兼容 5.6

## 开源协议

本项目基于 [MIT License](LICENSE) 开源发布。

## 补充声明

- 本项目名称 "DouPHP" 及官方 Logo 不在 MIT 授权范围内，未经授权不得用于衍生项目的名称与宣传
- 项目内置的第三方组件（jQuery、Bootstrap Icons、WeUI 等）遵循其各自的原始授权协议

