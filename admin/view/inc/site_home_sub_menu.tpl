<aside class="subnav">
 <h3><i class="bi bi-pie-chart"></i>{$lang.menu_site_home_other}</h3>
 <ul>
  <li><a href="{url link='admin.site_home'}"{if $cur eq 'site_home' || $group eq 'index'} class="cur"{/if}>{$lang.site_home}</a></li>
  <li><a href="{url link='admin.show'}"{if $cur eq 'show'} class="cur"{/if}>{$lang.show}</a></li>
  <!-- {if $features.data} -->
  <li><a href="{url link='admin.data'}"{if $cur eq 'data' && ($group eq 'common' || $group eq 'all')} class="cur"{/if}>{$lang.data}</a></li>
  <li><a href="{url link='admin.data' group=banner}"{if $cur eq 'data' && $group eq 'banner'} class="cur"{/if}>{$lang.data_banner}</a></li>
  <!-- {/if} -->
  <!-- {if $features.fragment} -->
  <li><a href="{url link='admin.fragment'}"{if $cur eq 'fragment'} class="cur"{/if}>{$lang.fragment}</a></li>
  <!-- {/if} -->
  <!-- {if $features.box} -->
  <li><a href="{url link='admin.box'}"{if $cur eq 'box'} class="cur"{/if}>{$lang.box}</a></li>
  <!-- {/if} -->
 </ul>
</aside>
