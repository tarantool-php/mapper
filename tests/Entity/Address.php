<?php

namespace Entity;

use Tarantool\Mapper\Entity as Entity;

class Address extends Entity
{
    /**
     * @var integer
     */
    public $id;

    /**
     * @var string
     */
    public $country;

    /**
     * @var string
     */
    public $city;

    /**
     * @var string
     * @required
     */
    public $street;

    /**
     * @var integer
     * @type integer
     * @required
     */
    public $house;

    /**
     * @var integer
     * @type integer
     */
    public $flat;

    /**
     * @var array
     * @type map
     */
    public $map;
}
