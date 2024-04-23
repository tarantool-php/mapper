<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Tarantool\Client\Handler\Handler;
use Tarantool\Client\Keys;
use Tarantool\Client\Middleware\Middleware as MiddlewareInterface;
use Tarantool\Client\Request\Request;
use Tarantool\Client\RequestTypes;
use Tarantool\Client\Response;

class Middleware implements MiddlewareInterface
{
    private array $changes = [];
    private array $types = [
        RequestTypes::DELETE,
        RequestTypes::INSERT,
        RequestTypes::UPDATE,
    ];

    public function __construct(public readonly Mapper $mapper)
    {
    }

    public function flush(): void
    {
        $this->changes = [];
    }

    public function getChanges(): array
    {
        $changes = [];
        foreach ($this->changes as [$request, $response]) {
            $spaceId = $request->getBody()[Keys::SPACE_ID];
            $space = $this->mapper->getSpace($spaceId);
            $tuple = $response->getBodyField(Keys::DATA)[0];
            $instance = $space->getInstance($tuple);
            $key = $spaceId . '/' . implode('/', $space->getKey($instance));
            if (array_key_exists($key, $changes)) {
                if (
                    $changes[$key]->type == RequestTypes::getName(RequestTypes::INSERT)
                    && $request->getType() == RequestTypes::DELETE
                ) {
                    unset($changes[$key]);
                } else {
                    $changes[$key]->data = array_combine($space->getFields(), $tuple);
                }
            } else {
                $changes[$key] = new Change(
                    RequestTypes::getName($request->getType()),
                    $space->getName(),
                    array_combine($space->getFields(), $tuple),
                );
            }
        }
        return array_values($changes);
    }

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);
        $this->mapper->setSchemaId($response->getSchemaId());
        $this->register($request, $response);
        return $response;
    }

    public function register(Request $request, Response $response): void
    {
        if ($this->mapper->spy && in_array($request->getType(), $this->types)) {
            $this->changes[] = [$request, $response];
        }
    }
}
