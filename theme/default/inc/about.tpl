<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<div class="index-box">
 <div class="container">
  <div class="row about">
   <div class="col-md-4">
    <div class="img scale"> 
     <!-- {if $data.about.image} --> 
     <img src="{$data.about.image}" /> 
     <!-- {else} --> 
     <img src="../images/img_about.jpg" /> 
     <!-- {/if} --> 
    </div>
   </div>
   <div class="col-md-8">
    <h2>{$about.name}</h2>
    <div class="desc">{$about.content|truncate:220:"..."}</div>
    <div class="more"><a href="{$about.link}">{$lang.about_link}</a></div>
   </div>
  </div>
 </div>
</div>
