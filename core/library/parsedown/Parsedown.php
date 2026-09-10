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

 namespace Dou\Vendor\Parsedown;

#
#
# Parsedown
# http://parsedown.org
#
# (c) Emanuil Rusev
# http://erusev.com
#
# For the full license information, view the LICENSE file that was distributed
# with this source code.
#
#

class Parsedown {
    # ~

    const version = '1.8.0';

    /** @var bool */
    protected $breaksEnabled;

    /** @var bool */
    protected $markupEscaped;

    /** @var bool */
    protected $urlsLinked = true;

    /** @var bool */
    protected $safeMode;

    /** @var bool */
    protected $strictMode;

    /** @var array */
    protected $safeLinksWhitelist = array(
        'http://',
        'https://',
        'ftp://',
        'ftps://',
        'mailto:',
        'tel:',
        'data:image/png;base64,',
        'data:image/gif;base64,',
        'data:image/jpeg;base64,',
        'irc:',
        'ircs:',
        'git:',
        'ssh:',
        'news:',
        'steam:',
    );

    /** @var array */
    protected $BlockTypes = array(
        '#' => array('Header'),
        '*' => array('Rule', 'List'),
        '+' => array('List'),
        '-' => array('SetextHeader', 'Table', 'Rule', 'List'),
        '0' => array('List'),
        '1' => array('List'),
        '2' => array('List'),
        '3' => array('List'),
        '4' => array('List'),
        '5' => array('List'),
        '6' => array('List'),
        '7' => array('List'),
        '8' => array('List'),
        '9' => array('List'),
        ':' => array('Table'),
        '<' => array('Comment', 'Markup'),
        '=' => array('SetextHeader'),
        '>' => array('Quote'),
        '[' => array('Reference'),
        '_' => array('Rule'),
        '`' => array('FencedCode'),
        '|' => array('Table'),
        '~' => array('FencedCode'),
    );

    /** @var array */
    protected $unmarkedBlockTypes = array(
        'Code',
    );

    /** @var array */
    protected $InlineTypes = array(
        '!' => array('Image'),
        '&' => array('SpecialCharacter'),
        '*' => array('Emphasis'),
        ':' => array('Url'),
        '<' => array('UrlTag', 'EmailTag', 'Markup'),
        '[' => array('Link'),
        '_' => array('Emphasis'),
        '`' => array('Code'),
        '~' => array('Strikethrough'),
        '\\' => array('EscapeSequence'),
    );

    /** @var string */
    protected $inlineMarkerList = '!*_&[:<`~\\';

    /** @var array */
    private static $instances = array();

    /** @var array */
    protected $DefinitionData;

    /** @var array */
    protected $specialCharacters = array(
        '\\',
        '`',
        '*',
        '_',
        '{',
        '}',
        '[',
        ']',
        '(',
        ')',
        '>',
        '#',
        '+',
        '-',
        '.',
        '!',
        '|',
        '~'
    );

    /** @var array */
    protected $StrongRegex = array(
        '*' => '/^[*]{2}((?:\\\\\*|[^*]|[*][^*]*+[*])+?)[*]{2}(?![*])/s',
        '_' => '/^__((?:\\\\_|[^_]|_[^_]*+_)+?)__(?!_)/us',
    );

    /** @var array */
    protected $EmRegex = array(
        '*' => '/^[*]((?:\\\\\*|[^*]|[*][*][^*]+?[*][*])+?)[*](?![*])/s',
        '_' => '/^_((?:\\\\_|[^_]|__[^_]*__)+?)_(?!_)\b/us',
    );

    /** @var string */
    protected $regexHtmlAttribute = '[a-zA-Z_:][\w:.-]*+(?:\s*+=\s*+(?:[^"\'=<>`\s]+|"[^"]*+"|\'[^\']*+\'))?+';

    /** @var array */
    protected $voidElements = array(
        'area',
        'base',
        'br',
        'col',
        'command',
        'embed',
        'hr',
        'img',
        'input',
        'link',
        'meta',
        'param',
        'source',
    );

    /** @var array */
    protected $textLevelElements = array(
        'a',
        'br',
        'bdo',
        'abbr',
        'blink',
        'nextid',
        'acronym',
        'basefont',
        'b',
        'em',
        'big',
        'cite',
        'small',
        'spacer',
        'listing',
        'i',
        'rp',
        'del',
        'code',
        'strike',
        'marquee',
        'q',
        'rt',
        'ins',
        'font',
        'strong',
        's',
        'tt',
        'kbd',
        'mark',
        'u',
        'xm',
        'sub',
        'nobr',
        'sup',
        'ruby',
        'var',
        'span',
        'wbr',
        'time',
    );

    # ~

    /**
     * 执行text操作。
     *
     * @param mixed $text 参数$text。
     * @return mixed 返回结果。
     */
    function text($text) {
        $Elements = $this->textElements($text);

        # convert to markup
        $markup = $this->elements($Elements);

        # trim line breaks
        $markup = trim($markup, "\n");

        return $markup;
    }

    /**
     * 执行textElements操作。
     *
     * @param mixed $text 参数$text。
     * @return mixed 返回结果。
     */
    protected function textElements($text) {
        # make sure no definitions are set
        $this->DefinitionData = array();

        # standardize line breaks
        $text = str_replace(array("\r\n", "\r"), "\n", $text ? $text : '');

        # remove surrounding line breaks
        $text = trim($text, "\n");

        # split text into lines
        $lines = explode("\n", $text);

        # iterate through lines to identify blocks
        return $this->linesElements($lines);
    }

    #
    # Setters
    #

    /**
     * 执行setBreaksEnabled操作。
     *
     * @param mixed $breaksEnabled 参数$breaksEnabled。
     * @return mixed 返回结果。
     */
    function setBreaksEnabled($breaksEnabled) {
        $this->breaksEnabled = $breaksEnabled;

        return $this;
    }

    /**
     * 执行setMarkupEscaped操作。
     *
     * @param mixed $markupEscaped 参数$markupEscaped。
     * @return mixed 返回结果。
     */
    function setMarkupEscaped($markupEscaped) {
        $this->markupEscaped = $markupEscaped;

        return $this;
    }

    /**
     * 执行setUrlsLinked操作。
     *
     * @param mixed $urlsLinked 参数$urlsLinked。
     * @return mixed 返回结果。
     */
    function setUrlsLinked($urlsLinked) {
        $this->urlsLinked = $urlsLinked;

        return $this;
    }

    /**
     * 执行setSafeMode操作。
     *
     * @param mixed $safeMode 参数$safeMode。
     * @return mixed 返回结果。
     */
    function setSafeMode($safeMode) {
        $this->safeMode = (bool) $safeMode;

        return $this;
    }

    /**
     * 执行setStrictMode操作。
     *
     * @param mixed $strictMode 参数$strictMode。
     * @return mixed 返回结果。
     */
    function setStrictMode($strictMode) {
        $this->strictMode = (bool) $strictMode;

        return $this;
    }

