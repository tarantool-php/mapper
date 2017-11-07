<?php

namespace Procedure;

use Tarantool\Mapper\Procedure;

class Info extends Procedure
{
    public function getBody() : string
    {
        return "return {box.info.version, box.info.uptime}";
    }

    public function getParams() : array
    {
        return [];
    }

    public function getMapping(): array
    {
        return ['version', 'uptime'];
    }
}
