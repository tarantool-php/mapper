<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Psr\Cache\CacheItemPoolInterface;
use Tarantool\Client\Client;
use Tarantool\Client\Exception\RequestFailed;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Space as ClientSpace;

class Mapper
{
    use Api;

    private array $spaceId = [];
    private array $spaces = [];
    private int $schemaId = 0;

    public readonly Client $client;
    public readonly Converter $converter;
    public readonly Middleware $middleware;

    public function __construct(
        Client $client,
        public ?CacheItemPoolInterface $cache = null,
        public bool $spy = false,
    ) {
        $this->middleware = new Middleware($this);
        $this->client = $client->withMiddleware($this->middleware);
        $this->converter = new Converter();
    }

    public function call(string $query, array $params = [])
    {
        return $this->evaluate($query, $params, true);
    }

    public function createSpace(string $space, array $options = []): Space
    {
        $this->client->evaluate('box.schema.space.create(...)', $space, $options);
        return $this->getSpace($space);
    }

    public function evaluate(string $query, array $params = [], bool $createFunction = false)
    {
        if (!count($params)) {
            return $this->client->evaluate($query);
        }

        if (!$createFunction) {
            $query = 'local ' . implode(', ', array_keys($params)) . ' = ...' . PHP_EOL . $query;
            return $this->client->evaluate($query, ...array_values($params));
        }

        $name = 'evaluate_' . md5($query . json_encode(array_keys($params)));
        try {
            return $this->client->call($name, ...array_values($params));
        } catch (RequestFailed $e) {
            if ($e->getMessage() == "Procedure '$name' is not defined") {
                $body = implode(PHP_EOL, [
                    "function(" . implode(', ', array_keys($params)) . ")",
                    $query,
                    "end",
                ]);
                $options = [
                    'body' => $body,
                    'if_not_exists' => true,
                ];
                $this->client->call('box.schema.func.create', $name, $options);

                return $this->client->call($name, ...array_values($params));
            }
            throw $e;
        }
    }

    public function fetchSchema()
    {
        $tuples = $this->client->getSpaceById(ClientSpace::VSPACE_ID)->select(Criteria::key([]));
        $spaceKeys = [];
        $indexKeys = [];
        foreach ($tuples as $tuple) {
            if ($tuple[0] == ClientSpace::VSPACE_ID) {
                foreach ($tuple[6] as $field) {
                    $spaceKeys[] = $field['name'];
                }
            }
            if ($tuple[0] == ClientSpace::VINDEX_ID) {
                foreach ($tuple[6] as $field) {
                    $indexKeys[] = $field['name'];
                }
            }
        }
        $spaces = [];
        foreach ($tuples as $tuple) {
            $spaces[] = array_combine($spaceKeys, $tuple);
        }
        $tuples = $this->client->getSpaceById(ClientSpace::VINDEX_ID)->select(Criteria::key([]));
        $indexes = [];
        foreach ($tuples as $tuple) {
            $indexes[] = array_combine($indexKeys, $tuple);
        }

        return [$spaces, $indexes];
    }

    public function flushChanges(): void
    {
        $this->middleware->flush();
    }

    public function getChanges(): array
    {
        return $this->middleware->getChanges();
    }

    public function getSpace(int|string $id): Space
    {
        if (!count($this->spaces)) {
            $this->setSchemaId(0);
        }

        return is_string($id) ? $this->getSpace($this->spaceId[$id]) : $this->spaces[$id];
    }

    public function getSpaces(): array
    {
        return array_values($this->spaces);
    }

    public function hasSpace(string $space): bool
    {
        return array_key_exists($space, $this->spaceId);
    }

    public function setSchemaId(int $schemaId)
    {
        if (!$this->schemaId || $this->schemaId !== $schemaId) {
            $this->schemaId = $schemaId;
            if ($this->cache !== null) {
                $item = $this->cache->getItem("schema.$schemaId");
                if (!$item->isHit()) {
                    $item->set($this->fetchSchema());
                }
                $this->cache->save($item);
                [$spaces, $indexes] = $item->get();
            } else {
                [$spaces, $indexes] = $this->fetchSchema();
            }

            $this->spaceId = [];
            foreach ($spaces as $row) {
                if (!array_key_exists($row['id'], $this->spaces)) {
                    $this->spaces[$row['id']] = new Space($this, $row);
                } else {
                    $this->spaces[$row['id']]->setFormat($row['format']);
                }
                $this->spaceId[$row['name']] = $row['id'];
            }

            foreach (array_keys($this->spaces) as $id) {
                if (!array_search($id, $this->spaceId)) {
                    unset($this->spaces[$id]);
                }
            }

            $spaceIndexes = [];
            foreach ($indexes as $row) {
                if (!array_key_exists($row['id'], $spaceIndexes)) {
                    $spaceIndexes[$row['id']] = [$row];
                } else {
                    $spaceIndexes[$row['id']][] = $row;
                }
            }

            foreach ($spaceIndexes as $id => $indexes) {
                $this->spaces[$id]->setIndexes($indexes);
            }
        }
    }
}
