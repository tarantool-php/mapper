<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Plugin\Temporal;

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin\Temporal;

class Aggregator
{
    private $temporal;
    private $createReferenceAggregate = true;

    public function __construct(Temporal $temporal)
    {
        $this->temporal = $temporal;
    }

    public function setReferenceAggregation(bool $value): self
    {
        $this->createReferenceAggregate = $value;
        return $this;
    }

    public function getLeafs(Entity $link): array
    {
        if ($link->timestamp) {
            return [$link];
        }

        $leafs = [];
        foreach ($this->temporal->getMapper()->find('_temporal_link', ['parent' => $link->id]) as $child) {
            foreach ($this->getLeafs($child) as $leaf) {
                $leafs[] = $leaf;
            }
        }
        return $leafs;
    }

    public function updateReferenceState(string $entity, int $id, string $target)
    {
        $mapper = $this->temporal->getMapper();

        $params = [
            'entity' => $this->temporal->entityNameToId($entity),
            'id'     => $id,
            'target' => $this->temporal->entityNameToId($target),
        ];

        $changes = $mapper->find('_temporal_reference', $params);
        $states = $this->generateStates($changes, function ($state, $change) {
            $state->data = $change->targetId;
        });

        $affected = [];
        foreach ($mapper->find('_temporal_reference_state', $params) as $state) {
            $mapper->remove($state);
        }

        foreach ($states as $state) {
            $entity = $mapper->create('_temporal_reference_state', array_merge($params, [
                'begin' => $state->begin,
                'end' => $state->end,
                'targetId' => $state->data,
            ]));
            if (!in_array([$entity->target, $entity->targetId], $affected)) {
                $affected[] = [$entity->target, $entity->targetId];
            }
        }

        if (!$this->createReferenceAggregate) {
            return $affected;
        }

        foreach ($affected as $affect) {
            list($entity, $entityId) = $affect;
            $changes = $mapper->find('_temporal_reference_state', [
                'target' => $entity,
                'targetId' => $entityId,
                'entity' => $params['entity'],
            ]);
            $aggregates = $this->generateStates($changes, function ($state, $change) {
                if (!in_array($change->id, $state->data)) {
                    $state->data[] = $change->id;
                }
                $state->exists = false;
            });

            $aggregateParams = [
                'entity' => $entity,
                'id' => $entityId,
                'source' => $params['entity']
            ];
            foreach ($mapper->find('_temporal_reference_aggregate', $aggregateParams) as $aggregate) {
                foreach ($aggregates as $candidate) {
                    if ($candidate->begin == $aggregate->begin && $candidate->end == $aggregate->end) {
                        if ($candidate->data == $aggregate->data) {
                            $candidate->exists = true;
                            continue 2;
                        }
                    }
                }
                $mapper->remove($aggregate);
            }
            foreach ($aggregates as $aggregate) {
                if ($aggregate->exists) {
                    continue;
                }
                $mapper->create('_temporal_reference_aggregate', array_merge($aggregateParams, [
                    'begin' => $aggregate->begin,
                    'end' => $aggregate->end,
                    'data' => $aggregate->data,
                ]));
            }
        }
    }


