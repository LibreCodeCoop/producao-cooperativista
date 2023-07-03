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

namespace ProducaoCooperativista\Service;

class INSS
{
    private float $baseMaxima = 7087.22;
    private float $aliquotaProducaoInterna = 0.10;
    private float $aliquotaProducaoExterna = 0.20;
    public const PRODUCAO_INTERNA = 1;
    public const PRODUCAO_EXTERNA = 2;
    public function __construct(
        private int $tipoProducao = self::PRODUCAO_EXTERNA
    ) {
    }

    public function calcula(float $base): float
    {
        if ($this->producaoInterna()) {
            if ($base > $this->baseMaxima) {
                return $this->baseMaxima * $this->aliquotaProducaoInterna;
            }
            return $base * $this->aliquotaProducaoInterna;
        }
        if ($this->producaoExterna()) {
            if ($base > $this->baseMaxima) {
                return $this->baseMaxima * $this->aliquotaProducaoExterna;
            }
            return $base * $this->aliquotaProducaoExterna;
        }
        return 0;
    }

    private function producaoExterna(): bool
    {
        return $this->tipoProducao === self::PRODUCAO_EXTERNA;
    }

    private function producaoInterna(): bool
    {
        return $this->tipoProducao === self::PRODUCAO_INTERNA;
    }
}
