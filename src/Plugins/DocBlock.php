<?php

namespace Tarantool\Mapper\Plugins;

use Exception;

use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;
use ReflectionProperty;

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Repository;

class DocBlock extends UserClasses
{
    private $entities = [];
    private $repositories = [];

    public function register($class)
    {
        $isEntity = is_subclass_of($class, Entity::class);
        $isRepository = is_subclass_of($class, Repository::class);

        if(!$isEntity && !$isRepository) {
            throw new Exception("Invalid registration");
        }

        if($isEntity) {
            if($class == Entity::class) {
                throw new Exception("Invalid entity registration");
            }
            $this->entities[] = $class;
        }

        if($isRepository) {
            if($class == Repository::class) {
                throw new Exception("Invalid repository registration");
            }
            $this->repositories[] = $class;
        }

        $reflection = new ReflectionClass($class);
        $space = $this->toUnderscore($reflection->getShortName());
        if($this->mapper->getSchema()->hasSpace($space)) {
            if($isEntity) {
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

        foreach($this->entities as $entity) {

            $class = new ReflectionClass($entity);
            $spaceName = $this->toUnderscore($class->getShortName());

            $space = $schema->hasSpace($spaceName) ? $schema->getSpace($spaceName) : $schema->createSpace($spaceName);
            $this->mapEntity($spaceName, $entity);

            foreach($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {

                $description = $factory->create($property->getDocComment());
                $tags = $description->getTags('var');

                if(!count($tags)) {
                    throw new Exception("No var tag for ".$entity.'::'.$property->getName());
                }

                if(count($tags) > 1) {
                    throw new Exception("Invalid var tag for ".$entity.'::'.$property->getName());
                }

                $property = $this->toUnderscore($property->getName());
                $type = $this->getTarantoolType($tags[0]->getType());

                if(!$space->hasProperty($property)) {
                    $space->addProperty($property, $type);
                }
            }
        }

        foreach($this->repositories as $repository) {

            $class = new ReflectionClass($repository);
            $spaceName = $this->toUnderscore($class->getShortName());

            if(!$schema->hasSpace($spaceName)) {
                throw new Exception("Repository with no entity definition");
            }

            $this->mapRepository($spaceName, $repository);

            $space = $schema->getSpace($spaceName);
            $properties = $class->getDefaultProperties();
            if(array_key_exists('indexes', $properties)) {
                foreach($properties['indexes'] as $index) {
                    $space->addIndex($index);
                }
            }
        }

        foreach($schema->getSpaces() as $space) {

            if(!count($space->getIndexes())) {
                if(!$space->hasProperty('id')) {
                    throw new Exception("No primary index on ". $space->getName());
                }
                $space->addIndex(['id']);
            }
        }

        return $this;
    }

    private $underscores = [];

    private function toUnderscore($input)
    {
        if(!array_key_exists($input, $this->underscores)) {
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
        if(array_key_exists($type, $this->tarantoolTypes)) {
            return $this->tarantoolTypes[$type];
        }

        if($type[0] == '\\') {
            return $this->tarantoolTypes[$type] = 'unsigned';
        }

        switch($type) {
            case 'int':
                return $this->tarantoolTypes[$type] = 'unsigned';

            default:
                return $this->tarantoolTypes[$type] = 'str';
        }
    }
}
