<!-- Admin File Image
 +----------------------------------------------------------
 * file 模块 · 后台单图控件片段（.file-image，与前台 inc/file_image.tpl 是两份独立片段）
 *
 * 入参：
 *   name          input 的 name / id（同时作为 DOM 定位依据）
 *   value         已存图 URL；空串时显示占位图
 *   file_number   已存图附件号，用于删除 / 原路径裁剪
 *   hidden_name   可选：附件号回写字段名（元数据槽位用；不传则不回写）
 *   crop_ratio    裁剪比例 "W/H"，缺省用站点缩略图比例
 *   defer_delete  1 = 清空时不删服务端文件，由业务保存时统一处理（元数据槽位）
 *                非 1 时输出 {$name}_delete 隐藏域：删除只标记，随主表单提交后由业务层删旧图并清空字段
 *   thumb_crop    裁剪复选是否默认勾选
 *
 * 依赖：js/common.js 的 douFileImage.init(root)（幂等，弹窗 / AJAX 刷新后可重调）、
 *       window.douCrop、window.douFileUploader.remove()。
 *
 * 与前台差异：本控件不上传请求，只做本地预览 + 回填，产物随表单提交；
 *             前台单图走 douFileBox 的独立请求 + 整表回填。
 +----------------------------------------------------------
-->
<div class="file-image{if $value} file-image-filled{/if}" data-initial-src="{$value}" data-initial-number="{$file_number}" data-crop-ratio="{if $crop_ratio}{$crop_ratio}{else}{if $site.thumb_width && $site.thumb_height}{$site.thumb_width}/{$site.thumb_height}{/if}{/if}" data-defer-delete="{if $defer_delete}1{else}0{/if}">
 <!-- {if $hidden_name} --><input type="hidden" class="file-number" name="{$hidden_name}" value="{$file_number}" /><!-- {/if} -->
 <!-- {if $defer_delete neq 1} --><input type="hidden" class="file-delete-flag" name="{$name}_delete" value="0" /><!-- {/if} -->
 <div class="file-image-stage">
  <label for="{$name}" class="file-image-label"><i class="file-placeholder bi-plus-lg"></i><!-- {if $value} --><img class="file-image-preview" src="{$value}" alt="" /><!-- {else} --><img class="file-image-preview" alt="" /><!-- {/if} --><input type="file" id="{$name}" name="{$name}" accept="image/*" /></label>
  <div class="file-image-mask">
   <button type="button" class="file-action file-action-upload bi-upload" title="{$lang.file_btn}" tabindex="-1"></button>
   <button type="button" class="file-action file-action-replace bi-arrow-repeat" title="{$lang.replace}" tabindex="-1"></button>
   <button type="button" class="file-action file-action-crop bi-scissors" title="{$lang.crop_title}"></button>
   <button type="button" class="file-action file-action-remove bi-x" title="{$lang.del}"></button>
  </div>
 </div>
 <div class="file-rail">
  <i class="file-rail-icon bi-scissors" aria-hidden="true"></i>
  <label class="file-crop-check" title="{$lang.crop_on_upload}"><input type="checkbox" class="file-crop-pref" data-crop-scope="thumb"{if $thumb_crop} checked="checked"{/if} /></label>
 </div>
</div>
