<?php

namespace Procedure;

use Tarantool\Mapper\Procedure;

class Greet extends Procedure
{
    public function getBody() : string
    {
        return "return 'Hello, '..name..'!'";
    }

    public function getParams() : array
    {
        return ['name'];
    }
}
