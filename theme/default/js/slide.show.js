/**
 +----------------------------------------------------------
 * 首页幻灯
 +----------------------------------------------------------
 */
var mySwiper = new Swiper ('.slide-show .swiper', {
  direction: 'horizontal', // horizontal：横向切换 vertical：竖向切换
  //slidesPerView: window.innerWidth >= 992 ? 6 : 2,      // 单页显示6列（根据实际需求调整）
  //slidesPerGroup: window.innerWidth >= 992 ? 6 : 2,     // 每次滚动6列
  //spaceBetween: 20,      // 列间距
  //grid: {
  //  rows: 3,             // 保持3行网格布局
  //},
  //freeMode: true, // 可选：启用自由模式（无缝滚动）
  loop: true, // 循环模式选项
  autoplay: {
    delay: 10 * 1000, // 自动播放间隔时间5秒
    pauseOnMouseEnter: false, // 鼠标置于swiper时暂停自动切换，鼠标离开时恢复自动切换
    stopOnLastSlide: false, // 当切换到最后一个slide时不停止自动切换（loop模式下无效）
  },
  speed: 2000, // 播放速度

  // 如果需要分页器
  pagination: {
    el: ".swiper-pagination",
    clickable: true,
  },

  // 如果需要前进后退按钮
  navigation: {
    nextEl: '.swiper-button-next',
    prevEl: '.swiper-button-prev',
  },
})