/**
 +----------------------------------------------------------
 * 在线客服
 +----------------------------------------------------------
 */
$(document).ready(function() {
    $(".online-service .item").hover(function(){
        $(this).addClass("active");
        $('.pop-box',this).css('display', 'block');
    }, function(){
        $(this).removeClass("active");
        $('.pop-box',this).css('display', 'none');
    });

    // 当滚动条的垂直位置距顶部100像素以上时，出现返回顶部
    $(window).scroll(function() {
        if ($(window).scrollTop() > 100) {
            $('.go-top').show();
        } else {
            $(".go-top").hide();
        }
    });

    //点击跳转链接，滚动条跳到0的位置，页面移动速度是1000
    $(".go-top").click(function() {
        $('html,body').animate({
            scrollTop: '0'
        }, 1000);
        return false; //防止默认事件行为
    })
});