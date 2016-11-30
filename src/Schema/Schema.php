<?php

namespace Tarantool\Mapper\Schema;

use Tarantool\Client\Client;
use Tarantool\Mapper\Contracts;
use Tarantool\Client\Schema\Space;
use Tarantool\Client\Schema\Index;

class Schema implements Contracts\Schema
{
    protected $client;
    protected $spaceSpace;
    protected $indexSpace;
    protected $spaceId = [];

    public function __construct(Client $client, array $data = null)
    {
        $this->client = $client;
        $this->spaceSpace = $client->getSpace(Space::VSPACE);
        $this->indexSpace = $client->getSpace(Space::VINDEX);

        if($data) {
            $this->spaceId = $data;

        } else {
            $this->collectData();
        }

    }

    public function getSpaceId($space)
    {
        if (!array_key_exists($space, $this->spaceId)) {
            $response = $this->spaceSpace->select([$space], Index::SPACE_NAME);
            $data = $response->getData();
            if (!empty($data)) {
                $this->spaceId[$space] = $data[0][0];
            }
        }
        if (array_key_exists($space, $this->spaceId)) {
            return $this->spaceId[$space];
        }
    }

    public function getSpaceName($spaceId)
    {
        if (!in_array($spaceId, $this->spaceId)) {
            $response = $this->spaceSpace->select([$spaceId], 0);
            $data = $response->getData();
            if (!empty($data)) {
                $this->spaceId[$data[0][2]] = $spaceId;
            }
        }

        if (in_array($spaceId, $this->spaceId)) {
            return array_search($spaceId, $this->spaceId);
        }
    }

    public function hasSpace($space)
    {
        return $this->getSpaceId($space) !== null;
    }

    public function createSpace($space)
    {
        $this->client->evaluate("box.schema.space.create('$space')");
    }

    public function dropSpace($space)
    {
        $this->client->evaluate('box.schema.space.drop('.$this->getSpaceId($space).')');
        unset($this->spaceId[$space]);
    }

    public function hasIndex($space, $index)
    {
        $spaceId = $this->getSpaceId($space);
        $response = $this->indexSpace->select([$spaceId, $index], Index::INDEX_NAME);

        return !empty($response->getData());
    }

    public function listIndexes($space)
    {
        $result = [];
        $response = $this->indexSpace->select([$this->getSpaceId($space)], Index::INDEX_NAME);

        foreach ($response->getData() as $row) {
            $result[$row[2]] = [];
            foreach ($row[5] as $f) {
                $result[$row[2]][] = $f[0];
            }
        }

        return $result;
    }

    public function createIndex($space, $index, array $arguments)
    {
        $config = [];
        foreach ($arguments as $k => $v) {
            if (is_array($v)) {
                // convert to lua array
                $v = str_replace(['[', ']'], ['{', '}'], json_encode($v));
            }
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            $config[] = $k.' = '.$v;
        }
        $config = '{'.implode(', ', $config).'}';
        $this->client->evaluate("box.space.$space:create_index('$index', $config)");

        $schema = $this->client->getSpace(Space::VINDEX);
        $response = $schema->select([$this->getSpaceId($space), $index], Index::INDEX_NAME);

        return $response->getData()[0][1];
    }

    public function dropIndex($spaceId, $index)
    {
        $space = $this->client->getSpace('_vindex');
        $row = $space->select([$spaceId, $index])->getData();
        $spaceName = $this->getSpaceName($spaceId);
        $indexName = $row[0][2];
        $this->client->evaluate("box.space.$spaceName.index.$indexName:drop{}");
    }

    private function collectData()
    {
        $spaces = $this->spaceSpace->select([])->getData();
        foreach ($spaces as $row) {
            list($id, $sys, $name) = $row;
            $this->spaceId[$name] = $id;
        }
    }

    public function toArray()
    {
        $this->collectData();
        return $this->spaceId;
    }
}
