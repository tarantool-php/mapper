<?php

namespace Tarantool\Mapper\Plugin;

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Space;

class NestedSet extends Plugin
{
    private $keys = ['id', 'parent', 'root', 'depth', 'left', 'right'];
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
            ['root', 'left'],
            ['root', 'right'],
        ];

        foreach ($indexes as $index) {
            $fields = array_key_exists('fields', $index) ? $index['fields'] : $index;
            if ($space->castIndex(array_flip($fields), true) === null) {
                $space->createIndex($index);
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
            foreach ($repository->find(['root' => $entity->root]) as $node) {
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
            // new root
            $map = $space->getTupleMap();
            $spaceName = $space->getName();

            $entity->root = $entity->root ?: 0;
            $max = $this->mapper->getClient()->evaluate("
                local max = 0
                local root = $entity->root
                for i, n in box.space.$spaceName.index.root_right:pairs(root, {iterator = 'le'}) do
                    if n[$map->root] == root then
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
            for i, current in box.space.$spaceName.index.root_left:pairs({removed_node[$map->root], removed_node[$map->left]}, 'gt') do
                if current[$map->root] ~= removed_node[$map->root] then
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
