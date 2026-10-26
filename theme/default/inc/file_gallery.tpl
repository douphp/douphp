<!-- FileBox Gallery
 +----------------------------------------------------------
 * file 模块 · 多图上传片段（通用能力，不归属任何业务模块）
 *
 * 用法：include file="inc/file_gallery.tpl" 具名传参
 * 入参（除 list_html 外均按需传，缺省值由服务端兜底）：
 *   module      落盘模块名，如 form / aftersale，仅 a-z0-9_
 *   prefix      DOM 前缀，派生 prefixFile / prefixList 两个节点，仅 a-z0-9_
 *   item_id     编辑场景业务主键；新建场景传空串并改传 draft_token
 *   draft_token 页面全局草稿 token（草稿键由服务端按 md5(token_prefix) 派生）
 *   list_html   已有文件 HTML，新建场景恒传空串
 *   extensions  允许的扩展名，逗号分隔，空串走平台默认
 *   max_size    单文件上限 KB，0 走磁盘默认
 *   img_width   图片最大宽度，0 不缩放
 *
 * 依赖：js/file.js（必须）。前台不提供裁剪，尺寸由服务端按 img_width 缩放。
 *
 * 行为全部由容器上的 data-file-* 驱动（见 js/file.js 头部契约），模板不再内联 onclick。
 * 上传与删除请求统一走 route('file.box') / route('file.destroy')，token 取页面隐藏域。
 * 与后台 inc/file_gallery.tpl 是两份独立片段，结构不共享。
 +----------------------------------------------------------
-->
{if $dou.auth.is_login}
<div id="{$prefix}File" class="fileBox"
 data-file-type="gallery"
 data-file-prefix="{$prefix}"
 data-file-module="{$module}"
 data-file-item-id="{$item_id}"
 data-file-draft-token="{$draft_token}"
 data-file-extensions="{$extensions}"
 data-file-max-size="{$max_size}"
 data-file-img-width="{$img_width}">
 <ul class="fileAdd">
  <li class="btnFile" data-file-pick>{$lang.btn_upload}</li>
  <li class="fileStatus" style="display:none"><img src="images/loader.gif" alt="uploading" /></li>
 </ul>
 <ul id="{$prefix}List" class="fileList">{$list_html nofilter}</ul>
</div>
{else}
<p class="cueGray">{$lang.file_login_required} <a href="{url link='user.login'}">{$lang.user_login_nav}</a></p>
{/if}
