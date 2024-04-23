<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Exception;
use Symfony\Component\Uid\Uuid;

final class Converter
{
    public static function formatValue(string $type, $value)
    {
        if ($value === null) {
            return null;
        }
        switch (strtolower($type)) {
            case 'str':
            case 'string':
                return (string) $value;

            case 'double':
            case 'float':
            case 'number':
                return (float) $value;

            case 'bool':
            case 'boolean':
                return (bool) $value;

            case 'integer':
            case 'unsigned':
            case 'num':
            case 'NUM':
                return (int) $value;

            case 'uuid':
                if (is_string($value)) {
                    $value = Uuid::fromString($value);
                }
                return $value;

            default:
                return $value;
        }
    }

    public static function getDefaultValue(string $type)
    {
        switch (strtolower($type)) {
            case 'str':
            case 'string':
                return (string) null;

            case 'bool':
            case 'boolean':
                return (bool) null;

            case 'double':
            case 'float':
            case 'number':
                return (float) null;

            case 'integer':
            case 'unsigned':
            case 'num':
            case 'NUM':
                return (int) null;
        }
        throw new Exception("Invalid type $type");
    }
}
