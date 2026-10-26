<!-- FileBox Attachment
 +----------------------------------------------------------
 * file 模块 · 附件上传片段（通用能力，不归属任何业务模块）
 *
 * 用法：include file="inc/file_attachment.tpl" 具名传参，入参同 inc/file_gallery.tpl
 * 附件独占 images/模块/attach/ 目录，不做图片缩放与水印，因此没有裁剪复选。
 *
 * 依赖：js/file.js（必须）
 * 行为全部由容器上的 data-file-* 驱动（见 js/file.js 头部契约），模板不再内联 onclick。
 * 与后台 inc/file_attachment.tpl 是两份独立片段：后台走 mask 卡片，前台走文本行。
 +----------------------------------------------------------
-->
{if $dou.auth.is_login}
<div id="{$prefix}File" class="fileBox"
 data-file-type="attach"
 data-file-prefix="{$prefix}"
 data-file-module="{$module}"
 data-file-item-id="{$item_id}"
 data-file-draft-token="{$draft_token}"
 data-file-extensions="{$extensions}"
 data-file-max-size="{$max_size}"
 data-file-img-width="0">
 <ul class="fileAdd">
  <li class="btnFile" data-file-pick>{$lang.file_btn_attach}</li>
  <li class="fileStatus" style="display:none"><img src="images/loader.gif" alt="uploading" /></li>
 </ul>
 <ul id="{$prefix}List" class="fileList file-list-attach">{$list_html nofilter}</ul>
</div>
{else}
<p class="cueGray">{$lang.file_login_required} <a href="{url link='user.login'}">{$lang.user_login_nav}</a></p>
{/if}