    #
    # Lines
    #

    #
    # Blocks
    #

    /**
     * 执行lines操作。
     *
     * @param array $lines 参数$lines。
     * @return mixed 返回结果。
     */
    protected function lines(array $lines) {
        return $this->elements($this->linesElements($lines));
    }

    /**
     * 执行linesElements操作。
     *
     * @param array $lines 参数$lines。
     * @return mixed 返回结果。
     */
    protected function linesElements(array $lines) {
        $Elements = array();
        $CurrentBlock = null;

        foreach ($lines as $line) {
            if (chop($line) === '') {
                if (isset($CurrentBlock)) {
                    $CurrentBlock['interrupted'] = (isset($CurrentBlock['interrupted'])
                        ? $CurrentBlock['interrupted'] + 1 : 1
                    );
                }

                continue;
            }

            while (($beforeTab = strstr($line, "\t", true)) !== false) {
                $shortage = 4 - mb_strlen($beforeTab, 'utf-8') % 4;

                $line = $beforeTab
                    . str_repeat(' ', $shortage)
                    . substr($line, strlen($beforeTab) + 1);
            }

            $indent = strspn($line, ' ');

            $text = $indent > 0 ? substr($line, $indent) : $line;

            # ~

            $Line = array('body' => $line, 'indent' => $indent, 'text' => $text);

            # ~

            if (isset($CurrentBlock['continuable'])) {
                $methodName = 'block' . $CurrentBlock['type'] . 'Continue';
                $Block = $this->$methodName($Line, $CurrentBlock);

                if (isset($Block)) {
                    $CurrentBlock = $Block;

                    continue;
                } else {
                    if ($this->isBlockCompletable($CurrentBlock['type'])) {
                        $methodName = 'block' . $CurrentBlock['type'] . 'Complete';
                        $CurrentBlock = $this->$methodName($CurrentBlock);
                    }
                }
            }

            # ~

            $marker = $text[0];

            # ~

            $blockTypes = $this->unmarkedBlockTypes;

            if (isset($this->BlockTypes[$marker])) {
                foreach ($this->BlockTypes[$marker] as $blockType) {
                    $blockTypes[] = $blockType;
                }
            }

            #
            # ~

            foreach ($blockTypes as $blockType) {
                $Block = $this->{"block$blockType"}($Line, $CurrentBlock);

                if (isset($Block)) {
                    $Block['type'] = $blockType;

                    if (! isset($Block['identified'])) {
                        if (isset($CurrentBlock)) {
                            $Elements[] = $this->extractElement($CurrentBlock);
                        }

                        $Block['identified'] = true;
                    }

                    if ($this->isBlockContinuable($blockType)) {
                        $Block['continuable'] = true;
                    }

                    $CurrentBlock = $Block;

                    continue 2;
                }
            }

            # ~

            if (isset($CurrentBlock) and $CurrentBlock['type'] === 'Paragraph') {
                $Block = $this->paragraphContinue($Line, $CurrentBlock);
            }

            if (isset($Block)) {
                $CurrentBlock = $Block;
            } else {
                if (isset($CurrentBlock)) {
                    $Elements[] = $this->extractElement($CurrentBlock);
                }

                $CurrentBlock = $this->paragraph($Line);

                $CurrentBlock['identified'] = true;
            }
        }

        # ~

        if (isset($CurrentBlock['continuable']) and $this->isBlockCompletable($CurrentBlock['type'])) {
            $methodName = 'block' . $CurrentBlock['type'] . 'Complete';
            $CurrentBlock = $this->$methodName($CurrentBlock);
        }

        # ~

        if (isset($CurrentBlock)) {
            $Elements[] = $this->extractElement($CurrentBlock);
        }

        # ~

        return $Elements;
    }

    /**
     * 执行extractElement操作。
     *
     * @param array $Component 参数$Component。
     * @return mixed 返回结果。
     */
    protected function extractElement(array $Component) {
        if (! isset($Component['element'])) {
            if (isset($Component['markup'])) {
                $Component['element'] = array('rawHtml' => $Component['markup']);
            } elseif (isset($Component['hidden'])) {
                $Component['element'] = array();
            }
        }

        return $Component['element'];
    }

    /**
     * 执行isBlockContinuable操作。
     *
     * @param mixed $Type 参数$Type。
     * @return mixed 返回结果。
     */
    protected function isBlockContinuable($Type) {
        return method_exists($this, 'block' . $Type . 'Continue');
    }

    /**
     * 执行isBlockCompletable操作。
     *
     * @param mixed $Type 参数$Type。
     * @return mixed 返回结果。
     */
    protected function isBlockCompletable($Type) {
        return method_exists($this, 'block' . $Type . 'Complete');
    }

    #
    # Code

    /**
     * 执行blockCode操作。
     *
     * @param mixed $Line 参数$Line。
     * @param mixed $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockCode($Line, $Block = null) {
        if (isset($Block) and $Block['type'] === 'Paragraph' and ! isset($Block['interrupted'])) {
            return;
        }

        if ($Line['indent'] >= 4) {
            $text = substr($Line['body'], 4);

            $Block = array(
                'element' => array(
                    'name' => 'pre',
                    'element' => array(
                        'name' => 'code',
                        'text' => $text,
                    ),
                ),
            );

            return $Block;
        }
    }

    /**
     * 执行blockCodeContinue操作。
     *
     * @param mixed $Line 参数$Line。
     * @param mixed $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockCodeContinue($Line, $Block) {
        if ($Line['indent'] >= 4) {
            if (isset($Block['interrupted'])) {
                $Block['element']['element']['text'] .= str_repeat("\n", $Block['interrupted']);

                unset($Block['interrupted']);
            }

            $Block['element']['element']['text'] .= "\n";

            $text = substr($Line['body'], 4);

            $Block['element']['element']['text'] .= $text;

            return $Block;
        }
    }

    /**
     * 执行blockCodeComplete操作。
     *
     * @param mixed $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockCodeComplete($Block) {
        return $Block;
    }

    #
    # Comment

    /**
     * 执行blockComment操作。
     *
     * @param mixed $Line 参数$Line。
     * @return mixed 返回结果。
     */
    protected function blockComment($Line) {
        if ($this->markupEscaped or $this->safeMode) {
            return;
        }

        if (strpos($Line['text'], '<!--') === 0) {
            $Block = array(
                'element' => array(
                    'rawHtml' => $Line['body'],
                    'autobreak' => true,
                ),
            );

            if (strpos($Line['text'], '-->') !== false) {
                $Block['closed'] = true;
            }

            return $Block;
        }
    }

