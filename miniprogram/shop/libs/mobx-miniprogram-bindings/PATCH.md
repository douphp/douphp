# mobx-miniprogram-bindings 本地补丁记录

本文件登记 DouPHP 仓库对 `mobx-miniprogram-bindings` 上游 dist 文件做的**所有本地修改**。每次升级该包后，**必须重新应用这里列出的全部 patch**。

## 当前补丁清单

### Patch 1：require 路径改写（必须）

**目的**：上游 `dist/index.js` 用 `require("mobx-miniprogram")` 这种「裸模块名」import peerDependency。该写法在标准 npm 工作流下会被微信小程序基础库解析到 `miniprogram_npm/mobx-miniprogram/index.js`，依赖 `project.config.json` 的 `nodeModules: true`。

为了让 DouPHP 走更朴素的「`libs/` 显式相对路径」分发模式（`nodeModules: false`，与 `core/library/sms/` 风格一致），把这一处 require 改写成相对路径。

**位置**：`index.js` 全文唯一一处 `require("mobx-miniprogram")`（搜索关键字定位即可）。

**改动**（diff 风格）：

```diff
- var x,l=require("mobx-miniprogram");
+ var x,l=require("../mobx-miniprogram/index.js");
```

**自动化应用脚本**（PowerShell，详见 `../README.md` 的「升级流程 → 步骤 2」）：

```powershell
$bindingsPath = "miniprogram\default\libs\mobx-miniprogram-bindings\index.js"
$content = Get-Content $bindingsPath -Raw -Encoding UTF8
$patched = $content -replace 'require\("mobx-miniprogram"\)', 'require("../mobx-miniprogram/index.js")'
[System.IO.File]::WriteAllText((Resolve-Path $bindingsPath), $patched, [System.Text.UTF8Encoding]::new($false))
```

**幂等性**：脚本对已 patch 文件再跑一次无副作用（替换条件已不再匹配）。

**风险**：该改动**仅影响模块解析路径**，不影响 mobx-miniprogram-bindings 的任何运行时逻辑。上游 v3.x 至 v6.x 的 dist 都是同一形态的 `require("mobx-miniprogram")` 单行调用，多年稳定。

## 升级后核验清单

下载新版本并重跑 Patch 1 后，确认：

1. `index.js` 中**有且仅有一处** `require("../mobx-miniprogram/index.js")`，**没有任何**残留的 `require("mobx-miniprogram")`。
2. 微信开发者工具编译无报错。
3. 业务页面里 `createStoreBindings({ store: someStore, fields: ['x'] })` 能正常工作，store 改值后 page 自动 setData。

如上游某次大版本变更后改了模块解析方式（如改用 ESM `import`、改用全局对象注入等），需重新评估本 patch 是否仍有效，必要时更新本文件并通知所有 DouPHP 维护者。
