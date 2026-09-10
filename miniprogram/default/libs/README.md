# libs/ 小程序端第三方库（手动维护）

本目录存放 DouPHP 小程序端使用的第三方库源码。所有库都是「手动从 npm registry / unpkg 下载并提交进仓库」的，**不依赖** Node.js / npm / 微信开发者工具的「工具 → 构建 npm」流程。

## 设计目标

- **终端用户零依赖**：DouPHP 用户下载解压后，打开微信开发者工具直接能跑，不需要装 Node.js、不需要联网下载依赖。
- **升级随系统版本走**：由 DouPHP 维护者在版本升级时统一替换，不下放给最终用户。
- **与 `core/library/sms/` 同一哲学**：第三方代码直接拷进项目核心目录，按业务功能分库，保留 LICENSE 满足开源协议合规。

## 当前清单

### mobx-miniprogram

- **版本**：6.12.3（published 2024-05-15）
- **来源**：<https://www.npmjs.com/package/mobx-miniprogram>
- **GitHub**：<https://github.com/mobxjs/mobx>（mobx 主仓库的小程序兼容构建）
- **协议**：MIT（详见 `mobx-miniprogram/LICENSE`）
- **入库日期**：2026-06-07
- **用途**：响应式状态管理（observable / action / computed / reaction / makeAutoObservable / autorun / when / flow 等）
- **dependencies**：0（自包含）
- **入库文件**：
  - `mobx-miniprogram/index.js`（来源：原包 `dist/mobx.cjs.production.min.js`，~52 KB）
  - `mobx-miniprogram/index.d.ts`（**手写最小 d.ts shim**，仅声明业务用到的 `observable`/`action`/`computed`/`runInAction`/`autorun`/`reaction` 等导出；详见 §"d.ts 类型 shim"）
  - `mobx-miniprogram/package.json`（精简至 6 字段，仅保留 name/version/license/author/homepage/main）
  - `mobx-miniprogram/LICENSE`（MIT 协议原文，Copyright Michel Weststrate 2015）
- **vendor 完整性**：`index.js` **未修改**，字节级与上游一致

### mobx-miniprogram-bindings

- **版本**：6.0.0
- **来源**：<https://www.npmjs.com/package/mobx-miniprogram-bindings>
- **GitHub**：<https://github.com/wechat-miniprogram/mobx-miniprogram-bindings>（**微信小程序官方** GitHub 组织维护）
- **协议**：MIT（详见 `mobx-miniprogram-bindings/LICENSE`）
- **入库日期**：2026-06-07
- **用途**：把 mobx store 字段自动绑定到 Page / Component（createStoreBindings / storeBindingsBehavior / ComponentWithStore / BehaviorWithStore / initStoreBindings）
- **peerDependencies**：`mobx-miniprogram ^6.0.0`（由本目录的 mobx-miniprogram 满足）
- **入库文件**：
  - `mobx-miniprogram-bindings/index.js`（来源：原包 `dist/index.js`，~6 KB，**对原包做了一处 require 路径改写**，详见 `mobx-miniprogram-bindings/PATCH.md`）
  - `mobx-miniprogram-bindings/index.d.ts`（**手写 d.ts shim**，导出 `createStoreBindings`/`storeBindingsBehavior`/`ComponentWithStore`/`BehaviorWithStore`/`initStoreBindings`）
  - `mobx-miniprogram-bindings/package.json`（精简至 6 字段）
  - `mobx-miniprogram-bindings/LICENSE`（MIT 协议原文，Copyright wechat-miniprogram 2019）
  - `mobx-miniprogram-bindings/PATCH.md`（记录对原 index.js 的唯一一处改动）

### miniprogram-api-typings

- **版本**：5.2.1
- **来源**：<https://www.npmjs.com/package/miniprogram-api-typings>
- **GitHub**：<https://github.com/wechat-miniprogram/api-typings>（**微信小程序官方** GitHub 组织维护）
- **协议**：MIT（详见 `miniprogram-api-typings/LICENSE`）
- **入库日期**：2026-06-07
- **用途**：`wx.*` 全量 API 的 TypeScript 类型定义（`wx.request` / `wx.getStorageSync` / `WechatMiniprogram.*` 命名空间等），供全目录 `.ts` 编译期类型检查使用
- **dependencies**：0（纯 `.d.ts`）
- **入库文件**：
  - `miniprogram-api-typings/index.d.ts` + `types/`（`wx/lib.wx.*.d.ts` 等约 20 个声明文件）
  - `miniprogram-api-typings/typings.json` / `package.json`（精简）
  - `miniprogram-api-typings/LICENSE`
- **全局可见机制**：由 `tsconfig.json` 的 `include: ["**/*.d.ts"]` 自动纳入，**未使用** `types` 字段；所有 `.ts` 文件直接可见 `wx.*` 与 `WechatMiniprogram.*` 类型，无需手动 `import`。
- **vendor 完整性**：未修改，与上游一致

