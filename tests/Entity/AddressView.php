<?php

namespace Entity;

use Tarantool\Mapper\Entity as Entity;

class AddressView extends Entity
{
    /**
     * @var integer
     */
    public $id;

    /**
     * @var string
     */
    public $address;

    public static function compute(Address $address)
    {
        return [
            'address' => "$address->country, $address->city, $address->street $address->house",
        ];
    }
}
