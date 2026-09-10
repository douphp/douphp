// pages/user/sns_link.ts
import { createStoreBindings } from '../../libs/mobx-miniprogram-bindings/index.js'
import { cartStore, commonStore } from '../../stores/index.js'
import { http } from '../../services/http.js'
import { route } from '../../utils/route.js'
import { douMsg } from '../../utils/ui.js'
import { getPromotionUserSn } from '../../utils/promotion.js'

Page({
  data: {} as Record<string, any>,

  onLoad() {
    const that = this

    this.storeBindings = createStoreBindings(this, {
      store: commonStore,
      fields: ['site', 'lang', 'data', 'param', 'features'],
    })

    http
      .post(route('user.sns_link'))
      .then(function (data) {
        that.setData({
          title: data.title,
          sns: JSON.parse(wx.getStorageSync('sns')),
          promotion_user_sn: getPromotionUserSn(),
        })
      })
      .catch(function (err) {
        douMsg(err.message || 'request_failed')
      })
  },

  onShow() {
    cartStore.refresh()
  },

  onUnload() {
    if (this.storeBindings) {
      this.storeBindings.destroyStoreBindings()
    }
  },
})
