<div class="cloud">
 <div class="cloud-filter">
  <!-- {if $cloud_extend.meta.theme_filters} -->
  <div class="cloud-filter-tabs">
   <!-- {if $cloud_extend.meta.theme_filters.active eq 'all'} -->
   <a href="{$cloud_extend.meta.theme_filters.all_url}" class="cloud-tab-active">{$lang.cloud_extend_filter_all}</a>
   <!-- {else} -->
   <a href="{$cloud_extend.meta.theme_filters.all_url}">{$lang.cloud_extend_filter_all}</a>
   <!-- {/if} -->
   <!-- {if $cloud_extend.meta.theme_filters.active eq 'free'} -->
   <a href="{$cloud_extend.meta.theme_filters.free_url}" class="cloud-tab-active">{$lang.cloud_extend_filter_free_theme}</a>
   <!-- {else} -->
   <a href="{$cloud_extend.meta.theme_filters.free_url}">{$lang.cloud_extend_filter_free_theme}</a>
   <!-- {/if} -->
  </div>
  <!-- {elseif $cloud_extend.meta.system_sign_tabs} -->
  <div class="cloud-filter-tabs">
   <!-- {if !$cloud_extend.meta.system_sign_tabs.active} -->
   <a href="{$cloud_extend.meta.system_sign_tabs.all_url}" class="cloud-tab-active">{$lang.cloud_extend_filter_all}</a>
   <!-- {else} -->
   <a href="{$cloud_extend.meta.system_sign_tabs.all_url}">{$lang.cloud_extend_filter_all}</a>
   <!-- {/if} -->
   <!-- {foreach from=$cloud_extend.meta.system_sign_tabs.items item=tab} -->
   <!-- {if $cloud_extend.meta.system_sign_tabs.active eq $tab.value} -->
   <a href="{$tab.url}" class="cloud-tab-active">{$tab.name}</a>
   <!-- {else} -->
   <a href="{$tab.url}">{$tab.name}</a>
   <!-- {/if} -->
   <!-- {/foreach} -->
  </div>
  <!-- {/if} -->
 </div>

 <div class="cloud-list{if $cloud_list_class} {$cloud_list_class}{/if}">
  <!-- {if $cloud_extend_error} -->
  <div class="handbook">{$cloud_extend_error}</div>
  <!-- {elseif $cloud_extend.meta.layout eq 'image'} -->
  <div class="cloud-theme-list">
   <!-- {foreach from=$cloud_extend.items item=item} -->
   <!-- {if $cloud_extend.meta.root eq 'mobile'} -->
   <dl class="cloud-theme-card cloud-theme-card--mobile">
    <!-- {else} -->
    <dl class="cloud-theme-card">
     <!-- {/if} -->
     <p class="cloud-theme-thumb"><!-- {if $item.preview_frame_url} --><a href="javascript:void(0)" onclick="douFrame('{$item.name}', '{$item.preview_frame_url}', '{url link='admin.cloud.details'}')"><!-- {/if} --><!-- {if $item.thumb_url} --><img src="{$item.thumb_url}" alt="{$item.name}"><!-- {elseif $item.image} --><img src="{$item.image}" alt="{$item.name}"><!-- {/if} --><!-- {if $item.preview_frame_url} --></a><!-- {/if} --></p>
     <dt>{$item.name} {$item.unique_id}</dt>
     <dd>{$lang.cloud_extend_price_label}{$item.price_display}</dd>
     <dd>{$lang.cloud_extend_author_label}{$item.developer}</dd>
     <dd class="cloud-need-module">
     <!-- {if $item.need_module_parsed && $item.need_module_parsed.total > 0} -->
     {$lang.cloud_extend_need_module_label}{$item.need_module_parsed.text nofilter}
     <!-- {/if} -->
    </dd>
     <dd class="cloud-btn-row"><span>
       {include file="inc/cloud_extend_action.tpl" action=$item.action}
       <a href="{$item.demo_pc}" target="_blank">{$lang.cloud_extend_preview_theme}</a>
      </span></dd>
    </dl>
    <!-- {/foreach} -->
  </div>
  <!-- {else} -->
  <div class="cloud-extend-list">
   <!-- {foreach from=$cloud_extend.items item=item} -->
   <div class="cloud-extend-box cloud-extend-box--col">
    <div class="cloud-extend-item">
     <div class="cloud-extend-head">
      <div class="cloud-extend-count">{$lang.cloud_extend_download_count}{$item.count}</div>
      <div class="cloud-extend-name">{$item.name}<!-- {if $item.miniprogram} --><em>{$lang.cloud_extend_miniprogram_badge}</em><!-- {/if} --></div>
     </div>
     <div class="cloud-extend-info">
      <span>{$lang.cloud_extend_update_time_label}<b>{$item.updated_at}</b></span>
      <span>{$lang.cloud_extend_min_version_label}<b>{$item.mini_version_support}</b></span>
     </div>
     <div class="cloud-extend-desc" title="{$item.description}">{$item.description}</div>
     <div class="cloud-extend-actions">
      <div class="cloud-extend-price">{$item.price_display}</div>
      {include file="inc/cloud_extend_action.tpl" action=$item.action}
      <!-- {if $item.preview_frame_url} -->
      <a href="javascript:void(0)" onclick="douFrame('{$item.name}', '{$item.preview_frame_url}', '{url link='admin.cloud.details'}')">{$lang.cloud_extend_detail}</a>
      <!-- {/if} -->
     </div>
    </div>
   </div>
   <!-- {/foreach} -->
  </div>
  <!-- {/if} -->
 </div>

 <div class="cloud-pager">
  <!-- {if $cloud_extend.pagination.pages} -->
  <div class="cloud-pager-pages">
   <!-- {foreach from=$cloud_extend.pagination.pages item=page} -->
   <!-- {if $page.active} --><span class="cloud-page-active">{$page.page}</span><!-- {else} --><a href="{$page.url}">{$page.page}</a><!-- {/if} -->
   <!-- {/foreach} -->
  </div>
  <!-- {/if} -->
 </div>
</div>