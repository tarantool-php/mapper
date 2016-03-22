<?php

namespace Tarantool\Mapper\Schema;

use Tarantool\Client;
use Tarantool\Mapper\Contracts;
use Tarantool\Schema\Space;
use Tarantool\Schema\Index;
use LogicException;

class Schema implements Contracts\Schema
{
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->spaceSpace = new Space($client, Space::VSPACE);
        $this->indexSpace = new Space($client, Space::VINDEX);
    }

    public function getSpaceId($space)
    {
        $response = $this->spaceSpace->select([$space], Index::SPACE_NAME);
        $data = $response->getData();
        if (!empty($data)) {
            return $data[0][0];
        }
    }

    public function hasSpace($space)
    {
        return $this->getSpaceId($space) != null;
    }

    public function createSpace($space)
    {
        $this->client->evaluate("box.schema.space.create('$space')");
    }

    public function getIndexId($space, $index)
    {
        $response = $this->indexSpace->select([$this->getSpaceId($space), $index], Index::INDEX_NAME);
        $data = $response->getData();

        if (!empty($data)) {
            return $data[0][0];
        }
    }

    public function hasIndex($space, $index)
    {
        $spaceId = $this->getSpaceId($space);
        $response = $this->indexSpace->select([$spaceId, $index], Index::INDEX_NAME);
        return !empty($response->getData());
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
    }
}
