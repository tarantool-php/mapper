<?php

namespace Tarantool\Mapper\Plugin;

use Carbon\Carbon;
use Exception;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Plugin\Temporal\Aggregator;
use Tarantool\Mapper\Plugin\Temporal\Schema;

class Temporal extends Plugin
{
    private $actor;
    private $timestamps = [];
    private $aggregator;

    public function __construct(Mapper $mapper)
    {
        $this->mapper = $mapper;
        $this->schema = new Schema($mapper);
        $this->aggregator = new Aggregator($this);
    }

    public function getLinksLog($entity, $entityId, $filter = [])
    {
        $this->schema->init('link');

        $entity = $this->entityNameToId($entity);

        $nodes = $this->mapper->find('_temporal_link', [
            'entity' => $entity,
            'entityId' => $entityId,
        ]);

        $links = [];

        foreach ($nodes as $node) {
            foreach ($this->aggregator->getLeafs($node) as $leaf) {
                $entityName = $this->entityIdToName($leaf->entity);
                $link = [
                    $entityName => $leaf->entityId,
                    'id'        => $leaf->id,
                    'begin'     => $leaf->begin,
                    'end'       => $leaf->end,
                    'timestamp' => $leaf->timestamp,
                    'actor'     => $leaf->actor,
                    'idle'      => property_exists($leaf, 'idle') ? $leaf->idle : 0,
                ];

                $current = $leaf;
                while ($current->parent) {
                    $current = $this->mapper->findOne('_temporal_link', $current->parent);
                    $entityName = $this->entityIdToName($current->entity);
                    $link[$entityName] = $current->entityId;
                }

                if (count($filter)) {
                    foreach ($filter as $required) {
                        if (!array_key_exists($required, $link)) {
                            continue 2;
                        }
                    }
                }
                $links[] = $link;
            }
        }

        return $links;
    }

    public function getLinks($entity, $id, $date)
    {
        $this->schema->init('link');

        $links = $this->getData($entity, $id, $date, '_temporal_link_aggregate');
        foreach ($links as $i => $source) {
            $link = array_key_exists(1, $source) ? ['data' => $source[1]] : [];
            foreach ($source[0] as $spaceId => $entityId) {
                $spaceName = $this->mapper->findOne('_temporal_entity', $spaceId)->name;
                $link[$spaceName] = $entityId;
            }
            $links[$i] = $link;
        }
        return $links;
    }

    public function getState($entity, $id, $date)
    {
        $this->schema->init('override');

        return $this->getData($entity, $id, $date, '_temporal_override_aggregate');
    }

    private function getData($entity, $id, $date, $space)
    {
        $entity = $this->entityNameToId($entity);
        $date = $this->getTimestamp($date);

        $rows = $this->mapper->getClient()->getSpace($space)
            ->select([$entity, $id, $date], 0, 1, 0, 4) // [key, index, limit, offset, iterator = LE]
            ->getData();

        if (count($rows) && $rows[0][0] == $entity && $rows[0][1] == $id) {
            $state = $this->mapper->findOne($space, [
                'entity' => $entity,
                'id' => $id,
                'begin' => $rows[0][2]
            ]);
            if (!$state->end || $state->end >= $date) {
                return $state->data;
            }
        }

        return [];
    }

    public function getOverrides($entityName, $id)
    {
        return $this->mapper->find('_temporal_override', [
            'entity' => $this->entityNameToId($entityName),
            'id' => $id,
        ]);
    }

    public function override(array $override)
    {
        $override = $this->parseConfig($override);

        foreach ($override as $k => $v) {
            if (!in_array($k, ['entity', 'id', 'begin', 'end', 'data'])) {
                $override['entity'] = $k;
                $override['id'] = $v;
                unset($override[$k]);
            }
        }

        if (!array_key_exists('entity', $override)) {
            throw new Exception("no entity defined");
        }

        // set entity id
        $entityName = $override['entity'];
        $override['entity'] = $this->entityNameToId($entityName);
        $override['actor'] = $this->actor;
        $override['timestamp'] = Carbon::now()->timestamp;

        $this->schema->init('override');
        $this->mapper->create('_temporal_override', $override);

        $this->aggregator->updateOverrideAggregation($entityName, $override['id']);
    }

    public function setLinkIdle($id, $flag)
    {
        $link = $this->mapper->findOrFail('_temporal_link', $id);

        $idled = property_exists($link, 'idle') && $link->idle > 0;
        if ($idled && !$flag || !$idled && $flag) {
            return $this->toggleLinkIdle($link);
        }
    }

