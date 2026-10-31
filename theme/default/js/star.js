$(document).ready(function() {
    var activeInd = 5;
    // 主题使用 Bootstrap Icons（.bi-star）；兼容旧主题的 .fa-star
    var starSelector = '.star-box .bi-star, .star-box .fa-star';
    var starUnit = $('.star-box').data('star-unit') || '分';
    
    // 鼠标移入
    $(starSelector).on('mouseenter', function() {
        // 移除所有星星的高亮
        $(starSelector).removeClass("active");
        // 记录当前鼠标滑动星星停止时，一共滑过的星星数量
        var _ind = $(this).index() * 1 + 1;
     
        $('.star-number').text(_ind + starUnit)
        
        //从停止位置开始(包括停止位置)，将前面的所有星星点亮
        for(var i = 0; i < _ind; i++) {
            $(starSelector).eq(i).addClass("hover");
        }
    })
    
    // 鼠标点击
    $(starSelector).on('click', function() {
        //移除所有星星的高亮
        $(starSelector).removeClass("active");
        
        // 记录当前鼠标点击时，一共滑过的星星数量
        var _ind = $(this).index() * 1 + 1;
        
        // 保存鼠标点击的位置
        activeInd = _ind;
        $('.star-number').text(activeInd + starUnit)
        $('#starValue').val(activeInd)
        
        //从停止位置开始(包括停止位置)，将前面的所有星星点亮
        for(var i = 0; i < _ind; i++) {
            $(starSelector).eq(i).addClass("active");
        }
    })

    // 鼠标移出
    $(starSelector).on('mouseleave', function() {
        //移除所有星星的高亮
        $(starSelector).removeClass("hover");
     
        $('.star-number').text(activeInd + starUnit)
        
        // 从停止位置开始(包括停止位置)，将前面的所有星星点亮
        for(var i = 0; i < activeInd; i++) {
            $(starSelector).eq(i).addClass("active");
        }
    })
});