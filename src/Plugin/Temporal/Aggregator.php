<?php

namespace Tarantool\Mapper\Plugin\Temporal;

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin\Temporal;

class Aggregator
{
    private $temporal;

    public function __construct(Temporal $temporal)
    {
        $this->temporal = $temporal;
    }

    public function getLeafs($link)
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

    public function updateReferenceState($entity, $id)
    {
        $mapper = $this->temporal->getMapper();

        $params = [
            'entity' => $this->temporal->entityNameToId($entity),
            'id'     => $id,
        ];

        // collect changes

        $states = [];
        $changeaxis = [];
        foreach ($mapper->find('_temporal_reference', $params) as $i => $reference) {
            if (property_exists($reference, 'idle') && $reference->idle) {
                continue;
            }
            if (!array_key_exists($reference->target, $changeaxis)) {
                $changeaxis[$reference->target] = [];
            }
            $changeaxis[$reference->target][] = $reference;
        }

        // calculate states

        $timeaxis = [];
        foreach ($changeaxis as $target => $references) {
            $targetStates = $this->generateSlices($references);
            foreach ($targetStates as $state) {
                foreach ($changeaxis[$target] as $reference) {
                    if ($reference->begin > $state->begin) {
                        // future reference
                        continue;
                    }
                    if ($reference->end && ($reference->end < $state->end || !$state->end)) {
                        // complete reference
                        continue;
                    }
                    $state->targetId = $reference->targetId;
                }
                if (array_key_exists('targetId', $state)) {
                    $states[] = (object) array_merge(get_object_vars($state), $params, ['target' => $target]);
                }
            }
        }

        // merge reference states

        $clean = false;
        while (!$clean) {
            $clean = true;
            foreach ($states as $i => $state) {
                if (array_key_exists($i+1, $states)) {
                    $next = $states[$i+1];
                    if ($state->target == $next->target && $state->targetId == $next->targetId) {
                        $states[$i]['end'] = $next->end;
                        unset($states[$i+1]);
                        $states = array_values($states);
                        $clean = false;
                        break;
                    }
                }
            }
        }

        // update states in database

        $affected = [];
        foreach ($mapper->find('_temporal_reference_state', $params) as $state) {
            $mapper->remove($state);
        }
        foreach ($states as $state) {
            if (!in_array([$state->target, $state->targetId], $affected)) {
                $affected[] = [$state->target, $state->targetId];
            }
            $mapper->create('_temporal_reference_state', $state);
        }


        // update reference aggregation for affected targets
        foreach ($affected as [$entity, $entityId]) {
            // collect changes
            $changeaxis = $mapper->find('_temporal_reference_state', [
                'target' => $entity,
                'targetId' => $entityId,
                'entity' => $params['entity'],
            ]);

            // generate states
            $targetStates = $this->generateSlices($changeaxis);

            $aggregateParams = [
                'entity' => $entity,
                'id' => $entityId,
                'source' => $params['entity']
            ];

            // calculate aggregates
            $aggregates = [];
            foreach ($targetStates as $state) {
                foreach ($changeaxis as $reference) {
                    if ($reference->begin > $state->begin) {
                        // future reference
                        continue;
                    }
                    if ($reference->end && ($reference->end < $state->end || !$state->end)) {
                        // complete reference
                        continue;
                    }
                    if (!in_array($reference->id, $state->data)) {
                        $state->data[] = $reference->id;
                    }
                }
                if (count($state->data)) {
                    $aggregates[] = array_merge(get_object_vars($state), $aggregateParams);
                }
            }

            // sync aggregates
            foreach ($mapper->find('_temporal_reference_aggregate', $aggregateParams) as $aggregate) {
                $mapper->remove($aggregate);
            }

            foreach ($aggregates as $aggregate) {
                $mapper->create('_temporal_reference_aggregate', $aggregate);
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

            $this->updateAggregation('link', $params, $changeaxis);
        }
    }

    public function updateOverrideAggregation($entity, $id)
    {
        $params = [
            'entity' => $this->temporal->entityNameToId($entity),
            'id'     => $id,
        ];

        $changeaxis = [];

        foreach ($this->temporal->getMapper()->find('_temporal_override', $params) as $i => $override) {
            if (property_exists($override, 'idle') && $override->idle) {
                continue;
            }
            if (!array_key_exists($override->begin, $changeaxis)) {
                $changeaxis[$override->begin] = [];
            }
            $changeaxis[$override->begin][] = (object) [
                'begin' => $override->begin,
                'end' => $override->end,
                'data' => $override->data,
            ];
        }

        $this->updateAggregation('override', $params, $changeaxis);
    }

    public function updateAggregation($type, $params, $changeaxis)
    {
        $isLink = $type === 'link';
        $space = $isLink ? '_temporal_link_aggregate' : '_temporal_override_aggregate';

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

        foreach ($this->temporal->getMapper()->find($space, $params) as $state) {
            $this->temporal->getMapper()->remove($state);
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
                    if ($isLink) {
                        $state->data[] = $change->data;
                    } else {
                        $state->data = array_merge($state->data, $change->data);
                    }
                }
            }
            if (count($state->data)) {
                $states[] = (object) array_merge(get_object_vars($state), $params);
            }
        }

        // merge states
        $clean = $isLink;
        while (!$clean) {
            $clean = true;
            foreach ($states as $i => $state) {
                if (array_key_exists($i+1, $states)) {
                    $next = $states[$i+1];
                    if (json_encode($state->data) == json_encode($next->data)) {
                        $states[$i]->end = $next->end;
                        unset($states[$i+1]);
                        $states = array_values($states);
                        $clean = false;
                        break;
                    }
                }
            }
        }

        foreach ($states as $state) {
            $this->temporal->getMapper()->create($space, $state);
        }
    }

    private function generateSlices($changes)
    {
        $slices = [];
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
        return $slices;
    }
}
