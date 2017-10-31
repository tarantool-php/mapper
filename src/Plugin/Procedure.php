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
        $nick = $this->getNick(get_class($procedure));
        $result = $this->mapper->getClient()->call($nick, $params);
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

        $instance = $this->mapper->findOrCreate('_procedure', ['name' => $class]);

        $procedure = new $class($this);

        if ($instance->hash != md5($procedure->getBody())) {
            $nick = $this->getNick($class);
            $params = implode(', ', $procedure->getParams());
            $body = $procedure->getBody();

            $script = "
            $nick = function($params) $body end
            box.schema.func.create('$nick', {if_not_exists=true})
            ";
            $this->mapper->getClient()->evaluate($script);
            $instance->hash = md5($body);
        }

        return $procedure;
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

    private function getNick($className)
    {
        return 'php_'.strtolower(implode('_', explode('\\', $className)));
    }
}
