<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<header id="header">
 <div class="top d-none d-md-block">
  <div class="container">
   <ul class="top-nav">
    <li><a href="{$site.home_url}">{$lang.home}</a></li>
    <li class="spacer"></li>
    <!-- {foreach from=$nav_top_list item=nav} --> 
    <!-- {if $nav.child} -->
    <li class="parent"><a href="{$nav.url}">{$nav.name}</a>
     <ul>
      <!-- {foreach from=$nav.child item=child} -->
      <li><a href="{$child.url}">{$child.name}</a></li>
      <!-- {/foreach} -->
     </ul>
    </li>
    <li class="spacer"></li>
    <!-- {else} -->
    <li><a href="{$nav.url}"{if $nav.target} target="_blank"{/if}>{$nav.name}</a></li>
    <li class="spacer"></li>
    <!-- {/if} --> 
    <!-- {/foreach} -->
    <li><a href="javascript:AddFavorite('{$site.home_url}', '{$site.site_name}')">{$lang.add_favorite}</a></li>
   </ul>
   <!-- {if $features.user} -->
   <ul class="user-top">
    <!-- {if $dou.auth.is_login} --> 
   <li><a href="{url link='user'}">{$dou.user.user_name}，{$lang.user_welcom_top}</a></li><li><a href="{url link='user.logout' token=$token}">{$lang.user_logout}</a></li>
    <!-- {if $dou.auth.is_work} -->
   <li><a href="{url link='work'}">{$lang.work}</a></li>
    <!-- {/if} -->
    <!-- {else} --> 
   <li><a href="{url link='user.login'}">{$lang.user_login_nav}</a></li><li><a href="{url link='user.register'}">{$lang.user_register_nav}</a></li>
    <!-- {/if} --> 
    <!-- {if $features.order} -->
   <li><a href="{url link='order.cart'}" class="cart">{$lang.order_cart}<span class="cartTotal"><span>{$cart_total}</span></span></a></li>
    <!-- {/if} -->
   </ul>
   <!-- {/if} -->
  </div>
 </div>
 <div class="head">
  <div class="container">
   <div class="logo">
    <a href="{$site.home_url}"><img src="../images/{$site.site_logo}" alt="{$site.site_name}" title="{$site.site_name}" /></a>
   </div>
   <div class="search-box">
   <form method="get" action="{url link='search'}">
     <input name="q" type="text" class="keyword" value="{$keyword|escape}" placeholder="{$lang.search_placeholder}" size="25">
     <input type="submit" class="btn-search" value="{$lang.btn_submit}">
    </form>
   </div>
  </div>
 </div>
</header>