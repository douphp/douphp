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

namespace Dou\Core\Web\Http;

use Dou\Core\Web\Template\TemplateRendererInterface;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 视图响应：携带模板渲染器 + 模板 + 局部数据，由内核 send() 时再 fetch 渲染。
 *
 * 与渲染器已有全局 assign（BaseController、ThemeExtensionLoader、header.tpl 等先期注入）
 * 叠加：本类只在 send() 阶段把 $data 写回渲染器，再 fetch 出 HTML，使「控制器只声明本页
 * 专属变量」的写法成立。
 *
 * 渲染器面向 {@see TemplateRendererInterface} 编程，默认绑定为 {@see \Dou\Core\Web\Template\DouView}。
 */
class ViewResponse extends Response
{
    /** @var TemplateRendererInterface */
    private $renderer;

    /** @var string 模板资源名（如 download_category.dwt） */
    private $template;

    /** @var array 本视图专属变量，键名同渲染器 assign() 第一个参数 */
    private $data;

    /**
     * @param TemplateRendererInterface $renderer 已配置好的模板渲染器实例（由容器解析 / `View::` 门面取得）
     * @param string $template 模板资源名
     * @param array $data 本视图专属变量（叠加在已 assign 的全局之上）
     * @param int $statusCode HTTP 状态码（默认 200）
     */
    public function __construct(TemplateRendererInterface $renderer, $template, array $data = array(), $statusCode = 200)
    {
        $this->renderer = $renderer;
        $this->template = (string) $template;
        $this->data = $data;
        parent::__construct('', (int) $statusCode);
        $charset = defined('DOU_CHARSET') ? DOU_CHARSET : 'utf-8';
        $this->setHeader('Content-Type', 'text/html; charset=' . $charset);
    }

    /**
     * 把本视图专属变量写入渲染器并 fetch 出 HTML 字符串。
     *
     * @return string
     */
    public function render()
    {
        foreach ($this->data as $key => $value) {
            $this->renderer->assign($key, $value);
        }

        return (string) $this->renderer->fetch($this->template);
    }

    /**
     * @return string
     */
    public function getTemplate()
    {
        return $this->template;
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * 追加 / 覆盖单个模板变量。
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function with($key, $value)
    {
        $this->data[(string) $key] = $value;

        return $this;
    }

    /**
     * 批量追加 / 覆盖模板变量。
     *
     * @param array $data
     * @return $this
     */
    public function withData(array $data)
    {
        foreach ($data as $key => $value) {
            $this->data[(string) $key] = $value;
        }

        return $this;
    }

    /**
     * 渲染并发送响应（与 Response::send 形态对齐）。
     *
     * @return void
     */
    public function send()
    {
        $this->setContent($this->render());
        parent::send();
    }
}
