<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Plugin;

use Closure;
use Exception;
use LogicException;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\ContextFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin\NestedSet;
use Tarantool\Mapper\Repository;
use Tarantool\Mapper\Space;

class Annotation extends UserClasses
{
    protected $entityClasses = [];
    protected $entityPostfix;

    protected $repositoryClasses = [];
    protected $repositoryPostifx;

    protected $extensions;

    public function register($class)
    {
        $isEntity = is_subclass_of($class, Entity::class);
        $isRepository = is_subclass_of($class, Repository::class);

        if (!$isEntity && !$isRepository) {
            throw new Exception("Invalid registration for $class");
        }

        if ($isEntity) {
            if ($class == Entity::class) {
                throw new Exception("Invalid entity registration for $class");
            }
            $this->entityClasses[] = $class;
        }

        if ($isRepository) {
            if ($class == Repository::class) {
                throw new Exception("Invalid repository registration for $class");
            }
            $this->repositoryClasses[] = $class;
        }

        $space = $this->getSpaceName($class);
        if ($isEntity) {
            $this->mapEntity($space, $class);
        } else {
            $this->mapRepository($space, $class);
        }
        return $this;
    }

    public function validateSpace(string $space) : bool
    {
        foreach ($this->entityClasses as $class) {
            if ($this->getSpaceName($class) == $space) {
                return true;
            }
        }

        foreach ($this->repositoryClasses as $class) {
            if ($this->getSpaceName($class) == $space) {
                return true;
            }
        }

        return parent::validateSpace($space);
    }

    public function getSpace($instance) : string
    {
        $class = get_class($instance);
        $target = $this->isExtension($class) ? $this->getExtensions()[$class] : $class;
        return $this->getSpaceName($target);
    }

    public function isExtension(string $class) : bool
    {
        return array_key_exists($class, $this->getExtensions());
    }

    public function getExtensions() : array
    {
        if (is_null($this->extensions)) {
            $this->extensions = [];
            foreach ($this->entityClasses as $entity) {
                $reflection = new ReflectionClass($entity);
                $parentEntity = $reflection->getParentClass()->getName();
                if (in_array($parentEntity, $this->entityClasses)) {
                    $this->extensions[$entity] = $parentEntity;
                }
            }
        }
        return $this->extensions;
    }

    public function migrate($extensionInstances = true) : self
    {
        $factory = DocBlockFactory::createInstance();
        $contextFactory = new ContextFactory();

        $schema = $this->mapper->getSchema();

        $computes = [];
        foreach ($this->entityClasses as $entity) {
            if ($this->isExtension($entity)) {
                continue;
            }

            $spaceName = $this->getSpaceName($entity);

            $params = [
                'engine' => 'memtx',
                'properties' => [],
            ];

            if (array_key_exists($spaceName, $this->repositoryMapping)) {
                $repositoryClass = $this->repositoryMapping[$spaceName];
                $repositoryReflection = new ReflectionClass($repositoryClass);
                $repositoryProperties = $repositoryReflection->getDefaultProperties();
                if (array_key_exists('engine', $repositoryProperties)) {
                    $params['engine'] = $repositoryProperties['engine'];
                }
                if (array_key_exists('local', $repositoryProperties)) {
                    $params['is_local'] = true;
                }
                if (array_key_exists('temporary', $repositoryProperties)) {
                    if ($params['engine'] == 'vinyl') {
                        throw new Exception("Vinyl does not support temporary spaces");
                    }
                    $params['temporary'] = true;
                }
            }

            if ($schema->hasSpace($spaceName)) {
                $space = $schema->getSpace($spaceName);
                if ($space->getEngine() != $params['engine']) {
                    throw new Exception("Space engine can't be updated");
                }
            } else {
                $space = $schema->createSpace($spaceName, $params);
            }

            $class = new ReflectionClass($entity);

            foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $context = $contextFactory->createFromReflector($property);
                $description = $factory->create($property->getDocComment(), $context);
                $tags = $description->getTags('var');

                if (!count($tags)) {
                    throw new Exception("No var tag for ".$entity.'::'.$property->getName());
                }

                $byTypes = [];
                foreach ($tags as $candidate) {
                    $byTypes[$candidate->getName()] = $candidate;
                }

                if (!array_key_exists('var', $byTypes)) {
                    throw new Exception("No var tag for ".$entity.'::'.$property->getName());
                }

                $propertyName = $property->getName();
                $phpType = $byTypes['var']->getType();

                if (array_key_exists('type', $byTypes)) {
                    $type = (string) $byTypes['type']->getDescription();
                } else {
                    $type = $this->getTarantoolType((string) $phpType);
                }

                $isNullable = true;

                if (array_key_exists('required', $byTypes)) {
                    $isNullable = false;
                }

                if (!$space->hasProperty($propertyName)) {
                    $opts = [
                        'is_nullable' => $isNullable,
                    ];
                    if ($this->isReference((string) $phpType)) {
                        $opts['reference'] = $this->getSpaceName((string) $phpType);
                    }
                    if (array_key_exists('default', $byTypes)) {
                        $opts['default'] = $schema->formatValue($type, (string) $byTypes['default']);
                    }
                    $space->addProperty($propertyName, $type, $opts);
                }
            }
            if ($this->mapper->hasPlugin(NestedSet::class)) {
                $nested = $this->mapper->getPlugin(NestedSet::class);
                if ($nested->isNested($space)) {
                    $nested->addIndexes($space);
                }
            }

            if ($class->hasMethod('compute')) {
                $computes[] = $spaceName;
            }
        }

