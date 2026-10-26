<!-- Admin File Attachment
 +----------------------------------------------------------
 * file 模块 · 后台通用附件片段（与前台 inc/file_attachment.tpl 是两份独立片段）
 *
 * 入参：
 *   prefix       容器前缀，派生 #{prefix}File / #{prefix}List；缺省则用 attachmentFile / attachmentList
 *   $cur         落盘模块名
 *   $item_id     编辑场景业务主键；新建场景为空串
 *   $draft_token 页面草稿 token（新建场景由控制器下发）
 *   list_html    已有文件 HTML（服务端预取）
 *
 * 依赖：js/file.uploader.js（必须）。
 * 行为全部由容器上的 data-file-* 驱动，模板不内联 onclick。
 *
 * 与前台差异：后台 li 是 mask 卡片（图标 + 文件名 + 大小 + 删除按钮）；
 *             前台是文本行 + span.del。附件不做图片处理，两端均无裁剪。
 +----------------------------------------------------------
-->
<!-- {if $prefix} -->
<div id="{$prefix}File" class="file-upload file-attachment"
 data-file-type="attachment"
 data-file-prefix="{$prefix}"
 data-file-module="{$cur}"
 data-file-item-id="{$item_id}"
 data-file-draft-token="{$draft_token}">
 <ul class="file-add">
  <li class="btn-file">
   <div class="file-upload-stage"><i class="file-placeholder bi-paperclip"></i></div>
  </li>
  <li class="file-status" style="display:none"><img src="images/loader.gif" alt="uploading" /></li>
 </ul>
 <ul id="{$prefix}List" class="file-list">
  {$list_html nofilter}
 </ul>
</div>
<!-- {else} -->
<div id="attachmentFile" class="file-upload file-attachment"
 data-file-type="attachment"
 data-file-prefix="attachment"
 data-file-module="{$cur}"
 data-file-item-id="{$item_id}"
 data-file-draft-token="{$draft_token}">
 <ul class="file-add">
  <li class="btn-file">
   <div class="file-upload-stage"><i class="file-placeholder bi-paperclip"></i></div>
  </li>
  <li class="file-status" style="display:none"><img src="images/loader.gif" alt="uploading" /></li>
 </ul>
 <ul id="attachmentList" class="file-list">
  {$list_html nofilter}
 </ul>
</div>
<!-- {/if} -->
