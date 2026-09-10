/**
 * 手写 d.ts shim —— mobx-miniprogram-bindings（微信官方组织维护）。
 *
 * vendor 运行时来源：dist/index.js（见同目录 index.js + PATCH.md 的 require 路径改写）。
 * 把 mobx store 字段 / computed / actions 自动绑定到 Page / Component。
 */

/** store 字段映射：数组（同名直绑）或映射对象（别名 / 自定义取值函数） */
export type StoreFields =
  | string[]
  | Record<string, string | ((store: any) => any)>

/** store actions 映射：数组（同名透传）或映射对象（别名） */
export type StoreActions = string[] | Record<string, string>

export interface StoreBindingsOptions {
  store: any
  fields?: StoreFields
  actions?: StoreActions
  namespace?: string
  structuralComparison?: boolean
}

export interface StoreBindings {
  updateStoreBindings(): void
  destroyStoreBindings(): void
}

/** 在 Page / Component 实例上建立 store 绑定；须在 onUnload / detached 调 destroyStoreBindings */
export function createStoreBindings(target: any, options: StoreBindingsOptions): StoreBindings

/** 供 Component behaviors 数组使用的行为；配合实例 storeBindings 选项自动绑定 / 销毁 */
export const storeBindingsBehavior: string

/** 包装 Component()，自动注入 storeBindingsBehavior */
export function ComponentWithStore(options: any): string

/** 包装 Behavior()，自动注入 storeBindingsBehavior */
export function BehaviorWithStore(options: any): string

/** 在自定义 lifetime 钩子里手动初始化绑定 */
export function initStoreBindings(
  target: { self: any; lifetime: (name: string, fn: () => void) => void },
  options: StoreBindingsOptions
): { updateStoreBindings(): void }