## d.ts 类型 shim

本目录两个 mobx vendor 的运行时是 CommonJS 压缩 JS，类型由**同目录手写 `index.d.ts`** 提供（TS 按"同名同目录"自动解析）：

- `mobx-miniprogram/index.d.ts`：从 mobx 6 主包类型摘录业务实际用到的子集（`observable` / `action` / `computed` / `runInAction` / `autorun` / `reaction` 等）。mobx-miniprogram 是 mobx 6 的小程序兼容构建，API 完全兼容。
- `mobx-miniprogram-bindings/index.d.ts`：声明 `createStoreBindings` / `storeBindingsBehavior` / `ComponentWithStore` / `BehaviorWithStore` / `initStoreBindings`。

shim 按**按需扩充**原则维护：业务用到新的 mobx / bindings 导出时，再往对应 `.d.ts` 补声明。升级 vendor 运行时（`index.js`）时若上游 API 有签名变化，需同步核对 shim。

工程化全貌（tsconfig、引用约定、本地 `tsc --noEmit` 校验、防误生成 `.js`）见 [`../TYPESCRIPT.md`](../TYPESCRIPT.md)。

## 引用方式

业务代码（TypeScript）用 **ESM `import` + 显式相对路径 + `.js` 后缀 + 分别 import**，不走 npm 解析、不做聚合出口（决策见 [`../TYPESCRIPT.md`](../TYPESCRIPT.md) §5）：

```ts
// 在 store / page / component 里（路径深度按文件层级调整）
import { observable, action, computed, runInAction, reaction } from '../libs/mobx-miniprogram/index.js'
import { createStoreBindings, storeBindingsBehavior } from '../libs/mobx-miniprogram-bindings/index.js'
```

`import` 路径写 `.js` 后缀（指向 vendor 的 CommonJS 运行时），TS 编译器自动从同目录同名 `index.d.ts` 取类型 —— 这是 TS 标准的 module resolution，无需额外配置。

> **不做 `utils/mobx.ts` 聚合出口**：聚合每页省 1 行，代价是新增内部约定 + 维护中间层，对开源二次开发场景收益不抵成本；分别 import 是 mobx-miniprogram 官方文档同款写法，最少惊讶。

## 与 `project.config.json` 的关系

本方案使用**纯显式相对路径** require，**不依赖**微信小程序的 npm 解析机制。`project.config.json` 应保持：

```json
"nodeModules": false
```

（这是工具默认值。如果在迁移过程中曾改为 `true`，应改回 `false` 以保持配置干净。）

## 升级流程（PowerShell）

> 升级周期：mobx-miniprogram / mobx-miniprogram-bindings API 多年稳定（核心 API `observable`/`action`/`computed`/`createStoreBindings` 等自 v3 以来未变），建议**按 DouPHP 系统版本节奏统一升级**而非定期升级。出现新 minor / patch 时先看上游 changelog 是否影响现有 store 写法，再决定是否升级。

### 1. 查最新版本

打开 <https://www.npmjs.com/package/mobx-miniprogram> 与 <https://www.npmjs.com/package/mobx-miniprogram-bindings>，记录新版本号。

### 2. 替换 index.js + LICENSE

```powershell
$ver1 = '6.12.3'    # 替换为最新版
$ver2 = '6.0.0'     # 替换为最新版

$ProgressPreference = 'SilentlyContinue'

# mobx-miniprogram（index.js 原样下载，无需 patch）
Invoke-WebRequest -Uri "https://unpkg.com/mobx-miniprogram@$ver1/dist/mobx.cjs.production.min.js" `
    -OutFile "miniprogram\default\libs\mobx-miniprogram\index.js" -UseBasicParsing
Invoke-WebRequest -Uri "https://unpkg.com/mobx-miniprogram@$ver1/LICENSE" `
    -OutFile "miniprogram\default\libs\mobx-miniprogram\LICENSE" -UseBasicParsing

# mobx-miniprogram-bindings（index.js 下载后必须 patch 一处 require 路径）
Invoke-WebRequest -Uri "https://unpkg.com/mobx-miniprogram-bindings@$ver2/dist/index.js" `
    -OutFile "miniprogram\default\libs\mobx-miniprogram-bindings\index.js" -UseBasicParsing
Invoke-WebRequest -Uri "https://unpkg.com/mobx-miniprogram-bindings@$ver2/LICENSE" `
    -OutFile "miniprogram\default\libs\mobx-miniprogram-bindings\LICENSE" -UseBasicParsing

# 应用 PATCH：把 require("mobx-miniprogram") 改成相对路径
$bindingsPath = "miniprogram\default\libs\mobx-miniprogram-bindings\index.js"
$content = Get-Content $bindingsPath -Raw -Encoding UTF8
$patched = $content -replace 'require\("mobx-miniprogram"\)', 'require("../mobx-miniprogram/index.js")'
[System.IO.File]::WriteAllText((Resolve-Path $bindingsPath), $patched, [System.Text.UTF8Encoding]::new($false))
```