    public function updateLinkAggregation(Entity $node)
    {
        $todo = [
            $this->temporal->entityIdToName($node->entity) => $node->entityId,
        ];

        $current = $node;
        while ($current->parent) {
            $current = $this->temporal->getMapper()->findOne('_temporal_link', ['id' => $current->parent]);
            $todo[$this->temporal->entityIdToName($current->entity)] = $current->entityId;
        }

        foreach ($todo as $entity => $id) {
            $spaceId = $this->temporal->entityNameToId($entity);
            $source = $this->temporal->getMapper()->find('_temporal_link', [
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

                if (property_exists($leaf, 'idle') && $leaf->idle) {
                    continue;
                }

                while ($current) {
                    if ($current->entity != $spaceId) {
                        $ref[$current->entity] = $current->entityId;
                    }
                    if ($current->parent) {
                        $current = $this->temporal->getMapper()->findOne('_temporal_link', $current->parent);
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
                $changeaxis[$leaf->timestamp][] = (object) [
                    'begin' => $leaf->begin,
                    'end' => $leaf->end,
                    'data' => $data
                ];
            }

            $params = [
                'entity' => $spaceId,
                'id'     => $id,
            ];

            $timeaxis = [];
            foreach ($changeaxis as $timestamp => $changes) {
                foreach ($changes as $change) {
                    foreach (['begin', 'end'] as $field) {
                        if (!array_key_exists($change->$field, $timeaxis)) {
                            $timeaxis[$change->$field] = (object) [
                                'begin' => $change->$field,
                                'end'   => $change->$field,
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
                    $timeaxis[$timestamp]->end = $nextSliceId;
                } else {
                    $timeaxis[$timestamp]->end = 0;
                }
                $nextSliceId = $timestamp;
            }

            $states = [];
            foreach ($timeaxis as $state) {
                foreach ($changeaxis as $changes) {
                    foreach ($changes as $change) {
                        if ($change->begin > $state->begin) {
                            // future override
                            continue;
                        }
                        if ($change->end && ($change->end < $state->end || !$state->end)) {
                            // complete override
                            continue;
                        }
                        $state->data[] = $change->data;
                    }
                }
                if (count($state->data)) {
                    $states[] = (object) array_merge(get_object_vars($state), $params);
                }
            }

            // merge states
            $clean = false;
            while (!$clean) {
                $clean = true;
                foreach ($states as $i => $state) {
                    if (array_key_exists($i + 1, $states)) {
                        $next = $states[$i + 1];
                        if (json_encode($state->data) == json_encode($next->data)) {
                            $states[$i]->end = $next->end;
                            unset($states[$i + 1]);
                            $states = array_values($states);
                            $clean = false;
                            break;
                        }
                    }
                }
            }

            foreach ($this->temporal->getMapper()->find('_temporal_link_aggregate', $params) as $state) {
                $this->temporal->getMapper()->remove($state);
            }

            foreach ($states as $state) {
                $this->temporal->getMapper()->create('_temporal_link_aggregate', $state);
            }
        }
    }

    public function updateOverrideAggregation($entity, $id)
    {
        $mapper = $this->temporal->getMapper();
        $params = [
            'entity' => $this->temporal->entityNameToId($entity),
            'id'     => $id,
        ];

        $changes = $mapper->find('_temporal_override', $params);
        $states = $this->generateStates($changes, function ($state, $change) {
            $state->data = array_merge($state->data, $change->data);
            $state->exists = false;
        });
        foreach ($mapper->find('_temporal_override_aggregate', $params) as $aggregate) {
            foreach ($states as $state) {
                if ($state->begin == $aggregate->begin && $state->end == $aggregate->end) {
                    if ($state->data == $aggregate->data) {
                        $state->exists = true;
                        continue 2;
                    }
                }
            }
            $mapper->remove($aggregate);
        }
        foreach ($states as $aggregate) {
            if ($aggregate->exists) {
                continue;
            }
            $mapper->create('_temporal_override_aggregate', array_merge($params, [
                'begin' => $aggregate->begin,
                'end' => $aggregate->end,
                'data' => $aggregate->data,
            ]));
        }
    }

    private function generateStates($changes, $callback)
    {
        $slices = [];
        foreach ($changes as $i => $change) {
            if (property_exists($change, 'idle') && $change->idle) {
                unset($changes[$i]);
            }
        }
        foreach ($changes as $change) {
            foreach (['begin', 'end'] as $field) {
                if (!array_key_exists($change->$field, $slices)) {
                    $slices[$change->$field] = (object) [
                        'begin'  => $change->$field,
                        'end'    => $change->$field,
                        'data'   => [],
                    ];
                }
            }
        }
        ksort($slices);

        $nextSliceId = null;
        foreach (array_reverse(array_keys($slices)) as $timestamp) {
            if ($nextSliceId) {
                $slices[$timestamp]->end = $nextSliceId;
            } else {
                $slices[$timestamp]->end = 0;
            }
            $nextSliceId = $timestamp;
        }

        // calculate states
        $states = [];
        foreach ($slices as $slice) {
            foreach ($changes as $change) {
                if ($change->begin > $slice->begin) {
                    // future change
                    continue;
                }
                if ($change->end && ($change->end < $slice->end || !$slice->end)) {
                    // complete change
                    continue;
                }
                $callback($slice, $change);
            }
            if (count((array) $slice->data)) {
                $states[] = $slice;
            }
        }

        // merge states
        $clean = false;
        while (!$clean) {
            $clean = true;
            foreach ($states as $i => $state) {
                if (array_key_exists($i + 1, $states)) {
                    $next = $states[$i + 1];
                    if ($state->end && $state->end < $next->begin) {
                        // unmergable
                        continue;
                    }
                    if (json_encode($state->data) == json_encode($next->data)) {
                        $state->end = $next->end;
                        unset($states[$i + 1]);
                        $states = array_values($states);
                        $clean = false;
                        break;
                    }
                }
            }
        }

        return $states;
    }
}
