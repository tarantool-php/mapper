<?php

namespace Tarantool\Mapper\Plugin;

use Exception;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\ContextFactory;
use ReflectionClass;
use ReflectionProperty;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin\NestedSet;
use Tarantool\Mapper\Repository;

class Annotation extends UserClasses
{
    protected $entityClasses = [];
    protected $entityPostfix;

    protected $repositoryClasses = [];
    protected $repositoryPostifx;

    public function register($class)
    {
        $isEntity = is_subclass_of($class, Entity::class);
        $isRepository = is_subclass_of($class, Repository::class);

        if (!$isEntity && !$isRepository) {
            throw new Exception("Invalid registration");
        }

        if ($isEntity) {
            if ($class == Entity::class) {
                throw new Exception("Invalid entity registration");
            }
            $this->entityClasses[] = $class;
        }

        if ($isRepository) {
            if ($class == Repository::class) {
                throw new Exception("Invalid repository registration");
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

    public function validateSpace($space)
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

    public function migrate()
    {
        $factory = DocBlockFactory::createInstance();
        $contextFactory = new ContextFactory();

        $schema = $this->mapper->getSchema();

        foreach ($this->entityClasses as $entity) {
            $spaceName = $this->getSpaceName($entity);
            $space = $schema->hasSpace($spaceName) ? $schema->getSpace($spaceName) : $schema->createSpace($spaceName);

            $this->mapEntity($spaceName, $entity);

            $class = new ReflectionClass($entity);

            foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $context = $contextFactory->createFromReflector($property);
                $description = $factory->create($property->getDocComment(), $context);
                $tags = $description->getTags('var');

                if (!count($tags)) {
                    throw new Exception("No var tag for ".$entity.'::'.$property->getName());
                }

                if (count($tags) > 1) {
                    throw new Exception("Invalid var tag for ".$entity.'::'.$property->getName());
                }

                $propertyName = $property->getName();
                $phpType = $tags[0]->getType();
                $type = $this->getTarantoolType($phpType);

                if (!$space->hasProperty($propertyName)) {
                    if ($this->isReference($phpType)) {
                        $space->addProperty($propertyName, $type, true, $this->getSpaceName((string) $phpType));
                    } else {
                        $space->addProperty($propertyName, $type);
                    }
                }
            }
            if ($this->mapper->hasPlugin(NestedSet::class)) {
                $nested = $this->mapper->getPlugin(NestedSet::class);
                if ($nested->isNested($space)) {
                    $nested->addIndexes($space);
                }
            }
        }

        foreach ($this->repositoryClasses as $repository) {
            $spaceName = $this->getSpaceName($repository);

            if (!$schema->hasSpace($spaceName)) {
                throw new Exception("Repository with no entity definition");
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
                        throw new Exception("Failed to add index $presentation. ". $e->getMessage(), 0, $e);
                    }
                }
            }
        }

        foreach ($schema->getSpaces() as $space) {
            if (!count($space->getIndexes())) {
                if (!$space->hasProperty('id')) {
                    throw new Exception("No primary index on ". $space->getName());
                }
                $space->addIndex(['id']);
            }
        }

        return $this;
    }

    public function setEntityPostfix($postfix)
    {
        $this->entityPostfix = $postfix;
        return $this;
    }

    public function setRepositoryPostfix($postfix)
    {
        $this->repositoryPostifx = $postfix;
        return $this;
    }

    private $spaceNames = [];

    public function getRepositorySpaceName($class)
    {
        return array_search($class, $this->repositoryMapping);
    }

    public function getSpaceName($class)
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

    private function isReference(string $type)
    {
        return $type[0] == '\\';
    }

    private function getTarantoolType(string $type)
    {
        if (array_key_exists($type, $this->tarantoolTypes)) {
            return $this->tarantoolTypes[$type];
        }

        if ($this->isReference($type)) {
            return $this->tarantoolTypes[$type] = 'unsigned';
        }

        switch ($type) {
            case 'mixed':
            case 'array':
                return $this->tarantoolTypes[$type] = '*';

            case 'float':
                return $this->tarantoolTypes[$type] = 'number';

            case 'int':
                return $this->tarantoolTypes[$type] = 'unsigned';

            default:
                return $this->tarantoolTypes[$type] = 'string';
        }
    }
}
