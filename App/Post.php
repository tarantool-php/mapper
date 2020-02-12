<?php

use Tarantool\Mapper\Entity;

class Post extends Entity
{
    /**
     * @var integer
     */
    public $id;

    /**
     * @var bool
     */
    public $title;
}
