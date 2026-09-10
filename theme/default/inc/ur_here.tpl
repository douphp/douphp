<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<div class="ur-here{if $cur eq 'product_category'} product-category{/if}">
 <div class="here"> {$lang.ur_here}：<a href="{$site.home_url}">{$lang.home}</a><!-- {if $ur_here.module} --><b>></b><a href="{$ur_here.module.url}">{$ur_here.module.name}</a><!-- {/if} --><!-- {if $ur_here.class} --><b>></b><a href="{$ur_here.class.url}">{$ur_here.class.name}</a><!-- {/if} --><!-- {if $ur_here.title} --><b>></b>{$ur_here.title}<!-- {/if} --> 
 </div>
 <!-- {if $cur eq 'product_category'} -->
 <div class="sort"> 
  <!-- {foreach from=$sort_list item=item} --> 
  <a{if $item.active} class="active"{/if} href="{$item.url}">{$item.name}<!-- {if $item.icon} --><i class="bi bi-chevron-{$item.icon}"></i><!-- {/if} --></a> 
  <!-- {/foreach} --> 
 </div>
 <!-- {/if} -->
 {$breadcrumb nofilter}
</div>