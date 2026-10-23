<!-- 多图相册：prefixFile=容器、prefixList=列表与 fileUpload target -->
<!-- {if $prefix} -->
<div id="{$prefix}File" class="file-upload file-gallery" data-crop-ratio="{$site.thumb_width}/{$site.thumb_height}">
 <ul class="file-add">
  <li class="btn-file">
   <div class="file-upload-stage" onclick="fileUpload('{$type}', '{$prefix}List', '{$cur}', '{$item_id}', '{$draft_token}', '', '', '{$prefix}File');"><i class="file-placeholder bi-plus-lg"></i></div>
   <div class="file-rail">
    <i class="file-rail-icon bi-scissors" aria-hidden="true"></i>
    <label class="file-crop-check" title="{$lang.crop_on_upload}"><input type="checkbox" class="file-crop-pref" data-crop-scope="gallery"{if $gallery_crop} checked="checked"{/if} /></label>
   </div>
  </li>
  <li class="file-status" style="display:none"><img src="images/loader.gif" alt="uploading" /></li>
 </ul>
 <ul id="{$prefix}List" class="file-list">
  {$list_html nofilter}
 </ul>
</div>
<!-- {else} -->
<div id="galleryFile" class="file-upload file-gallery" data-crop-ratio="{$site.thumb_width}/{$site.thumb_height}">
 <ul class="file-add">
  <li class="btn-file">
   <div class="file-upload-stage" onclick="fileUpload('{$type}', 'galleryList', '{$cur}', '{$item_id}', '{$draft_token}', '', '', 'galleryFile');"><i class="file-placeholder bi-plus-lg"></i></div>
   <div class="file-rail">
    <i class="file-rail-icon bi-scissors" aria-hidden="true"></i>
    <label class="file-crop-check" title="{$lang.crop_on_upload}"><input type="checkbox" class="file-crop-pref" data-crop-scope="gallery"{if $gallery_crop} checked="checked"{/if} /></label>
   </div>
  </li>
  <li class="file-status" style="display:none"><img src="images/loader.gif" alt="uploading" /></li>
 </ul>
 <ul id="galleryList" class="file-list">
  {$list_html nofilter}
 </ul>
</div>
<!-- {/if} -->
