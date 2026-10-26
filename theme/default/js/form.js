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
 * 表单主题脚本（form 模块）
 *
 * 只做三件事，不含任何上传逻辑（上传在 file 模块的 js/file.js）：
 *   1. douArea：省/市/区三级联动（Alpine 组件，数据取 user/area）
 *   2. refreshFormCaptcha(id)：一页多表单时按 id 刷新验证码
 *   3. 把 input[name="source_url"] 填成当前页面地址，供后台看提交来源
 */

/**
 * 省/市/区三级联动（Alpine.data('douArea')）
 *
 * 模板用法（每个区域字段一套，name 必须是 slug[province] / [city] / [district]）：
 *   <div x-data="douArea()" x-init="init($el)"
 *        data-default="福建省,厦门市,思明区">
 *     <select name="region[province]" data-part="province"></select>
 *     <select name="region[city]" data-part="city"></select>
 *     <select name="region[district]" data-part="district"></select>
 *   </div>
 *
 * city / district 的 parent 传的是**上级名称**（与 user/area 接口一致），不是 id。
 */
document.addEventListener("alpine:init", function () {
  Alpine.data("douArea", function () {
    return {
      box: null,
      list: {},
      value: {},

      init: function (el) {
        this.box = el;
        this.list = {};
        this.value = {};

        var preset = (el.getAttribute("data-default") || "").split(",");
        this.value.province = this.trim(preset[0]);
        this.value.city = this.trim(preset[1]);
        this.value.district = this.trim(preset[2]);

        this.bind("province");
        this.bind("city");
        this.bind("district");

        this.load("province", "", this.value.province);
      },

      /**
       * 绑定某一级 select 的 change：清空下级后重新拉取。
       */
      bind: function (part) {
        var self = this;
        var select = this.select(part);
        if (!select) {
          return;
        }

        select.addEventListener("change", function () {
          self.value[part] = select.value;

          if (part === "province") {
            self.value.city = "";
            self.value.district = "";
            self.fill("city", []);
            self.fill("district", []);
            self.load("city", select.value, "");
          } else if (part === "city") {
            self.value.district = "";
            self.fill("district", []);
            self.load("district", select.value, "");
          }
        });
      },

      /**
       * 拉取某一级选项。
       *
       * @param {string} part     province / city / district
       * @param {string} parent   上级名称
       * @param {string} current  需要回选的名称
       */
      load: function (part, parent, current) {
        var self = this;
        if (part !== "province" && !parent) {
          return;
        }

        var body = "type=" + part + "&current=" + encodeURIComponent(current || "");
        if (part !== "province") {
          body += "&parent=" + encodeURIComponent(parent);
        }

        fetch(route("user.area"), {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Requested-With": "XMLHttpRequest",
          },
          body: body,
          credentials: "same-origin",
        })
          .then(function (response) {
            return response.json();
          })
          .then(function (raw) {
            var result = typeof douApi === "function" ? douApi(raw) : { ok: false, data: {} };
            var rows = result.ok && result.data && result.data.area_list ? result.data.area_list : [];

            var names = [];
            var selected = "";
            for (var i = 0; i < rows.length; i++) {
              var name = rows[i].name || "";
              if (name === "") {
                continue;
              }
              names.push(name);
              if (rows[i].cur) {
                selected = name;
              }
            }

            self.list[part] = names;
            self.fill(part, names, selected || current);
          })
          .catch(function () {
            self.fill(part, []);
          });
      },

      /**
       * 把选项写进对应 select（首项为空占位，便于「省市」非必选场景留空）。
       */
      fill: function (part, names, current) {
        var select = this.select(part);
        if (!select) {
          return;
        }

        select.innerHTML = "";

        var placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "-";
        select.appendChild(placeholder);

        for (var i = 0; i < names.length; i++) {
          var option = document.createElement("option");
          option.value = names[i];
          option.textContent = names[i];
          if (names[i] === current) {
            option.selected = true;
          }
          select.appendChild(option);
        }

        this.value[part] = select.value;
      },

      /**
       * 取某一级 select 节点。
       */
      select: function (part) {
        if (!this.box) {
          return null;
        }

        return this.box.querySelector('select[data-part="' + part + '"]');
      },

      /**
       * 去首尾空白（不依赖 jQuery）。
       */
      trim: function (text) {
        return String(text || "").replace(/^\s+|\s+$/g, "");
      },
    };
  });
});

/**
 * 按 id 刷新验证码（dou.js 的 refreshimage() 写死 #vcode，一页多表单会互相覆盖）
 *
 * @param {string} id 验证码图片节点 id
 */
function refreshFormCaptcha(id) {
  var image = document.getElementById(id);
  if (!image) {
    return;
  }

  var src = image.getAttribute("src") || "";
  var base = src.split("?")[0];
  var query = src.indexOf("route=captcha") !== -1 ? "?route=captcha&t=" : "?t=";

  image.setAttribute("src", base + query + new Date().getTime());
}

/**
 * 提交来源：模板留空隐藏域，前端补当前地址（服务端在为空时回退 HTTP_REFERER）
 */
(function () {
  function fillSourceUrl() {
    var list = document.querySelectorAll('input[name="source_url"]');
    for (var i = 0; i < list.length; i++) {
      if (!list[i].value) {
        list[i].value = window.location.href;
      }
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", fillSourceUrl);
  } else {
    fillSourceUrl();
  }
})();
