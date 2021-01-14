<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Exception;
use Tarantool\Client\Schema\Space as ClientSpace;
use Tarantool\Client\Schema\Criteria;

class Space
{
    private $mapper;

    private $id;
    private $name;
    private $engine;
    private $format;
    private $indexes;

    private $formatNamesHash = [];
    private $formatTypesHash = [];
    private $formatReferences = [];

    private $repository;

    public function __construct(Mapper $mapper, int $id, string $name, string $engine, array $meta = null)
    {
        $this->mapper = $mapper;
        $this->id = $id;
        $this->name = $name;
        $this->engine = $engine;

        if ($meta) {
            foreach ($meta as $key => $value) {
                $this->$key = $value;
            }
        }
    }

    public function getEngine(): string
    {
        return $this->engine;
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
        $format = $this->getFormat();

        if ($this->getProperty($name, false)) {
            throw new Exception("Property $name exists");
        }

        $row = array_merge(compact('name', 'type'), $opts);
        if (!array_key_exists('is_nullable', $row)) {
            $row['is_nullable'] = true;
        }

        if (array_key_exists('default', $row)) {
            $row['defaultValue'] = $row['default'];
            unset($row['default']);
        }

        $format[] = $row;

        return $this->setFormat($format);
    }

    public function hasDefaultValue(string $name): bool
    {
        return array_key_exists('defaultValue', $this->getProperty($name));
    }

    public function getDefaultValue(string $name)
    {
        return $this->getPropertyFlag($name, 'defaultValue');
    }

    public function isPropertyNullable(string $name): bool
    {
        return !!$this->getPropertyFlag($name, 'is_nullable');
    }

    public function setFormat(array $format): self
    {
        $this->format = $format;
        $this->mapper->getClient()->call("box.space.$this->name:format", $format);
        return $this->parseFormat();
    }

    public function setPropertyNullable(string $name, bool $nullable = true): self
    {
        $format = $this->getFormat();
        foreach ($format as $i => $field) {
            if ($field['name'] == $name) {
                $format[$i]['is_nullable'] = $nullable;
            }
        }

        return $this->setFormat($format);
    }

    public function removeProperty(string $name): self
    {
        $format = $this->getFormat();
        $last = array_pop($format);
        if ($last['name'] != $name) {
            throw new Exception("Remove only last property");
        }

        return $this->setFormat($format);
    }

    public function removeIndex(string $name): self
    {
        $this->mapper->getClient()->call("box.space.$this->name.index.$name:drop");
        $this->indexes = null;
        $this->mapper->getRepository('_vindex')->flushCache();

        return $this;
    }

    public function addIndex($config): self
    {
        return $this->createIndex($config);
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

        foreach ($config['fields'] as $property) {
            $isNullable = false;
            if (is_array($property)) {
                if (!array_key_exists('property', $property)) {
                    throw new Exception("Invalid property configuration");
                }
                if (array_key_exists('is_nullable', $property)) {
                    $isNullable = $property['is_nullable'];
                }
                $property = $property['property'];
            }
            if ($this->isPropertyNullable($property) != $isNullable) {
                $this->setPropertyNullable($property, $isNullable);
            }
            if (!$this->getPropertyType($property)) {
                throw new Exception("Unknown property $property", 1);
            }
            $part = [
                'field' => $this->getPropertyIndex($property) + 1,
                'type' => $this->getPropertyType($property),
            ];
            if ($this->isPropertyNullable($property)) {
                $part['is_nullable'] = true;
            }
            $options['parts'][] = $part;
        }

        $name = array_key_exists('name', $config) ? $config['name'] : implode('_', $config['fields']);

        $this->mapper->getClient()->call("box.space.$this->name:create_index", $name, $options);
        $this->mapper->getSchema()->getSpace('_vindex')->getRepository()->flushCache();

        $this->indexes = null;

        return $this;
    }

    public function getIndex(int $id): array
    {
        foreach ($this->getIndexes() as $index) {
            if ($index['iid'] == $id) {
                return $index;
            }
        }

        throw new Exception("Invalid index #$id");
    }

