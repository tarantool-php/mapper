<?php

namespace Tarantool\Mapper;

use Tarantool\Connection\Connection;
use Tarantool\IProto;
use Tarantool\Request\EvaluateRequest;
use Tarantool\Request\Request;
use Tarantool\Request\SelectRequest;

class Event
{
    private $time;
    private $class;
    private $request;
    private $response;

    public function __construct($time, $class, $request, $response)
    {
        $this->time = $time;
        $this->class = $class;
        $this->request = $request;
        $this->response = $response;
    }

    public function render(Manager $manager)
    {
        switch ($this->class) {
            case Connection::class:
                return 'Make connection';

            case EvaluateRequest::class:
                return trim($this->request[39]);

            case SelectRequest::class:

                $params = $this->request[IProto::KEY];
                $spaceId = $this->request[IProto::SPACE_ID];
                $index = $this->request[IProto::INDEX_ID];

                $client = $manager->getClient();
                $meta = $manager->getMeta();
                $schema = $manager->getSchema();
                $space = $schema->getSpaceName($spaceId);

                if ($meta->has($space)) {
                    $index = $meta->get($space)->getIndex($index);
                } else {
                    $data = $client->getSpace('_vindex')->select([$this->request[IProto::SPACE_ID], $index], 'primary')->getData();
                    $index = [];

                    foreach ($data[0][5] as $row) {
                        $index[] = $row[0];
                    }
                }

                if (count($params) == 1) {
                    $params = array_shift($params);
                    $index = array_shift($index);
                } else {
                    $params = json_encode($params);
                    $index = json_encode($index);
                }

                return "select $space where $index = $params";
            default:
                return json_encode($this->request);
        }
    }

    public function getTime()
    {
        return $this->time;
    }

    public function getResponse()
    {
        return $this->response;
    }
}
