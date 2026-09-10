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

namespace Dou\Core\Infra\Database;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 查询构建器类（用于闭包查询）
 */
class QueryBuilder
{
    /** @var Connection */
    private $db;

    /** @var array */
    private $where_list = array();

    /** @var array */
    private $bind_params = array();

    /** @var string */
    private $bind_types = '';

    /**
     * @param Connection $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * WHERE条件（AND连接）
     *
     * @param mixed $field
     * @param mixed $op
     * @param mixed $value
     * @return $this
     */
    public function where($field, $op = null, $value = null)
    {
        $this->parseWhere($field, $op, $value, 'AND');
        return $this;
    }

    /**
     * WHERE条件（OR连接）
     *
     * @param mixed $field
     * @param mixed $op
     * @param mixed $value
     * @return $this
     */
    public function whereOr($field, $op = null, $value = null)
    {
        $this->parseWhere($field, $op, $value, 'OR');
        return $this;
    }

    /**
     * HAVING条件（AND连接）
     *
     * @param mixed $field
     * @param mixed $op
     * @param mixed $value
     * @return $this
     */
    public function having($field, $op = null, $value = null)
    {
        $this->parseHaving($field, $op, $value, 'AND');
        return $this;
    }

    /**
     * HAVING条件（OR连接）
     *
     * @param mixed $field
     * @param mixed $op
     * @param mixed $value
     * @return $this
     */
    public function havingOr($field, $op = null, $value = null)
    {
        $this->parseHaving($field, $op, $value, 'OR');
        return $this;
    }

    /**
     * 解析WHERE条件
     *
     * @param mixed $field
     * @param mixed $op
     * @param mixed $value
     * @param string $logic
     * @return void
     */
    private function parseWhere($field, $op, $value, $logic)
    {
        // 闭包查询支持
        if ($field instanceof \Closure) {
            $query = new self($this->db);
            $field($query);
            $sub_where = $query->getWhere();
            $sub_bind = $query->getBind();
            $sub_types = $query->getBindTypes();

            if (!empty($sub_where)) {
                $this->where_list[] = array(
                    'type' => 'group',
                    'logic' => $logic,
                    'conditions' => $sub_where,
                );
                $this->bind_params = array_merge($this->bind_params, $sub_bind);
                $this->bind_types .= $sub_types;
            }
            return;
        }

        if ($value === null && $op !== null) {
            $op_upper = strtoupper($op);
            if ($op_upper === 'IS NULL' || $op_upper === 'IS NOT NULL') {
                $op = $op_upper;
            } elseif ($op_upper === 'IS' || $op_upper === 'IS NOT') {
                $op = $op_upper . ' NULL';
            } elseif (in_array($op_upper, array('NOT IN', 'IN', 'NOT LIKE', 'LIKE'))) {
                if (!is_array($value) || empty($value)) {
                    return;
                }
                $op = $op_upper;
            } elseif (in_array($op_upper, array('>', '<', '>=', '<=', '<>', '!=', '='))) {
                // 三参数调用：value 仍然是 null，保持不变
            } else {
                $value = $op;
                $op = '=';
            }
        } elseif ($value === null && $op === null) {
            $op = 'IS NULL';
        }

        $op = strtoupper($op);

        // IN/NOT IN 查询：检查空数组
        if (($op === 'IN' || $op === 'NOT IN') && is_array($value) && empty($value)) {
            return; // 忽略空数组的 IN 查询
        }

        $this->where_list[] = array(
            'type' => 'condition',
            'logic' => $logic,
            'field' => $field,
            'op' => $op,
            'value' => $value,
        );

        // 添加绑定参数
        if ($op === 'IN' || $op === 'NOT IN') {
            // IN 查询：将数组中的每个值都添加到参数列表
            if (is_array($value)) {
                foreach ($value as $item) {
                    $this->bind_params[] = $item;
                    $this->bind_types .= $this->getParamType($item);
                }
            }
        } elseif ($op === 'IS NULL' || $op === 'IS NOT NULL') {
            // IS NULL / IS NOT NULL 不需要绑定参数
        } else {
            // 普通查询：添加单个值
            $this->bind_params[] = $value;
            $this->bind_types .= $this->getParamType($value);
        }
    }

    /**
     * 解析HAVING条件
     *
     * @param mixed $field
     * @param mixed $op
     * @param mixed $value
     * @param string $logic
     * @return void
     */
    private function parseHaving($field, $op, $value, $logic)
    {
        if ($value === null) {
            $value = $op;
            $op = '=';
        }

        $op = strtoupper($op);

        $this->where_list[] = array(
            'type' => 'having',
            'logic' => $logic,
            'field' => $field,
            'op' => $op,
            'value' => $value,
        );

        // 添加绑定参数
        if ($op === 'IN' || $op === 'NOT IN') {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $this->bind_params[] = $item;
                    $this->bind_types .= $this->getParamType($item);
                }
            }
        } else {
            $this->bind_params[] = $value;
            $this->bind_types .= $this->getParamType($value);
        }
    }

    /**
     * 获取参数类型
     *
     * @param mixed $value
     * @return string
     */
    private function getParamType($value)
    {
        if (is_int($value)) {
            return 'i';
        } elseif (is_float($value)) {
            return 'd';
        }
        // null 也用 's' 类型，mysqli_stmt_bind_param 会自动处理 NULL 值
        return 's';
    }

    /**
     * @return array
     */
    public function getWhere()
    {
        return $this->where_list;
    }

    /**
     * @return array
     */
    public function getBind()
    {
        return $this->bind_params;
    }

    /**
     * @return string
     */
    public function getBindTypes()
    {
        return $this->bind_types;
    }
}
