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

namespace Dou\Core\Web\Template\Expression;

use Dou\Core\Web\Template\DouView;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 内建变量解析器：把编译期可定的 $douview / $smarty 引用 lowering 为 $ctx 访问 / 字面量。
 *
 * $douview 为 DouView 规范命名（与应用变量 $dou.* 物理隔离，避免命名空间抢占），
 * $smarty 作为向后兼容别名同等支持（由 ExprEmitter::emitVar 层分流）。
 * 支持：now / foreach.NAME.(index|first|last|show|<bare>) / template / version / ldelim / rdelim。
 */
class BuiltinVarResolver
{
    /** @var ExprParser */
    private $parser;

    /**
     * @param ExprParser $parser 宿主（提供 compileValue / 定界符 / 定位 / syntaxError）
     */
    public function __construct(ExprParser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * 解析 $douview.* / $smarty.* 引用。$indexes 按引用消费（剩余段交回调用方继续应用）。
     *
     * @param array $indexes 引用：变量名之后的访问段（形如 '.foreach' / '.l' / '.iteration'）
     * @param string $varName 顶层变量名（'douview' / 'smarty'），仅用于错误信息
     * @return string lowering 后的 PHP 片段
     */
    public function resolve(&$indexes, $varName = 'smarty')
    {
        if (!is_array($indexes)) {
            $this->parser->syntaxError('invalid ' . $varName . ' reference');
            return '';
        }

        $compiled_ref = '';
        $_ref = substr($indexes[0], 1);
        foreach ($indexes as $_index_no => $_index) {
            $head = substr($_index, 0, 1);
            $isAccessor = ($head === '.' || $head === '[' || substr($_index, 0, 2) === '->');
            if ((substr($_index, 0, 1) != '.' && $_index_no < 2) || !$isAccessor) {
                $this->parser->syntaxError('$' . $varName . implode('', array_slice($indexes, 0, 2)) . ' is an invalid reference');
            }
        }

        switch ($_ref) {
            case 'now':
                $compiled_ref = 'time()';
                $_max_index = 1;
                break;

            case 'foreach':
                array_shift($indexes);
                $_var = $this->parser->compileValue(substr($indexes[0], 1), false);
                $_propname = substr($indexes[1], 1);
                $_max_index = 1;
                switch ($_propname) {
                    case 'index':
                        array_shift($indexes);
                        $compiled_ref = "(\$ctx->loops[$_var]['iteration']-1)";
                        break;

                    case 'first':
                        array_shift($indexes);
                        $compiled_ref = "(\$ctx->loops[$_var]['iteration'] <= 1)";
                        break;

                    case 'last':
                        array_shift($indexes);
                        $compiled_ref = "(\$ctx->loops[$_var]['iteration'] == \$ctx->loops[$_var]['total'])";
                        break;

                    case 'show':
                        array_shift($indexes);
                        $compiled_ref = "(\$ctx->loops[$_var]['total'] > 0)";
                        break;

                    default:
                        unset($_max_index);
                        $compiled_ref = "\$ctx->loops[$_var]";
                }
                break;

            case 'template':
                $compiled_ref = "'" . $this->parser->getCurrentFile() . "'";
                $_max_index = 1;
                break;

            case 'version':
                $compiled_ref = "'" . DouView::VERSION . "'";
                $_max_index = 1;
                break;

            case 'ldelim':
                $compiled_ref = "'" . $this->parser->getLeftDelimiter() . "'";
                break;

            case 'rdelim':
                $compiled_ref = "'" . $this->parser->getRightDelimiter() . "'";
                break;

            default:
                $this->parser->syntaxError('$' . $varName . '.' . $_ref . ' is an unknown reference');
                break;
        }

        if (isset($_max_index) && count($indexes) > $_max_index) {
            $this->parser->syntaxError('$' . $varName . implode('', $indexes) . ' is an invalid reference');
        }

        array_shift($indexes);

        return $compiled_ref;
    }
}
