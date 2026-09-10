/**
 * 手写 d.ts shim —— 覆盖 DouPHP 小程序端用到的 mobx-miniprogram (mobx 6 兼容构建) API 子集。
 *
 * vendor 运行时来源：dist/mobx.cjs.production.min.js（见同目录 index.js，字节级未改）。
 * 仅声明业务实际使用到的导出；mobx 6 完整 API 远多于此，按需扩充。
 */

export type IObservableValue<T> = {
  get(): T
  set(value: T): void
}

export interface IComputedValue<T> {
  get(): T
}

export interface IReactionOptions {
  equals?: (a: any, b: any) => boolean
  fireImmediately?: boolean
  delay?: number
  name?: string
}

export interface IComparer {
  structural: (a: any, b: any) => boolean
  default: (a: any, b: any) => boolean
  shallow: (a: any, b: any) => boolean
}

export const comparer: IComparer

/** 把普通对象转为深度响应式对象（返回同形态类型） */
export function observable<T extends object>(value: T): T

export namespace observable {
  function box<T>(value?: T): IObservableValue<T>
  function ref<T>(value?: T): T
  function shallow<T extends object>(value: T): T
}

/** 包裹一个会修改 observable 的函数；保持原签名 */
export function action<F extends (...args: any[]) => any>(fn: F): F
export function action<F extends (...args: any[]) => any>(name: string, fn: F): F

/** 派生值 */
export function computed<T>(fn: () => T): IComputedValue<T>

/** 在一个 action 事务内执行并返回其结果 */
export function runInAction<T>(fn: () => T): T

/** 自动追踪依赖并在变化时重跑；返回 disposer */
export function autorun(
  fn: (reaction: { dispose(): void }) => void,
  options?: { delay?: number; name?: string }
): () => void

/** 监听 expression 的返回值，变化时执行 effect；返回 disposer */
export function reaction<T>(
  expression: () => T,
  effect: (value: T, prev: T) => void,
  options?: IReactionOptions
): () => void

/** 条件满足前等待；返回 Promise（可 cancel） */
export function when(predicate: () => boolean, options?: { timeout?: number; name?: string }): Promise<void> & { cancel(): void }
export function when(predicate: () => boolean, effect: () => void): () => void

/** 把对象的属性 / 方法自动标注为 observable / action / computed */
export function makeAutoObservable<T extends object>(target: T, overrides?: object, options?: object): T
export function makeObservable<T extends object>(target: T, annotations?: object, options?: object): T

/** 深度脱壳为普通对象（剥离 observable 代理） */
export function toJS<T>(source: T): T

export function configure(options: {
  enforceActions?: 'never' | 'observed' | 'always'
  useProxies?: 'always' | 'never' | 'ifavailable'
  [key: string]: any
}): void
