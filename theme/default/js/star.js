$(document).ready(function() {
    var activeInd = 5;
    
    // 鼠标移入
    $(".star-box .fa-star").on('mouseenter', function() {
        // 移除所有星星的高亮
        $(".star-box .fa-star").removeClass("active");
        // 记录当前鼠标滑动星星停止时，一共滑过的星星数量
        var _ind = $(this).index() * 1 + 1;
     
        $('.star-number').html(_ind + '分')
        
        //从停止位置开始(包括停止位置)，将前面的所有星星点亮
        for(var i = 0; i < _ind; i++) {
            $(".star-box .fa-star").eq(i).addClass("hover");
        }
    })
    
    // 鼠标点击
    $(".star-box .fa-star").on('click', function() {
        //移除所有星星的高亮
        $(".star-box .fa-star").removeClass("active");
        
        // 记录当前鼠标点击时，一共滑过的星星数量
        var _ind = $(this).index() * 1 + 1;
        
        // 保存鼠标点击的位置
        activeInd = _ind;
        $('.star-number').html(activeInd + '分')
        $('#starValue').val(activeInd)
        
        //从停止位置开始(包括停止位置)，将前面的所有星星点亮
        for(var i = 0; i < _ind; i++) {
            $(".star-box .fa-star").eq(i).addClass("active");
        }
    })

    // 鼠标移出
    $(".star-box .fa-star").on('mouseleave', function() {
        //移除所有星星的高亮
        $(".star-box .fa-star").removeClass("hover");
     
        $('.star-number').html(activeInd + '分')
        
        // 从停止位置开始(包括停止位置)，将前面的所有星星点亮
        for(var i = 0; i < activeInd; i++) {
            $(".star-box .fa-star").eq(i).addClass("active");
        }
    })
});