        foreach ($this->repositoryClasses as $repository) {
            $spaceName = $this->getSpaceName($repository);

            if (!$schema->hasSpace($spaceName)) {
                throw new Exception("Repository $spaceName has no entity definition");
            }

            $this->mapRepository($spaceName, $repository);

            $space = $schema->getSpace($spaceName);

            $class = new ReflectionClass($repository);
            $properties = $class->getDefaultProperties();

            if (array_key_exists('indexes', $properties)) {
                foreach ($properties['indexes'] as $i => $index) {
                    if (!is_array($index)) {
                        $index = (array) $index;
                    }
                    if (!array_key_exists('fields', $index)) {
                        $index = ['fields' => $index];
                    }

                    $index['if_not_exists'] = true;
                    try {
                        $space->addIndex($index);
                    } catch (Exception $e) {
                        $presentation = json_encode($properties['indexes'][$i]);
                        throw new Exception("Failed to add index $presentation. ".$e->getMessage(), 0, $e);
                    }
                }
            }
        }
        foreach ($schema->getSpaces() as $space) {
            if ($space->isSystem()) {
                continue;
            }
            if (!count($space->getIndexes())) {
                if (!$space->hasProperty('id')) {
                    throw new Exception("No primary index on ".$space->getName());
                }
                $space->addIndex(['id']);
            }
        }

        foreach ($computes as $spaceName) {
            $method = new ReflectionMethod($this->entityMapping[$spaceName], 'compute');
            $type = (string) $method->getParameters()[0]->getType();
            $sourceSpace = array_search($type, $this->entityMapping);
            if (!$sourceSpace) {
                throw new Exception("Invalid compute source $type");
            }
            $compute = Closure::fromCallable([$this->entityMapping[$spaceName], 'compute']);
            $this->mapper->getPlugin(Compute::class)->register($sourceSpace, $spaceName, $compute);
        }

        foreach ($this->entityClasses as $entity) {
            if ($this->isExtension($entity)) {
                continue;
            }
            if (in_array($entity, $this->extensions)) {
                $spaceName = $this->getSpaceName($entity);
                $space = $schema->getSpace($spaceName);
                if (!$space->hasProperty('class')) {
                    throw new Exception("$entity has extensions, but not class property is defined");
                }
                if ($space->castIndex(['class' => ''], true) === null) {
                    $space->addIndex('class', [
                        'unique' => true,
                    ]);
                }
            }
        }

        if ($extensionInstances) {
            foreach ($this->extensions as $class => $target) {
                $space = $this->getSpaceName($target);
                $instance = $this->mapper->findOrCreate($space, [
                    'class' => $class,
                ]);
                $reflection = new ReflectionClass($class);
                foreach ($reflection->getDefaultProperties() as $key => $value) {
                    if ($value && !$instance->$key) {
                        $instance->$key = $value;
                    }
                }
                $instance->save();
            }
        }

        return $this;
    }

    public function getEntityClass(Space $space, array $data) : ?string
    {
        $class = parent::getEntityClass($space, $data);
        if (in_array($class, $this->getExtensions())) {
            if (!array_key_exists('class', $data) || !$data['class']) {
                throw new LogicException("Extension without class defined");
            }
            return $data['class'];
        }
        return $class;
    }

    public function setEntityPostfix(?string $postfix) : self
    {
        $this->entityPostfix = $postfix;
        return $this;
    }

    public function setRepositoryPostfix(?string $postfix) : self
    {
        $this->repositoryPostifx = $postfix;
        return $this;
    }

    private $spaceNames = [];

    public function getRepositorySpaceName($class) : string
    {
        return array_search($class, $this->repositoryMapping);
    }

    public function getSpaceName(string $class) : string
    {
        if (!array_key_exists($class, $this->spaceNames)) {
            $reflection = new ReflectionClass($class);
            $className = $reflection->getShortName();

            if ($reflection->isSubclassOf(Repository::class)) {
                if ($this->repositoryPostifx) {
                    $className = substr($className, 0, strlen($className) - strlen($this->repositoryPostifx));
                }
            }

            if ($reflection->isSubclassOf(Entity::class)) {
                if ($this->entityPostfix) {
                    $className = substr($className, 0, strlen($className) - strlen($this->entityPostfix));
                }
            }

            $this->spaceNames[$class] = $this->mapper->getSchema()->toUnderscore($className);
        }

        return $this->spaceNames[$class];
    }

    private $tarantoolTypes = [];

    private function isReference(string $type) : bool
    {
        return $type[0] == '\\';
    }

    private function getTarantoolType(string $type) : string
    {
        static $map;
        if (!$map) {
            $map = [
                'mixed' => '*',
                'array' => '*',
                'float' => 'number',
                'int' => 'unsigned',
                'bool' => 'boolean'
            ];
        }

        if (array_key_exists($type, $map)) {
            return $map[$type];
        }

        return $this->isReference($type) ? 'unsigned' : 'string';
    }
}
