<!-- {if $workspace.admin_theme_custom.header} -->
{include file="header.custom.htm"}
<!-- {else} -->
<div id="dou-header">
 <div class="header-logo"><a href="{url link='admin.index'}" title="{if !$pure_mode}DouPHP轻量级企业网站管理系统{else}{$site.site_name}{/if}"{if $cloud_vip} class="cloud_vip"{/if}>{if !$pure_mode}DouPHP轻量级企业网站管理系统{else}{$site.site_name}{/if}</a></div>
 <div class="header-box">
  <ul class="header-site-name">{$site.site_name}</ul>
  <ul class="header-nav">
   <!-- {if !$site.close_douphp_plus && $workspace.menu_permission.module} -->
   <li class="m-none"><a href="{url link='admin.module'}"{if $cur eq 'module'} class="cur"{/if}><i class="bi-grid-1x2"></i>{$lang.top_module}{if $unum.module}<span class="badge"><span>{$unum.module}</span></span>{/if}</a></li>
   <!-- {/if} -->
   <li class="m-none"><a href="{$site.root_url}" target="_blank"><i class="bi-laptop"></i>{$lang.top_go_site}</a></li>
   <li><a href="{url link='admin.index.clear_cache'}" class="js-post"><i class="bi-arrow-clockwise"></i>{$lang.clear_cache}</a></li>
   <!-- {if $features.language && $workspace.menu_permission.language} -->
   <li><a href="{url link='admin.language'}"><i class="bi-globe"></i>{$lang.language}</a></li>
   <!-- {/if} -->
   <!-- {if !$pure_mode && $workspace.menu_permission.cloud} -->
   <li class="dropdown"><a href="javaScript:;" class="dropdown-toggle"><i class="bi-cloud"></i>{$lang.cloud}</a>
    <div class="dropdown-menu">
     <!-- {if !$cloud_vip} -->
     <a href="https://www.douphp.com/buy" target="_blank">{$lang.cloud_buy_vip}</a>
     <!-- {/if} -->
     <a href="{url link='admin.cloud.account'}">{$lang.cloud_account}</a>
    </div>
   </li>
   <!-- {/if} -->
   <li class="dropdown"><a href="javaScript:;" class="dropdown-toggle"><i class="bi-person-circle"></i>{$lang.top_welcome}{$global_admin.username}</a>
    <div class="dropdown-menu">
     <a href="{url link='admin.manager.edit' id=$global_admin.user_id}">{$lang.top_manager_edit}</a>
     <a href="{url link='admin.login.logout'}" class="js-post">{$lang.top_logout}</a>
    </div>
   </li>
  </ul>
 </div>
</div>
<!-- dou-header 结束 -->
<!-- {/if} -->
