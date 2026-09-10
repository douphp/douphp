<?php

/**
 * DouPHP®
 * ------------------------------------------------------------------------------------
 * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)
 *
 * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-08
 */

namespace Dou\Front\Service\Seo;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台 JSON-LD（Schema.org）结构化数据输出
 */
class SchemaService extends BaseService
{
    /** @var string */
    public $domain;

    /** @var string */
    public $site_logo;

    /** @var bool Schema 开关状态 */
    public $enabled;

    public function __construct()
    {
        // 后台「启用 Schema（AI 结构化数据）」开关，存于 dou_config.name='schema'，默认 '1' 启用
        $this->enabled = (bool) Config::get('site.schema', false);

        // logo
        $this->site_logo = (string) Config::get('site.theme_url', '') . 'images/' . Config::get('site.site_logo', '');

        $this->domain = (string) Config::get('site.root_url', '/');
    }

    /**
     * 执行organization操作。
     *
     * @return mixed 返回结果。
     */
    public function organization()
    {
        //  开关判断
        if (!$this->enabled) {
            return '';
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => Config::get('site.site_name', ''),
            'url' => HOME_URL,
            'logo' => array(
                '@type' => 'ImageObject',
                'url' => $this->site_logo
            )
        );

        if (!empty(Config::get('site.site_description', ''))) {
            $schema['description'] = Config::get('site.site_description', '');
        }

        $contact = array();
        if (!empty(Config::get('site.tel', ''))) {
            $contact['telephone'] = Config::get('site.tel', '');
        }
        if (!empty(Config::get('site.email', ''))) {
            $contact['email'] = Config::get('site.email', '');
        }
        if (!empty($contact)) {
            $contact['@type'] = 'ContactPoint';
            $contact['contactType'] = 'customer service';
            $schema['contactPoint'] = $contact;
        }

        $same_as = array();
        foreach (array('weibo', 'wechat', 'linkedin', 'twitter', 'facebook') as $social) {
            if (!empty(Config::get('site.' . $social, ''))) {
                $same_as[] = Config::get('site.' . $social, '');
            }
        }
        if (!empty($same_as)) {
            $schema['sameAs'] = $same_as;
        }

        return $this->output($schema);
    }

    /**
     * 执行website操作。
     *
     * @return mixed 返回结果。
     */
    public function website()
    {
        // 开关判断
        if (!$this->enabled) {
            return '';
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => Config::get('site.site_name', ''),
            'url' => HOME_URL,
            'potentialAction' => array(
                '@type' => 'SearchAction',
                'target' => HOME_URL . '?s={search_term_string}',
                'query-input' => 'required name=search_term_string'
            )
        );

        return $this->output($schema);
    }

    /**
     * 执行index操作。
     *
     * @return mixed 返回结果。
     */
    public function index()
    {
        // 开关判断
        if (!$this->enabled) {
            return '';
        }

        $html = '';

        // 1. Organization Schema（全站通用，首页必加）
        $html .= $this->organization() . "\r\n";

        // 2. Website Schema（首页专用，支持搜索框）
        $html .= $this->website() . "\r\n";

        // 3. LocalBusiness Schema（只有有实体店才加）
        // 可以通过地址判断是否有实体店
        if (!empty(Config::get('site.address', ''))) {
            $html .= $this->localBusiness();
        }

        return $html;
    }

