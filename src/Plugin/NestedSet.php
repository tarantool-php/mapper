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

            $parent = $repository->findOne($entity->parent);
            $level_up = $parent ? $parent->depth+1 : 0;

            $left_key = $entity->left;
            $right_key = $entity->right;

            $client = $this->mapper->getClient();
            $map = $space->getTupleMap();

            $old_entity = $client->getSpace($space->getId())->select([$entity->id])->getData()[0];
            $old_parent = $repository->findOne($old_entity[$map->parent-1]);
            $old_parent_id = $old_parent ? $old_parent->id : 0;

            if ($old_parent_id != $entity->parent) {
                $right_key_near = 0;
                if (!$entity->parent) {
                    foreach ($repository->find(['parent' => 0]) as $node) {
                        if ($node->right > $right_key_near) {
                            $right_key_near = $node->right;
                        }
                    }
                } else {
                    $right_key_near = $parent->left;
                }

                $skew_tree = $right_key - $left_key + 1;
                $skew_edit = $right_key_near - $left_key + 1;
                $skew_level = $level_up - $entity->depth;

                $spaceName = $space->getName();

                if ($right_key < $right_key_near) {
                    $skew_edit -= $skew_tree;
                }
                $result = $this->mapper->getClient()->evaluate("
                    local result = {}
                    local updates = {}
                    local leftKeys = {}
                    local rightKeys = {}
                    local maxRightTuple = box.space.$spaceName.index.group_right:max(right);
                    local maxLeftTuple = box.space.$spaceName.index.group_left:max(left);
                    local maxRight = 100
                    if maxRightTuple ~= nil then
                        maxRight = maxRightTuple[$map->right]+100
                    end
                    local maxLeft = 100
                    if maxLeftTuple ~= nil then
                        maxLeft = maxLeftTuple[$map->left]+100
                    end
                    box.begin()
                    for i, node in box.space.$spaceName:pairs() do
                        if $right_key < $right_key_near then
                            if node[$map->right] > $left_key and node[$map->left] <= $right_key_near then
                                if node[$map->right] <= $right_key then
                                    table.insert(updates, {node[$map->id], $map->left, node[$map->left]+$skew_edit})
                                    box.space.$spaceName:update(node[$map->id], {{'=', $map->left, maxLeft}})
                                    maxLeft = maxLeft+1
                                elseif node[$map->left] > $right_key then
                                    table.insert(updates, {node[$map->id], $map->left, node[$map->left]-$skew_tree})
                                    box.space.$spaceName:update(node[$map->id], {{'=', $map->left, maxLeft}})
                                    maxLeft = maxLeft+1
                                end
                                if node[$map->right] <= $right_key then
                                    table.insert(updates, {node[$map->id], $map->depth, node[$map->depth]+$skew_level})
                                end
                                if node[$map->right] <= $right_key then
                                    table.insert(updates, {node[$map->id], $map->right, node[$map->right]+$skew_edit})
                                    box.space.$spaceName:update(node[$map->id], {{'=', $map->right, maxRight}})
                                    maxRight = maxRight+1
                                elseif node[$map->right] <= $right_key_near then
                                    table.insert(updates, {node[$map->id], $map->right, node[$map->right]-$skew_tree})
                                    box.space.$spaceName:update(node[$map->id], {{'=', $map->right, maxRight}})
                                    maxRight = maxRight+1
                                end
                            end
                        else
                            if node[$map->right] > $right_key_near and node[$map->left] < $right_key then
                                if node[$map->left] >= $left_key then
                                    table.insert(updates, {node[$map->id], $map->right, node[$map->right]+$skew_edit})
                                    box.space.$spaceName:update(node[$map->id], {{'=', $map->right, maxRight}})
                                    maxRight = maxRight+1
                                elseif node[$map->right] < $left_key then
                                    table.insert(updates, {node[$map->id], $map->right, node[$map->right]+$skew_tree})
                                    box.space.$spaceName:update(node[$map->id], {{'=', $map->right, maxRight}})
                                    maxRight = maxRight+1
                                end
                                if node[$map->left] >= $left_key then
                                    table.insert(updates, {node[$map->id], $map->depth, node[$map->depth]+$skew_level})
                                end
                                if node[$map->left] >= $left_key then
                                    table.insert(updates, {node[$map->id], $map->left, node[$map->left]+$skew_edit})
                                    box.space.$spaceName:update(node[$map->id], {{'=', $map->left, maxLeft}})
                                    maxLeft = maxLeft+1
                                elseif node[$map->left] > $right_key_near then
                                    table.insert(updates, {node[$map->id], $map->left, node[$map->left]+$skew_tree})
                                    box.space.$spaceName:update(node[$map->id], {{'=', $map->left, maxLeft}})
                                    maxLeft = maxLeft+1
                                end
                            end
                        end
                    end
                    for i, node in pairs(updates) do
                        table.insert(result, node[1])
                        box.space.$spaceName:update(node[1], {{'=', node[2], node[3]}})
                    end
                    box.commit()

                    return result
                ")->getData();

                foreach (array_unique($result[0]) as $id) {
                    $space->getRepository()->sync($id);
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
