<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Procedure;

use Exception;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Procedure;
use Tarantool\Mapper\Space;

class FindOrCreate extends Procedure
{
    public function execute(Space $space, array $params, array $query = null): array
    {
        $index = $space->castIndex($query ?: $params);
        if ($index === null) {
            throw new Exception("No valid index for " . json_encode($params));
        }

        $key = $space->getIndex(0)->getProperty()?->name;
        $sequence = 0;
        $pkIndex = null;
        if ($key) {
            // convert php to lua index
            $pkIndex = $space->getPropertyIndex($key) + 1;
            if (!array_key_exists($key, $params) || !$params[$key]) {
                $sequence = 1;
                $space->getMapper()
                    ->getPlugin(Sequence::class)
                    ->initializeSequence($space);
            }
        }

        $tuple = $space->getTuple($params);
        $indexValues = $index->getValues($query);
        $result = $this($space->name, $index->id, $indexValues, $tuple, $sequence, $pkIndex);

        if (is_string($result)) {
            throw new Exception($result);
        }

        $key = [];
        $fields = $space->getFields();
        foreach ($index->parts as $part) {
            $key[$fields[$part['field']]] = $result['tuple'][$part['field']];
        }
        return [
            'created' => !!$result['created'],
            'key' => $key,
            'tuple' => $result['tuple'],
        ];
    }

    public function getBody(): string
    {
        return <<<LUA
        if box.space[space] == nil then
            return 'no space ' .. space
        end

        if box.space[space].index[index] == nil then
            return 'no space index ' .. index
        end

        local is_in_txn = box.is_in_txn()

        if is_in_txn == false then
            box.begin()
        end
        local instances = box.space[space].index[index]:select(params)

        if #instances > 0 then
            if is_in_txn == false then
                box.rollback()
            end
            return {tuple = instances[1], created = 0}
        end

        if sequence == 1 then
            local sequence_name = space
            if box.sequence[sequence_name] == nil and box.sequence[sequence_name .. '_seq'] ~= nil then
                sequence_name = sequence_name .. '_seq'
            end
            if box.sequence[sequence_name] == nil then
                if is_in_txn == false then
                    box.rollback()
                end
                return 'no sequence '..sequence_name
            end
            tuple[key] = box.sequence[sequence_name]:next()
        end

        tuple = box.space[space]:insert(tuple)

        if is_in_txn == false then
            box.commit()
        end
        return {tuple = tuple, created = 1}
LUA;
    }

    public function getParams(): array
    {
        return ['space', 'index', 'params', 'tuple', 'sequence', 'key'];
    }

    public function getName(): string
    {
        return 'mapper_find_or_create';
    }
}
