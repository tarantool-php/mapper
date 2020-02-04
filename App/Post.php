<?php

use Tarantool\Mapper\Entity;

class Post extends Entity
{
    /**
     * @var integer
     */
    public $id;

    /**
     * @var string
     */
    public $title;
}
