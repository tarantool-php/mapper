<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Exception;
use Tarantool\Client\Schema\Space as ClientSpace;
use Tarantool\Client\Schema\Criteria;

class Space
{
    public int $id;
    public string $name;
    public string $engine;
    public array $format = [];

    private $properties = [];

    private $indexes = [];
    private $tupleMap = null;
    private $repository;

    public function __construct(
        public readonly Mapper $mapper,
        array $meta,
    ) {
        $this->id = $meta['id'];
        $this->name = $meta['name'];
        $this->engine = $meta['engine'];
        $this->format = $meta['format'];
    }

    public function addIndex($config): self
    {
        return $this->createIndex($config);
    }

    public function addProperties(array $config): self
    {
        foreach ($config as $name => $type) {
            $this->addProperty($name, $type);
        }
        return $this;
    }

    public function addProperty(string $name, string $type, array $opts = []): self
    {
        if (!count($this->properties)) {
            $opts['is_nullable'] = false;
        }

        if (array_key_exists($name, $this->properties)) {
            throw new Exception("Property $this->name.$name exists!");
        }

        $this->properties[$name] = new Property($name, $type, $opts);

        return $this->updateFormat();
    }

    public function castIndex(array $params, bool $suppressException = false): ?Index
    {
        if (!count($this->getIndexes())) {
            return null;
        }

        $keys = [];
        foreach ($params as $name => $value) {
            $keys[$this->getPropertyIndex($name)] = $name;
        }

        // equals
        foreach ($this->getIndexes() as $index) {
            $equals = false;
            if (count($keys) == count($index->parts)) {
                // same length
                $equals = true;
                foreach ($index->parts as $part) {
                    $field = array_key_exists(0, $part) ? $part[0] : $part['field'];
                    $equals = $equals && array_key_exists($field, $keys);
                }
            }

            if ($equals) {
                return $index;
            }
        }

        // index part
        foreach ($this->getIndexes() as $index) {
            $partial = [];
            foreach ($index->parts as $n => $part) {
                $field = array_key_exists(0, $part) ? $part[0] : $part['field'];
                if (!array_key_exists($field, $keys)) {
                    break;
                }
                $partial[] = $keys[$field];
            }

            if (count($partial) == count($keys)) {
                return $index;
            }
        }

        if (!$suppressException) {
            throw new Exception("No index on " . $this->name . ' for [' . implode(', ', array_keys($params)) . ']');
        }

        return null;
    }

    public function createIndex($config): self
    {
        if (!is_array($config)) {
            $config = ['fields' => $config];
        }

        if (!array_key_exists('fields', $config)) {
            if (array_values($config) != $config) {
                throw new Exception("Invalid index configuration");
            }
            $config = [
                'fields' => $config
            ];
        }

        if (!is_array($config['fields'])) {
            $config['fields'] = [$config['fields']];
        }

        $options = [
            'parts' => []
        ];

        foreach ($config as $k => $v) {
            if ($k != 'name' && $k != 'fields') {
                $options[$k] = $v;
            }
        }

        foreach ($config['fields'] as $part) {
            $isNullable = false;
            if (is_array($part)) {
                if (array_key_exists('is_nullable', $part)) {
                    $isNullable = $part['is_nullable'];
                }
                $part = $part['property'];
            }
            $property = $this->getProperty($part);
            if ($property->isNullable !== $isNullable) {
                $property->isNullable = $isNullable;
                $this->updateFormat();
            }
            $options['parts'][] = [
                'field' => $this->getPropertyIndex($part) + 1,
                'type' => $property->type,
                'is_nullable' => $isNullable,
            ];
        }

        $name = array_key_exists('name', $config) ? $config['name'] : implode('_', $config['fields']);
        $this->mapper->getClient()->call("box.space.$this->name:create_index", $name, $options);
        $this->indexes = [];

        return $this;
    }

    public function getEngine(): string
    {
        return $this->engine;
    }

