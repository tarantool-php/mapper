<?php

namespace Tarantool\Mapper\Plugin;

use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Procedure as BaseProcedure;
use Exception;

class Procedure extends Plugin
{
    public function get($class)
    {
        return $this->register($class);
    }

    public function invoke(BaseProcedure $procedure, $params)
    {
        $name = $procedure->getName();
        $this->validatePresence($procedure);
        $result = $this->mapper->getClient()->call($name, $params);
        return $result->getData()[0];
    }

    public function isRegistered($class)
    {
        return !!$this->mapper->findOne('_procedure', ['name' => $class]);
    }

    public function register($class)
    {
        if (!is_subclass_of($class, BaseProcedure::class)) {
            throw new Exception("Procedure should extend ".BaseProcedure::class.' class');
        }
        $this->initSchema();

        $procedure = new $class($this);
        $this->validatePresence($procedure);
        return $procedure;
    }

    private function validatePresence(BaseProcedure $procedure)
    {
        $name = $procedure->getName();
        $exists = $this->mapper->getClient()->evaluate("return _G.$name ~= nil")->getData()[0];

        $instance = $this->mapper->findOrCreate('_procedure', [
            'name' => get_class($procedure)
        ]);

        if ($instance->hash != md5($procedure->getBody()) || !$exists) {
            $params = implode(', ', $procedure->getParams());
            $body = $procedure->getBody();

            $script = "
            $name = function($params) $body end
            box.schema.func.create('$name', {if_not_exists=true})
            ";
            $this->mapper->getClient()->evaluate($script);
            $instance->hash = md5($body);
            $instance->save();
        }
    }

    private function initSchema()
    {
        $this->mapper->getSchema()->once(__CLASS__, function ($mapper) {
            $mapper->getSchema()
                ->createSpace('_procedure')
                ->addProperties([
                    'name' => 'string',
                    'hash' => 'string',
                ])
                ->addIndex([
                    'fields' => ['name'],
                    'type' => 'hash',
                ]);
        });
    }
}
