<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<div class="tree-box">
 <h3>{$lang.article_tree}</h3>
 <ul>
  <!-- {foreach from=$article_category item=cate} 一级分类 -->
  <li{if $cate.cur} class="cur"{/if}><a href="{$cate.url}">{$cate.name}</a></li>
  <!-- {if $cate.child} -->
  <ul>
   <!-- {foreach from=$cate.child item=child} 二级分类 -->
   <li{if $child.cur} class="cur"{/if}><i>-</i><a href="{$child.url}">{$child.name}</a></li>
   <!-- {/foreach} -->
  </ul>
  <!-- {/if} -->
  <!--{/foreach}-->
 </ul>
 <ul class="search">
  <div class="search-box">
  <form method="get" action="{url link='search'}">
    <input type="hidden" name="module" value="article">
    <input name="q" type="text" class="keyword" title="{$lang.search_cue}" value="{$keyword_article|escape}" placeholder="{$lang.search_article}">
    <button type="submit" class="btnSearch bi bi-search"></button>
   </form>
  </div>
 </ul>
</div>