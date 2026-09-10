<aside class="subnav">
 <h3><i class="bi-wechat"></i>{$lang.miniprogram}</h3>
 <ul>
  <li><a href="{url link='admin.miniprogram'}"{if $rec eq 'default' && $group neq 'miniprogram'} class="cur"{/if}>{$lang.miniprogram_list}</a></li>
  <!-- {if $_SYSTEM_SIGN neq 'miniprogram'} -->
  <li><a href="{url link='admin.miniprogram.nav'}"{if $rec eq 'nav'} class="cur"{/if}>{$lang.miniprogram_nav}</a></li>
  <li><a href="{url link='admin.miniprogram.show'}"{if $rec eq 'show'} class="cur"{/if}>{$lang.miniprogram_show}</a></li>
  <!-- {if $features.data} -->
  <li><a href="{url link='admin.data' group=miniprogram}"{if $group eq 'miniprogram'} class="cur"{/if}>{$lang.miniprogram_data}</a></li>
  <!-- {/if} -->
  <!-- {/if} -->
  <li><a href="{url link='admin.miniprogram.system'}"{if $rec eq 'system'} class="cur"{/if}>{$lang.miniprogram_system}</a></li>
  <li><a href="{url link='admin.miniprogram.release'}"{if $rec eq 'release'} class="cur"{/if}>{$lang.miniprogram_release}</a></li>
  <!-- {if !$site.close_douphp_plus} -->
  <li><a href="{url link='admin.module'}">{$lang.module}</a></li>
  <!-- {/if} -->
 </ul>
</aside>
