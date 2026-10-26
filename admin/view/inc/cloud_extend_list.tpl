<div class="cloud">
 <!-- {if !$cloud_extend_hide_filter} -->
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
 <!-- {/if} -->

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
     <p class="cloud-theme-thumb"><!-- {if $item.preview_frame_url} --><a href="javascript:void(0)" onclick="douFrame('{$item.title}', '{$item.preview_frame_url}', '{url link='admin.cloud.details'}')"><!-- {/if} --><!-- {if $item.thumb_url} --><img src="{$item.thumb_url}" alt="{$item.title}"><!-- {elseif $item.image} --><img src="{$item.image}" alt="{$item.title}"><!-- {/if} --><!-- {if $item.preview_frame_url} --></a><!-- {/if} --></p>
     <dt>{$item.title} {$item.slug}</dt>
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
  <!-- {if $cloud_extend_groups} -->
  <div class="cloud-extend-groups">
   <!-- {foreach from=$cloud_extend_groups item=group} -->
   <div class="cloud-extend-group">
    <h3 class="cloud-extend-group-title" id="cloud-extend-group-{$group.slug}">{$group.name}<em>{$group.count}</em></h3>
    <div class="cloud-extend-list">
     <!-- {foreach from=$group.items item=item} -->
     {include file="inc/cloud_extend_card.tpl" item=$item hide_min=$cloud_extend_hide_min_version show_state=$cloud_extend_show_state}
     <!-- {/foreach} -->
    </div>
   </div>
   <!-- {/foreach} -->
  </div>
  <!-- {else} -->
  <div class="cloud-extend-list">
   <!-- {foreach from=$cloud_extend.items item=item} -->
   {include file="inc/cloud_extend_card.tpl" item=$item hide_min=$cloud_extend_hide_min_version show_state=$cloud_extend_show_state}
   <!-- {/foreach} -->
  </div>
  <!-- {/if} -->
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