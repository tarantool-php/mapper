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

    public function getLinks($entity, $id, $date)
    {
        $links = $this->getData($entity, $id, $date, '_link_aggregate');
        foreach ($links as $i => $source) {
            $link = array_key_exists(1, $source) ? ['data' => $source[1]] : [];
            foreach ($source[0] as $spaceId => $entityId) {
                $spaceName = $this->mapper->getSchema()->getSpace($spaceId)->getName();
                $link[$spaceName] = $entityId;
            }
            $links[$i] = $link;
        }
        return $links;
    }

    public function getState($entity, $id, $date)
    {
        return $this->getData($entity, $id, $date, '_override_aggregate');
    }

    private function getData($entity, $id, $date, $space)
    {
        if (!$this->mapper->getSchema()->hasSpace($entity)) {
            throw new Exception("invalid entity: " . $entity);
        }

        $entity = $this->mapper->getSchema()->getSpace($entity)->getId();
        $date = $this->getTimestamp($date);

        $rows = $this->mapper->getClient()->getSpace($space)
            ->select([$entity, $id, $date], 0, 1, 0, 4) // [key, index, limit, offset, iterator = LE]
            ->getData();

        if (count($rows)) {
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

        if (!$this->mapper->getSchema()->hasSpace($override['entity'])) {
            throw new Exception("invalid entity " . $override['entity']);
        }

        $space = $this->mapper->getSchema()->getSpace($override['entity']);

        // set entity id
        $override['entity'] = $space->getId();

        $override['actor'] = $this->actor;
        $override['timestamp'] = Carbon::now()->timestamp;

        $this->initSchema('override');
        $this->mapper->create('_override', $override);
        $this->processOverride($override);
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
            $spaceId = $this->mapper->getSchema()->getSpace($entity)->getId();

            $params = [
                'entity'   => $spaceId,
                'entityId' => $id,
                'parent'   => $node ? $node->id : 0,
            ];
            $node = $this->mapper->findOrCreate('_link', $params);
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
            $this->processLink($entity, $id);
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
        foreach ($this->mapper->find('_link', ['parent' => $link->id]) as $child) {
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

    private function initSchema($name)
    {
        switch ($name) {
            case 'override':
                return $this->mapper->getSchema()->once(__CLASS__.'@states', function (Mapper $mapper) {
                    $mapper->getSchema()
                        ->createSpace('_override', [
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
                        ->createSpace('_override_aggregate', [
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
                    if (!$mapper->hasPlugin(Sequence::class)) {
                        $mapper->addPlugin(Sequence::class);
                    }
                    $mapper->getSchema()
                        ->createSpace('_link', [
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
                        ->createSpace('_link_aggregate', [
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

    private function processLink($entity, $id)
    {
        $spaceId = $this->mapper->getSchema()->getSpace($entity)->getId();
        $source = $this->mapper->find('_link', [
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
                    $current = $this->mapper->findOne('_link', $current->parent);
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

    private function processOverride($params)
    {
        $params = [
            'entity' => $params['entity'],
            'id'     => $params['id'],
        ];

        $changeaxis = [];

        foreach ($this->mapper->find('_override', $params) as $i => $override) {
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

    private function updateAggregation($type, $params, $changeaxis)
    {
        $isLink = $type === 'link';
        $space = $isLink ? '_link_aggregate' : '_override_aggregate';

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
}
