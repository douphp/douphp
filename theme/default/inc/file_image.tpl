<!-- FileBox Image
 +----------------------------------------------------------
 * file 模块 · 单图上传片段（通用能力，不归属任何业务模块）
 *
 * 用法：include file="inc/file_image.tpl" 具名传参，入参同 inc/file_gallery.tpl
 * 单图语义：服务端在写入前清空该字段的既有文件，重复上传即替换；
 *           因此文件选择器不带 multiple。
 *
 * 依赖：js/file.js（必须）。前台不提供裁剪，尺寸由服务端按 img_width 缩放。
 * 行为全部由容器上的 data-file-* 驱动（见 js/file.js 头部契约），模板不再内联 onclick。
 * 与后台 inc/file_image.tpl 是两份独立片段（后台是带悬停蒙板与附件号的控件）。
 +----------------------------------------------------------
-->
{if $dou.auth.is_login}
<div id="{$prefix}File" class="fileBox"
 data-file-type="main"
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
