# DouPHP 小程序端 TypeScript 工程化指南

本目录（`miniprogram/default/`）已整体迁移为 TypeScript。本文说明工程化机制、目录约定、类型来源与维护方式，**面向 DouPHP 维护者**，最终用户无需关心（解压即用、零 Node.js 依赖）。

## 1. 核心机制：微信开发者工具原生编译，零 npm

- `project.config.json` → `setting.useCompilerPlugins: ["typescript"]`：开启工具内置的 TypeScript 编译插件（v1.05.2109101 / 2021-09 起）。工具内部用 `@babel/plugin-transform-typescript` 把 `.ts` **按需即时**转 `.js`，**不需要** npm / Node.js / `package.json`。
- `setting.nodeModules: false`：完全不走小程序 npm 工作流，工具不扫描 / 不同步 `libs/`，手工维护的 vendor 文件不会被覆盖。
- **源码目录不出现 `.js` 产物**：编译产物进工具内部缓存，不写回源码目录。`stores/common.ts` 旁永远不会自动出现 `stores/common.js`。

> ⚠️ **`useCompilerPlugins` 不能重复声明**：`project.config.json` 的 `setting` 内若同时出现 `"useCompilerPlugins": ["typescript"]` 与 `"useCompilerPlugins": false`，JSON 后者覆盖前者，TS 编译会被静默关闭、`.ts` 页面运行时全部失效。该键在 `setting` 内必须**唯一**且为 `["typescript"]`。

## 2. 关键限制：工具只"擦类型"，不做类型检查

微信工具的 TS 编译只擦类型注解，**类型错误不阻塞编译 / 预览 / 上传**。因此：

- **IDE 层**（Cursor / VSCode）：完整 TS Language Server，红线 / Hover / 跳转 / refactor 全有 —— 开发者必须看 IDE 红线，不能依赖"构建失败"兜底。
- **维护者发版前**：本地跑一次 `tsc --noEmit` 做强制类型检查（仅维护者机器需要装 `typescript`，不下放给最终用户）。

### 本地强制类型检查

仓库不内置 `node_modules`。一次性建临时校验环境即可：

```powershell
# 在 miniprogram/default 下
mkdir .tsverify; cd .tsverify
npm init -y
npm install --no-save typescript
cd ..
node ./.tsverify/node_modules/typescript/bin/tsc --noEmit -p tsconfig.json
```

退出码 0 = 类型全绿。`.tsverify/` 是临时目录，**不入库**（见 `.gitignore`）。

## 3. `tsconfig.json`

```jsonc
{
  "compilerOptions": {
    "target": "ES2015",            // 与 project.config.json es6:true 对齐
    "module": "CommonJS",          // 与 mobx-miniprogram vendor 的 cjs 一致
    "moduleResolution": "node",
    "strict": true,                // 严格模式全开，吃满 TS 收益
    "noImplicitAny": true,
    "strictNullChecks": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "forceConsistentCasingInFileNames": true,
    "lib": ["ES2018", "ES2019.Object"],  // ES2019.Object 为 Object.fromEntries 所需
    "baseUrl": "."
  },
  "include": ["**/*.ts", "**/*.d.ts"],
  "exclude": ["libs/**/*.js", "utils/grcode/**"]
}
```

要点：

- **不写 `outDir`、不写 `noEmit: false`**：避免编辑器插件"保存时编译"把 `.js` 写回源码目录。强制检查只用 `tsc --noEmit`，从不裸跑 `tsc`。
- **`include: ["**/*.d.ts"]`**：所有 `.d.ts`（含 `libs/miniprogram-api-typings/`、`types/`、各 d.ts shim）自动纳入，不需要 `types` 字段。
- **`exclude` vendor 的 `.js`**：mobx 压缩产物与 `utils/grcode/` 不参与 TS 检查；类型靠同目录 `.d.ts` 提供。

## 4. 目录与类型来源