    /**
     * 执行blockCommentContinue操作。
     *
     * @param mixed $Line 参数$Line。
     * @param array $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockCommentContinue($Line, array $Block) {
        if (isset($Block['closed'])) {
            return;
        }

        $Block['element']['rawHtml'] .= "\n" . $Line['body'];

        if (strpos($Line['text'], '-->') !== false) {
            $Block['closed'] = true;
        }

        return $Block;
    }

    #
    # Fenced Code

    /**
     * 执行blockFencedCode操作。
     *
     * @param mixed $Line 参数$Line。
     * @return mixed 返回结果。
     */
    protected function blockFencedCode($Line) {
        $marker = $Line['text'][0];

        $openerLength = strspn($Line['text'], $marker);

        if ($openerLength < 3) {
            return;
        }

        $infostring = trim(substr($Line['text'], $openerLength), "\t ");

        if (strpos($infostring, '`') !== false) {
            return;
        }

        $Element = array(
            'name' => 'code',
            'text' => '',
        );

        if ($infostring !== '') {
            /**
             * https://www.w3.org/TR/2011/WD-html5-20110525/elements.html#classes
             * Every HTML element may have a class attribute specified.
             * The attribute, if specified, must have a value that is a set
             * of space-separated tokens representing the various classes
             * that the element belongs to.
             * [...]
             * The space characters, for the purposes of this specification,
             * are U+0020 SPACE, U+0009 CHARACTER TABULATION (tab),
             * U+000A LINE FEED (LF), U+000C FORM FEED (FF), and
             * U+000D CARRIAGE RETURN (CR).
             */
            $language = substr($infostring, 0, strcspn($infostring, " \t\n\f\r"));

            $Element['attributes'] = array('class' => "language-$language");
        }

        $Block = array(
            'char' => $marker,
            'openerLength' => $openerLength,
            'element' => array(
                'name' => 'pre',
                'element' => $Element,
            ),
        );

        return $Block;
    }

    /**
     * 执行blockFencedCodeContinue操作。
     *
     * @param mixed $Line 参数$Line。
     * @param mixed $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockFencedCodeContinue($Line, $Block) {
        if (isset($Block['complete'])) {
            return;
        }

        if (isset($Block['interrupted'])) {
            $Block['element']['element']['text'] .= str_repeat("\n", $Block['interrupted']);

            unset($Block['interrupted']);
        }

        if (($len = strspn($Line['text'], $Block['char'])) >= $Block['openerLength']
            and chop(substr($Line['text'], $len), ' ') === ''
        ) {
            $Block['element']['element']['text'] = substr($Block['element']['element']['text'], 1);

            $Block['complete'] = true;

            return $Block;
        }

        $Block['element']['element']['text'] .= "\n" . $Line['body'];

        return $Block;
    }

    /**
     * 执行blockFencedCodeComplete操作。
     *
     * @param mixed $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockFencedCodeComplete($Block) {
        return $Block;
    }

    #
    # Header

    /**
     * 执行blockHeader操作。
     *
     * @param mixed $Line 参数$Line。
     * @return mixed 返回结果。
     */
    protected function blockHeader($Line) {
        $level = strspn($Line['text'], '#');

        if ($level > 6) {
            return;
        }

        $text = trim($Line['text'], '#');

        if ($this->strictMode and isset($text[0]) and $text[0] !== ' ') {
            return;
        }

        $text = trim($text, ' ');

        $Block = array(
            'element' => array(
                'name' => 'h' . $level,
                'handler' => array(
                    'function' => 'lineElements',
                    'argument' => $text,
                    'destination' => 'elements',
                )
            ),
        );

        return $Block;
    }

    #
    # List

    /**
     * 执行blockList操作。
     *
     * @param mixed $Line 参数$Line。
     * @param array $CurrentBlock 参数$CurrentBlock。
     * @return mixed 返回结果。
     */
    protected function blockList($Line, $CurrentBlock = null) {
        list($name, $pattern) = $Line['text'][0] <= '-' ? array('ul', '[*+-]') : array('ol', '[0-9]{1,9}+[.\)]');

        if (preg_match('/^(' . $pattern . '([ ]++|$))(.*+)/', $Line['text'], $matches)) {
            $contentIndent = strlen($matches[2]);

            if ($contentIndent >= 5) {
                $contentIndent -= 1;
                $matches[1] = substr($matches[1], 0, -$contentIndent);
                $matches[3] = str_repeat(' ', $contentIndent) . $matches[3];
            } elseif ($contentIndent === 0) {
                $matches[1] .= ' ';
            }

            $markerWithoutWhitespace = strstr($matches[1], ' ', true);

            $Block = array(
                'indent' => $Line['indent'],
                'pattern' => $pattern,
                'data' => array(
                    'type' => $name,
                    'marker' => $matches[1],
                    'markerType' => ($name === 'ul' ? $markerWithoutWhitespace : substr($markerWithoutWhitespace, -1)),
                ),
                'element' => array(
                    'name' => $name,
                    'elements' => array(),
                ),
            );
            $Block['data']['markerTypeRegex'] = preg_quote($Block['data']['markerType'], '/');

            if ($name === 'ol') {
                $listStart = ltrim(strstr($matches[1], $Block['data']['markerType'], true), '0') ?: '0';

                if ($listStart !== '1') {
                    if (
                        isset($CurrentBlock)
                        and $CurrentBlock['type'] === 'Paragraph'
                        and ! isset($CurrentBlock['interrupted'])
                    ) {
                        return;
                    }

                    $Block['element']['attributes'] = array('start' => $listStart);
                }
            }

            $Block['li'] = array(
                'name' => 'li',
                'handler' => array(
                    'function' => 'li',
                    'argument' => !empty($matches[3]) ? array($matches[3]) : array(),
                    'destination' => 'elements'
                )
            );

            $Block['element']['elements'][] = &$Block['li'];

            return $Block;
        }
    }

