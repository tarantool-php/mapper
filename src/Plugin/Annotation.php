<?php

namespace Tarantool\Mapper\Plugin;

use Exception;

use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;
use ReflectionProperty;

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Repository;

class Annotation extends UserClasses
{
    private $entities = [];
    private $entityPostfix;

    private $repositories = [];
    private $repositoryPostifx;

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
            $this->entities[] = $class;
        }

        if ($isRepository) {
            if ($class == Repository::class) {
                throw new Exception("Invalid repository registration");
            }
            $this->repositories[] = $class;
        }

        $space = $this->getSpaceName($class);
        if ($this->mapper->getSchema()->hasSpace($space)) {
            if ($isEntity) {
                $this->mapEntity($space, $class);
            } else {
                $this->mapRepository($space, $class);
            }
        }
        return $this;
    }

    public function migrate()
    {
        $factory = DocBlockFactory::createInstance();

        $schema = $this->mapper->getSchema();

        foreach ($this->entities as $entity) {
            $spaceName = $this->getSpaceName($entity);
            $space = $schema->hasSpace($spaceName) ? $schema->getSpace($spaceName) : $schema->createSpace($spaceName);

            $this->mapEntity($spaceName, $entity);

            $class = new ReflectionClass($entity);

            foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $description = $factory->create($property->getDocComment());
                $tags = $description->getTags('var');

                if (!count($tags)) {
                    throw new Exception("No var tag for ".$entity.'::'.$property->getName());
                }

                if (count($tags) > 1) {
                    throw new Exception("Invalid var tag for ".$entity.'::'.$property->getName());
                }

                $property = $this->toUnderscore($property->getName());
                $type = $this->getTarantoolType($tags[0]->getType());

                if (!$space->hasProperty($property)) {
                    $space->addProperty($property, $type);
                }
            }
        }

        foreach ($this->repositories as $repository) {
            $spaceName = $this->getSpaceName($repository);

            if (!$schema->hasSpace($spaceName)) {
                throw new Exception("Repository with no entity definition");
            }

            $this->mapRepository($spaceName, $repository);

            $space = $schema->getSpace($spaceName);

            $class = new ReflectionClass($repository);
            $properties = $class->getDefaultProperties();

            if (array_key_exists('indexes', $properties)) {
                foreach ($properties['indexes'] as $index) {
                    if (!is_array($index)) {
                        $index = (array) $index;
                    }
                    if (!array_key_exists('fields', $index)) {
                        $index = ['fields' => $index];
                    }

                    $index['if_not_exists'] = true;
                    $space->addIndex($index);
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

    public function getRepositoryMapping()
    {
        $mapping = [];
        foreach ($this->repositories as $class) {
            $mapping[$this->getSpaceName($class)] = $class;
        }
        return $mapping;
    }

    public function getEntityMapping()
    {
        $mapping = [];
        foreach ($this->entities as $class) {
            $mapping[$this->getSpaceName($class)] = $class;
        }
        return $mapping;
    }

    private $spaceNames = [];

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

            $this->spaceNames[$class] = $this->toUnderscore($className);
        }

        return $this->spaceNames[$class];
    }

    private $underscores = [];

    private function toUnderscore($input)
    {
        if (!array_key_exists($input, $this->underscores)) {
            preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
            $ret = $matches[0];
            foreach ($ret as &$match) {
                $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
            }
            $this->underscores[$input] = implode('_', $ret);
        }
        return $this->underscores[$input];
    }

    private $tarantoolTypes = [];

    private function getTarantoolType(string $type)
    {
        if (array_key_exists($type, $this->tarantoolTypes)) {
            return $this->tarantoolTypes[$type];
        }

        if ($type[0] == '\\') {
            return $this->tarantoolTypes[$type] = 'unsigned';
        }

        switch ($type) {
            case 'int':
                return $this->tarantoolTypes[$type] = 'unsigned';

            default:
                return $this->tarantoolTypes[$type] = 'str';
        }
    }
}
