<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<div class="index-box">
 <h3><b>{$lang.article_news}</b><em>Recommend News</em></h3>
 <div class="article-list">
  <div class="container">
   <div class="row">
    <div class="img-box col-md-5"> 
     <!-- {foreach from=$recommend_article name=article_img item=article} --> 
     <!-- {if $article@first} -->
     <dl class="img scale">
      <a href="{$article.url}"><!-- {if $article.image} --><img src="{$article.image}" /><!-- {else} --><em>NO IMAGE</em><!-- {/if} --></a>
     </dl>
     <dl class="item">
      <dt><em>{$article.time.d}</em><b>{$article.time.y}-{$article.time.m}</b></dt>
      <dd><a href="{$article.url}">{$article.title}</a>
       <p>{$article.description|truncate:66:"..."}</p>
      </dd>
     </dl>
     <!-- {/if} --> 
     <!-- {/foreach} --> 
    </div>
    <div class="text-box col-md-7"> 
     <!-- {foreach from=$recommend_article name=article_text item=article} --> 
     <!-- {if !$article@first} -->
     <dl class="item">
      <dt><em>{$article.time.d}</em><b>{$article.time.y}-{$article.time.m}</b></dt>
      <dd><a href="{$article.url}">{$article.title}</a>
       <p>{$article.description|truncate:76:"..."}</p>
      </dd>
     </dl>
     <!-- {/if} --> 
     <!-- {/foreach} --> 
    </div>
   </div>
  </div>
 </div>
<div class="more"><a href="{url link='article'}">{$lang.article_more}</a></div>
</div>
