<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Exception;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;
use ReflectionMethod;
use Tarantool\Client\Exception\RequestFailed;
use Tarantool\Client\Keys;
use Tarantool\Client\Request\InsertRequest;
use Tarantool\Client\Response;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;
use ValueError;

class Space
{
    public ?CacheItemPoolInterface $cache = null;

    private readonly int $id;
    private readonly string $name;

    private array $fields = [];
    private array $format = [];
    private array $indexes = [];

    private ?string $class = null;
    private ?string $init = null;
    private ?ReflectionMethod $constructor = null;

    public function __construct(public readonly Mapper $mapper, array $meta = [])
    {
        $this->id = $meta['id'];
        $this->name = $meta['name'];
        $this->setFormat($meta['format']);
    }

    public function addIndex(array $fields, array $config = [])
    {
        $config['parts'] = [];
        foreach ($fields as $field) {
            if (!in_array($field, $this->fields)) {
                throw new Exception("Invalid field $field");
            }
            $config['parts'][] = [
                'field' => $field,
                'type' => $this->format[array_search($field, $this->fields)]['type'],
            ];
        }
        if (!array_key_exists('unique', $config)) {
            $config['unique'] = true;
        }
        if (!array_key_exists('if_not_exists', $config)) {
            $config['if_not_exists'] = true;
        }
        $name = implode('_', $fields);
        $this->mapper->client->call("box.space.$this->name:create_index", $name, $config);
    }

    public function addProperty(string $name, string $type, array $config = [])
    {
        $config = array_merge(compact('name', 'type'), $config);

        if (!count($this->fields)) {
            $config['is_nullable'] = false;
        } elseif (in_array($name, $this->fields)) {
            throw new Exception("Duplicate property $name");
        }

        $this->format[] = $config;
        $this->mapper->client->call("box.space.$this->name:format", $this->format);

        if (count($this->fields) == 1) {
            $this->addIndex($this->fields, [
                'unique' => true,
            ]);
            if ($this->fields[0] == 'id') {
                $this->mapper->client->call('box.schema.sequence.create', $this->name);
            }
        }
    }

    public function castIndex(array $fields): ?array
    {
        foreach ($this->indexes as $index) {
            if ($index['fields'] == $fields) {
                return $index;
            }
        }

        foreach ($this->indexes as $index) {
            if (array_slice($index['fields'], 0, count($fields)) == $fields) {
                return $index;
            }
        }

        foreach ($this->indexes as $index) {
            $check = false;
            $partialIndexFields = array_slice($index['fields'], 0, count($fields));
            foreach ($fields as $field) {
                if (in_array($field, $partialIndexFields)) {
                    $check = true;
                } else {
                    $check = false;
                    break;
                }
            }
            if ($check) {
                return $index;
            }
        }

        throw new Exception("Index casting failure");
    }

    public function create(array $data)
    {
        if (!array_key_exists('id', $data) && $this->fields[0] == 'id') {
            try {
                [$data['id']] = $this->mapper->client->call("box.sequence.$this->name:next");
            } catch (RequestFailed $e) {
                if (str_contains($e->getMessage(), "box.sequence.$this->name:next")) {
                    $this->mapper->client->call('box.schema.sequence.create', $this->name);
                    return $this->create($data);
                }
                throw $e;
            }
        }
        [$tuple] = $this->mapper->client->getSpaceById($this->id)->insert($this->getTuple($data));
        return $this->getInstance($tuple);
    }

    public function delete($instance)
    {
        $this->mapper->client->getSpaceById($this->id)->delete($this->getKey($instance));
    }

    public function drop()
    {
        if (func_num_args() != 0) {
            throw new Exception("use delete instead of drop");
        }
        $this->mapper->client->call("box.space.$this->name:drop");

        try {
            $this->mapper->client->call("box.sequence.$this->name:drop");
        } catch (\Exception) {
        }
    }

    public function find(Criteria|array|null $criteria = null, ?int $limit = null): array
    {
        if (!$criteria) {
            $criteria = Criteria::allIterator();
        } elseif (is_array($criteria)) {
            $index = $this->castIndex(array_keys($criteria));
            $criteria = Criteria::eqIterator()
                ->andIndex($index['iid'])
                ->andKey($this->getKey($criteria, $index));
        }

        if ($limit) {
            $criteria = $criteria->andLimit($limit);
        }

        $item = null;
        if ($this->cache) {
            $item = $this->cache->getItem(md5(serialize($criteria)));
            if ($item->isHit()) {
                return $item->get();
            }
        }

        $tuples = $this->mapper->client->getSpaceById($this->id)->select($criteria);
        $result = array_map($this->getInstance(...), $tuples);
        if ($item) {
            $item->set($result);
            $this->cache->save($item);
        }

        return $result;
    }

    public function findOne(Criteria|array|null $criteria = null)
    {
        $rows = $this->find($criteria, 1);

        if (count($rows)) {
            return $rows[0];
        }
    }

