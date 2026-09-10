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
    <li><a href="{url link='order.cart'}">{$lang.order_cart}</a></li>
    <!-- {/if} -->
   </ul>
   <ul class="search">
    <div class="search-box">
     <form method="get" action="{url link='search'}">
      <input name="q" type="text" class="keyword" value="{$keyword|escape}" placeholder="{$lang.search_placeholder}" size="25">
      <button type="submit" class="btnSearch bi bi-search"></button>
     </form>
    </div>
   </ul>
  </div>
 </div>
 <nav class="navbar navbar-expand-lg">
  <div class="container">
   <div class="navbar-brand"> <a href="{$site.home_url}" class="logo"><img src="../images/{$site.site_logo}" alt="{$site.site_name}" /></a> </div>
   <div class="navbar-action d-lg-none"> 
    <!-- {if $features.user} --> 
   <a href="{url link='user'}" class="bi bi-person-circle"></a>
    <!-- {/if} -->
    <a href="javascript:;" class="bi bi-list" data-toggle="collapse" data-target="#main-nav" aria-controls="main-nav" aria-expanded="false" aria-label="Toggle navigation"></a>
   </div>
   <div class="main-nav collapse navbar-collapse justify-content-lg-end" id="main-nav">
    <ul class="navbar-nav">
     <li class="nav-item{if $index.cur} active{/if}"> <a class="nav-link" href="{$site.home_url}">{$lang.home}</a></li>
     <!-- {foreach from=$nav_middle_list name=nav_middle_list item=nav} -->
     <li class="nav-item{if $nav.child} dropdown{/if}{if $nav.cur} active{/if}"> <a href="{$nav.url}" class="nav-link{if $nav.child} dropdown-toggle{/if}{if $nav.cur} active{/if}" {if $nav.child} data-toggle="dropdown"{/if} aria-haspopup="true" aria-expanded="false"{if $nav.target} target="_blank"{/if}>{$nav.name}</a> 
      <!-- {if $nav.child} -->
      <ul class="dropdown-menu">
       <!-- {foreach from=$nav.child item=child} --> 
       <li{if $child.child} class="dropdown"{/if}> <a href="{$child.url}" class="dropdown-item{if $child.child} dropdown-toggle{/if}" {if $child.child} data-toggle="dropdown"{/if}>{$child.name}</a> 
        <!-- {if $child.child} -->
        <ul class="dropdown-menu">
         <!-- {foreach from=$child.child item=children} --> 
         <li{if $children.child} class="dropdown"{/if}> <a class="dropdown-item{if $children.child} dropdown-toggle{/if}" href="{$children.url}">{$children.name}</a> 
          <!-- {if $children.child} -->
          <ul class="dropdown-menu">
           <!-- {foreach from=$children.child item=item} -->
           <li><a class="dropdown-item" href="{$item.url}">{$item.name}</a></li>
           <!-- {/foreach} -->
          </ul>
          <!-- {/if} -->
         </li>
         <!-- {/foreach} -->
        </ul>
        <!-- {/if} -->
       </li>
       <!-- {/foreach} -->
      </ul>
      <!-- {/if} -->
     </li>
     <!-- {/foreach} -->
     <!-- {if $features.language} -->
     <li class="nav-item dropdown d-lg-none">
      <a href="javascript:;" class="nav-link dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">{$lang.cur_language}</a> 
      <ul class="dropdown-menu">
       <!-- {foreach from=$lang_menu item=item} -->
       <li> <a href="{$item.url}" class="dropdown-item">{$item.name}</a> 
       <!-- {/foreach} -->
      </ul>
     </li>
     <!-- {/if} --> 
    </ul>
   <form class="form-inline my-2 my-lg-0 d-lg-none" action="{url link='search'}">
     <input class="form-control mr-sm-2" name="q" type="text" value="{$keyword|escape}" placeholder="{$lang.search_placeholder}">
     <button class="btn btn-outline-success my-2 my-sm-0" type="submit">{$lang.btn_submit}</button>
    </form>
   </div>
  </div>
 </nav>
</header>