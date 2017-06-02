<?php

namespace Tarantool\Mapper\Plugin;

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Space;

class NestedSet extends Plugin
{
    private $keys = ['id', 'parent', 'group', 'depth', 'left', 'right'];
    private $nestedSpaces = [];

    public function __construct(Mapper $mapper)
    {
        $this->mapper = $mapper;
    }

    public function addIndexes(Space $space)
    {
        $indexes = [
            ['id'],
            [
                'fields' => ['parent'],
                'unique' => false,
            ],
            ['group', 'left'],
            ['group', 'right'],
        ];

        foreach ($indexes as $index) {
            $fields = array_key_exists('fields', $index) ? $index['fields'] : $index;
            if ($space->castIndex(array_flip($fields), true) === null) {
                $space->createIndex($index);
            }
        }
    }

    public function beforeUpdate(Entity $entity, Space $space)
    {
        if ($this->isNested($space)) {
            $repository = $space->getRepository();
            $spaceName = $space->getName();

            $parent = $repository->findOne($entity->parent);

            $client = $this->mapper->getClient();
            $map = $space->getTupleMap();

            $old_entity = $repository->getOriginal($entity);

            foreach (['group'] as $field) {
                if ($old_entity[$map->$field-1] != $entity->$field) {
                    throw new \Exception(ucfirst($field)." can't be changed");
                }
            }

            if ($old_entity[$map->parent-1] != $entity->parent) {
                $leftValue = $entity->left;
                $rightValue = $entity->right;
                $toRoot = $entity->parent == 0 ? 1 : 0;

                $value = 0;
                $delta = 0;
                $depth = 0;
                if ($entity->parent) {
                    $value = $parent->right;
                    $depth = $parent->depth - $entity->depth + 1;
                    $delta = $rightValue - $leftValue + 1;
                }

                $right_key_near = 0;
                if (!$entity->parent) {
                    foreach ($repository->find(['group' => $entity->group]) as $node) {
                        if (!$node->parent && $node->right > $right_key_near) {
                            $right_key_near = $node->right;
                        }
                    }
                }
                $skew_tree = $rightValue - $leftValue + 1;
                $skew_edit = $right_key_near - $leftValue + 1;

                $spaceName = $space->getName();

                if ($rightValue < $right_key_near) {
                    $skew_edit -= $skew_tree;
                }

                $result = $this->mapper->getClient()->evaluate("
                    local result = {}
                    local updates = {}
                    local maxRightTuple = box.space.$spaceName.index.group_right:max(right);
                    local maxLeftTuple = box.space.$spaceName.index.group_left:max(left);
                    local maxValue = 100
                    if maxRightTuple ~= nil then
                        maxValue = maxValue + maxRightTuple[$map->right]
                    end
                    if maxLeftTuple ~= nil then
                        maxValue = maxValue + maxLeftTuple[$map->left]
                    end

                    local leftValue = $entity->left
                    local rightValue = $entity->right

                    if $leftValue >= $value then
                        leftValue = leftValue + ($delta)
                        rightValue = rightValue + ($delta)
                    end
                    local left
                    local right

                    box.begin()
                    for i, node in box.space.$spaceName.index.group_right:pairs({{$entity->group}, 1}, 'ge') do
                        if node[$map->group] ~= $entity->group then
                            break
                        end
                        left = node[$map->left]
                        right = node[$map->right]
                        if $toRoot == 1 then
                            if left >= $leftValue and right <= $rightValue then
                                if node[$map->id] ~= $entity->id then
                                    table.insert(updates, {node[$map->id], $map->left, left + 1 - $leftValue})
                                    table.insert(updates, {node[$map->id], $map->right, right + 1 - $leftValue})
                                else
                                    table.insert(updates, {node[$map->id], $map->right, right + $skew_edit})
                                    table.insert(updates, {node[$map->id], $map->left, left + $skew_edit})
                                end
                                table.insert(updates, {node[$map->id], $map->depth, node[$map->depth] - $entity->depth})
                            end
                            if left >= $rightValue + 1 then
                                table.insert(updates, {node[$map->id], $map->left, left + $leftValue - $rightValue - 1})
                            end
                            if right >= $rightValue + 1 then
                                table.insert(updates, {node[$map->id], $map->right, right + $leftValue - $rightValue - 1})
                            end
                        else
                            if left >= $value then
                                left = left + ($delta)
                                table.insert(updates, {node[$map->id], $map->left, left})
                            end
                            if right >= $value then
                                right = right + ($delta)
                                table.insert(updates, {node[$map->id], $map->right, right})
                            end

                            if left >= leftValue and right <= rightValue then
                                table.insert(updates, {node[$map->id], $map->depth, node[$map->depth] + $depth})
                            end

                            if left >= leftValue and left <= rightValue then
                                left = left + $value - leftValue
                                table.insert(updates, {node[$map->id], $map->left, left})
                            end
                            if right >= leftValue and right <= rightValue then
                                right = right + $value - leftValue
                                table.insert(updates, {node[$map->id], $map->right, right})
                            end
                            if left >= rightValue + 1 then
                                left = left-($delta)
                                table.insert(updates, {node[$map->id], $map->left, left})
                            end
                            if right >= rightValue + 1 then
                                right = right-($delta)
                                table.insert(updates, {node[$map->id], $map->right, right})
                            end
                        end
                    end
                    for i, node in pairs(updates) do
                        box.space.$spaceName:update(node[1], {{'=', node[2], maxValue}})
                        maxValue = maxValue + 1
                    end
                    for i, node in pairs(updates) do
                        table.insert(result, node[1])
                        box.space.$spaceName:update(node[1], {{'=', node[2], node[3]}})
                    end
                    box.commit()

                    return result
                ")->getData();

                foreach (array_unique($result[0]) as $id) {
                    $space->getRepository()->sync($id, ['left', 'right', 'depth']);
                }

                $space->getRepository()->flushCache();
            }
        }
    }

    public function beforeCreate(Entity $entity, Space $space)
    {
        if (!$this->isNested($space)) {
            return;
        }
        $repository = $space->getRepository();

        if ($entity->parent) {
            $parent = $repository->findOne($entity->parent);
            $entity->depth = $parent->depth + 1;

            $updateLeft = [];
            $updateRight = [];
            foreach ($repository->find(['group' => $entity->group]) as $node) {
                if ($node->right >= $parent->right) {
                    if ($node->left > $parent->right) {
                        $updateLeft[$node->left] = $node;
                    }
                    $updateRight[$node->right] = $node;
                }
            }

            $entity->left = $parent->right;
            $entity->right = $entity->left + 1;

            krsort($updateRight);
            foreach ($updateRight as $node) {
                $node->right += 2;
                $node->save();
            }

            krsort($updateLeft);
            foreach ($updateLeft as $node) {
                $node->left += 2;
                $node->save();
            }
        } else {
            // new group
            $map = $space->getTupleMap();
            $spaceName = $space->getName();

            $entity->group = $entity->group ?: 0;
            $max = $this->mapper->getClient()->evaluate("
                local max = 0
                local group = $entity->group
                for i, n in box.space.$spaceName.index.group_right:pairs(group, {iterator = 'le'}) do
                    if n[$map->group] == group then
                        max = n[$map->right]
                    end
                    break
                end
                return max
            ")->getData()[0];

            $entity->left = $max + 1;
            $entity->right = $entity->left + 1;
        }
    }

    public function beforeRemove(Entity $instance, Space $space)
    {
        if (!$this->isNested($space)) {
            return;
        }

        $spaceName = $space->getName();
        $map = $space->getTupleMap();

        $result = $this->mapper->getClient()->evaluate("
            local removed_node = box.space.$spaceName:get($instance->id)
            local remove_list = {}
            local update_list = {}
            for i, current in box.space.$spaceName.index.group_left:pairs({removed_node[$map->group], removed_node[$map->left]}, 'gt') do
                if current[$map->group] ~= removed_node[$map->group] then
                    break
                end
                if current[$map->left] < removed_node[$map->right] then
                    table.insert(remove_list, current[$map->id])
                else
                    table.insert(update_list, current[$map->id])
                end
            end

            local delta = removed_node[$map->right] - removed_node[$map->left] + 1

            for i, id in ipairs(remove_list) do
                box.space.$spaceName:delete(id)
            end

            box.space.$spaceName:update($instance->id, {
                {'=', $map->left, 0},
                {'=', $map->right, 0},
            })

            for i, id in pairs(update_list) do
                box.space.$spaceName:update(id, {
                    {'-', $map->left, delta},
                    {'-', $map->right, delta}
                })
            end

            return remove_list, update_list, delta, removed_node
        ")->getData();

        // remove
        foreach ($result[0] as $id) {
            $space->getRepository()->forget($id);
        }

        // update
        foreach ($result[1] as $id) {
            $space->getRepository()->sync($id);
        }

        $space->getRepository()->flushCache();
    }

    public function isNested(Space $space, $force = false)
    {
        $spaceName = $space->getName();
        if ($force || !array_key_exists($spaceName, $this->nestedSpaces)) {
            $fields = [];
            foreach ($space->getFormat() as $field) {
                $fields[] = $field['name'];
            }

            $this->nestedSpaces[$spaceName] = !count(array_diff($this->keys, $fields));
        }

        return $this->nestedSpaces[$spaceName];
    }

    public function resetNestedSpacesCache()
    {
        $this->nestedSpaces = [];
    }
}
