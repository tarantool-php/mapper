<?php

namespace Entity;

use Tarantool\Mapper\Entity as MapperEntity;

class Post extends MapperEntity
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
