<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Procedure;

use Exception;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Procedure;
use Tarantool\Mapper\Space;

class CreateSequence extends Procedure
{
    public function execute(string $space, string $primaryIndex, int $primaryField)
    {
        $this($space, $primaryIndex, $primaryField);
    }

    public function getBody(): string
    {
        return <<<LUA
        if box.sequence[space] == nil then
            local last = 0
            local tuple = box.space[space].index[primary_index]:max()
            if tuple ~= nil then
                last = tuple[primary_field]
            end
            box.schema.sequence.create(space, { start = last + 1 })
        end
LUA;
    }

    public function getName(): string
    {
        return 'mapper_create_sequence';
    }

    public function getParams(): array
    {
        return ['space', 'primary_index', 'primary_field'];
    }
}