    public function toggleLinkIdle(Entity $link)
    {
        if (property_exists($link, 'idle') && $link->idle) {
            $link->idle = 0;
        } else {
            $link->idle = time();
        }
        $link->save();

        $this->aggregator->updateLinkAggregation($link);
    }

    public function setOverrideIdle($entity, $id, $begin, $actor, $timestamp, $flag)
    {
        $override = $this->mapper->findOrFail('_temporal_override', [
            'entity' => $this->entityNameToId($entity),
            'id' => $id,
            'begin' => $begin,
            'actor' => $actor,
            'timestamp' => $timestamp,
        ]);
        $idled = property_exists($override, 'idle') && $override->idle > 0;
        if ($idled && !$flag || !$idled && $flag) {
            return $this->toggleOverrideIdle($entity, $id, $begin, $actor, $timestamp);
        }
    }

    public function toggleOverrideIdle($entity, $id, $begin, $actor, $timestamp)
    {
        $override = $this->mapper->findOrFail('_temporal_override', [
            'entity' => $this->entityNameToId($entity),
            'id' => $id,
            'begin' => $begin,
            'actor' => $actor,
            'timestamp' => $timestamp,
        ]);

        if (property_exists($override, 'idle') && $override->idle) {
            $override->idle = 0;
        } else {
            $override->idle = time();
        }
        $override->save();

        $this->aggregator->updateOverrideAggregation($entity, $id);
    }


    public function link(array $link)
    {
        $link = $this->parseConfig($link);

        $this->schema->init('link');

        $config = [];
        foreach ($link as $entity => $id) {
            if (!in_array($entity, ['begin', 'end', 'data'])) {
                $config[$entity] = $id;
            }
        }

        ksort($config);
        $node = null;

        foreach (array_keys($config) as $i => $entity) {
            $id = $config[$entity];
            $spaceId = $this->entityNameToId($entity);
            $params = [
                'entity'   => $spaceId,
                'entityId' => $id,
                'parent'   => $node ? $node->id : 0,
            ];
            if (count($config) == $i+1) {
                $params['begin'] = $link['begin'];
                $params['timestamp'] = 0;
            }
            $node = $this->mapper->findOrCreate('_temporal_link', $params);
        }

        if (!$node || !$node->parent) {
            throw new Exception("Invalid link configuration");
        }

        $node->begin = $link['begin'];
        $node->end = $link['end'];
        $node->actor = $this->actor;
        $node->timestamp = Carbon::now()->timestamp;
        if (array_key_exists('data', $link)) {
            $node->data = $link['data'];
        }

        $node->save();

        $this->aggregator->updateLinkAggregation($node);
    }

    public function setActor($actor)
    {
        $this->actor = $actor;
        return $this;
    }

    private function getTimestamp($string)
    {
        if (!array_key_exists($string, $this->timestamps)) {
            if (strlen($string) == 8 && is_numeric($string)) {
                $this->timestamps[$string] = Carbon::createFromFormat('Ymd', $string)->setTime(0, 0, 0)->timestamp;
            } else {
                $this->timestamps[$string] = Carbon::parse($string)->timestamp;
            }
        }
        return $this->timestamps[$string];
    }

    private function parseConfig(array $data)
    {
        if (!$this->actor) {
            throw new Exception("actor is undefined");
        }

        if (array_key_exists('actor', $data)) {
            throw new Exception("actor is defined");
        }

        if (array_key_exists('timestamp', $data)) {
            throw new Exception("timestamp is defined");
        }

        foreach (['begin', 'end'] as $field) {
            if (array_key_exists($field, $data) && strlen($data[$field])) {
                if (strlen($data[$field]) == 8 || is_string($data[$field])) {
                    $data[$field] = $this->getTimestamp($data[$field]);
                }
            } else {
                $data[$field] = 0;
            }
        }

        return $data;
    }

    public function entityNameToId($name)
    {
        if (!$this->mapper->hasPlugin(Sequence::class)) {
            $this->mapper->addPlugin(Sequence::class);
        }

        $this->mapper->getSchema()->once(__CLASS__.'@entity', function (Mapper $mapper) {
            $this->mapper->getSchema()
                ->createSpace('_temporal_entity', [
                    'id'   => 'unsigned',
                    'name' => 'str',
                ])
                ->addIndex(['id'])
                ->addIndex(['name']);
        });

        return $this->mapper->findOrCreate('_temporal_entity', compact('name'))->id;
    }

    public function entityIdToName($id)
    {
        return $this->mapper->findOne('_temporal_entity', compact('id'))->name;
    }
}
