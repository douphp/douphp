<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<header id="header">
 <div class="top d-none d-lg-block">
  <div class="container">
   <ul class="top-nav">
    <!-- {if $lang_menu} -->
    <li class="parent lang-select"><a href="javascript:;"><i class="bi bi-globe-americas"></i> {$lang.cur_language}</a>
     <ul>
      <!-- {foreach from=$lang_menu item=item} -->
      <li><a href="{$item.url}">{$item.name}</a></li>
      <!-- {/foreach} -->
     </ul>
    </li>
    <!-- {/if} --> 
    <!-- {foreach from=$nav_top_list item=nav} -->
    <li{if $nav.child} class="parent"{/if}>
     <a href="{$nav.url}"{if $nav.target} target="_blank"{/if}>{$nav.name}</a>
     <!-- {if $nav.child} -->
     <ul>
      <!-- {foreach from=$nav.child item=child} -->
      <li><a href="{$child.url}">{$child.name}</a></li>
      <!-- {/foreach} -->
     </ul>
     <!-- {/if} --> 
    </li>
    <!-- {/foreach} -->
    <li><a href="javascript:AddFavorite('{$site.home_url}', '{$site.site_name}')">{$lang.add_favorite}</a></li>
    <!-- {if $features.user} --> 
    <!-- {if $dou.auth.is_login} -->
    <li><a href="{url link='user'}">{$dou.user.user_name}，{$lang.user_welcom_top}</a></li>
    <li><a href="{url link='user.logout' token=$token}">{$lang.user_logout}</a></li>
    <!-- {if $dou.auth.is_work} -->
    <li><a href="{url link='work'}">{$lang.work}</a></li>
    <!-- {/if} -->
    <!-- {else} -->
    <li><a href="{url link='user.login'}">{$lang.user_login_nav}</a></li>
    <li><a href="{url link='user.register'}">{$lang.user_register_nav}</a></li>
    <!-- {/if} --> 
    <!-- {/if} -->
    <!-- {if $features.order} --> 
    <li><a href="{url link='order.cart'}">{$lang.order_cart}<span class="cartTotal"><span>{$cart_total}</span></span></a></li>
    <!-- {/if} -->
   </ul>
   <ul class="search">
    <div class="search-box">
     <form method="get" action="{url link='search'}">
      <!-- {if !$site.rewrite} --><input type="hidden" name="route" value="search"><!-- {/if} -->
      <input name="q" type="text" class="keyword" value="{$keyword|escape}" placeholder="{$lang.search_placeholder}" size="25">
      <button type="submit" class="btnSearch bi bi-search"></button>
     </form>
    </div>
   </ul>
  </div>
 </div>
 <div class="head">
  <div class="container">
   <div class="logo">
    <a href="{$site.home_url}"><img src="../images/{$site.site_logo}" alt="{$site.site_name}" title="{$site.site_name}" /></a>
    <!-- {if $column_name} --><span class="logo-divider"></span><span class="logo-column">{$column_name}</span><!-- {/if} -->
   </div>
  </div>
 </div>
</header>