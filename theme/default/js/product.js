/**
 +----------------------------------------------------------
 * 监听
 +----------------------------------------------------------
 */
$(function() {
    // 款式属性
    if ($(".attribute-list").length > 0) {
        $('.attribute-list .radio-box input[type="radio"]').on('change', function() {
            var price_text = $('.attribute-list').data('price');
            var price = Number(price_text.match(/\d+(?:\.\d+)?/g));
            var sale_price_text = $('.attribute-list').data('sale_price');
            var sale_price = Number(sale_price_text.match(/\d+(?:\.\d+)?/g));
            
            $('.attribute-list .radio-box input[type="radio"]').each(function() {
                if ($(this).prop('checked')) {
                    price += Number($(this).data('money'));
                    sale_price += Number($(this).data('money'));
                }
            });
            
            $('#price').text(price_text.replace(/\d+(\.\d+)?/g, price.toFixed(2)));
            $('#salePrice').text(sale_price_text.replace(/\d+(\.\d+)?/g, sale_price.toFixed(2)));
        }).trigger('change'); // 初始化时触发一次更新
    }
 
    // 购买数量
    $(".quantity .minus").click(function() {
        var input = $(this).siblings('.inp');
        var value = parseInt(input.val()) || 0;
        // 防止数值变为负数（可根据需求移除）
        if (value > 0) {
            input.val(value - 1);
        }
    });
 
    $(".quantity .plus").click(function() {
        var input = $(this).siblings('.inp');
        var value = parseInt(input.val()) || 0;
        input.val(value + 1);
    });
 
    $(".quantity .inp").change(function() {
        this.value = this.value.replace(/[^0-9]/g, '');
        if (this.value === '') {
            this.value = '0';
        }
    });
 
    /**
     +----------------------------------------------------------
     * 多图切换
     +----------------------------------------------------------
     */
    const container = $('.gallery-thumbs');
    const list = $('.gallery-thumbs .thumb-list');
    let items = $('.gallery-thumbs .thumb-item');

    // 状态变量
    let currentTranslate = 0;
    let lastTime;
    let itemWidth = 0;
    const edgeThreshold = 20; // 边界检测阈值

    // 初始化系统
    refreshElements();
    calculateDimensions();
    bindEvents();

    // 刷新元素引用
    function refreshElements() {
        items = $('.thumb-item');
        itemWidth = items.first().outerWidth(true);
    }

    // 计算尺寸参数
    function calculateDimensions() {
        const containerWidth = container.width();
        const listWidth = list[0].scrollWidth;

        minTranslate = Math.min(containerWidth - listWidth, 0);
        maxTranslate = 0;
        currentTranslate = Math.max(minTranslate, Math.min(currentTranslate, maxTranslate));

        list.css('transform', `translateX(${currentTranslate}px)`);
    }

    // 事件绑定
    function bindEvents() {
        items.off('click').on('click', handleThumbClick);
    }

    // 处理缩略图点击
    function handleThumbClick(e) {
        const $item = $(this);
        checkEdgeScroll($item);
        updateMainImage($item);
    }

    // 边界滚动检测
    function checkEdgeScroll($item) {
        const containerRect = container[0].getBoundingClientRect();
        const itemRect = $item[0].getBoundingClientRect();
        
        const isAtLeftEdge = itemRect.left <= containerRect.left + edgeThreshold;
        const isAtRightEdge = itemRect.right >= containerRect.right - edgeThreshold;

        if (isAtRightEdge && canScrollNext()) {
            scrollBy(1);
        } else if (isAtLeftEdge && canScrollPrev()) {
            scrollBy(-1);
        }
    }

    // 执行滚动
    function scrollBy(units) {
        const target = currentTranslate - (units * itemWidth);
        const clamped = Math.max(minTranslate, Math.min(target, maxTranslate));

        if (clamped === currentTranslate) return;

        currentTranslate = clamped;
        animateScroll();
    }

    // 动画滚动
    function animateScroll() {
        list.css({
            'transition': 'transform 0.3s ease',
            'transform': `translateX(${currentTranslate}px)`
        });

        setTimeout(() => list.css('transition', 'none'), 300);
    }

    // 更新主图
    function updateMainImage($item) {
        const src = $item.find('img').attr('src');
        $('.gallery-top img').attr('src', src);
        $('.gallery-top img').attr('data-zoom-image', src);
        items.removeClass('active');
        $item.addClass('active');
    }

    function canScrollNext() {
        return currentTranslate > minTranslate + 1;
    }

    function canScrollPrev() {
        return currentTranslate < maxTranslate - 1;
    }

    // 窗口resize处理
    $(window).on('resize', () => {
        calculateDimensions();
        list.css('transform', `translateX(${currentTranslate}px)`);
    });
 
    /**
     +----------------------------------------------------------
     * 放大镜
     +----------------------------------------------------------
     */
    $.fn.jqueryzoom = function(options) {
        var settings = {
          xzoom: 200,		//zoomed width default width
          yzoom: 200,		//zoomed div default width
          offset: 10,		//zoomed div default offset
          position: "right", //zoomed div default position,offset position is to the right of the image
          preload: 1

        };

        if(options) {
          $.extend(settings, options);
        }

        var noalt='';

        $(this).hover(function(){

        const rect = this.getBoundingClientRect();
         
        var imageLeft = rect.left + window.scrollX;
        var imageRight = rect.right + window.scrollX;
        var imageTop =  rect.top + window.scrollY;
        var imageWidth = $(this).children('img').get(0).offsetWidth;
        var imageHeight = $(this).children('img').get(0).offsetHeight;


        noalt = $(this).children("img").attr("alt");

        var bigimage = $(this).children("img").attr("data-zoom-image");
        $(this).children("img").attr("alt",'');

        if ($("div.zoomdiv").get().length == 0) {
            $(this).after("<div class='zoomdiv'><img class='bigimg' src='"+bigimage+"'/></div>");
            $(this).append("<div class='jqZoomPup'> </div>");
        }


        if (settings.position == "right") {
            leftpos = this.offsetLeft + imageWidth + settings.offset;
        } else {
            leftpos = this.offsetLeft - settings.xzoom - settings.offset;
        }

        $("div.zoomdiv").css({ top: $(this).get(0).offsetTop,left: leftpos });
        $("div.zoomdiv").width(settings.xzoom);
        $("div.zoomdiv").height(settings.yzoom);
        $("div.zoomdiv").show();

       $(document.body).mousemove(function(e){
          $("div.jqZoomPup").hide();

          var bigwidth = $(".bigimg").get(0).offsetWidth;
          var bigheight = $(".bigimg").get(0).offsetHeight;
          var scaley ='x';
          var scalex= 'y';

          if (isNaN(scalex)|isNaN(scaley)) {
              var scalex = (bigwidth/imageWidth);
              var scaley = (bigheight/imageHeight);

              $("div.jqZoomPup").width((settings.xzoom)/scalex );
              $("div.jqZoomPup").height((settings.yzoom)/scaley);
              $('div.jqZoomPup').show();
              $("div.jqZoomPup").css('visibility','visible');
          }

          mouse = new MouseEvent(e);
        
          
          xpos = mouse.x - $("div.jqZoomPup").width()/2 - imageLeft;
          
          ypos = mouse.y - $("div.jqZoomPup").height()/2 - imageTop ;
          xpos = (mouse.x - $("div.jqZoomPup").width()/2 < imageLeft ) ? 0 : (mouse.x + $("div.jqZoomPup").width()/2 > imageWidth + imageLeft ) ?  (imageWidth -$("div.jqZoomPup").width() -2)  : xpos;
          ypos = (mouse.y - $("div.jqZoomPup").height()/2 < imageTop ) ? 0 : (mouse.y + $("div.jqZoomPup").height()/2  > imageHeight + imageTop ) ?  (imageHeight - $("div.jqZoomPup").height() -2 ) : ypos;

          $("div.jqZoomPup").css({ top: ypos,left: xpos });
          $("div.jqZoomPup").show();

          scrolly = ypos;

          $("div.zoomdiv").get(0).scrollTop = scrolly * scaley;

          scrollx = xpos;

          $("div.zoomdiv").get(0).scrollLeft = (scrollx) * scalex ;
          });
        },function(){
           $(this).children("img").attr("alt",noalt);
           $(document.body).unbind("mousemove");
           $("div.jqZoomPup").remove();
           $("div.zoomdiv").remove();
        });

        count=0;

        if (settings.preload) {
            $('body').append("<div style='display:none;' class='jqPreload"+count+"'>sdsdssdsd</div>");

            $(this).each(function(){
                 var imagetopreload= $(this).children("img").attr("data-zoom-image");
                 var content = jQuery('div.jqPreload'+count+'').html();
                 jQuery('div.jqPreload'+count+'').html(content+'<img src=\"'+imagetopreload+'\">');
            });
        }
    }
    
    if ($(window).width() < 992) {
        $(".popup-img").click(function() {
            $(".mobile-image").toggle();
            $("#wrapper").toggle();
        });
    } else {
        $(".jqzoom").jqueryzoom({
            xzoom: 498, //放大图片的宽度(默认值200)  
            yzoom: 498, //放大图片的高度度(默认值200)
            offset: 10, //放大图片的偏移值(度(默认值10)
            position: "right" //放大图片的显示位置度(默认值“right”)
        });	
    }
});

function MouseEvent(e) {
    this.x = e.pageX
    this.y = e.pageY
}

/**
 +----------------------------------------------------------
 * 更新购物车数量
 +----------------------------------------------------------
 */
function changeNumber(id, calculate) {
    var item = document.getElementById("number_" + id);
   
    if (calculate == 'plus') {
        item.value++;
    } else {
        if (item.value > 1) {
            item.value--;
        }
    }
    
    changePrice(id, item.value);
}