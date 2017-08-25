<?php

namespace Tarantool\Mapper\Plugin;

use Carbon\Carbon;
use Exception;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Plugin;

class Temporal extends Plugin
{
    private $actor;
    private $timestamps = [];

    public function getLinksLog($entity, $entityId, $filter = [])
    {
        $this->initSchema('link');

        $entity = $this->entityNameToId($entity);

        $nodes = $this->mapper->find('_temporal_link', [
            'entity' => $entity,
            'entityId' => $entityId,
        ]);

        $links = [];

        foreach ($nodes as $node) {
            foreach ($this->getLeafs($node) as $leaf) {
                $entityName = $this->entityIdToName($leaf->entity);
                $link = [
                    $entityName => $leaf->entityId,
                    'begin'     => $leaf->begin,
                    'end'       => $leaf->end,
                    'timestamp' => $leaf->timestamp,
                    'actor'     => $leaf->actor,
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
        $this->initSchema('link');

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
        $this->initSchema('override');

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
        $override['entity'] = $this->entityNameToId($override['entity']);

        $override['actor'] = $this->actor;
        $override['timestamp'] = Carbon::now()->timestamp;

        $this->initSchema('override');
        $this->mapper->create('_temporal_override', $override);

        $params = [
            'entity' => $override['entity'],
            'id'     => $override['id'],
        ];

        $changeaxis = [];

        foreach ($this->mapper->find('_temporal_override', $params) as $i => $override) {
            if (!array_key_exists($override->timestamp, $changeaxis)) {
                $changeaxis[$override->timestamp] = [];
            }
            $changeaxis[$override->timestamp][] = [
                'begin' => $override->begin,
                'end' => $override->end,
                'data' => $override->data,
            ];
        }

        $this->updateAggregation('override', $params, $changeaxis);
    }

    public function link(array $link)
    {
        $link = $this->parseConfig($link);

        $this->initSchema('link');

        ksort($link);
        $node = null;
        foreach ($link as $entity => $id) {
            if (in_array($entity, ['begin', 'end', 'data'])) {
                continue;
            }
            $spaceId = $this->entityNameToId($entity);

            $params = [
                'entity'   => $spaceId,
                'entityId' => $id,
                'parent'   => $node ? $node->id : 0,
            ];
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

        foreach ($link as $entity => $id) {
            if (in_array($entity, ['begin', 'end', 'data'])) {
                continue;
            }
            $spaceId = $this->entityNameToId($entity);
            $source = $this->mapper->find('_temporal_link', [
                'entity'   => $spaceId,
                'entityId' => $id,
            ]);

            $leafs = [];
            foreach ($source as $node) {
                foreach ($this->getLeafs($node) as $detail) {
                    $leafs[] = $detail;
                }
            }

            $changeaxis = [];

            foreach ($leafs as $leaf) {
                $current = $leaf;
                $ref = [];

                while ($current) {
                    if ($current->entity != $spaceId) {
                        $ref[$current->entity] = $current->entityId;
                    }
                    if ($current->parent) {
                        $current = $this->mapper->findOne('_temporal_link', $current->parent);
                    } else {
                        $current = null;
                    }
                }

                $data = [$ref];
                if (property_exists($leaf, 'data') && $leaf->data) {
                    $data[] = $leaf->data;
                }

                if (!array_key_exists($leaf->timestamp, $changeaxis)) {
                    $changeaxis[$leaf->timestamp] = [];
                }
                $changeaxis[$leaf->timestamp][] = [
                    'begin' => $leaf->begin,
                    'end' => $leaf->end,
                    'data' => $data
                ];
            }

            $params = [
                'entity' => $spaceId,
                'id'     => $id,
            ];

            $this->updateAggregation('link', $params, $changeaxis);
        }
    }

    public function setActor($actor)
    {
        $this->actor = $actor;
        return $this;
    }

    private function getLeafs($link)
    {
        if ($link->timestamp) {
            return [$link];
        }

        $leafs = [];
        foreach ($this->mapper->find('_temporal_link', ['parent' => $link->id]) as $child) {
            foreach ($this->getLeafs($child) as $leaf) {
                $leafs[] = $leaf;
            }
        }
        return $leafs;
    }

    private function getTimestamp($string)
    {
        if (!array_key_exists($string, $this->timestamps)) {
            $this->timestamps[$string] = Carbon::parse($string)->timestamp;
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
            if (array_key_exists($field, $data)) {
                if (is_string($data[$field])) {
                    $data[$field] = $this->getTimestamp($data[$field]);
                }
            } else {
                $data[$field] = 0;
            }
        }

        return $data;
    }

    private function updateAggregation($type, $params, $changeaxis)
    {
        $isLink = $type === 'link';
        $space = $isLink ? '_temporal_link_aggregate' : '_temporal_override_aggregate';

        $timeaxis = [];
        foreach ($changeaxis as $timestamp => $changes) {
            foreach ($changes as $change) {
                foreach (['begin', 'end'] as $field) {
                    if (!array_key_exists($change[$field], $timeaxis)) {
                        $timeaxis[$change[$field]] = [
                            'begin' => $change[$field],
                            'end'   => $change[$field],
                            'data'  => [],
                        ];
                    }
                }
            }
        }

        ksort($changeaxis);
        ksort($timeaxis);

        $nextSliceId = null;
        foreach (array_reverse(array_keys($timeaxis)) as $timestamp) {
            if ($nextSliceId) {
                $timeaxis[$timestamp]['end'] = $nextSliceId;
            } else {
                $timeaxis[$timestamp]['end'] = 0;
            }
            $nextSliceId = $timestamp;
        }

        foreach ($this->mapper->find($space, $params) as $state) {
            $this->mapper->remove($state);
        }

        $states = [];
        foreach ($timeaxis as $state) {
            foreach ($changeaxis as $changes) {
                foreach ($changes as $change) {
                    if ($change['begin'] > $state['begin']) {
                        // future override
                        continue;
                    }
                    if ($change['end'] && ($change['end'] < $state['end'] || !$state['end'])) {
                        // complete override
                        continue;
                    }
                    if ($isLink) {
                        $state['data'][] = $change['data'];
                    } else {
                        $state['data'] = array_merge($state['data'], $change['data']);
                    }
                }
            }
            if (count($state['data'])) {
                $states[] = array_merge($state, $params);
            }
        }

        // merge states
        $clean = $isLink;
        while (!$clean) {
            $clean = true;
            foreach ($states as $i => $state) {
                if (array_key_exists($i+1, $states)) {
                    $next = $states[$i+1];
                    if (!count(array_diff_assoc($state['data'], $next['data']))) {
                        $states[$i]['end'] = $next['end'];
                        unset($states[$i+1]);
                        $states = array_values($states);
                        $clean = false;
                        break;
                    }
                }
            }
        }

        foreach ($states as $state) {
            $this->mapper->create($space, $state);
        }
    }

    private function entityNameToId($name)
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

    private function entityIdToName($id)
    {
        return $this->mapper->findOne('_temporal_entity', compact('id'))->name;
    }

    private function initSchema($name)
    {
        switch ($name) {
            case 'override':
                return $this->mapper->getSchema()->once(__CLASS__.'@states', function (Mapper $mapper) {
                    $mapper->getSchema()
                        ->createSpace('_temporal_override', [
                            'entity'     => 'unsigned',
                            'id'         => 'unsigned',
                            'begin'      => 'unsigned',
                            'end'        => 'unsigned',
                            'timestamp'  => 'unsigned',
                            'actor'      => 'unsigned',
                            'data'       => '*',
                        ])
                        ->addIndex(['entity', 'id', 'begin', 'timestamp', 'actor']);

                    $mapper->getSchema()
                        ->createSpace('_temporal_override_aggregate', [
                            'entity'     => 'unsigned',
                            'id'         => 'unsigned',
                            'begin'      => 'unsigned',
                            'end'        => 'unsigned',
                            'data'       => '*',
                        ])
                        ->addIndex(['entity', 'id', 'begin']);
                });

            case 'link':
                return $this->mapper->getSchema()->once(__CLASS__.'@link', function (Mapper $mapper) {
                    $mapper->getSchema()
                        ->createSpace('_temporal_link', [
                            'id'        => 'unsigned',
                            'parent'    => 'unsigned',
                            'entity'    => 'unsigned',
                            'entityId'  => 'unsigned',
                            'begin'     => 'unsigned',
                            'end'       => 'unsigned',
                            'timestamp' => 'unsigned',
                            'actor'     => 'unsigned',
                            'data'      => '*',
                        ])
                        ->addIndex(['id'])
                        ->addIndex(['entity', 'entityId', 'parent', 'begin', 'timestamp', 'actor'])
                        ->addIndex([
                            'fields' => 'parent',
                            'unique' => false,
                        ]);

                    $mapper->getSchema()
                        ->createSpace('_temporal_link_aggregate', [
                            'entity' => 'unsigned',
                            'id'     => 'unsigned',
                            'begin'  => 'unsigned',
                            'end'    => 'unsigned',
                            'data'   => '*',
                        ])
                        ->addIndex(['entity', 'id', 'begin']);
                });
        }

        throw new Exception("Invalid schema $name");
    }
}
