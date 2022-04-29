<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

class Property
{
    public ?string $reference;
    public bool $isNullable;
    public $defaultValue;

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        array $opts = [],
    ) {
        $this->isNullable = !array_key_exists('is_nullable', $opts) || $opts['is_nullable'];
        $this->defaultValue = array_key_exists('default', $opts) ? $opts['default'] : null;
        $this->reference = array_key_exists('reference', $opts) ? $opts['reference'] : null;
    }

    public function getConfiguration(): array
    {
        $configuration = [
            'name' => $this->name,
            'type' => $this->type,
            'is_nullable' => $this->isNullable,
            'defaultValue' => $this->defaultValue,
            'reference' => $this->reference,
        ];

        foreach ($configuration as $k => $v) {
            if ($v === null) {
                unset($configuration[$k]);
            }
        }

        return $configuration;
    }

    public static function fromConfiguration(array $configuration): self
    {
        $opts = [
            'reference' => array_key_exists('reference', $configuration) ? $configuration['reference'] : null,
            'default' => array_key_exists('defaultValue', $configuration) ? $configuration['defaultValue'] : null,
            'is_nullable' => array_key_exists('is_nullable', $configuration) ? $configuration['is_nullable'] : null,
        ];

        return new static($configuration['name'], $configuration['type'], $opts);
    }
}
