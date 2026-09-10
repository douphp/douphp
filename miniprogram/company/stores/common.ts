/**
 * 公共数据 store —— bootstrap/index 信封的单一真相源。
 *
 * 流程（SWR）：种子 import 第 0 帧即有文案 → hydrate() 从 storage 秒开 → refresh() 网络刷新并写回。
 * features 在此统一暴露，供 module-guard / authStore / cartStore 降级判断。
 */

import { action, observable, runInAction } from '../libs/mobx-miniprogram/index.js'
import { lang as seedLang, siteName as seedSiteName } from '../config/seed.generated.js'
import type { BootstrapData, Features, LangPack } from '../types/api'
import type { CommonState } from '../types/store'
import * as bootstrap from '../services/bootstrap.js'
import * as lang from '../services/lang.js'
import { loadPersisted, savePersisted } from './persist.js'

const PERSIST_KEY = 'common'

/** 核心模块永远存在；可选模块由后端 features 下发 */
const DEFAULT_FEATURES: Features = { product: true, article: true, data: true }

type CommonStore = CommonState & {
  applyData(payload: BootstrapData): void
  hydrate(): void
  refresh(): Promise<void>
}

function normalizeFeatures(features: Partial<Features> | undefined): Features {
  return Object.assign({}, DEFAULT_FEATURES, features || {}) as Features
}

function isEmptyLangPack(bag: LangPack | Record<string, string> | undefined): boolean {
  if (!bag) {
    return true
  }
  for (const k in bag) {
    if (Object.prototype.hasOwnProperty.call(bag, k)) {
      return false
    }
  }
  return true
}

function existingLangPack(): LangPack {
  const bag = commonStore.lang
  return bag && typeof bag === 'object' && !isEmptyLangPack(bag as LangPack) ? (bag as LangPack) : seedLang
}

export const commonStore: CommonStore = observable<CommonStore>({
  site: { site_name: seedSiteName },
  lang: seedLang,
  param: {},
  nav_list: [],
  features: Object.assign({}, DEFAULT_FEATURES) as Features,
  data: {},
  user_level_has_data: false,
  version: '',
  ready: false,

  applyData: action(function (this: CommonStore, payload: BootstrapData) {
    const incomingSite = payload.site || {}
    this.site = incomingSite.site_name
      ? incomingSite
      : Object.assign({}, incomingSite, { site_name: seedSiteName })
    this.lang = isEmptyLangPack(payload.lang) ? seedLang : (payload.lang as LangPack)
    this.param = payload.param || {}
    this.nav_list = Array.isArray(payload.nav_list) ? payload.nav_list : []
    this.features = normalizeFeatures(payload.features)
    this.data = payload.data || {}
    this.user_level_has_data = !!payload.user_level_has_data
    this.version = typeof payload.version === 'string' ? payload.version : ''
    this.ready = true
  }),

  hydrate: action(function (this: CommonStore) {
    const cached = loadPersisted<BootstrapData>(PERSIST_KEY)
    if (cached) {
      this.applyData(cached)
    }
  }),

  refresh: function () {
    return bootstrap.fetchBootstrap()
      .then(function (payload) {
        return lang.fetchLang()
          .then(function (langPack) {
            return { payload: payload, langPack: langPack || {} }
          })
          .catch(function () {
            return { payload: payload, langPack: existingLangPack() }
          })
      })
      .then(function (result) {
        const merged: BootstrapData = Object.assign({}, result.payload, {
          lang: isEmptyLangPack(result.langPack) ? existingLangPack() : result.langPack,
        })
        runInAction(function () {
          commonStore.applyData(merged)
        })
        savePersisted(PERSIST_KEY, merged)
      })
      .catch(function () {
        /* bootstrap 失败：保留已有（hydrate / 种子）数据，不打断首屏 */
      })
  },
})
