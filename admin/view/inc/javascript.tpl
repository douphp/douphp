<script>var admin_url = "{$admin_url}";</script>
<script>var dou_crop_max_edge = {if $site.image_width}{$site.image_width}{else}1000{/if};</script>
<script>window.__douRouteConfig = {$js_route_config_json nofilter};</script>
<script type="text/javascript" src="js/jquery.min.js"></script>
<script type="text/javascript" src="js/dou.csrf.js"></script>
<script type="text/javascript" src="{$js_routes_script_url}"></script>
<script type="text/javascript" src="{$js_lang_script_url}"></script>
<script type="text/javascript" src="js/lang.js?v=1"></script>
<script type="text/javascript" src="js/route.js"></script>
<script type="text/javascript" src="js/jquery.form.min.js"></script>
<script type="text/javascript" src="js/pinyin.min.js"></script>
<script type="text/javascript" src="js/alpine.min.js" defer></script>
<script type="text/javascript" src="js/common.js"></script>
<script type="text/javascript" src="js/dou.toast.js"></script>
<script type="text/javascript" src="js/browser-md5-file.min.js"></script>
<script>var cur = "{$cur}";</script>
<!-- {if $ai_config} -->
<script type="application/json" id="ai-config">{$ai_config nofilter}</script>
<script type="text/javascript" src="js/ai.assist.js"></script>
<!-- {/if} -->
<script type="text/javascript">
{literal}
$(function () {
    $(document).on('click', '.notice-dismiss', function () {
        $(this).closest('.notice').remove();
    });
});
{/literal}
</script>