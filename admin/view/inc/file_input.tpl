<div class="file-input{if $value} file-input-filled{/if}" data-initial-src="{$value}" data-initial-number="{$file_number}" data-crop-ratio="{if $crop_ratio}{$crop_ratio}{else}{$site.thumb_width}/{$site.thumb_height}{/if}">
 <div class="file-input-stage">
  <label for="{$name}" class="file-input-label"><i class="file-input-placeholder bi-plus-lg"></i><!-- {if $value} --><img class="file-input-preview" src="{$value}" alt="" /><!-- {else} --><img class="file-input-preview" alt="" /><!-- {/if} --><input type="file" id="{$name}" name="{$name}" accept="image/*" /></label>
  <div class="file-input-mask">
   <button type="button" class="file-input-action file-input-action-upload bi-upload" title="{$lang.file_btn}" tabindex="-1"></button>
   <button type="button" class="file-input-action file-input-action-replace bi-arrow-repeat" title="{$lang.replace}" tabindex="-1"></button>
   <button type="button" class="file-input-action file-input-action-crop bi-scissors" title="{$lang.crop_title}"></button>
   <button type="button" class="file-input-action file-input-remove bi-x" title="{$lang.del}"></button>
  </div>
 </div>
 <div class="file-input-rail">
  <i class="file-input-rail-icon bi-scissors" aria-hidden="true"></i>
  <label class="file-input-crop-check" title="{$lang.crop_on_upload}"><input type="checkbox" class="file-input-crop-pref" data-crop-scope="thumb"{if $thumb_crop} checked="checked"{/if} /></label>
 </div>
</div>
