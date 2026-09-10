<!-- {if $js neq 'no'} -->
<!-- {if $site.editor eq 'vditor'} -->
<link href="{$site.admin_url}editor/vditor/dist/index.css" rel="stylesheet" type="text/css">
<script type="text/javascript" src="{$site.admin_url}editor/vditor/dist/index.js"></script>
<script type="text/javascript" src="{$site.admin_url}editor/vditor/init.js"></script>
<script>var cur_editor = "vditor";</script>
<!-- {else} -->
<script type="text/javascript" src="{$site.admin_url}editor/ueditor/ueditor.config.js"></script>
<script type="text/javascript" src="{$site.admin_url}editor/ueditor/ueditor.all.js"></script>
<script type="text/javascript" src="{$site.admin_url}editor/ueditor/init.js"></script>
<script>var cur_editor = "ueditor";</script>
<!-- {/if} -->
<!-- {/if} -->
<div id="{$name}Editor" class="editor">
 <div class="editor-bar">
  <div class="editor-bar-left">
   <!-- {if $paid_use} -->
   <div class="editor-btn" onClick="editorInsert('{$name}', '<hr/>');">
    <span class="btn-file">{$lang.insert_paid_use_line}</span>
   </div>
   <!-- {/if} -->
   <div id="{$name}File" class="editor-btn" onclick="fileBox('content', '{$name}', '{$cur}', '{$item_id}', '{$draft_token}', '', cur_editor);">
    <span class="btn-file">{$lang.file_insert_image}</span>
    <span class="file-status" style="display:none"><img src="images/loader.gif" alt="uploading"/></span>
   </div>
   <div id="{$name}BigFile" class="editor-btn" onChange="fileBig('content', '{$name}', '{$cur}', '{$item_id}', cur_editor, '{$draft_token}');">
    <input type="file" class="field">
    <span class="btn-file">{$lang.file_insert_media}<em class="percent"></em></span>
   </div>
  </div>
  <div class="editor-bar-right">
   <span class="editor-text">
    <label>
     <input name="content_remote_image_local" type="checkbox"> {$lang.file_remote_image_local}</label>
   </span>
   <a class="editor-text js-post" href="{url link='admin.tool.editor'}">{if $site.editor eq 'vditor'}切换为传统编辑器{else}切换为markdown编辑器{/if}</a>
   <span class="editor-text btn-fullscreen" onclick="btnFullscreen('{$name}')"><em class="yes">{$lang.fullscreen}</em><em class="no">{$lang.fullscreen_exit}</em></span>
  </div>
 </div>
 <!-- {if $site.editor eq 'vditor'} -->
 <div class="editor-content">
  <div id="{$name}" class="editor-class"></div>
 </div>
 <textarea id="{$name}Textarea" name="{$name}" style="display: none;">{$value nofilter}</textarea>
 <!-- {else} -->
 <script id="{$name}" name="{$name}" type="text/plain" class="editor-class">{$value nofilter}</script>
 <!-- {/if} -->
</div>