    public function getFields(): array
    {
        return array_keys($this->getProperties());
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getIndex(int $id): Index
    {
        foreach ($this->getIndexes() as $index) {
            if ($index->id == $id) {
                return $index;
            }
        }

        throw new Exception("Invalid index #$id");
    }

    public function getIndexes(): array
    {
        if (!count($this->indexes)) {
            if ($this->id == ClientSpace::VINDEX_ID) {
                $indexTuples = $this->mapper->getClient()
                    ->getSpaceById(ClientSpace::VINDEX_ID)
                    ->select(Criteria::key([$this->id]));

                $fields = $this->mapper->getSchema()->getSpace(ClientSpace::VINDEX_ID)->getFields();

                foreach ($indexTuples as $tuple) {
                    $instance = [];
                    foreach ($fields as $index => $name) {
                        $instance[$name] = $tuple[$index];
                    }
                    $this->indexes[] = $instance;
                }
            } else {
                $indexes = $this->mapper->find('_vindex', [
                    'id' => $this->id,
                ]);
                foreach ($indexes as $index) {
                    $this->indexes[] = $index->toArray();
                }
            }
            foreach ($this->indexes as $i => $index) {
                $this->indexes[$i] = Index::fromConfiguration($this, $index);
            }
        }
        return $this->indexes;
    }

    public function getMap(array $tuple): array
    {
        $map = [];
        foreach ($this->getFields() as $index => $name) {
            $map[$name] = array_key_exists($index, $tuple) ? $tuple[$index] : null;
        }
        return $map;
    }

    public function getMapper(): Mapper
    {
        return $this->mapper;
    }

    public function getMeta(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'engine' => $this->engine,
            'format' => $this->format,
            'indexes' => $this->getIndexes(),
        ];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getProperties(): array
    {
        if (!count($this->properties)) {
            foreach ($this->format as $row) {
                $property = Property::fromConfiguration($row);
                $this->properties[$property->name] = $property;
            }
        }
        return $this->properties;
    }

    public function getProperty(string $name, bool $required = true): ?Property
    {
        if (array_key_exists($name, $this->properties)) {
            return $this->properties[$name];
        }

        if ($required) {
            throw new Exception("No property $name found in space $this->name");
        }

        return null;
    }

    public function getPropertyIndex(string $name): int
    {
        $index = array_search($name, $this->getFields());

        if ($index === false) {
            throw new Exception("No property $name found in space $this->name");
        }

        return $index;
    }

    public function getRepository(): Repository
    {
        $class = Repository::class;
        foreach ($this->mapper->getPlugins() as $plugin) {
            $repositoryClass = $plugin->getRepositoryClass($this);
            if ($repositoryClass) {
                if ($class !== Repository::class && !is_subclass_of($class, Repository::class)) {
                    throw new Exception('Repository class override');
                }
                $class = $repositoryClass;
            }
        }
        return $this->repository ?: $this->repository = new $class($this);
    }

    public function getTuple(array $array): array
    {
        $tuple = [];
        $schema = $this->getMapper()->getSchema();
        foreach ($this->getFields() as $i => $field) {
            $property = $this->getProperty($field);
            $value = null;
            if (array_key_exists($field, $array)) {
                $value = Converter::formatValue($property->type, $array[$field]);
            }

            if ($value === null && !$property->isNullable) {
                $value = Converter::getDefaultValue($property->type);
            }

            $tuple[$i] = $value;
        }
        return $tuple;
    }

    public function getTupleMap()
    {
        if ($this->tupleMap === null) {
            $this->tupleMap = (object) [];
            foreach ($this->getFields() as $i => $field) {
                $this->tupleMap->$field = $i + 1;
            }
        }

        return $this->tupleMap;
    }

    public function hasProperty(string $name): bool
    {
        return array_key_exists($name, $this->getProperties());
    }

    public function isSystem(): bool
    {
        return $this->id < 512;
    }

    public function removeIndex(string $name): self
    {
        foreach ($this->getIndexes() as $i => $index) {
            if ($index->name == $name) {
                $this->mapper->getClient()->call("box.space.$this->name.index.$name:drop");
                unset($this->indexes[$i]);
                $this->indexes = array_values($this->indexes);
                return $this;
            }
        }

        throw new Exception("Index $name not found");
    }

    public function removeProperty(string $name): self
    {
        $fields = $this->getFields();
        if (!count($fields)) {
            return $this->getProperty($name);
        }

        if (array_reverse($fields)[0] !== $name) {
            throw new Exception("Remove only last property");
        }

        array_pop($this->properties);

        return $this->updateFormat();
    }

    public function repositoryExists(): bool
    {
        return !!$this->repository;
    }

    public function setPropertyNullable(string $name, bool $nullable = true): self
    {
        $this->getProperty($name)->isNullable = $nullable;
        $this->updateFormat();

        return $this;
    }

    public function updateFormat(): self
    {
        $this->format = array_map(fn($property) => $property->getConfiguration(), array_values($this->getProperties()));
        $this->mapper->getClient()->call("box.space.$this->name:format", $this->format);

        return $this;
    }
}
