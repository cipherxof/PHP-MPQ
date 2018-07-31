<?php
namespace TriggerHappy\MPQ\Compression;

class HuffmanNode
{
    public $parent;
    public $child;
    public $next;
    public $prev;
    public $value;
    public $probability;

    function __construct() 
    {
        $child[0] = null;
        $child[1] = null;
        $child[2] = null;
    }

    function treeSwap($with)
    {
        $temp = null;

        if ($this->parent === $with->parent)
        {
            $temp = $this->parent->child[0];
            $this->parent->child[0] = $this->parent->child[1];
            $this->parent->child[1] = $temp;
        }
        else
        {
            if ($with->parent->child[0] === $with) $with->parent->child[0] = $this;
            else $with->parent->child[1] = $this;
            if ($this->parent->child[0] === $this) $this->parent->child[0] = $with;
            else $this->parent->child[1] = $with;
        }
    }

    function insertAfter($where)
    {
        $this->prev = $where;
        $this->next = $where->next;
        $where->next = $this;
        $this->next->prev = $this;
    }

    function listSwap($with)
    {
        if ($this->next === $with)
        {
            $this->next = $with->next;
            $with->next = $this;
            $with->prev = $this->prev;
            $this->prev = $with;

            $with->prev->next = $with;
            $this->next->prev = $this;
        }
        elseif($this->prev === $with)
        {
            $this->prev = $with->prev;
            $with->prev = $this;
            $with->next = $this->next;
            $this->next = $with;

            $with->next->prev = $with;
            $this->prev->next = $this;
        }
        else
        {
            $temp = $this->prev;
            $this->prev = $with->prev;
            $with->prev = $temp;

            $temp = $this->next;
            $this->next = $with->next;
            $with->next = $temp;

            $this->prev->next = $this;
            $this->next->prev = $this;

            $with->prev->next = $with;
            $with->next->prev = $with;
        }
    }

    function newList()
    {
        $this->prev = $this->next = $this;
    }

    function removeFromList()
    {
        if ($this === $this->next) return null;

        $this->prev->next = $this->next;
        $this->next->prev = $this->prev;
    }

    function joinList($list)
    {
        $tail = $this->prev;

        $this->prev = $list->prev;
        $this->prev->next = $this;

        $list->prev = $tail;
        $tail->next = $list;
    }

}
?>