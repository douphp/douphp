<div id="dou-sidebar">
 <!-- {if $workspace.admin_theme_custom.menu} -->
 {include file="menu.custom.htm"}
 <!-- {else} -->
 <ul class="top">
  <li data-id="home"><a href="{url link='admin.index'}"><i class="{$workspace.menu_icon_map.home}"></i><em>{$lang.menu_home}<!-- {if $unum.system} --><span class="badge"><span>{$unum.system}</span></span><!-- {/if} --></em></a></li>
 </ul>
 <div class="menu-scroll">
  <ul>
   <!-- {if $workspace.menu_permission.setting} -->
   <li{if $cur eq 'setting'} class="cur"{/if} data-id="setting"><a href="{url link='admin.setting'}"><i class="{$workspace.menu_icon_map.setting}"></i><em>{$lang.setting}</em></a></li>
   <!-- {/if} -->
   <!-- {if $workspace.menu_permission.nav} -->
   <!-- {if $_SYSTEM_SIGN eq 'miniprogram'} -->
   <li{if $sub_cur eq 'miniprogram_nav'} class="cur"{/if} data-id="miniprogram"><a href="{url link='admin.miniprogram.nav'}"><i class="{$workspace.menu_icon_map.nav}"></i><em>{$lang.nav}</em></a></li>
   <!-- {else} -->
   <li{if $cur eq 'nav'} class="cur"{/if} data-id="nav"><a href="{url link='admin.nav'}"><i class="{$workspace.menu_icon_map.nav}"></i><em>{$lang.nav}</em></a></li>
   <!-- {/if} -->
   <!-- {/if} -->
   <!-- {if $workspace.menu_permission.page} -->
   <li{if $cur eq 'page'} class="cur"{/if} data-id="page"><a href="{url link='admin.page'}"><i class="{$workspace.menu_icon_map.page}"></i><em>{$lang.menu_page}</em></a></li>
   <!-- {/if} -->
  </ul>
  <!-- {if $features.item} 全能内容模块判断 -->
  <ul>
   <li{if $cur eq 'item_category'} class="cur"{/if} data-id="item_category"><a href="{url link='admin.item.category'}"><i class="{$workspace.menu_icon_map.article_cat}"></i><em>{$lang.item_category}</em></a></li>
   <!-- {if !$workspace.menu_item} -->
   <li{if $cur eq 'item'} class="cur"{/if} data-id="item"><a href="{url link='admin.item'}"><i class="{$workspace.menu_icon_map.article}"></i><em>{$lang.item}</em></a></li>
   <!-- {else} -->
   <!-- {foreach from=$workspace.menu_item item=menu} -->
   <li{if $menu.cur} class="cur"{/if} data-id="item"><a href="{$menu.url}"><i class="{$workspace.menu_icon_map.article}"></i><em>{$menu.name}</em></a></li>
   <!-- {/foreach} -->
   <!-- {/if} -->
  </ul>
  <!-- {/if} 全能内容模块判断 -->
  <!-- {if !$workspace.menu_column && !$workspace.menu_single} 如果没有模块则调用单页面菜单 -->
  <!-- {foreach from=$workspace.menu_simple item=menu} -->
  <ul>
   <li{if $cur eq $menu.slug} class="cur"{/if} data-id="{$menu.slug}"><a href="{url link='admin.page.edit' id=$menu.id}"><i class="{$menu.icon}"></i><em>{$menu.name}</em></a></li>
   <!-- {foreach from=$menu.child item=child} -->
   <li{if $cur eq $child.slug} class="cur"{/if} data-id="{$child.slug}"><a href="{url link='admin.page.edit' id=$child.id}"><i class="{$child.icon}"></i><em>{$child.name}</em></a></li>
   <!-- {/foreach} -->
  </ul>
  <!-- {/foreach} -->
  <!-- {/if} -->
  <!-- {if $workspace.menu_column} -->
  <ul>
   <!-- {foreach from=$workspace.menu_column item=menu} -->
   <!-- {if $menu.name neq 'item'} -->
   <!-- {if $menu.lang} -->
   <li class="{if $cur eq $menu.name}cur {/if}has-sub" data-id="{$menu.name}"><a href="{$menu.url}"><i class="{$menu.icon}"></i><em>{$menu.lang}</em></a>
    <!-- {if $menu.lang_category} -->
    <ul class="sub-menu">
     <li{if $cur eq $menu.name && $submenu neq $menu.name_category} class="cur"{/if}><a href="{$menu.url}"><em>{$menu.lang_all}</em></a></li>
     <li{if $cur eq $menu.name && $submenu eq $menu.name_category} class="cur"{/if}><a href="{$menu.url_category}"><em>{$menu.lang_category}</em></a></li>
    </ul>
    <!-- {/if} -->
   </li>
   <!-- {elseif $menu.lang_category} -->
   <li data-id="{$menu.name_category}"><a href="{$menu.url_category}"><i class="{$menu.icon_category}"></i><em>{$menu.lang_category}</em></a></li>
   <!-- {/if} -->
   <!-- {/if} -->
   <!-- {/foreach} -->
  </ul>
  <!-- {/if} -->
  <!-- {if $workspace.menu_single} -->
  <ul>
   <!-- {foreach from=$workspace.menu_single item=menu} -->
   <!-- {if $menu.lang} -->
   <li{if $cur eq $menu.name} class="cur"{/if} data-id="{$menu.name}"><a href="{$menu.url}"><i class="{$menu.icon}"></i><em>{$menu.lang}<!-- {if $menu.name eq 'plugin'} -->{if $unum.plugin}<span class="badge"><span>{$unum.plugin}</span></span>{/if}<!-- {/if} --></em></a></li>
   <!-- {/if} -->
   <!-- {/foreach} -->
  </ul>
  <!-- {/if} -->
  <ul class="bot">
   <!-- {if $workspace.menu_permission.site_home_other} -->
   <li{if $cur eq 'site_home' || $cur eq 'show' || $cur eq 'data' || $cur eq 'box' || $cur eq 'fragment'} class="cur"{/if} data-id="site_home"><a href="{url link='admin.site_home'}"><i class="{$workspace.menu_icon_map.show}"></i><em><!-- {if $features.data || $features.box || $features.fragment || $features.area} -->{$lang.site_home_other}<!-- {else} -->{$lang.show}<!-- {/if} --></em></a></li>
   <!-- {/if} -->
   <!-- {if $workspace.menu_permission.backup} -->
   <li{if $cur eq 'backup'} class="cur"{/if} data-id="backup"><a href="{url link='admin.backup'}"><i class="{$workspace.menu_icon_map.backup}"></i><em>{$lang.backup}</em></a></li>
   <!-- {/if} -->
   <!-- {if !$site.close_miniprogram && $workspace.menu_permission.miniprogram} -->
   <li{if $cur eq 'miniprogram' && $sub_cur neq 'miniprogram_nav'} class="cur"{/if} data-id="miniprogram"><a href="{url link='admin.miniprogram'}"><i class="{$workspace.menu_icon_map.miniprogram}"></i><em>{$lang.miniprogram}<!-- {if $unum.miniprogram} --><span class="badge"><span>{$unum.miniprogram}</span></span><!-- {/if} --></em></a></li>
   <!-- {/if} -->
   <!-- {if !$pure_mode && $_SYSTEM_SIGN neq 'miniprogram' && $workspace.menu_permission.theme} -->
   <li{if $cur eq 'theme'} class="cur"{/if} data-id="theme"><a href="{url link='admin.theme'}"><i class="{$workspace.menu_icon_map.theme}"></i><em>{$lang.theme}<!-- {if $unum.theme} --><span class="badge"><span>{$unum.theme}</span></span><!-- {/if} --></em></a></li>
   <!-- {/if} -->
   <!-- {if $workspace.menu_permission.manager} -->
   <li{if $cur eq 'manager'} class="cur"{/if} data-id="manager"><a href="{url link='admin.manager'}"><i class="{$workspace.menu_icon_map.manager}"></i><em>{$lang.manager}</em></a></li>
   <!-- {/if} -->
  </ul>
 </div>
 <!-- {/if} -->
 <div id="switch-menu" class="switch-menu p-none">></div>
</div>