    /**
     * 执行blockListContinue操作。
     *
     * @param mixed $Line 参数$Line。
     * @param array $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockListContinue($Line, array $Block) {
        if (isset($Block['interrupted']) and empty($Block['li']['handler']['argument'])) {
            return null;
        }

        $requiredIndent = ($Block['indent'] + strlen($Block['data']['marker']));

        if (
            $Line['indent'] < $requiredIndent
            and (
                (
                    $Block['data']['type'] === 'ol'
                    and preg_match('/^[0-9]++' . $Block['data']['markerTypeRegex'] . '(?:[ ]++(.*)|$)/', $Line['text'], $matches)
                ) or (
                    $Block['data']['type'] === 'ul'
                    and preg_match('/^' . $Block['data']['markerTypeRegex'] . '(?:[ ]++(.*)|$)/', $Line['text'], $matches)
                )
            )
        ) {
            if (isset($Block['interrupted'])) {
                $Block['li']['handler']['argument'][] = '';

                $Block['loose'] = true;

                unset($Block['interrupted']);
            }

            unset($Block['li']);

            $text = isset($matches[1]) ? $matches[1] : '';

            $Block['indent'] = $Line['indent'];

            $Block['li'] = array(
                'name' => 'li',
                'handler' => array(
                    'function' => 'li',
                    'argument' => array($text),
                    'destination' => 'elements'
                )
            );

            $Block['element']['elements'][] = &$Block['li'];

            return $Block;
        } elseif ($Line['indent'] < $requiredIndent and $this->blockList($Line)) {
            return null;
        }

        if ($Line['text'][0] === '[' and $this->blockReference($Line)) {
            return $Block;
        }

        if ($Line['indent'] >= $requiredIndent) {
            if (isset($Block['interrupted'])) {
                $Block['li']['handler']['argument'][] = '';

                $Block['loose'] = true;

                unset($Block['interrupted']);
            }

            $text = substr($Line['body'], $requiredIndent);

            $Block['li']['handler']['argument'][] = $text;

            return $Block;
        }

        if (! isset($Block['interrupted'])) {
            $text = preg_replace('/^[ ]{0,' . $requiredIndent . '}+/', '', $Line['body']);

            $Block['li']['handler']['argument'][] = $text;

            return $Block;
        }
    }

    /**
     * 执行blockListComplete操作。
     *
     * @param array $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockListComplete(array $Block) {
        if (isset($Block['loose'])) {
            foreach ($Block['element']['elements'] as &$li) {
                if (end($li['handler']['argument']) !== '') {
                    $li['handler']['argument'][] = '';
                }
            }
        }

        return $Block;
    }

    #
    # Quote

    /**
     * 执行blockQuote操作。
     *
     * @param mixed $Line 参数$Line。
     * @return mixed 返回结果。
     */
    protected function blockQuote($Line) {
        if (preg_match('/^>[ ]?+(.*+)/', $Line['text'], $matches)) {
            $Block = array(
                'element' => array(
                    'name' => 'blockquote',
                    'handler' => array(
                        'function' => 'linesElements',
                        'argument' => (array) $matches[1],
                        'destination' => 'elements',
                    )
                ),
            );

            return $Block;
        }
    }

    /**
     * 执行blockQuoteContinue操作。
     *
     * @param mixed $Line 参数$Line。
     * @param array $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockQuoteContinue($Line, array $Block) {
        if (isset($Block['interrupted'])) {
            return;
        }

        if ($Line['text'][0] === '>' and preg_match('/^>[ ]?+(.*+)/', $Line['text'], $matches)) {
            $Block['element']['handler']['argument'][] = $matches[1];

            return $Block;
        }

        if (! isset($Block['interrupted'])) {
            $Block['element']['handler']['argument'][] = $Line['text'];

            return $Block;
        }
    }

    #
    # Rule

    /**
     * 执行blockRule操作。
     *
     * @param mixed $Line 参数$Line。
     * @return mixed 返回结果。
     */
    protected function blockRule($Line) {
        $marker = $Line['text'][0];

        if (substr_count($Line['text'], $marker) >= 3 and chop($Line['text'], " $marker") === '') {
            $Block = array(
                'element' => array(
                    'name' => 'hr',
                ),
            );

            return $Block;
        }
    }

    #
    # Setext

    /**
     * 执行blockSetextHeader操作。
     *
     * @param mixed $Line 参数$Line。
     * @param array $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockSetextHeader($Line, $Block = null) {
        if (! isset($Block) or $Block['type'] !== 'Paragraph' or isset($Block['interrupted'])) {
            return;
        }

        if ($Line['indent'] < 4 and chop(chop($Line['text'], ' '), $Line['text'][0]) === '') {
            $Block['element']['name'] = $Line['text'][0] === '=' ? 'h1' : 'h2';

            return $Block;
        }
    }

    #
    # Markup

    /**
     * 执行blockMarkup操作。
     *
     * @param mixed $Line 参数$Line。
     * @return mixed 返回结果。
     */
    protected function blockMarkup($Line) {
        if ($this->markupEscaped or $this->safeMode) {
            return;
        }

        if (preg_match('/^<[\/]?+(\w*)(?:[ ]*+' . $this->regexHtmlAttribute . ')*+[ ]*+(\/)?>/', $Line['text'], $matches)) {
            $element = strtolower($matches[1]);

            if (in_array($element, $this->textLevelElements)) {
                return;
            }

            $Block = array(
                'name' => $matches[1],
                'element' => array(
                    'rawHtml' => $Line['text'],
                    'autobreak' => true,
                ),
            );

            return $Block;
        }
    }

    /**
     * 执行blockMarkupContinue操作。
     *
     * @param mixed $Line 参数$Line。
     * @param array $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockMarkupContinue($Line, array $Block) {
        if (isset($Block['closed']) or isset($Block['interrupted'])) {
            return;
        }

        $Block['element']['rawHtml'] .= "\n" . $Line['body'];

        return $Block;
    }

    #
    # Reference

    /**
     * 执行blockReference操作。
     *
     * @param mixed $Line 参数$Line。
     * @return mixed 返回结果。
     */
    protected function blockReference($Line) {
        if (
            strpos($Line['text'], ']') !== false
            and preg_match('/^\[(.+?)\]:[ ]*+<?(\S+?)>?(?:[ ]+["\'(](.+)["\')])?[ ]*+$/', $Line['text'], $matches)
        ) {
            $id = strtolower($matches[1]);

            $Data = array(
                'url' => $matches[2],
                'title' => isset($matches[3]) ? $matches[3] : null,
            );

            $this->DefinitionData['Reference'][$id] = $Data;

            $Block = array(
                'element' => array(),
            );

            return $Block;
        }
    }

    #
    # Table

