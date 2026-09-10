<!-- {if $workspace.admin_theme_custom.handle} -->
{include file="handle.custom.htm"}
<!-- {else} -->
<div class="dou-toolbar">
 <ul>
  <li class="dropdown"><a href="JavaScript:void(0);"><i class="bi bi-plus-lg"></i><em>{$lang.top_create}</em></a>
   <div class="dropdown-menu">
    <a href="{url link='admin.nav.create'}">{$lang.top_add_nav}</a>
    <!-- {foreach from=$workspace.menu_column item=menu} -->
    <a href="{$menu.url_create}">{$menu.lang_top_add}</a>
    <!-- {/foreach} -->
    <a href="{url link='admin.show'}">{$lang.top_add_show}</a>
    <a href="{url link='admin.page.create'}">{$lang.top_add_page}</a>
    <a href="{url link='admin.manager.create'}">{$lang.top_add_manager}</a>
    <!-- {if $features.link} -->
    <a href="{url link='admin.link'}">{$lang.top_add_link}</a>
    <!-- {/if} -->
   </div>
  </li>
  <!-- {if !$pure_mode} -->
  <li class="last"><a href="http://help.douphp.com" target="_blank"><i class="bi-question-circle"></i><em>{$lang.top_help}</em></a></li>
  <!-- {/if} -->
 </ul>
</div>
<!-- {/if} -->