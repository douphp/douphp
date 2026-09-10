<?php

namespace Dou\Api\Service\Bootstrap;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Web\I18n\JsLangExporter;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 应用引导服务（小程序冷启动公共数据）
 *
 * 对应路由：route=bootstrap/index；输出 site / param / data / features / nav_list / lang_v / version
 * 等启动期上下文，供 commonStore 作单一真相源。语言包译串不再内嵌于此，改由独立 route=lang 接口
 * 下发，本服务仅给出 lang_v 内容指纹供客户端判断是否重新拉取。
 */
class BootstrapService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * @return array
     */
    public function build()
    {
        $dataResult = data()->get();

        $data = array();
        $data['site'] = Config::get('site', array());
        $data['param'] = Config::get('param', array());
        $data['data'] = $dataResult;
        $data['features'] = Config::get('features', array());
        $data['user_level_has_data'] = Config::get('features.user', false) && DB::rowExist('user_level');
        $data['nav_list'] = app(\Dou\Core\Service\Nav\MiniprogramNavigationBuilder::class)->build('miniprogram_top');

        // 语言包内容指纹：与独立 route=lang 接口返回的 lang_all() 同源同算法（见 JsLangExporter），
        // 客户端据其变化决定是否重新拉取语言包，避免在 bootstrap 内嵌整包译串。
        $pack = (string) Config::get('site.language', 'zh_cn');
        $data['lang_v'] = JsLangExporter::manifestHash('api', $pack);

        // 缓存失效校验：site / param / features / lang_v 任一变更即令小程序端本地缓存失效重新拉取。
        $data['version'] = md5(serialize(array(
            $data['site'],
            $data['param'],
            $data['features'],
            $data['lang_v'],
        )));

        return $data;
    }
}
