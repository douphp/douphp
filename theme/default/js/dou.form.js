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

/**
 * 单次 POST 表单组件（Alpine.data('douForm')）
 *
 * 配合服务端「单次 POST + 内容协商」协议：表单以 fetch 发起 POST，请求头带
 * Accept: application/json，服务端命中 wantsJson() 后返回统一 envelope
 * （code/message/data/errors）。成功 envelope 的 data.redirect_url 决定跳转，
 * errors 为字段级错误，按字段名回填到模板的 x-show/x-text 节点。
 *
 * 选项：
 *   - alert：true 时错误以 alert 弹出（用于无字段错误位的紧凑表单），默认 false 走内联回填。
 *
 * 模板用法：
 *   <form x-data="douForm()" @submit.prevent="submit" action="...">
 *     <input name="username" />
 *     <span x-show="hasError('username')" x-text="errorOf('username')"></span>
 *     <button type="submit" :disabled="loading">提交</button>
 *   </form>
 */
document.addEventListener("alpine:init", function () {
  Alpine.data("douForm", function (options) {
    var opts = options || {};
    return {
      loading: false,
      errors: {},
      message: "",

      errorOf: function (field) {
        return this.errors && this.errors[field] ? this.errors[field] : "";
      },

      hasError: function (field) {
        return !!(this.errors && this.errors[field]);
      },

      submit: function (event) {
        var self = this;
        if (self.loading) {
          return;
        }

        var form = event.target;
        if (!form || form.tagName !== "FORM") {
          form = self.$el;
        }

        self.loading = true;
        self.errors = {};
        self.message = "";

        var action = form.getAttribute("action") || window.location.href;
        var body = new FormData(form);

        fetch(action, {
          method: "POST",
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          body: body,
          credentials: "same-origin",
        })
          .then(function (response) {
            return response
              .json()
              .then(function (json) {
                return json;
              })
              .catch(function () {
                return null;
              });
          })
          .then(function (raw) {
            self.loading = false;
            var result = typeof douApi === "function" ? douApi(raw) : self.parse(raw);
            if (result.ok) {
              self.success(result);
              return;
            }
            self.fail(result);
          })
          .catch(function () {
            self.loading = false;
            self.fail({ ok: false, message: "error", errors: {} });
          });
      },

      success: function (result) {
        var url = result.data && result.data.redirect_url ? result.data.redirect_url : "";
        if (url) {
          window.location.href = url;
          return;
        }
        if (opts.alert && result.message) {
          alert(result.message);
        }
      },

      fail: function (result) {
        var errors = result.errors && typeof result.errors === "object" ? result.errors : {};
        if (opts.alert) {
          if (result.message) {
            alert(result.message);
            return;
          }
          for (var nkey in errors) {
            if (errors.hasOwnProperty(nkey) && errors[nkey]) {
              alert(errors[nkey]);
              return;
            }
          }
          return;
        }
        this.errors = errors;
        var hasFieldErrors = false;
        for (var key in errors) {
          if (Object.prototype.hasOwnProperty.call(errors, key) && errors[key]) {
            hasFieldErrors = true;
            break;
          }
        }
        this.message = hasFieldErrors ? "" : (result.message || "");
      },

      /**
       * douApi 不可用时的兜底解析（与 dou.js douApi 同字段口径）。
       */
      parse: function (raw) {
        var code = raw && typeof raw.code === "string" ? raw.code : "";
        return {
          ok: code === "OK",
          code: code,
          message: raw && typeof raw.message === "string" ? raw.message : "",
          data: raw && raw.data && typeof raw.data === "object" ? raw.data : {},
          errors: raw && raw.errors && typeof raw.errors === "object" ? raw.errors : {},
        };
      },
    };
  });
});
