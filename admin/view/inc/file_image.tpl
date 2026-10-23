<div class="file-image{if $value} file-image-filled{/if}" data-initial-src="{$value}" data-initial-number="{$file_number}" data-crop-ratio="{if $crop_ratio}{$crop_ratio}{else}{$site.thumb_width}/{$site.thumb_height}{/if}" data-defer-delete="{if $defer_delete}1{else}0{/if}">
 <!-- {if $hidden_name} --><input type="hidden" class="file-number" name="{$hidden_name}" value="{$file_number}" /><!-- {/if} -->
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
