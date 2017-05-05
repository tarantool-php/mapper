<?php

namespace Entities;

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
    public $slug;

    /**
     * @var string
     */
    public $title;

    /**
     * @var Person
     */
    public $author;
}
