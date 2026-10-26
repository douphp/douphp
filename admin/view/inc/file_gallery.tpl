<!-- Admin File Gallery
 +----------------------------------------------------------
 * file 模块 · 后台多图相册片段（与前台 inc/file_gallery.tpl 是两份独立片段）
 *
 * 入参：
 *   type         上传类型（gallery）；原样作为 data-file-type 上报
 *   prefix       容器前缀，派生 #{prefix}File / #{prefix}List；缺省则用 galleryFile / galleryList
 *   $cur         落盘模块名
 *   $item_id     编辑场景业务主键；新建场景为空串
 *   $draft_token 页面草稿 token（新建场景由控制器下发）
 *   list_html    已有文件 HTML（服务端预取）
 *   gallery_crop 裁剪复选是否默认勾选（会话记忆）
 *
 * 依赖：js/file.uploader.js（必须）；js/common.js 提供 flashUpdated / douCrop / 裁剪偏好上报。
 * 行为全部由容器上的 data-file-* 驱动，模板不内联 onclick。
 *
 * 与前台差异：后台 li 带悬停 mask（替换 / 裁剪 / 删除）且支持「上传时裁剪」；
 *             前台只有缩略图 + span.del，无 mask、无裁剪入口。
 +----------------------------------------------------------
-->
<!-- {if $prefix} -->
<div id="{$prefix}File" class="file-upload file-gallery"
 data-file-type="{$type}"
 data-file-prefix="{$prefix}"
 data-file-module="{$cur}"
 data-file-item-id="{$item_id}"
 data-file-draft-token="{$draft_token}"
 data-crop-ratio="{if $site.thumb_width && $site.thumb_height}{$site.thumb_width}/{$site.thumb_height}{/if}">
 <ul class="file-add">
  <li class="btn-file">
   <div class="file-upload-stage"><i class="file-placeholder bi-plus-lg"></i></div>
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
<div id="galleryFile" class="file-upload file-gallery"
 data-file-type="{$type}"
 data-file-prefix="gallery"
 data-file-module="{$cur}"
 data-file-item-id="{$item_id}"
 data-file-draft-token="{$draft_token}"
 data-crop-ratio="{if $site.thumb_width && $site.thumb_height}{$site.thumb_width}/{$site.thumb_height}{/if}">
 <ul class="file-add">
  <li class="btn-file">
   <div class="file-upload-stage"><i class="file-placeholder bi-plus-lg"></i></div>
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
