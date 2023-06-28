<?php
/**
 * @copyright Copyright (c) 2023, Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace ProducaoCooperativista\DB;

use DateTime;
use Doctrine\Inflector\InflectorFactory;
use ReflectionClass;

class Entity
{
    /**
     * Assign entity properties using an array
     * 
     * @param array $attributes assoc array of values to assign
     * @return null 
     */
    public function fromArray(array $attributes)
    {
        $inflector = InflectorFactory::create()->build();
        foreach ($attributes as $name => $value) {
            $property = $inflector->camelize($name);
            if (property_exists($this, $property)) {
                $class = new ReflectionClass($this);
                $reflectionProperty = $class->getProperty($property);
                $type = $reflectionProperty->getType();
                if (str_contains((string) $type, 'DateTime') && is_string($value)) {
                    $value = new DateTime($value);
                }
                $methodName = sprintf('%s%s', 'set', $property);
                $this->{$methodName}($value);
            }
        }
    }
}