### 2b. 替换 miniprogram-api-typings（wx.* 类型）

```powershell
$ver3 = '5.2.1'    # 替换为最新版

# 下载 tgz（unpkg 不便取整个目录树时走 npmmirror tgz 更稳）
Invoke-WebRequest -Uri "https://registry.npmmirror.com/miniprogram-api-typings/-/miniprogram-api-typings-$ver3.tgz" `
    -OutFile "$env:TEMP\api-typings.tgz" -UseBasicParsing
# 解压后用 package/ 下的 index.d.ts + types/ + typings.json + LICENSE 覆盖
#   miniprogram\default\libs\miniprogram-api-typings\
```

> api-typings 是纯 `.d.ts`，无运行时、无需 patch；覆盖后跑一次 `tsc --noEmit` 确认与业务 `.ts` 兼容即可。

### 3. 手动更新 package.json 的 version 字段

三个包各打开 `package.json`，把 `"version"` 字段更新到新版本号。其它字段保持不动。

### 4. 同步本文件

更新本文件的「当前清单」段落里的版本号与入库日期，必要时也更新 `mobx-miniprogram-bindings/PATCH.md`。

### 5. 烟测

打开微信开发者工具，编译运行，确认无报错；按 `.cursor/skills/e2e-smoke/SKILL.md` 跑核心流程。重点检查：

- store 的 observable 改值后 page 自动 setData
- createStoreBindings / destroyStoreBindings 生命周期正常
- bindings 的 `require("../mobx-miniprogram/index.js")` 能正确解析到 `libs/mobx-miniprogram/index.js`

### 6. 提交

```sh
git add miniprogram/default/libs/
git commit -m "chore(miniprogram): bump mobx-miniprogram to <new-version>"
```

## 国内镜像

如下载 unpkg 较慢，可换为 npmmirror（淘宝镜像，由阿里维护）：

```
https://registry.npmmirror.com/mobx-miniprogram/-/mobx-miniprogram-<version>.tgz
https://registry.npmmirror.com/mobx-miniprogram-bindings/-/mobx-miniprogram-bindings-<version>.tgz
```

下载 tgz 后解压取 `package/dist/` 下的对应文件。

## 模块化兼容与 `features` 单一真相源

DouPHP 是模块化系统：只有 `product / article / data` 是必装核心模块，其余（`user / order / book / vip / point / money / withdraw / coupon / favorites / distribution / comment / aftersale / health / share / ai` 等）均为可选。小程序端在可选模块未安装时必须**不报错、自动降级**。落地机制（细节见 [`../TYPESCRIPT.md`](../TYPESCRIPT.md)）：

- **单一真相源**：后端 `DataboxService::buildCommonDatabox` 的 `data.open = Config::get('features')` 下发到小程序，统一由 `stores/common.ts` 的 `commonStore.features` 暴露。页面用字段别名 `open: () => commonStore.features` 保持 wxml 里 `{{open.x}}` 不变。
- **类型层强约束**：`types/api.d.ts` 把 `Features` 分为「必装」（`product/article/data` 非可选）+「可选」（`boolean | undefined`，编译期强制处理 `undefined`）；并维护 `USER_DERIVED_FEATURES`（与 PHP 端 `link_user_center` 同步）。
- **查询工具**：`utils/module-guard.ts` 提供 `isModuleEnabled` / `isUserDerivedModuleEnabled`（纯查询、无副作用、不做主动跳转）。
- **store 降级**：`authStore` 在 `features.user === false` 时 `is_login` 恒 false、不发请求；`cartStore` 在 `features.order === false` 时数量恒 0、`refresh()` 短路。覆盖 bootstrap 期与发布版未装 user/order 场景。
- **service 静默兜底**：可选模块 API 失败时 `catch` 后返回空数据，不弹错。
- **不做**路由级守卫跳转 / 专用提示页：发布版打包按已装模块裁剪 `app.json`，未装模块 URL 由微信 `onPageNotFound` 系统默认接管（与 `data/installed/` 安装清单同机制，不在小程序重构范围内）。

## 注意事项

- **禁止** 在本目录下手动新增非 vendor 的文件——本目录的语义是「第三方库 vendor」，DouPHP 自有代码应放在 `miniprogram/default/utils/` / `miniprogram/default/stores/` / `miniprogram/default/services/` 等业务目录。
- **禁止** 修改 vendor 源码（包括压缩 JS 内的字符串、变量名等），**除非**已在对应包的 `PATCH.md` 中明确记录。当前唯一允许的 patch 是 mobx-miniprogram-bindings/index.js 的 require 路径改写，详见 `mobx-miniprogram-bindings/PATCH.md`。
- **微信开发者工具的「工具 → 构建 npm」按钮在本仓库不需要点**——本方案完全不走小程序 npm 工作流。如果误点了，工具会去找根目录的 `package.json`，找不到就报错，对本目录无影响。
