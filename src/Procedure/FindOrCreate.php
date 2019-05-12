<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Procedure;

use Exception;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Procedure;
use Tarantool\Mapper\Space;

class FindOrCreate extends Procedure
{
    public function execute(Space $space, array $params) : array
    {
        $index = $space->castIndex($params);
        if (is_null($index)) {
            throw new Exception("No valid index for ".json_encode($params));
        }

        $values = $space->getIndexValues($index, $params);

        $tuple = [];
        $schema = $space->getMapper()->getSchema();

        foreach ($space->getFormat() as $i => $info) {
            $name = $info['name'];
            if (!array_key_exists($name, $params)) {
                $params[$name] = null;
            }

            $params[$name] = $schema->formatValue($info['type'], $params[$name]);
            if (is_null($params[$name])) {
                if (!$space->isPropertyNullable($name)) {
                    $params[$name] = $schema->getDefaultValue($info['type']);
                }
            }

            $tuple[$i] = $params[$name];
        }

        $primary = $space->getPrimaryIndex();
        $sequence = 0;
        $pkIndex = null;
        if (count($primary['parts']) == 1) {
            $pkIndex = $primary['parts'][0][0];
            $key = $space->getFormat()[$pkIndex]['name'];
            $pkIndex++; // convert php to lua index
            if (!array_key_exists($key, $params) || !$params[$key]) {
                $sequence = 1;
                $space->getMapper()
                    ->getPlugin(Sequence::class)
                    ->initializeSequence($space);
            }
        }

        $result = $this($space->getName(), $index, $values, $tuple, $sequence, $pkIndex);

        if (is_string($result)) {
            throw new Exception($result);
        }

        $key = [];
        $format = $space->getFormat();
        foreach ($primary['parts'] as $part) {
            $key[$format[$part[0]]['name']] = $result['tuple'][$part[0]];
        }
        return [
            'key' => $key,
            'created' => !!$result['created'],
        ];
    }

    public function getBody() : string
    {
        return <<<LUA
        if box.space[space] == nil then
            return 'no space ' .. space
        end

        if box.space[space].index[index] == nil then
            return 'no space index ' .. index
        end

        local instances = box.space[space].index[index]:select(params)

        if #instances > 0 then
            return {tuple = instances[1], created = 0}
        end

        if sequence == 1 then
            if box.space.sequence == nil then
                return 'no sequence space'
            end
            local row = box.space.sequence:update(box.space[space].id, {{'+', 2, 1}});
            tuple[key] = row[2];
        end

        tuple = box.space[space]:insert(tuple)
        return {tuple = tuple, created = 1}
LUA;
    }

    public function getParams() : array
    {
        return ['space', 'index', 'params', 'tuple', 'sequence', 'key'];
    }

    public function getName() : string
    {
        return 'mapper_find_or_create';
    }
}
