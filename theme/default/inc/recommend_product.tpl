<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<div class="index-box bg">
 <h3><b>{$lang.product_recommend}</b><em>Recommend Product</em></h3>
 <div class="product-list">
  <div class="container">
   <div class="row"> 
    <!-- {foreach from=$recommend_product name=recommend_product item=product} -->
    <div class="col-md-3 col-6">
     <div class="item">
      <div class="img scale"><a href="{$product.url}"><img src="{$product.thumb}" /></a></div>
      <div class="name"><a href="{$product.url}">{$product.title}</a></div>
      <!-- {if $site.show_price} -->
      <div class="price-box"><em class="price">{$product.sale_price.format}</em><!-- {if $product.sale_price.name} --><i class="price-cue">{$product.sale_price.name}</i><!-- {/if} --><!-- {if $product.sale_price.type neq 'original'} --><em class="price-gray">{$product.price}</em><!-- {/if} --></div>
      <!-- {/if} --> 
     </div>
    </div>
    <!-- {/foreach} --> 
   </div>
  </div>
 </div>
<div class="more"><a href="{url link='product'}">{$lang.product_more}</a></div>
</div>