    /**
     * 执行blockTable操作。
     *
     * @param mixed $Line 参数$Line。
     * @param array $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockTable($Line, $Block = null) {
        if (! isset($Block) or $Block['type'] !== 'Paragraph' or isset($Block['interrupted'])) {
            return;
        }

        if (
            strpos($Block['element']['handler']['argument'], '|') === false
            and strpos($Line['text'], '|') === false
            and strpos($Line['text'], ':') === false
            or strpos($Block['element']['handler']['argument'], "\n") !== false
        ) {
            return;
        }

        if (chop($Line['text'], ' -:|') !== '') {
            return;
        }

        $alignments = array();

        $divider = $Line['text'];

        $divider = trim($divider);
        $divider = trim($divider, '|');

        $dividerCells = explode('|', $divider);

        foreach ($dividerCells as $dividerCell) {
            $dividerCell = trim($dividerCell);

            if ($dividerCell === '') {
                return;
            }

            $alignment = null;

            if ($dividerCell[0] === ':') {
                $alignment = 'left';
            }

            if (substr($dividerCell, -1) === ':') {
                $alignment = $alignment === 'left' ? 'center' : 'right';
            }

            $alignments[] = $alignment;
        }

        # ~

        $HeaderElements = array();

        $header = $Block['element']['handler']['argument'];

        $header = trim($header);
        $header = trim($header, '|');

        $headerCells = explode('|', $header);

        if (count($headerCells) !== count($alignments)) {
            return;
        }

        foreach ($headerCells as $index => $headerCell) {
            $headerCell = trim($headerCell);

            $HeaderElement = array(
                'name' => 'th',
                'handler' => array(
                    'function' => 'lineElements',
                    'argument' => $headerCell,
                    'destination' => 'elements',
                )
            );

            if (isset($alignments[$index])) {
                $alignment = $alignments[$index];

                $HeaderElement['attributes'] = array(
                    'style' => "text-align: $alignment;",
                );
            }

            $HeaderElements[] = $HeaderElement;
        }

        # ~

        $Block = array(
            'alignments' => $alignments,
            'identified' => true,
            'element' => array(
                'name' => 'table',
                'elements' => array(),
            ),
        );

        $Block['element']['elements'][] = array(
            'name' => 'thead',
        );

        $Block['element']['elements'][] = array(
            'name' => 'tbody',
            'elements' => array(),
        );

        $Block['element']['elements'][0]['elements'][] = array(
            'name' => 'tr',
            'elements' => $HeaderElements,
        );

        return $Block;
    }

    /**
     * 执行blockTableContinue操作。
     *
     * @param mixed $Line 参数$Line。
     * @param array $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function blockTableContinue($Line, array $Block) {
        if (isset($Block['interrupted'])) {
            return;
        }

        if (count($Block['alignments']) === 1 or $Line['text'][0] === '|' or strpos($Line['text'], '|')) {
            $Elements = array();

            $row = $Line['text'];

            $row = trim($row);
            $row = trim($row, '|');

            preg_match_all('/(?:(\\\\[|])|[^|`]|`[^`]++`|`)++/', $row, $matches);

            $cells = array_slice($matches[0], 0, count($Block['alignments']));

            foreach ($cells as $index => $cell) {
                $cell = trim($cell);

                $Element = array(
                    'name' => 'td',
                    'handler' => array(
                        'function' => 'lineElements',
                        'argument' => $cell,
                        'destination' => 'elements',
                    )
                );

                if (isset($Block['alignments'][$index])) {
                    $Element['attributes'] = array(
                        'style' => 'text-align: ' . $Block['alignments'][$index] . ';',
                    );
                }

                $Elements[] = $Element;
            }

            $Element = array(
                'name' => 'tr',
                'elements' => $Elements,
            );

            $Block['element']['elements'][1]['elements'][] = $Element;

            return $Block;
        }
    }

    #
    # ~
    #

    /**
     * 执行paragraph操作。
     *
     * @param mixed $Line 参数$Line。
     * @return mixed 返回结果。
     */
    protected function paragraph($Line) {
        return array(
            'type' => 'Paragraph',
            'element' => array(
                'name' => 'p',
                'handler' => array(
                    'function' => 'lineElements',
                    'argument' => $Line['text'],
                    'destination' => 'elements',
                ),
            ),
        );
    }

    /**
     * 执行paragraphContinue操作。
     *
     * @param mixed $Line 参数$Line。
     * @param array $Block 参数$Block。
     * @return mixed 返回结果。
     */
    protected function paragraphContinue($Line, array $Block) {
        if (isset($Block['interrupted'])) {
            return;
        }

        $Block['element']['handler']['argument'] .= "\n" . $Line['text'];

        return $Block;
    }

    #
    # Inline Elements
    #

    #
    # ~
    #

    /**
     * 执行line操作。
     *
     * @param mixed $text 参数$text。
     * @param array $nonNestables 参数$nonNestables。
     * @return mixed 返回结果。
     */
    public function line($text, $nonNestables = array()) {
        return $this->elements($this->lineElements($text, $nonNestables));
    }

    /**
     * 执行lineElements操作。
     *
     * @param mixed $text 参数$text。
     * @param array $nonNestables 参数$nonNestables。
     * @return mixed 返回结果。
     */
    protected function lineElements($text, $nonNestables = array()) {
        # standardize line breaks
        $text = str_replace(array("\r\n", "\r"), "\n", $text ? $text : '');

        $Elements = array();

        $nonNestables = (empty($nonNestables)
            ? array()
            : array_combine($nonNestables, $nonNestables)
        );

        # $excerpt is based on the first occurrence of a marker

        while ($excerpt = strpbrk($text, $this->inlineMarkerList)) {
            $marker = $excerpt[0];

            $markerPosition = strlen($text) - strlen($excerpt);

            $Excerpt = array('text' => $excerpt, 'context' => $text);

            foreach ($this->InlineTypes[$marker] as $inlineType) {
                # check to see if the current inline type is nestable in the current context

                if (isset($nonNestables[$inlineType])) {
                    continue;
                }

                $Inline = $this->{"inline$inlineType"}($Excerpt);

                if (! isset($Inline)) {
                    continue;
                }

                # makes sure that the inline belongs to "our" marker

                if (isset($Inline['position']) and $Inline['position'] > $markerPosition) {
                    continue;
                }

                # sets a default inline position

                if (! isset($Inline['position'])) {
                    $Inline['position'] = $markerPosition;
                }

                # cause the new element to 'inherit' our non nestables

                $Inline['element']['nonNestables'] = isset($Inline['element']['nonNestables'])
                    ? array_merge($Inline['element']['nonNestables'], $nonNestables)
                    : $nonNestables;

                # the text that comes before the inline
                $unmarkedText = substr($text, 0, $Inline['position']);

                # compile the unmarked text
                $InlineText = $this->inlineText($unmarkedText);
                $Elements[] = $InlineText['element'];

                # compile the inline
                $Elements[] = $this->extractElement($Inline);

                # remove the examined text
                $text = substr($text, $Inline['position'] + $Inline['extent']);

                continue 2;
            }

            # the marker does not belong to an inline

            $unmarkedText = substr($text, 0, $markerPosition + 1);

            $InlineText = $this->inlineText($unmarkedText);
            $Elements[] = $InlineText['element'];

            $text = substr($text, $markerPosition + 1);
        }

        $InlineText = $this->inlineText($text);
        $Elements[] = $InlineText['element'];

        foreach ($Elements as &$Element) {
            if (! isset($Element['autobreak'])) {
                $Element['autobreak'] = false;
            }
        }

        return $Elements;
    }

    #
    # ~
    #

    /**
     * 执行inlineText操作。
     *
     * @param mixed $text 参数$text。
     * @return mixed 返回结果。
     */
    protected function inlineText($text) {
        $Inline = array(
            'extent' => strlen($text),
            'element' => array(),
        );

        $Inline['element']['elements'] = self::pregReplaceElements(
            $this->breaksEnabled ? '/[ ]*+\n/' : '/(?:[ ]*+\\\\|[ ]{2,}+)\n/',
            array(
                array('name' => 'br'),
                array('text' => "\n"),
            ),
            $text
        );

        return $Inline;
    }

