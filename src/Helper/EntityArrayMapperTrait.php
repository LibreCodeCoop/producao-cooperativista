<?php

namespace App\Helper;

use DateTime;
use ReflectionClass;

trait EntityArrayMapperTrait
{
    public function fromArray(array $attributes): void
    {
        foreach ($attributes as $name => $value) {
            $property = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
            if (property_exists($this, $property)) {
                $reflectionClass = new ReflectionClass($this);
                $propertyType = $reflectionClass->getProperty($property)->getType()->getName();
                if ($propertyType === 'DateTime' && is_string($value)) {
                    $value = new DateTime($value);
                }
                $varType = get_debug_type($value);
                if ($varType !== $propertyType) {
                    if ($propertyType === 'array' && $varType === 'string') {
                        $value = json_decode($value, true);
                    } elseif ($varType !== 'null') {
                        settype($value, $propertyType);
                    }
                }
                $methodName = sprintf('%s%s', 'set', ucfirst($property));
                $this->{$methodName}($value);
            }
        }
    }

    public function toArray(): array
    {
        $data = [];
        $reflectionClass = new ReflectionClass($this);

        foreach ($reflectionClass->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($this);

            if ($value instanceof DateTime) {
                $value = $value->format('Y-m-d H:i:s');
            }

            $data[$property->getName()] = $value;
        }

        return $data;
    }
}
