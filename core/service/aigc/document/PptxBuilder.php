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
 * Release Date: 2026-11-11
 */

namespace Dou\Core\Service\Aigc\Document;

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 最小可用 PPTX 生成器（纯 OOXML + ZipArchive）。
 *
 * 输入结构：{title, subtitle, slides:[{title, bullets:[]}]}；
 * 输出临时 .pptx 绝对路径，由调用方落盘后删除。
 */
class PptxBuilder extends BaseService
{
    const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    /**
     * 生成 PPTX。
     *
     * @param array $outline
     * @return array {ok, path, error}
     */
    public function build(array $outline)
    {
        if (!class_exists('ZipArchive')) {
            return array('ok' => false, 'path' => '', 'error' => 'ZipArchive extension is required');
        }

        $title = isset($outline['title']) ? trim((string) $outline['title']) : '';
        $subtitle = isset($outline['subtitle']) ? trim((string) $outline['subtitle']) : '';
        $slides = isset($outline['slides']) && is_array($outline['slides']) ? array_values($outline['slides']) : array();
        if (!$slides) {
            $slides = array(array('title' => $title, 'bullets' => array($subtitle)));
        }
        $slides = array_slice($slides, 0, 30);

        $dir = STORAGE_PATH . 'tmp/aigc/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $token = date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8);
        $zipPath = $dir . 'ppt_' . $token . '.pptx';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return array('ok' => false, 'path' => '', 'error' => 'cannot create pptx');
        }

