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

    public function __construct(Mapper $mapper)
    {
        $this->mapper = $mapper;

        $this->mapper->getSchema()->once(__CLASS__.'_states', function (Mapper $mapper) {
            $mapper->getSchema()
                ->createSpace('_override', [
                    'entity'     => 'unsigned',
                    'id'         => 'unsigned',
                    'begin'      => 'unsigned',
                    'end'        => 'unsigned',
                    'timestamp'  => 'unsigned',
                    'actor'      => 'unsigned',
                    'data'       => '*',
                ])->addIndex([
                    'fields' => ['entity', 'id', 'begin', 'timestamp', 'actor']
                ]);

            $mapper->getSchema()
                ->createSpace('_override_aggregate', [
                    'entity'     => 'unsigned',
                    'id'         => 'unsigned',
                    'begin'      => 'unsigned',
                    'end'        => 'unsigned',
                    'data'       => '*',
                ])
                ->addIndex([
                    'fields' => ['entity', 'id', 'begin'],
                ]);
        });
    }

    public function override(array $override)
    {
        if (!$this->actor) {
            throw new Exception("actor is undefined");
        }

        if (array_key_exists('actor', $override)) {
            throw new Exception("actor override is defined");
        }

        if (array_key_exists('timestamp', $override)) {
            throw new Exception("timestamp override is defined");
        }

        foreach (['begin', 'end'] as $field) {
            if (array_key_exists($field, $override)) {
                if (is_string($override[$field])) {
                    $override[$field] = $this->getTimestamp($override[$field]);
                }
            } else {
                $override[$field] = 0;
            }
        }

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

        $this->mapper->create('_override', $override);
        $this->updateState($override);
    }

    public function setActor($actor)
    {
        $this->actor = $actor;
        return $this;
    }

    public function state($entity, $id, $date)
    {
        if (!$this->mapper->getSchema()->hasSpace($entity)) {
            throw new Exception("invalid entity: " . $entity);
        }

        $entity = $this->mapper->getSchema()->getSpace($entity)->getId();
        $date = $this->getTimestamp($date);

        $rows = $this->mapper->getClient()->getSpace('_override_aggregate')
            ->select([$entity, $id, $date], 0, 1, 0, 4) // [key, index, limit, offset, iterator = LE]
            ->getData();

        if (count($rows)) {
            $state = $this->mapper->findOne('_override_aggregate', [
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

    private function updateState($params)
    {
        $params = [
            'entity' => $params['entity'],
            'id'     => $params['id'],
        ];

        $timeaxis = [];
        $changeaxis = [];

        foreach ($this->mapper->find('_override', $params) as $i => $override) {
            foreach (['begin', 'end'] as $field) {
                if (!array_key_exists($override->$field, $timeaxis)) {
                    $timeaxis[$override->$field] = [
                        'begin' => $override->$field,
                        'end'   => $override->$field,
                        'data'  => [],
                    ];
                }
            }

            if (!array_key_exists($override->timestamp, $changeaxis)) {
                $changeaxis[$override->timestamp] = [];
            }
            $changeaxis[$override->timestamp][] = $override;
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

        foreach ($this->mapper->find('_override_aggregate', $params) as $state) {
            $this->mapper->remove($state);
        }

        $states = [];
        foreach ($timeaxis as $state) {
            foreach ($changeaxis as $overrides) {
                foreach ($overrides as $override) {
                    if ($override->begin > $state['begin']) {
                        // future override
                        continue;
                    }
                    if ($override->end && ($override->end < $state['end'] || !$state['end'])) {
                        // complete override
                        continue;
                    }

                    $state['data'] = array_merge($state['data'], $override->data);
                }
            }
            if (count($state['data'])) {
                $states[] = array_merge($state, $params);
            }
        }

        // merge states
        $clean = false;
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
            $this->mapper->create('_override_aggregate', $state);
        }
    }

    private function getTimestamp($string)
    {
        if (!array_key_exists($string, $this->timestamps)) {
            $this->timestamps[$string] = Carbon::parse($string)->timestamp;
        }
        return $this->timestamps[$string];
    }
}
