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
 *   1. douArea：国家/省/市/区四级联动（Alpine 组件，数据取 user/area）
 *   2. refreshFormCaptcha(id)：一页多表单时按 id 刷新验证码
 *   3. 把 input[name="source_url"] 填成当前页面地址，供后台看提交来源
 */

/**
 * 国家/省/市/区四级联动（Alpine.data('douArea')）
 *
 * 模板用法（每个区域字段一套，name 建议 slug[country] / [province] / [city] / [district]）：
 *   <div x-data="douArea()" x-init="init($el)"
 *        data-default="中国,福建省,厦门市,思明区">
 *     <select name="region[country]" data-part="country"></select>
 *     <select name="region[province]" data-part="province"></select>
 *     <select name="region[city]" data-part="city"></select>
 *     <select name="region[district]" data-part="district"></select>
 *   </div>
 *
 * data-default 兼容 3 段（省,市,区）与 4 段（国,省,市,区）。
 * 当前分区只有一个顶层节点时，country 下拉自动隐藏（值照常提交），
 * 因此无论单国家还是多国家，服务端始终能拿到 4 个字段。
 */
document.addEventListener("alpine:init", function () {
  Alpine.data("douArea", function () {
    return {
      box: null,
      list: {},
      value: {},
      id: {},
      multiple: false,

      init: function (el) {
        this.box = el;
        this.list = {};
        this.value = {};
        this.id = {};
        this.multiple = false;

        var preset = String(el.getAttribute("data-default") || "").split(",");
        // 3 段视为省市区，4 段视为国省市区
        var offset = preset.length >= 4 ? 0 : 1;
        this.value.country = offset === 0 ? this.trim(preset[0]) : "";
        this.value.province = this.trim(preset[offset]);
        this.value.city = this.trim(preset[offset + 1]);
        this.value.district = this.trim(preset[offset + 2]);

        this.bind("country");
        this.bind("province");
        this.bind("city");
        this.bind("district");

        this.loadRoot();
      },

      /**
       * 国家层：决定是否展示国家下拉，并推动省级加载。
       */
      loadRoot: function () {
        var self = this;
        this.request("root", 0, this.value.country, function (rows, data) {
          self.list.country = rows;
          self.multiple = !!data.multiple;

          if (!self.multiple) {
            var only = rows.length === 1 ? rows[0].name : "";
            self.fill("country", rows, only);
            self.toggle("country", false);
            if (only) {
              self.load("province", self.id.country, self.value.province);
            }
            return;
          }

          self.fill("country", rows, self.value.country);
          if (self.value.country) {
            self.load("province", self.id.country, self.value.province);
          }
        });
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
          self.id[part] = self.optionId(select);

          if (part === "country") {
            self.clear(["province", "city", "district"]);
            self.load("province", self.id.country, "");
          } else if (part === "province") {
            self.clear(["city", "district"]);
            self.load("city", self.id.province, "");
          } else if (part === "city") {
            self.clear(["district"]);
            self.load("district", self.id.city, "");
          }
        });
      },

      /**
       * 清空若干级的值与选项。
       */
      clear: function (parts) {
        for (var i = 0; i < parts.length; i++) {
          this.value[parts[i]] = "";
          this.id[parts[i]] = "";
          this.fill(parts[i], [], "");
        }
      },

      /**
       * 拉取某一级选项。
       *
       * @param {string} part     country / province / city / district
       * @param {string} parentId 上级 id（country 忽略）
       * @param {string} current  需要回选的名称
       */
      load: function (part, parentId, current) {
        var self = this;
        this.request(part, parentId, current, function (rows) {
          self.fill(part, rows, current);
        });
      },

      /**
       * 请求地区接口。
       */
      request: function (type, parentId, current, done) {
        var body = "type=" + type + "&current=" + encodeURIComponent(current || "");
        if (type !== "root" && type !== "country" && parentId) {
          body += "&parent_id=" + encodeURIComponent(parentId);
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
            var data = result.ok && result.data ? result.data : {};
            done(data.area_list ? data.area_list : [], data);
          })
          .catch(function () {
            done([], {});
          });
      },

      /**
       * 把选项写进对应 select（首项为空占位，便于「省市」非必选场景留空）。
       */
      fill: function (part, rows, current) {
        this.list[part] = rows;

        var select = this.select(part);
        if (!select) {
          return;
        }

        select.innerHTML = "";

        var placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "-";
        select.appendChild(placeholder);

        for (var i = 0; i < rows.length; i++) {
          var name = rows[i].name || "";
          if (name === "") {
            continue;
          }
          var option = document.createElement("option");
          option.value = name;
          option.textContent = name;
          option.setAttribute("data-id", rows[i].id);
          if (name === current) {
            option.selected = true;
          }
          select.appendChild(option);
        }

        this.value[part] = select.value;
        this.id[part] = this.optionId(select);
        this.toggle(part, rows.length > 0);
      },

      /**
       * 控制某一级 select 的显隐（没有下级数据时收起）。
       */
      toggle: function (part, visible) {
        var select = this.select(part);
        if (!select) {
          return;
        }

        select.style.display = visible ? "" : "none";
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
       * 取当前选中项绑定的地区 id。
       */
      optionId: function (select) {
        var option = select.options[select.selectedIndex];
        if (!option) {
          return "";
        }

        return option.getAttribute("data-id") || "";
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