    public function getIndexType(int $id): string
    {
        return $this->getIndex($id)['type'];
    }

    public function isSpecial(): bool
    {
        return in_array($this->id, [ ClientSpace::VSPACE_ID, ClientSpace::VINDEX_ID ]);
    }

    public function isSystem(): bool
    {
        return $this->id < 512;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTupleMap()
    {
        $reverse = [];
        foreach ($this->getFormat() as $i => $field) {
            $reverse[$field['name']] = $i + 1;
        }
        return (object) $reverse;
    }

    public function getFormat(): array
    {
        if ($this->format === null) {
            if ($this->isSpecial()) {
                $this->format = $this->mapper->getClient()
                    ->getSpaceById(ClientSpace::VSPACE_ID)
                    ->select(Criteria::key([$this->id]))[0][6];
            } else {
                $this->format = $this->mapper->findOrFail('_vspace', ['id' => $this->id])->format;
            }
            if (!$this->format) {
                $this->format = [];
            }
            $this->parseFormat();
        }

        return $this->format;
    }

    public function getMapper(): Mapper
    {
        return $this->mapper;
    }

    public function getName(): string
    {
        return $this->name;
    }

    private function parseFormat(): self
    {
        $this->formatTypesHash = [];
        $this->formatNamesHash = [];
        $this->formatReferences = [];
        foreach ($this->format as $key => $row) {
            $name = $row['name'];
            $this->formatTypesHash[$name] = $row['type'];
            $this->formatNamesHash[$name] = $key;
            if (array_key_exists('reference', $row)) {
                $this->formatReferences[$name] = $row['reference'];
            }
        }
        return $this;
    }

    public function hasProperty(string $name): bool
    {
        $this->getFormat();
        return array_key_exists($name, $this->formatNamesHash);
    }

    public function getMeta(): array
    {
        $this->getFormat();
        $this->getIndexes();

        return [
            'formatNamesHash' => $this->formatNamesHash,
            'formatTypesHash' => $this->formatTypesHash,
            'formatReferences' => $this->formatReferences,
            'indexes' => $this->indexes,
            'format' => $this->format,
        ];
    }

    public function getProperty(string $name, bool $required = true): ?array
    {
        foreach ($this->getFormat() as $field) {
            if ($field['name'] == $name) {
                return $field;
            }
        }

        if ($required) {
            throw new Exception("Invalid property $name");
        }

        return null;
    }

    public function getPropertyFlag(string $name, string $flag)
    {
        $property = $this->getProperty($name);
        if (array_key_exists($flag, $property)) {
            return $property[$flag];
        }
    }

    public function getPropertyType(string $name)
    {
        if (!$this->hasProperty($name)) {
            throw new Exception("No property $name");
        }
        return $this->formatTypesHash[$name];
    }

    public function getPropertyIndex(string $name): int
    {
        if (!$this->hasProperty($name)) {
            throw new Exception("No property $name");
        }
        return $this->formatNamesHash[$name];
    }

    public function getReference(string $name): ?string
    {
        return $this->isReference($name) ? $this->formatReferences[$name] : null;
    }

    public function isReference(string $name): bool
    {
        return array_key_exists($name, $this->formatReferences);
    }

    public function getIndexes(): array
    {
        if ($this->indexes === null) {
            $this->indexes = [];
            if ($this->isSpecial()) {
                $indexTuples = $this->mapper->getClient()
                    ->getSpaceById(ClientSpace::VINDEX_ID)
                    ->select(Criteria::key([$this->id]));

                $indexFormat = $this->mapper->getSchema()
                    ->getSpace(ClientSpace::VINDEX_ID)
                    ->getFormat();

                foreach ($indexTuples as $tuple) {
                    $instance = [];
                    foreach ($indexFormat as $index => $format) {
                        $instance[$format['name']] = $tuple[$index];
                    }
                    $this->indexes[] = $instance;
                }
            } else {
                $indexes = $this->mapper->find('_vindex', [
                    'id' => $this->id,
                ]);
                foreach ($indexes as $index) {
                    $index = get_object_vars($index);
                    foreach ($index as $key => $value) {
                        if (is_object($value)) {
                            unset($index[$key]);
                        }
                    }
                    $this->indexes[] = $index;
                }
            }
        }
        return $this->indexes;
    }

    public function castIndex(array $params, bool $suppressException = false): ?int
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
            if (count($keys) == count($index['parts'])) {
                // same length
                $equals = true;
                foreach ($index['parts'] as $part) {
                    $field = array_key_exists(0, $part) ? $part[0] : $part['field'];
                    $equals = $equals && array_key_exists($field, $keys);
                }
            }

            if ($equals) {
                return $index['iid'];
            }
        }