    /**
     * 执行product操作。
     *
     * @param mixed $product 参数product。
     * @return mixed 返回结果。
     */
    public function product($product)
    {
        //  开关判断
        if (!$this->enabled || empty($product)) {
            return '';
        }

        $image_list = array();
        if (!empty($product['image'])) {
            $image_list[] = $product['image'];
        }
        if (!empty($product['gallery_list'])) {
            foreach ($product['gallery_list'] as $gallery) {
                $image_list[] = $gallery['file'];
            }
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['title'],
            'url' => route('product.show', ['id' => $product['id']])
        );

        if (!empty($product['description'])) {
            $schema['description'] = $this->trimText($product['description'], 200);
        } elseif (!empty($product['content'])) {
            $schema['description'] = $this->trimText($product['content'], 200);
        }

        if (!empty($image_list)) {
            $schema['image'] = $image_list;
        }

        $schema['sku'] = (string) $product['id'];

        if (!empty($product['brand'])) {
            $schema['brand'] = array(
                '@type' => 'Brand',
                'name' => is_array($product['brand']) ? $product['brand']['name'] : $product['brand']
            );
        }

        // 分类信息
        if (!empty($product['cate_info']['name'])) {
            $schema['category'] = $product['cate_info']['name'];
        }

        // 获取货币类型
        $currency = $this->getCurrency();

        $schema['offers'] = array(
            '@type' => 'Offer',
            'price' => $product['sale_price']['value'],
            'priceCurrency' => $currency,
            'availability' => 'https://schema.org/InStock',
            'url' => route('product.show', ['id' => $product['id']])
        );

        return $this->output($schema);
    }

