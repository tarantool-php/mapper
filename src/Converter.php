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

    private static $camelcase = [];

    public static function toCamelCase(string $input): string
    {
        if (!array_key_exists($input, self::$camelcase)) {
            self::$camelcase[$input] = lcfirst(implode('', array_map('ucfirst', explode('_', $input))));
        }
        return self::$camelcase[$input];
    }

    private static $underscores = [];

    public static function toUnderscore(string $input): string
    {
        if (!array_key_exists($input, self::$underscores)) {
            preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
            $ret = $matches[0];
            foreach ($ret as &$match) {
                $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
            }
            self::$underscores[$input] = implode('_', $ret);
        }
        return self::$underscores[$input];
    }
}
