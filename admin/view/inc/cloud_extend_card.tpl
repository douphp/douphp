<!-- 云端扩展文本卡片（调用方传入 item、hide_min、show_state） -->
<div class="cloud-extend-box cloud-extend-box--col">
 <div class="cloud-extend-item<!-- {if $show_state && $item.state neq 'install'} --> cloud-extend-item--{$item.state}<!-- {/if} -->">
  <div class="cloud-extend-head">
   <!-- {if $show_state} -->
   <!-- {if $item.state eq 'install'} -->
   <span class="cloud-extend-state cloud-extend-state--install">{$lang.cloud_extend_state_install}</span>
   <!-- {else} -->
   <span class="cloud-extend-state cloud-extend-state--installed">{$lang.cloud_extend_state_installed}</span>
   <!-- {/if} -->
   <!-- {/if} -->
   <div class="cloud-extend-name">{$item.title}<!-- {if $item.miniprogram} --><em>{$lang.cloud_extend_miniprogram_badge}</em><!-- {/if} --></div>
  </div>
  <div class="cloud-extend-info">
   <span>{$lang.cloud_extend_update_time_label}<b>{$item.update_time}</b></span>
   <span>{$lang.cloud_extend_download_count}<b>{$item.count}</b></span>
   <!-- {if !$hide_min} -->
   <span>{$lang.cloud_extend_min_version_label}<b>{$item.mini_version_support}</b></span>
   <!-- {/if} -->
  </div>
  <div class="cloud-extend-desc" title="{$item.description}">{$item.description}</div>
  <div class="cloud-extend-actions">
   <div class="cloud-extend-price">{$item.price_display}</div>
   {include file="inc/cloud_extend_action.tpl" action=$item.action}
   <!-- {if $item.preview_frame_url} -->
   <a href="javascript:void(0)" onclick="douFrame('{$item.title}', '{$item.preview_frame_url}', '{url link='admin.cloud.details'}')">{$lang.cloud_extend_detail}</a>
   <!-- {/if} -->
  </div>
 </div>
</div>