    /**
     * 执行inlineCode操作。
     *
     * @param mixed $Excerpt 参数$Excerpt。
     * @return mixed 返回结果。
     */
    protected function inlineCode($Excerpt) {
        $marker = $Excerpt['text'][0];

        if (preg_match('/^([' . $marker . ']++)[ ]*+(.+?)[ ]*+(?<![' . $marker . '])\1(?!' . $marker . ')/s', $Excerpt['text'], $matches)) {
            $text = $matches[2];
            $text = preg_replace('/[ ]*+\n/', ' ', $text);

            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'code',
                    'text' => $text,
                ),
            );
        }
    }

    /**
     * 执行inlineEmailTag操作。
     *
     * @param mixed $Excerpt 参数$Excerpt。
     * @return mixed 返回结果。
     */
    protected function inlineEmailTag($Excerpt) {
        $hostnameLabel = '[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?';

        $commonMarkEmail = '[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]++@'
            . $hostnameLabel . '(?:\.' . $hostnameLabel . ')*';

        if (
            strpos($Excerpt['text'], '>') !== false
            and preg_match("/^<((mailto:)?$commonMarkEmail)>/i", $Excerpt['text'], $matches)
        ) {
            $url = $matches[1];

            if (! isset($matches[2])) {
                $url = "mailto:$url";
            }

            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'a',
                    'text' => $matches[1],
                    'attributes' => array(
                        'href' => $url,
                    ),
                ),
            );
        }
    }

    /**
     * 执行inlineEmphasis操作。
     *
     * @param mixed $Excerpt 参数$Excerpt。
     * @return mixed 返回结果。
     */
    protected function inlineEmphasis($Excerpt) {
        if (! isset($Excerpt['text'][1])) {
            return;
        }

        $marker = $Excerpt['text'][0];

        if ($Excerpt['text'][1] === $marker and preg_match($this->StrongRegex[$marker], $Excerpt['text'], $matches)) {
            $emphasis = 'strong';
        } elseif (preg_match($this->EmRegex[$marker], $Excerpt['text'], $matches)) {
            $emphasis = 'em';
        } else {
            return;
        }

        return array(
            'extent' => strlen($matches[0]),
            'element' => array(
                'name' => $emphasis,
                'handler' => array(
                    'function' => 'lineElements',
                    'argument' => $matches[1],
                    'destination' => 'elements',
                )
            ),
        );
    }

    /**
     * 执行inlineEscapeSequence操作。
     *
     * @param mixed $Excerpt 参数$Excerpt。
     * @return mixed 返回结果。
     */
    protected function inlineEscapeSequence($Excerpt) {
        if (isset($Excerpt['text'][1]) and in_array($Excerpt['text'][1], $this->specialCharacters)) {
            return array(
                'element' => array('rawHtml' => $Excerpt['text'][1]),
                'extent' => 2,
            );
        }
    }

    /**
     * 执行inlineImage操作。
     *
     * @param mixed $Excerpt 参数$Excerpt。
     * @return mixed 返回结果。
     */
    protected function inlineImage($Excerpt) {
        if (! isset($Excerpt['text'][1]) or $Excerpt['text'][1] !== '[') {
            return;
        }

        $Excerpt['text'] = substr($Excerpt['text'], 1);

        $Link = $this->inlineLink($Excerpt);

        if ($Link === null) {
            return;
        }

        $Inline = array(
            'extent' => $Link['extent'] + 1,
            'element' => array(
                'name' => 'img',
                'attributes' => array(
                    'src' => $Link['element']['attributes']['href'],
                    'alt' => $Link['element']['handler']['argument'],
                ),
                'autobreak' => true,
            ),
        );

        $Inline['element']['attributes'] += $Link['element']['attributes'];

        unset($Inline['element']['attributes']['href']);

        return $Inline;
    }

    /**
     * 执行inlineLink操作。
     *
     * @param mixed $Excerpt 参数$Excerpt。
     * @return mixed 返回结果。
     */
    protected function inlineLink($Excerpt) {
        $Element = array(
            'name' => 'a',
            'handler' => array(
                'function' => 'lineElements',
                'argument' => null,
                'destination' => 'elements',
            ),
            'nonNestables' => array('Url', 'Link'),
            'attributes' => array(
                'href' => null,
                'title' => null,
            ),
        );

        $extent = 0;

        $remainder = $Excerpt['text'];

        if (preg_match('/\[((?:[^][]++|(?R))*+)\]/', $remainder, $matches)) {
            $Element['handler']['argument'] = $matches[1];

            $extent += strlen($matches[0]);

            $remainder = substr($remainder, $extent);
        } else {
            return;
        }

        if (preg_match('/^[(]\s*+((?:[^ ()]++|[(][^ )]+[)])++)(?:[ ]+("[^"]*+"|\'[^\']*+\'))?\s*+[)]/', $remainder, $matches)) {
            $Element['attributes']['href'] = $matches[1];

            if (isset($matches[2])) {
                $Element['attributes']['title'] = substr($matches[2], 1, -1);
            }

            $extent += strlen($matches[0]);
        } else {
            if (preg_match('/^\s*\[(.*?)\]/', $remainder, $matches)) {
                $definition = strlen($matches[1]) ? $matches[1] : $Element['handler']['argument'];
                $definition = strtolower($definition);

                $extent += strlen($matches[0]);
            } else {
                $definition = strtolower($Element['handler']['argument']);
            }

            if (! isset($this->DefinitionData['Reference'][$definition])) {
                return;
            }

            $Definition = $this->DefinitionData['Reference'][$definition];

            $Element['attributes']['href'] = $Definition['url'];
            $Element['attributes']['title'] = $Definition['title'];
        }

        return array(
            'extent' => $extent,
            'element' => $Element,
        );
    }

    /**
     * 执行inlineMarkup操作。
     *
     * @param mixed $Excerpt 参数$Excerpt。
     * @return mixed 返回结果。
     */
    protected function inlineMarkup($Excerpt) {
        if ($this->markupEscaped or $this->safeMode or strpos($Excerpt['text'], '>') === false) {
            return;
        }

        if ($Excerpt['text'][1] === '/' and preg_match('/^<\/\w[\w-]*+[ ]*+>/s', $Excerpt['text'], $matches)) {
            return array(
                'element' => array('rawHtml' => $matches[0]),
                'extent' => strlen($matches[0]),
            );
        }

        if ($Excerpt['text'][1] === '!' and preg_match('/^<!---?[^>-](?:-?+[^-])*-->/s', $Excerpt['text'], $matches)) {
            return array(
                'element' => array('rawHtml' => $matches[0]),
                'extent' => strlen($matches[0]),
            );
        }

        if ($Excerpt['text'][1] !== ' ' and preg_match('/^<\w[\w-]*+(?:[ ]*+' . $this->regexHtmlAttribute . ')*+[ ]*+\/?>/s', $Excerpt['text'], $matches)) {
            return array(
                'element' => array('rawHtml' => $matches[0]),
                'extent' => strlen($matches[0]),
            );
        }
    }

    /**
     * 执行inlineSpecialCharacter操作。
     *
     * @param mixed $Excerpt 参数$Excerpt。
     * @return mixed 返回结果。
     */
    protected function inlineSpecialCharacter($Excerpt) {
        if (
            substr($Excerpt['text'], 1, 1) !== ' ' and strpos($Excerpt['text'], ';') !== false
            and preg_match('/^&(#?+[0-9a-zA-Z]++);/', $Excerpt['text'], $matches)
        ) {
            return array(
                'element' => array('rawHtml' => '&' . $matches[1] . ';'),
                'extent' => strlen($matches[0]),
            );
        }

        return;
    }

    /**
     * 执行inlineStrikethrough操作。
     *
     * @param mixed $Excerpt 参数$Excerpt。
     * @return mixed 返回结果。
     */
    protected function inlineStrikethrough($Excerpt) {
        if (! isset($Excerpt['text'][1])) {
            return;
        }

        if ($Excerpt['text'][1] === '~' and preg_match('/^~~(?=\S)(.+?)(?<=\S)~~/', $Excerpt['text'], $matches)) {
            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'del',
                    'handler' => array(
                        'function' => 'lineElements',
                        'argument' => $matches[1],
                        'destination' => 'elements',
                    )
                ),
            );
        }
    }

    /**
     * 执行inlineUrl操作。
     *
     * @param mixed $Excerpt 参数$Excerpt。
     * @return mixed 返回结果。
     */
    protected function inlineUrl($Excerpt) {
        if ($this->urlsLinked !== true or ! isset($Excerpt['text'][2]) or $Excerpt['text'][2] !== '/') {
            return;
        }

        if (
            strpos($Excerpt['context'], 'http') !== false
            and preg_match('/\bhttps?+:[\/]{2}[^\s<]+\b\/*+/ui', $Excerpt['context'], $matches, PREG_OFFSET_CAPTURE)
        ) {
            $url = $matches[0][0];

            $Inline = array(
                'extent' => strlen($matches[0][0]),
                'position' => $matches[0][1],
                'element' => array(
                    'name' => 'a',
                    'text' => $url,
                    'attributes' => array(
                        'href' => $url,
                    ),
                ),
            );

            return $Inline;
        }
    }

    /**
     * 执行inlineUrlTag操作。
     *
     * @param mixed $Excerpt 参数$Excerpt。
     * @return mixed 返回结果。
     */
    protected function inlineUrlTag($Excerpt) {
        if (strpos($Excerpt['text'], '>') !== false and preg_match('/^<(\w++:\/{2}[^ >]++)>/i', $Excerpt['text'], $matches)) {
            $url = $matches[1];

            return array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'a',
                    'text' => $url,
                    'attributes' => array(
                        'href' => $url,
                    ),
                ),
            );
        }
    }

    # ~

    /**
     * 执行unmarkedText操作。
     *
     * @param mixed $text 参数$text。
     * @return mixed 返回结果。
     */
    protected function unmarkedText($text) {
        $Inline = $this->inlineText($text);
        return $this->element($Inline['element']);
    }

    #
    # Handlers
    #

    /**
     * 执行handle操作。
     *
     * @param array $Element 参数$Element。
     * @return mixed 返回结果。
     */
    protected function handle(array $Element) {
        if (isset($Element['handler'])) {
            if (!isset($Element['nonNestables'])) {
                $Element['nonNestables'] = array();
            }

            if (is_string($Element['handler'])) {
                $function = $Element['handler'];
                $argument = $Element['text'];
                unset($Element['text']);
                $destination = 'rawHtml';
            } else {
                $function = $Element['handler']['function'];
                $argument = $Element['handler']['argument'];
                $destination = $Element['handler']['destination'];
            }

            $Element[$destination] = $this->{$function}($argument, $Element['nonNestables']);

            if ($destination === 'handler') {
                $Element = $this->handle($Element);
            }

            unset($Element['handler']);
        }

        return $Element;
    }

    /**
     * 执行handleElementRecursive操作。
     *
     * @param array $Element 参数$Element。
     * @return mixed 返回结果。
     */
    protected function handleElementRecursive(array $Element) {
        return $this->elementApplyRecursive(array($this, 'handle'), $Element);
    }

    /**
     * 执行handleElementsRecursive操作。
     *
     * @param array $Elements 参数$Elements。
     * @return mixed 返回结果。
     */
    protected function handleElementsRecursive(array $Elements) {
        return $this->elementsApplyRecursive(array($this, 'handle'), $Elements);
    }

    /**
     * 执行elementApplyRecursive操作。
     *
     * @param mixed $closure 参数$closure。
     * @param array $Element 参数$Element。
     * @return mixed 返回结果。
     */
    protected function elementApplyRecursive($closure, array $Element) {
        $Element = call_user_func($closure, $Element);

        if (isset($Element['elements'])) {
            $Element['elements'] = $this->elementsApplyRecursive($closure, $Element['elements']);
        } elseif (isset($Element['element'])) {
            $Element['element'] = $this->elementApplyRecursive($closure, $Element['element']);
        }

        return $Element;
    }

    /**
     * 执行elementApplyRecursiveDepthFirst操作。
     *
     * @param mixed $closure 参数$closure。
     * @param array $Element 参数$Element。
     * @return mixed 返回结果。
     */
    protected function elementApplyRecursiveDepthFirst($closure, array $Element) {
        if (isset($Element['elements'])) {
            $Element['elements'] = $this->elementsApplyRecursiveDepthFirst($closure, $Element['elements']);
        } elseif (isset($Element['element'])) {
            $Element['element'] = $this->elementsApplyRecursiveDepthFirst($closure, $Element['element']);
        }

        $Element = call_user_func($closure, $Element);

        return $Element;
    }

    /**
     * 执行elementsApplyRecursive操作。
     *
     * @param mixed $closure 参数$closure。
     * @param array $Elements 参数$Elements。
     * @return mixed 返回结果。
     */
    protected function elementsApplyRecursive($closure, array $Elements) {
        foreach ($Elements as &$Element) {
            $Element = $this->elementApplyRecursive($closure, $Element);
        }

        return $Elements;
    }

    /**
     * 执行elementsApplyRecursiveDepthFirst操作。
     *
     * @param mixed $closure 参数$closure。
     * @param array $Elements 参数$Elements。
     * @return mixed 返回结果。
     */
    protected function elementsApplyRecursiveDepthFirst($closure, array $Elements) {
        foreach ($Elements as &$Element) {
            $Element = $this->elementApplyRecursiveDepthFirst($closure, $Element);
        }

        return $Elements;
    }

    /**
     * 执行element操作。
     *
     * @param array $Element 参数$Element。
     * @return mixed 返回结果。
     */
    protected function element(array $Element) {
        if ($this->safeMode) {
            $Element = $this->sanitiseElement($Element);
        }

        # identity map if element has no handler
        $Element = $this->handle($Element);

        $hasName = isset($Element['name']);

        $markup = '';

        if ($hasName) {
            $markup .= '<' . $Element['name'];

            if (isset($Element['attributes'])) {
                foreach ($Element['attributes'] as $name => $value) {
                    if ($value === null) {
                        continue;
                    }

                    $markup .= " $name=\"" . self::escape($value) . '"';
                }
            }
        }

        $permitRawHtml = false;

        if (isset($Element['text'])) {
            $text = $Element['text'];
        }
        // very strongly consider an alternative if you're writing an
        // extension
        elseif (isset($Element['rawHtml'])) {
            $text = $Element['rawHtml'];

            $allowRawHtmlInSafeMode = isset($Element['allowRawHtmlInSafeMode']) && $Element['allowRawHtmlInSafeMode'];
            $permitRawHtml = !$this->safeMode || $allowRawHtmlInSafeMode;
        }

        $hasContent = isset($text) || isset($Element['element']) || isset($Element['elements']);

        if ($hasContent) {
            $markup .= $hasName ? '>' : '';

            if (isset($Element['elements'])) {
                $markup .= $this->elements($Element['elements']);
            } elseif (isset($Element['element'])) {
                $markup .= $this->element($Element['element']);
            } else {
                if (!$permitRawHtml) {
                    $markup .= self::escape($text, true);
                } else {
                    $markup .= $text;
                }
            }

            $markup .= $hasName ? '</' . $Element['name'] . '>' : '';
        } elseif ($hasName) {
            $markup .= ' />';
        }

        return $markup;
    }

    /**
     * 执行elements操作。
     *
     * @param array $Elements 参数$Elements。
     * @return mixed 返回结果。
     */
    protected function elements(array $Elements) {
        $markup = '';

        $autoBreak = true;

        foreach ($Elements as $Element) {
            if (empty($Element)) {
                continue;
            }

            $autoBreakNext = (isset($Element['autobreak'])
                ? $Element['autobreak'] : isset($Element['name'])
            );
            // (autobreak === false) covers both sides of an element
            $autoBreak = !$autoBreak ? $autoBreak : $autoBreakNext;

            $markup .= ($autoBreak ? "\n" : '') . $this->element($Element);
            $autoBreak = $autoBreakNext;
        }

        $markup .= $autoBreak ? "\n" : '';

        return $markup;
    }

    # ~

    /**
     * 执行li操作。
     *
     * @param mixed $lines 参数$lines。
     * @return mixed 返回结果。
     */
    protected function li($lines) {
        $Elements = $this->linesElements($lines);

        if (
            ! in_array('', $lines)
            and isset($Elements[0]) and isset($Elements[0]['name'])
            and $Elements[0]['name'] === 'p'
        ) {
            unset($Elements[0]['name']);
        }

        return $Elements;
    }

    #
    # AST Convenience
    #

    /**
     * 执行pregReplaceElements操作。
     *
     * @param mixed $regexp 参数$regexp。
     * @param array $Elements 参数$Elements。
     * @param mixed $text 参数$text。
     * @return array 返回结果。
     */
    protected static function pregReplaceElements($regexp, $Elements, $text) {
        $newElements = array();

        while (preg_match($regexp, $text, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = $matches[0][1];
            $before = substr($text, 0, $offset);
            $after = substr($text, $offset + strlen($matches[0][0]));

            $newElements[] = array('text' => $before);

            foreach ($Elements as $Element) {
                $newElements[] = $Element;
            }

            $text = $after;
        }

        $newElements[] = array('text' => $text);

        return $newElements;
    }

    #
    # Deprecated Methods
    #

    /**
     * 执行parse操作。
     *
     * @param mixed $text 参数$text。
     * @return mixed 返回结果。
     */
    function parse($text) {
        $markup = $this->text($text);

        return $markup;
    }

    /**
     * 执行sanitiseElement操作。
     *
     * @param array $Element 参数$Element。
     * @return mixed 返回结果。
     */
    protected function sanitiseElement(array $Element) {
        static $goodAttribute = '/^[a-zA-Z0-9][a-zA-Z0-9-_]*+$/';
        static $safeUrlNameToAtt  = array(
            'a'   => 'href',
            'img' => 'src',
        );

        if (! isset($Element['name'])) {
            unset($Element['attributes']);
            return $Element;
        }

        if (isset($safeUrlNameToAtt[$Element['name']])) {
            $Element = $this->filterUnsafeUrlInAttribute($Element, $safeUrlNameToAtt[$Element['name']]);
        }

        if (! empty($Element['attributes'])) {
            foreach ($Element['attributes'] as $att => $val) {
                # filter out badly parsed attribute
                if (! preg_match($goodAttribute, $att)) {
                    unset($Element['attributes'][$att]);
                }
                # dump onevent attribute
                elseif (self::striAtStart($att, 'on')) {
                    unset($Element['attributes'][$att]);
                }
            }
        }

        return $Element;
    }

    /**
     * 执行filterUnsafeUrlInAttribute操作。
     *
     * @param array $Element 参数$Element。
     * @param mixed $attribute 参数$attribute。
     * @return mixed 返回结果。
     */
    protected function filterUnsafeUrlInAttribute(array $Element, $attribute) {
        foreach ($this->safeLinksWhitelist as $scheme) {
            if (self::striAtStart($Element['attributes'][$attribute], $scheme)) {
                return $Element;
            }
        }

        $Element['attributes'][$attribute] = str_replace(':', '%3A', $Element['attributes'][$attribute]);

        return $Element;
    }

    #
    # Static Methods
    #

    /**
     * 执行escape操作。
     *
     * @param mixed $text 参数$text。
     * @param bool $allowQuotes 参数$allowQuotes。
     * @return mixed 返回结果。
     */
    protected static function escape($text, $allowQuotes = false) {
        return htmlspecialchars($text, $allowQuotes ? ENT_NOQUOTES : ENT_QUOTES, 'UTF-8');
    }

    /**
     * 执行striAtStart操作。
     *
     * @param mixed $string 参数$string。
     * @param mixed $needle 参数$needle。
     * @return bool 返回结果。
     */
    protected static function striAtStart($string, $needle) {
        $len = strlen($needle);

        if ($len > strlen($string)) {
            return false;
        } else {
            return strtolower(substr($string, 0, $len)) === strtolower($needle);
        }
    }

    /**
     * 执行instance操作。
     *
     * @param string $name 参数$name。
     * @return mixed 返回结果。
     */
    public static function instance($name = 'default') {
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }

        $instance = new static();

        self::$instances[$name] = $instance;

        return $instance;
    }

    #
    # Fields
    #

    #
    # Read-Only
}
