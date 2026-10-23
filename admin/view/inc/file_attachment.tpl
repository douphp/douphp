<!-- 通用附件（前端 type=attachment，服务端归一为 attach）：prefix=容器前缀，支持一页多实例；无裁剪控件，选中即上传 -->
<!-- {if $prefix} -->
<div id="{$prefix}File" class="file-upload file-attachment">
 <ul class="file-add">
  <li class="btn-file">
   <div class="file-upload-stage" onclick="fileUpload('attachment', '{$prefix}List', '{$cur}', '{$item_id}', '{$draft_token}', '', '', '{$prefix}File');"><i class="file-placeholder bi-paperclip"></i></div>
  </li>
  <li class="file-status" style="display:none"><img src="images/loader.gif" alt="uploading" /></li>
 </ul>
 <ul id="{$prefix}List" class="file-list">
  {$list_html nofilter}
 </ul>
</div>
<!-- {else} -->
<div id="attachmentFile" class="file-upload file-attachment">
 <ul class="file-add">
  <li class="btn-file">
   <div class="file-upload-stage" onclick="fileUpload('attachment', 'attachmentList', '{$cur}', '{$item_id}', '{$draft_token}', '', '', 'attachmentFile');"><i class="file-placeholder bi-paperclip"></i></div>
  </li>
  <li class="file-status" style="display:none"><img src="images/loader.gif" alt="uploading" /></li>
 </ul>
 <ul id="attachmentList" class="file-list">
  {$list_html nofilter}
 </ul>
</div>
<!-- {/if} -->