        // index part
        foreach ($this->getIndexes() as $index) {
            $partial = [];
            foreach ($index['parts'] as $n => $part) {
                $field = array_key_exists(0, $part) ? $part[0] : $part['field'];
                if (!array_key_exists($field, $keys)) {
                    break;
                }
                $partial[] = $keys[$field];
            }

            if (count($partial) == count($keys)) {
                return $index['iid'];
            }
        }

        if (!$suppressException) {
            throw new Exception("No index on " . $this->name . ' for [' . implode(', ', array_keys($params)) . ']');
        }

        return null;
    }

    public function getIndexValues(int $indexId, array $params): array
    {
        $index = $this->getIndex($indexId);
        $format = $this->getFormat();

        $values = [];
        foreach ($index['parts'] as $part) {
            $field = array_key_exists(0, $part) ? $part[0] : $part['field'];
            $name = $format[$field]['name'];
            if (!array_key_exists($name, $params)) {
                break;
            }
            $type = array_key_exists(1, $part) ? $part[1] : $part['type'];
            $value = $this->mapper->getSchema()->formatValue($type, $params[$name]);
            if ($value === null && !$this->isPropertyNullable($name)) {
                $value = $this->mapper->getSchema()->getDefaultValue($format[$field]['type']);
            }
            $values[] = $value;
        }
        return $values;
    }

    protected $primaryKey;

    public function getPrimaryKey(): ?string
    {
        if ($this->primaryKey !== null) {
            return $this->primaryKey ?: null;
        }
        $field = $this->getPrimaryField();
        if ($field !== null) {
            return $this->primaryKey = $this->getFormat()[$field]['name'];
        }

        $this->primaryKey = false;
        return null;
    }

    protected $primaryField;

    public function getPrimaryField(): ?int
    {
        if ($this->primaryField !== null) {
            return $this->primaryField ?: null;
        }
        $primary = $this->getPrimaryIndex();
        if (count($primary['parts']) == 1) {
            return $this->primaryField = $primary['parts'][0][0];
        }

        $this->primaryField = false;
        return null;
    }

    public function getPrimaryIndex(): array
    {
        return $this->getIndex(0);
    }

    public function getTupleKey(array $tuple)
    {
        $key = [];
        foreach ($this->getPrimaryIndex()['parts'] as $part) {
            $field = array_key_exists(0, $part) ? $part[0] : $part['field'];
            $key[] = $tuple[$field];
        }
        return count($key) == 1 ? $key[0] : implode(':', $key);
    }

    public function getInstanceKey(Entity $instance)
    {
        if ($this->getPrimaryKey()) {
            $key = $this->getPrimaryKey();
            return $instance->{$key};
        }

        $key = [];

        foreach ($this->getPrimaryIndex()['parts'] as $part) {
            $field = array_key_exists(0, $part) ? $part[0] : $part['field'];
            $name = $this->getFormat()[$field]['name'];
            if (!property_exists($instance, $name)) {
                throw new Exception("Field $name is undefined", 1);
            }
            $key[] = $instance->$name;
        }

        return count($key) == 1 ? $key[0] : implode(':', $key);
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

    public function repositoryExists(): bool
    {
        return !!$this->repository;
    }
}