        $slideCount = count($slides);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes($slideCount));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('docProps/core.xml', $this->coreProps($title));
        $zip->addFromString('docProps/app.xml', $this->appProps($slideCount));
        $zip->addFromString('ppt/presentation.xml', $this->presentationXml($slideCount));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->presentationRels($slideCount));
        $zip->addFromString('ppt/presProps.xml', $this->presProps());
        $zip->addFromString('ppt/viewProps.xml', $this->viewProps());
        $zip->addFromString('ppt/tableStyles.xml', $this->tableStyles());
        $zip->addFromString('ppt/theme/theme1.xml', $this->themeXml());
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', $this->slideMaster());
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $this->slideMasterRels());
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', $this->slideLayout());
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $this->slideLayoutRels());

        foreach ($slides as $index => $slide) {
            $no = $index + 1;
            $zip->addFromString('ppt/slides/slide' . $no . '.xml', $this->slideXml($slide, $no === 1 ? $subtitle : ''));
            $zip->addFromString('ppt/slides/_rels/slide' . $no . '.xml.rels', $this->slideRels());
        }

        $zip->close();

        return array('ok' => is_file($zipPath), 'path' => $zipPath, 'error' => is_file($zipPath) ? '' : 'pptx write failed');
    }

    /**
     * @param int $slideCount
     * @return string
     */
    private function contentTypes($slideCount)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . '<Override PartName="/ppt/presProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presProps+xml"/>'
            . '<Override PartName="/ppt/viewProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.viewProps+xml"/>'
            . '<Override PartName="/ppt/tableStyles.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.tableStyles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';

        for ($i = 1; $i <= $slideCount; $i++) {
            $xml .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }

        return $xml . '</Types>';
    }

    private function rootRels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function coreProps($title)
    {
        $now = date('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $this->escape($title) . '</dc:title><dc:creator>DouPHP</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function appProps($slideCount)
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>DouPHP</Application><Slides>' . (int) $slideCount . '</Slides>'
            . '</Properties>';
    }

    private function presentationXml($slideCount)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:presentation xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '">'
            . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            . '<p:sldIdLst>';
        for ($i = 1; $i <= $slideCount; $i++) {
            $xml .= '<p:sldId id="' . (255 + $i) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        $xml .= '</p:sldIdLst><p:sldSz cx="12192000" cy="6858000" type="screen16x9"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>';

        return $xml;
    }

    private function presentationRels($slideCount)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>';
        for ($i = 1; $i <= $slideCount; $i++) {
            $xml .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $i . '.xml"/>';
        }
        $xml .= '<Relationship Id="rId90" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/presProps" Target="presProps.xml"/>'
            . '<Relationship Id="rId91" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/viewProps" Target="viewProps.xml"/>'
            . '<Relationship Id="rId92" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/tableStyles" Target="tableStyles.xml"/>'
            . '</Relationships>';

        return $xml;
    }

    private function presProps()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentationPr xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '"/>';
    }

    private function viewProps()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:viewPr xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '"><p:normalViewPr><p:restoredLeft sz="15620"/><p:restoredTop sz="94660"/></p:normalViewPr><p:slideViewPr><p:cSldViewPr><p:cViewPr varScale="1"><p:scale><a:sx n="100" d="100"/><a:sy n="100" d="100"/></p:scale><p:origin x="0" y="0"/></p:cViewPr><p:guideLst/></p:cSldViewPr></p:slideViewPr><p:notesTextViewPr><p:cViewPr><p:scale><a:sx n="100" d="100"/><a:sy n="100" d="100"/></p:scale><p:origin x="0" y="0"/></p:cViewPr></p:notesTextViewPr><p:gridSpacing cx="72008" cy="72008"/></p:viewPr>';
    }

    private function tableStyles()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:tblStyleLst xmlns:a="' . self::NS_A . '" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>';
    }

    private function themeXml()
    {
        $colors = '<a:dk1><a:srgbClr val="000000"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>'
            . '<a:dk2><a:srgbClr val="44546A"/></a:dk2><a:lt2><a:srgbClr val="E7E6E6"/></a:lt2>'
            . '<a:accent1><a:srgbClr val="4472C4"/></a:accent1><a:accent2><a:srgbClr val="ED7D31"/></a:accent2>'
            . '<a:accent3><a:srgbClr val="A5A5A5"/></a:accent3><a:accent4><a:srgbClr val="FFC000"/></a:accent4>'
            . '<a:accent5><a:srgbClr val="5B9BD5"/></a:accent5><a:accent6><a:srgbClr val="70AD47"/></a:accent6>'
            . '<a:hlink><a:srgbClr val="0563C1"/></a:hlink><a:folHlink><a:srgbClr val="954F72"/></a:folHlink>';

        $font = '<a:latin typeface="Arial"/><a:ea typeface="Microsoft YaHei"/><a:cs typeface=""/>';
        $fill = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<a:theme xmlns:a="' . self::NS_A . '" name="DouPHP">'
            . '<a:themeElements>'
            . '<a:clrScheme name="DouPHP">' . $colors . '</a:clrScheme>'
            . '<a:fontScheme name="DouPHP"><a:majorFont>' . $font . '</a:majorFont><a:minorFont>' . $font . '</a:minorFont></a:fontScheme>'
            . '<a:fmtScheme name="DouPHP">'
            . '<a:fillStyleLst>' . $fill . $fill . $fill . '</a:fillStyleLst>'
            . '<a:lnStyleLst><a:ln w="6350" cap="flat" cmpd="sng" algn="ctr">' . $fill . '</a:ln><a:ln w="12700" cap="flat" cmpd="sng" algn="ctr">' . $fill . '</a:ln><a:ln w="19050" cap="flat" cmpd="sng" algn="ctr">' . $fill . '</a:ln></a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst>' . $fill . $fill . $fill . '</a:bgFillStyleLst>'
            . '</a:fmtScheme>'
            . '</a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/>'
            . '</a:theme>';
    }

    private function slideMaster()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldMaster xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '">'
            . '<p:cSld><p:spTree>' . $this->emptySpTree() . '</p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            . '</p:sldMaster>';
    }

    private function slideMasterRels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
            . '</Relationships>';
    }

    private function slideLayout()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sldLayout xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '" type="blank" preserve="1">'
            . '<p:cSld name="Blank"><p:spTree>' . $this->emptySpTree() . '</p:spTree></p:cSld>'
            . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>'
            . '</p:sldLayout>';
    }

    private function slideLayoutRels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
            . '</Relationships>';
    }

    private function slideRels()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            . '</Relationships>';
    }

    private function slideXml(array $slide, $subtitle)
    {
        $title = isset($slide['title']) ? (string) $slide['title'] : '';
        $bullets = isset($slide['bullets']) && is_array($slide['bullets']) ? $slide['bullets'] : array();
        if ($subtitle !== '') {
            array_unshift($bullets, $subtitle);
        }
        $bullets = array_slice($bullets, 0, 8);

        $body = '';
        foreach ($bullets as $bullet) {
            $bullet = trim((string) $bullet);
            if ($bullet === '') {
                continue;
            }
            $body .= '<a:p><a:pPr marL="342900" indent="-342900"><a:buFont typeface="Arial"/><a:buChar char="&#8226;"/></a:pPr>'
                . '<a:r><a:rPr lang="zh-CN" sz="1800"/><a:t>' . $this->escape($bullet) . '</a:t></a:r></a:p>';
        }
        if ($body === '') {
            $body = '<a:p><a:r><a:rPr lang="zh-CN" sz="1800"/><a:t></a:t></a:r></a:p>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:sld xmlns:a="' . self::NS_A . '" xmlns:r="' . self::NS_R . '" xmlns:p="' . self::NS_P . '">'
            . '<p:cSld><p:spTree>' . $this->emptySpTree()
            . $this->textBox(2, 'Title', 609600, 457200, 10972800, 1371600, $title, 3200, true)
            . $this->textBox(3, 'Content', 609600, 1828800, 10972800, 4114800, '', 1800, false, $body)
            . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
    }

    private function textBox($id, $name, $x, $y, $cx, $cy, $text, $size, $bold, $paragraphs = null)
    {
        $txBody = '<p:txBody><a:bodyPr wrap="square"><a:normAutofit/></a:bodyPr><a:lstStyle/>';
        if ($paragraphs !== null) {
            $txBody .= $paragraphs;
        } else {
            $txBody .= '<a:p><a:r><a:rPr lang="zh-CN" sz="' . (int) $size . '"' . ($bold ? ' b="1"' : '') . '/><a:t>' . $this->escape($text) . '</a:t></a:r></a:p>';
        }
        $txBody .= '</p:txBody>';

        return '<p:sp><p:nvSpPr><p:cNvPr id="' . (int) $id . '" name="' . $this->escape($name) . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr><a:xfrm><a:off x="' . (int) $x . '" y="' . (int) $y . '"/><a:ext cx="' . (int) $cx . '" cy="' . (int) $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
            . $txBody . '</p:sp>';
    }

    private function emptySpTree()
    {
        return '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
            . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>';
    }

    private function escape($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