    /**
     * 执行article操作。
     *
     * @param mixed $article 参数article。
     * @param array $cate_info 参数cate_info。
     * @return mixed 返回结果。
     */
    public function article($article, $cate_info = array())
    {
        //  开关判断
        if (!$this->enabled || empty($article)) {
            return '';
        }

        $cover = !empty($article['image']) ? $article['image'] : $this->site_logo;
        $created_at = is_numeric($article['created_at']) ? date('c', strtotime($article['created_at'])) : date('c', strtotime($article['created_at']));

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $article['title'],
            'image' => $cover,
            'datePublished' => $created_at,
            'dateModified' => $created_at,
            'author' => array(
                '@type' => 'Person',
                'name' => !empty($article['author']) ? $article['author'] : Config::get('site.site_name', '')
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => Config::get('site.site_name', ''),
                'logo' => array(
                    '@type' => 'ImageObject',
                    'url' => $this->site_logo
                )
            ),
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id' => route('article.show', ['id' => $article['id']])
            )
        );

        if (!empty($article['description'])) {
            $schema['description'] = $this->trimText($article['description'], 160);
        } elseif (!empty($article['content'])) {
            $schema['description'] = $this->trimText($article['content'], 160);
        }

        if (!empty($cate_info['name'])) {
            $schema['articleSection'] = $cate_info['name'];
        }

        return $this->output($schema);
    }

    /**
     * 执行 course 操作（与 article 结构化数据契约一致）。
     *
     * @param mixed $course 课程行数据
     * @param array $cate_info 分类信息
     * @return mixed
     */
    public function course($course, $cate_info = array())
    {
        if (!$this->enabled || empty($course)) {
            return '';
        }

        $cover = !empty($course['image']) ? $course['image'] : $this->site_logo;
        $created_at = is_numeric($course['created_at']) ? date('c', strtotime($course['created_at'])) : date('c', strtotime($course['created_at']));

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $course['title'],
            'image' => $cover,
            'datePublished' => $created_at,
            'dateModified' => $created_at,
            'author' => array(
                '@type' => 'Person',
                'name' => !empty($course['author']) ? $course['author'] : Config::get('site.site_name', '')
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => Config::get('site.site_name', ''),
                'logo' => array(
                    '@type' => 'ImageObject',
                    'url' => $this->site_logo
                )
            ),
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id' => route('course.show', array('id' => $course['id']))
            )
        );

        if (!empty($course['description'])) {
            $schema['description'] = $this->trimText($course['description'], 160);
        } elseif (!empty($course['content'])) {
            $schema['description'] = $this->trimText($course['content'], 160);
        }

        if (!empty($cate_info['name'])) {
            $schema['articleSection'] = $cate_info['name'];
        }

        return $this->output($schema);
    }

    /**
     * 执行video操作。
     *
     * @param mixed $video 参数video。
     * @param array $cate_info 参数cate_info。
     * @return mixed 返回结果。
     */
    public function video($video, $cate_info = array())
    {
        //  开关判断
        if (!$this->enabled || empty($video)) {
            return '';
        }

        $cover = !empty($video['image']) ? $video['image'] : $this->site_logo;
        $created_at = is_numeric($video['created_at']) ? date('c', strtotime($video['created_at'])) : date('c', strtotime($video['created_at']));

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => $video['title'],
            'description' => $this->trimText(!empty($video['description']) ? $video['description'] : $video['content'], 200),
            'thumbnailUrl' => $cover,
            'contentUrl' => !empty($video['file']) ? $video['file'] : '',
            'uploadDate' => $created_at,
            'duration' => !empty($video['duration']) ? $video['duration'] : '',
            'author' => array(
                '@type' => 'Organization',
                'name' => Config::get('site.site_name', '')
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => Config::get('site.site_name', ''),
                'logo' => array(
                    '@type' => 'ImageObject',
                    'url' => $this->site_logo
                )
            )
        );

        if (!empty($cate_info['name'])) {
            $schema['about'] = $cate_info['name'];
        }

        return $this->output($schema);
    }

    /**
     * 执行download操作。
     *
     * @param mixed $download 参数download。
     * @param array $cate_info 参数cate_info。
     * @return mixed 返回结果。
     */
    public function download($download, $cate_info = array())
    {
        //  开关判断
        if (!$this->enabled || empty($download)) {
            return '';
        }

        $cover = !empty($download['image']) ? $download['image'] : $this->site_logo;
        $created_at = is_numeric($download['created_at']) ? date('c', strtotime($download['created_at'])) : date('c', strtotime($download['created_at']));

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => $download['title'],
            'description' => $this->trimText(!empty($download['description']) ? $download['description'] : $download['content'], 200),
            'image' => $cover,
            'url' => route('download.show', ['id' => $download['id']]),
            'datePublished' => $created_at,
            'dateModified' => $created_at,
            'author' => array(
                '@type' => 'Organization',
                'name' => Config::get('site.site_name', '')
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => Config::get('site.site_name', ''),
                'logo' => array(
                    '@type' => 'ImageObject',
                    'url' => $this->site_logo
                )
            )
        );

        // 下载特有字段
        if (!empty($download['download_link'])) {
            $schema['downloadUrl'] = $download['download_link'];
        }

        if (!empty($download['size'])) {
            $schema['fileSize'] = $download['size'];
        }

        if (!empty($download['version'])) {
            $schema['softwareVersion'] = $download['version'];
        }

        if (!empty($cate_info['name'])) {
            $schema['applicationCategory'] = $cate_info['name'];
        }

        return $this->output($schema);
    }

    /**
     * 执行gallery操作。
     *
     * @param mixed $gallery 参数gallery。
     * @param array $cate_info 参数cate_info。
     * @return mixed 返回结果。
     */
    public function gallery($gallery, $cate_info = array())
    {
        //  开关判断
        if (!$this->enabled || empty($gallery)) {
            return '';
        }

        $cover = !empty($gallery['image']) ? $gallery['image'] : $this->site_logo;
        $created_at = is_numeric($gallery['created_at']) ? date('c', strtotime($gallery['created_at'])) : date('c', strtotime($gallery['created_at']));

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'ImageGallery',
            'name' => $gallery['title'],
            'description' => $this->trimText(!empty($gallery['description']) ? $gallery['description'] : $gallery['content'], 200),
            'image' => $cover,
            'url' => route('gallery.show', ['id' => $gallery['id']]),
            'datePublished' => $created_at,
            'dateModified' => $created_at,
            'author' => array(
                '@type' => 'Organization',
                'name' => Config::get('site.site_name', '')
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => Config::get('site.site_name', ''),
                'logo' => array(
                    '@type' => 'ImageObject',
                    'url' => $this->site_logo
                )
            )
        );

        // 相册图片列表
        if (!empty($gallery['image_list'])) {
            $schema['associatedMedia'] = array();
            foreach ($gallery['image_list'] as $img) {
                $schema['associatedMedia'][] = array(
                    '@type' => 'ImageObject',
                    'contentUrl' => $img['file'],
                    'caption' => !empty($img['title']) ? $img['title'] : ''
                );
            }
        }

        if (!empty($cate_info['name'])) {
            $schema['about'] = $cate_info['name'];
        }

        return $this->output($schema);
    }

    /**
     * 执行page操作。
     *
     * @param mixed $page 参数page。
     * @param array $top 参数top。
     * @return mixed 返回结果。
     */
    public function page($page, $top = array())
    {
        //  开关判断
        if (!$this->enabled || empty($page)) {
            return '';
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $page['name'],
            'url' => route('page.show', ['id' => $page['id']])
        );

        if (!empty($page['description'])) {
            $schema['description'] = $this->trimText($page['description'], 200);
        } elseif (!empty($page['content'])) {
            $schema['description'] = $this->trimText($page['content'], 200);
        }

        // 面包屑
        if (!empty($top['name'])) {
            $schema['breadcrumb'] = 'Home > ' . $top['name'] . ' > ' . $page['name'];
        }

        return $this->output($schema);
    }

    /**
     * 执行generic操作。
     *
     * @param mixed $module 参数module。
     * @param mixed $data 参数data。
     * @param array $cate_info 参数cate_info。
     * @return mixed 返回结果。
     */
    public function generic($module, $data, $cate_info = array())
    {
        //  开关判断
        if (!$this->enabled || empty($data) || empty($module)) {
            return '';
        }

        // 模块类型映射（Schema.org 类型）
        $type_map = array(
            'article' => 'Article',
            'news' => 'NewsArticle',
            'cases' => 'CreativeWork',
            'solution' => 'CreativeWork',
            'professional' => 'Person',
            'support' => 'Service',
            'equipment' => 'Product',
            'job' => 'JobPosting',
            'team' => 'Person',
            'service' => 'Service',
            'store' => 'Store'
        );
        $type = isset($type_map[$module]) ? $type_map[$module] : 'Article';

        $cover = !empty($data['image']) ? $data['image'] : $this->site_logo;
        $created_at = is_numeric($data['created_at']) ? date('c', strtotime($data['created_at'])) : date('c', strtotime($data['created_at']));

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => $type,
            'headline' => !empty($data['title']) ? $data['title'] : (!empty($data['name']) ? $data['name'] : ''),
            'description' => $this->trimText(!empty($data['description']) ? $data['description'] : (!empty($data['content']) ? $data['content'] : ''), 200),
            'image' => $cover,
            'url' => route($module . '.show', array('id' => $data['id'])),
            'datePublished' => $created_at,
            'dateModified' => $created_at,
            'author' => array(
                '@type' => 'Organization',
                'name' => Config::get('site.site_name', '')
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => Config::get('site.site_name', ''),
                'logo' => array(
                    '@type' => 'ImageObject',
                    'url' => $this->site_logo
                )
            ),
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id' => route($module . '.show', array('id' => $data['id']))
            )
        );

        // 分类信息
        if (!empty($cate_info['name'])) {
            $schema['articleSection'] = $cate_info['name'];
        }

        return $this->output($schema);
    }

    /**
     * 执行faq操作。
     *
     * @param array $faqs 参数faqs。
     * @return mixed 返回结果。
     */
    public function faq($faqs = array())
    {
        //  开关判断
        if (!$this->enabled || empty($faqs)) {
            return '';
        }

        $main_entity = array();
        foreach ($faqs as $faq) {
            if (empty($faq['question'])) {
                continue;
            }
            $main_entity[] = array(
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text' => $this->trimText($faq['answer'], 500)
                )
            );
        }

        if (empty($main_entity)) {
            return '';
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $main_entity
        );

        return $this->output($schema);
    }

    /**
     * 执行breadcrumb操作。
     *
     * @param array $ur_here 参数ur_here。
     * @return mixed 返回结果。
     */
    public function breadcrumb($ur_here = array())
    {
        //  开关判断
        if (!$this->enabled || empty($ur_here)) {
            return '';
        }

        $item_list = array();
        $position = 1;

        // 首页
        $item_list[] = array(
            '@type' => 'ListItem',
            'position' => $position,
            'name' => lang('home') !== '' ? lang('home') : 'Home',
            'item' => HOME_URL
        );
        $position++;

        $items = array();
        if (isset($ur_here['module']['name'])) {
            $items[] = $ur_here['module'];
        }
        if (isset($ur_here['class']['name'])) {
            $items[] = $ur_here['class'];
        }
        if (!empty($ur_here['title'])) {
            $items[] = array('name' => $ur_here['title']);
        }

        foreach ($items as $item) {
            $list_item = array(
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $item['name']
            );
            if (!empty($item['url'])) {
                $list_item['item'] = $item['url'];
            }
            $item_list[] = $list_item;
            $position++;
        }

        if (empty($item_list)) {
            return '';
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $item_list
        );

        return $this->output($schema);
    }

    /**
     * 执行localBusiness操作。
     *
     * @return mixed 返回结果。
     */
    public function localBusiness()
    {
        //  开关判断
        if (!$this->enabled) {
            return '';
        }

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => Config::get('site.site_name', ''),
            'url' => HOME_URL,
            'logo' => $this->site_logo
        );

        if (!empty(Config::get('site.site_description', ''))) {
            $schema['description'] = Config::get('site.site_description', '');
        }
        if (!empty(Config::get('site.tel', ''))) {
            $schema['telephone'] = Config::get('site.tel', '');
        }
        if (!empty(Config::get('site.email', ''))) {
            $schema['email'] = Config::get('site.email', '');
        }
        if (!empty(Config::get('site.address', ''))) {
            $schema['address'] = array(
                '@type' => 'PostalAddress',
                'streetAddress' => Config::get('site.address', '')
            );
        }

        // 电话和传真
        if (!empty(Config::get('site.tel', ''))) {
            $schema['telephone'] = Config::get('site.tel', '');
        }
        if (!empty(Config::get('site.fax', ''))) {
            $schema['faxNumber'] = Config::get('site.fax', '');
        }

        // 社交媒体链接
        $same_as = array();
        $social_fields = array('weibo', 'facebook', 'twitter', 'linkedin', 'instagram', 'youtube', 'vk', 'pinterest', 'tiktok', 'skype', 'whatsapp');
        foreach ($social_fields as $social) {
            if (!empty(Config::get('site.' . $social, ''))) {
                $same_as[] = Config::get('site.' . $social, '');
            }
        }
        if (!empty($same_as)) {
            $schema['sameAs'] = $same_as;
        }

        // 营业时间（可选）
        if (!empty(Config::get('site.office_hours', ''))) {
            $schema['openingHours'] = Config::get('site.office_hours', '');
        }

        return $this->output($schema);
    }

    /**
     * 执行output操作。
     *
     * @param mixed $schema 参数schema。
     * @return string 返回结果。
     */
    public function output($schema)
    {
        if (empty($schema)) {
            return '';
        }
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG;
        return "<script type=\"application/ld+json\">\n" . json_encode($schema, $options) . "\n</script>";
    }

    /**
     * 执行getCurrency操作。
     *
     * @return mixed 返回结果。
     */
    public function getCurrency()
    {
        $price_format = !empty(Config::get('site.price_format', '')) ? Config::get('site.price_format', '') : '￥d%';

        // 货币映射
        $currency_map = array(
            '￥' => 'CNY',   // 人民币
            '¥' => 'CNY',    // 人民币
            '$' => 'USD',    // 美元
            '€' => 'EUR',    // 欧元
            '£' => 'GBP',    // 英镑
            '円' => 'JPY',   // 日元
            '₩' => 'KRW',    // 韩元
            '₽' => 'RUB'     // 卢布
        );

        foreach ($currency_map as $symbol => $code) {
            if (strpos($price_format, $symbol) !== false) {
                return $code;
            }
        }

        // 默认返回人民币
        return 'CNY';
    }

    /**
     * 执行trimText操作。
     *
     * @param mixed $text 参数text。
     * @param mixed $length 参数length。
     * @return mixed 返回结果。
     */
    public function trimText($text, $length)
    {
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', trim($text));
        if (mb_strlen($text, 'utf-8') > $length) {
            $text = mb_substr($text, 0, $length, 'utf-8') . '...';
        }
        return $text;
    }
}
