<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

class Index
{
    public static function fromConfiguration(Space $space, array $configuration): self
    {
        $parts = $configuration['parts'];

        if (array_key_exists(0, $parts[0])) {
            foreach ($parts as $i => $part) {
                $parts[$i] = [
                    'field' => $part[0],
                    'type' => $part[1],
                    'is_nullable' => array_key_exists(2, $part) && $part[2],
                ];
            }
        }

        $fields = $space->getFields();
        foreach ($parts as $i => $part) {
            $parts[$i]['property'] = $space->getProperty($fields[$part['field']]);
        }

        return new static(
            $configuration['iid'],
            $configuration['name'],
            $configuration['type'],
            $configuration['opts'],
            $parts,
        );
    }

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly array $opts,
        public readonly array $parts,
    ) {
    }

    public function getFields(): array
    {
        return array_map(fn($part) => $part['property'], $this->parts);
    }

    public function getProperty(): ?Property
    {
        return count($this->parts) == 1 ? $this->parts[0]['property'] : null;
    }

    public function getValue(array $params)
    {
        return $this->getProperty() ? $this->getValues($params)[0] : null;
    }

    public function getValues(array $params): array
    {
        $values = [];

        foreach ($this->parts as $part) {
            if (!array_key_exists($part['property']->name, $params)) {
                break;
            }
            $type = array_key_exists(1, $part) ? $part[1] : $part['type'];
            $value = Converter::formatValue($type, $params[$part['property']->name]);
            if ($value === null && !$part['property']->isNullable) {
                $value = Converter::getDefaultValue($format[$field]['type']);
            }
            $values[] = $value;
        }
        return $values;
    }
}