    public function findOrCreate(array $query, ?array $data = null)
    {
        if ($data == null) {
            $data = $query;
        } else {
            $data = array_merge($query, $data);
        }

        $index = $this->castIndex(array_keys($query));
        $select = [];
        foreach ($index['fields'] as $field) {
            if (array_key_exists($field, $query)) {
                $select[] = $query[$field];
            } else {
                break;
            }
        }

        [$present, $tuple] = $this->mapper->call(
            <<<LUA
            local tuples = box.space[space].index[index]:select(select, {limit=1})
            if #tuples > 0 then
                return true, tuples[1]
            end
            if tuple[id_key] == 0 and box.sequence[space] then
                tuple[id_key] = box.sequence[space]:next()
            end
            return false, box.space[space]:insert(tuple)
            LUA,
            [
                'space' => $this->name,
                'index' => $index['iid'],
                'select' => $select,
                'tuple' => $this->getTuple($data),
                'id_key' => array_search('id', $this->fields) + 1
            ]
        );
        if (!$present) {
            $this->mapper->middleware->register(
                new InsertRequest($this->id, $tuple),
                new Response([], [Keys::DATA => [$tuple]]),
            );
        }

        return $this->getInstance($tuple);
    }

    public function findOrFail(Criteria|array|null $criteria = null)
    {
        $one = $this->findOne($criteria);
        if ($one) {
            return $one;
        }

        throw new Exception("Not found");
    }

    public function getFieldFormat(string $name): array
    {
        foreach ($this->format as $field) {
            if ($field['name'] == $name) {
                return $field;
            }
        }

        throw new Exception("Field $name not found");
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getInstance(array $tuple)
    {
        if ($this->constructor) {
            return new $this->class(...$tuple);
        }

        if ($this->class) {
            $instance = new $this->class();
            foreach ($this->fields as $n => $field) {
                $instance->$field = $tuple[$n];
            }
            return $instance;
        }

        try {
            $instance = array_combine($this->fields, $tuple);
        } catch (ValueError) {
            $instance = [];
            foreach ($this->fields as $n => $field) {
                $instance[$field] = array_key_exists($n, $tuple) ? $tuple[$n] : null;
            }
        }

        if ($this->mapper->arrays) {
            return $instance;
        }

        return (object) $instance;
    }

    public function getKey($query, ?array $index = null): array
    {
        if ($index == null) {
            [$index] = $this->indexes;
        }
        $values = [];

        foreach ($index['fields'] as $field) {
            if (is_array($query) && array_key_exists($field, $query)) {
                $values[] = $query[$field];
            } elseif (is_object($query) && property_exists($query, $field)) {
                $values[] = $query->$field;
            } else {
                break;
            }
        }
        return $values;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTuple($data)
    {
        $tuple = [];
        foreach ($this->format as $field) {
            $value = null;
            if (is_array($data) && array_key_exists($field['name'], $data)) {
                $value = $data[$field['name']];
            } elseif (!is_array($data) && property_exists($data, $field['name'])) {
                $value = $data->{$field['name']};
            } elseif (array_key_exists('default', $field)) {
                $value = $field['default'];
            } elseif (array_key_exists('is_nullable', $field) && $field['is_nullable']) {
                $tuple[] = $value;
                continue;
            } else {
                $value = $this->mapper->converter->getDefaultValue($field['type']);
            }
            $tuple[] = $this->mapper->converter->formatValue($field['type'], $value);
        }
        return $tuple;
    }

    public function migrate()
    {
        if (!$this->class) {
            return;
        }

        $reflection = new ReflectionClass($this->class);
        $constructor = $reflection->getConstructor();
        $source = $this->constructor ? $constructor->getParameters() : $reflection->getProperties();

        foreach ($source as $property) {
            if (in_array($property->getName(), $this->fields)) {
                continue;
            }
            $tarantoolType = match ((string) $property->getType()) {
                'bool' => 'boolean',
                'float' => 'number',
                'int' => 'unsigned',
                'mixed' => '*',
                'array' => '*',
                default => 'string',
            };
            $opts = [];

            if (method_exists($property, 'hasDefaultValue') && $property->hasDefaultValue()) {
                $opts['default'] = $property->getDefaultValue();
            }

            if (method_exists($property, 'isDefaultValueAvailable') && $property->isDefaultValueAvailable()) {
                $opts['default'] = $property->getDefaultValue();
            }

            $this->addProperty($property->getName(), $tarantoolType, $opts);
        }
        $init = $reflection->getMethod($this->init);

        if ($init && $init->isStatic()) {
            $init->invoke(null, $this);
        }
    }

    public function setClass(string $class, string $init = 'initSchema')
    {
        $reflection = new ReflectionClass($class);
        $this->class = $class;
        $this->init = $init;
        $this->constructor = $reflection->getConstructor();
    }

    public function setFormat(array $format)
    {
        $this->fields = [];
        $this->format = $format;
        foreach ($format as $field) {
            $this->fields[] = $field['name'];
        }
    }

    public function setIndexes(array $indexes)
    {
        foreach ($indexes as $n => $index) {
            $indexes[$n]['fields'] = [];
            foreach ($index['parts'] as $part) {
                if (array_key_exists('field', $part)) {
                    $indexes[$n]['fields'][] = $this->fields[$part['field']];
                } else {
                    $indexes[$n]['fields'][] = $this->fields[$part[0]];
                }
            }
        }
        $this->indexes = $indexes;
    }

    public function update($instance, Operations|array $operations)
    {
        if (is_array($operations)) {
            $data = $operations;
            $operations = null;
            foreach ($data as $k => $v) {
                if ($operations == null) {
                    $operations = Operations::set($k, $v);
                } else {
                    $operations = $operations->andSet($k, $v);
                }
            }
        }
        [$tuple] = $this->mapper->client->getSpaceById($this->id)->update(
            $this->getKey($instance),
            $operations,
        );
        $data = $this->getInstance($tuple);
        if (is_array($instance)) {
            return $data;
        }
        foreach ($operations->toArray() as $operation) {
            $field = $operation[1];
            $instance->$field = $data->$field;
        }
        return $instance;
    }
}