```
miniprogram/default/
├── tsconfig.json
├── app.ts                         应用引导（onLaunch 注册 http 拦截器 + bootstrapStores）
├── types/                         业务契约类型（手写）
│   ├── api.d.ts                   与 DataboxService::buildCommonDatabox 字段对齐 + Features 强类型
│   ├── store.d.ts                 各 store 形态
│   └── wx-ext.d.ts                Page/Component 实例扩展（storeBindings 等 [key:string]:any）
├── config/
│   ├── site.ts                      InstallService 同步写入 root_url / mp_url 等
│   └── seed.generated.ts            冷启动语言种子（lang 全表 + siteName，与 route=lang 同源）
├── stores/                        mobx-miniprogram 状态层
│   ├── index.ts                   聚合 + bootstrapStores + tabBar 角标 autorun
│   ├── common.ts / auth.ts / cart.ts / persist.ts
├── services/                      业务/HTTP 服务层
│   ├── http.ts                    DouPHP 信封解析 + 拦截器 + SWR/dedupe + ApiError
│   ├── bootstrap.ts               route=bootstrap 站点/功能/导航
│   ├── lang.ts                    route=lang 拉取译串全表
│   ├── databox.ts / user.ts / captcha.ts / upload.ts
├── utils/                         无状态工具
│   ├── dou.ts                     admin sync 写入端（PHP preg_replace 替换 root_url / debug_enable）
│   ├── page_title.ts              导航标题：pageTitle(key) 读 commonStore.lang / site
│   ├── i18n.ts                    TS 侧 t(key)（wx.showModal 等）
│   ├── url.ts / ui.ts / env.ts / countdown.ts / promotion.ts / module-guard.ts
│   └── grcode/                    vendor，不参与 TS 检查
├── libs/                          零 npm 手动 vendor
│   ├── mobx-miniprogram/          index.js + 手写 index.d.ts
│   ├── mobx-miniprogram-bindings/ index.js + 手写 index.d.ts + PATCH.md
│   └── miniprogram-api-typings/   wx.* 官方类型（vendor）
├── components/navbar/navbar.ts    绑 commonStore 的组件示例
└── pages/**/*.ts                  全部页面（已无 .js）
```

### 类型来源三条线

1. **wx.\* API 类型**：`libs/miniprogram-api-typings/`（来源 `wechat-miniprogram/api-typings`，手动 vendor），由 `include` 全局可见。
2. **mobx / bindings 类型**：`libs/mobx-miniprogram/index.d.ts`、`libs/mobx-miniprogram-bindings/index.d.ts`（手写 shim）。
3. **业务契约类型**：`types/api.d.ts` / `types/store.d.ts`（与 PHP 后端 `DataboxService` 对齐，改字段时编译期飘红）。

## 5. 引用约定（显式相对路径 + `.js` 后缀）

vendor 是手动 CommonJS 产物，`import` 路径写 `.js` 后缀，TS 按"同目录同名 `.d.ts`"自动取类型：

```ts
import { observable, action } from '../libs/mobx-miniprogram/index.js'
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { commonStore } from '../../stores/index.js'
```

路径深度速查：

| 文件位置 | 相对前缀 |
|---|---|
| `app.ts`（根） | `./libs/...` `./stores/...` |
| `stores/` `services/` `utils/`（一层） | `../libs/...` |
| `pages/{module}/{action}.ts`（两层） | `../../libs/...` `../../stores/...` |
| `pages/book/work/show.ts`（三层） | `../../../libs/...` |

## 6. 页面迁移落地模式

每个页面统一遵循：

- `onLoad` 内 `this.storeBindings = createStoreBindings(this, { store: commonStore, fields: {...} })`；`onUnload` 内 `this.storeBindings.destroyStoreBindings()`（防 mobx reaction 内存泄漏）。
- 需要后端 `open`（模块开关）的页面用字段别名 `open: () => commonStore.features` 保持 wxml 里 `{{open.x}}` 不变。
- 删除每页旧的 `databox/common` 拉取与 `setData lang/site`（改由 store 绑定）。
- 登录态：`authStore.ensureLogin()` 串联鉴权；登录页登录成功收口到 `authStore.login()`。
- 购物车数量：`cartStore.refresh()` 取代旧 `dou.showCartNumber()`；tabBar 角标由 `stores/index.ts` 的 autorun 读 `cartStore.badge` 统一驱动。
- 冷启动文案：`commonStore` 以 `config/seed.generated.ts` 初始化 `lang` / `site.site_name`（第 0 帧即有 `{{lang.xxx}}`）；`refresh()` 成功后网络数据覆盖。后台「同步小程序配置」会重写该文件。
- 导航标题：`onLoad` 内 `this.setData({ title: pageTitle('module_key') })`；`pageTitle` 读 `commonStore`（种子兜底，无需 autorun）。
- `data` 用 `{} as Record<string, any>` 承接灵活字段，避免逐字段声明的迁移摩擦。

## 7. 防"误生成 .js"三道闸

1. **同名 `.js`/`.ts` 共存**：迁移时同一次提交里删 `.js` + 新增 `.ts`，code review 检查无共存（工具规则：同名优先 `.ts`，但不会自动删 `.js`）。
2. **编辑器插件保存时编译**：`tsconfig.json` 不写 `outDir` / `noEmit:false`；编辑器只用 TS Language Server。
3. **维护者手动 `tsc`**：强制检查只用 `tsc --noEmit`，从不裸跑 `tsc`。

## 8. 升级 vendor

见 [`libs/README.md`](libs/README.md) 的「vendor 升级」与「miniprogram-api-typings / mobx d.ts shim」章节。
