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

namespace Dou\Core\Orm;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use JsonSerializable;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 模型集合：对 foreach / array 访问 / json_encode / Smarty 透明的 Model 容器。
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var array */
    protected $items = array();

    /**
     * @param array $items
     */
    public function __construct(array $items = array())
    {
        $this->items = $items;
    }

    /**
     * @return array
     */
    public function all()
    {
        return $this->items;
    }

    /**
     * @return mixed|null
     */
    public function first()
    {
        if (empty($this->items)) {
            return null;
        }

        return reset($this->items);
    }

    /**
     * @return mixed|null
     */
    public function last()
    {
        if (empty($this->items)) {
            return null;
        }

        return end($this->items);
    }

    /**
     * @return bool
     */
    public function isEmpty()
    {
        return empty($this->items);
    }

    /**
     * @param callable $callback
     * @return static
     */
    public function map($callback)
    {
        return new static(array_map($callback, $this->items));
    }

    /**
     * @param callable|null $callback
     * @return static
     */
    public function filter($callback = null)
    {
        if ($callback === null) {
            return new static(array_values(array_filter($this->items)));
        }

        return new static(array_values(array_filter($this->items, $callback)));
    }

    /**
     * 取每个元素的某字段值列表。
     *
     * @param string $field
     * @return array
     */
    public function pluck($field)
    {
        $result = array();
        foreach ($this->items as $item) {
            if ($item instanceof Model) {
                $result[] = $item->getAttribute($field);
            } elseif (is_array($item) || $item instanceof ArrayAccess) {
                $result[] = isset($item[$field]) ? $item[$field] : null;
            } else {
                $result[] = null;
            }
        }

        return $result;
    }

    /**
     * 以某字段为键重建集合。
     *
     * @param string $field
     * @return static
     */
    public function keyBy($field)
    {
        $result = array();
        $idx = 0;
        foreach ($this->items as $item) {
            if ($item instanceof Model) {
                $key = $item->getAttribute($field);
            } elseif (is_array($item) || $item instanceof ArrayAccess) {
                $key = isset($item[$field]) ? $item[$field] : null;
            } else {
                $key = null;
            }
            if ($key === null || (is_scalar($key) && (string) $key === '')) {
                $key = '__null_' . ($idx++);
            }
            $result[$key] = $item;
        }

        return new static($result);
    }

    /**
     * 取每个模型的主键值列表。
     *
     * @return array
     */
    public function modelKeys()
    {
        $result = array();
        foreach ($this->items as $item) {
            if ($item instanceof Model) {
                $result[] = $item->getKey();
            }
        }

        return $result;
    }

    /**
     * @param mixed $item
     * @return $this
     */
    public function push($item)
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $result = array();
        foreach ($this->items as $key => $item) {
            if ($item instanceof Model || $item instanceof Collection) {
                $result[$key] = $item->toArray();
            } else {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new \ArrayIterator($this->items);
    }

    #[\ReturnTypeWillChange]
    public function count()
    {
        return count($this->items);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->items[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return isset($this->items[$offset]) ? $this->items[$offset] : null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->items[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
