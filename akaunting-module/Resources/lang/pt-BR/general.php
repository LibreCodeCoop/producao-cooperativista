<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
    'name' => 'Produção cooperativista',
    'description' => 'Demonstrativos de produção e pagamentos dos cooperados',
    'member_history' => 'Meu histórico de produção',
    'private_history_notice' => 'Somente seus demonstrativos são exibidos. Estimativas não representam valores aprovados para pagamento.',
    'paid_chart' => 'Pagamentos por mês trabalhado',
    'chart_explanation' => 'O gráfico exibe somente pagamentos registrados. Valores ausentes não são considerados zero.',
    'no_confirmed_payments' => 'Não há valores de pagamento disponíveis neste histórico.',
    'payment_for' => 'Pagamento referente a',
    'paid' => 'Valor pago',
    'worked_competence' => 'Mês trabalhado',
    'financial_competence' => 'Competência financeira',
    'payment_competence' => 'Mês do repasse',
    'observed_hours' => 'Horas registradas',
    'last_source_update' => 'Última atualização da fonte',
    'unavailable' => 'Indisponível',
    'no_history' => 'Não há demonstrativos de produção disponíveis para esta conta.',
    'statuses' => [
        'observed' => 'Registrado, não aprovado',
        'estimated' => 'Estimativa, não aprovada',
        'approved' => 'Aprovado, não necessariamente pago',
        'billed' => 'Faturado, não necessariamente pago',
        'partially_paid' => 'Parcialmente pago',
        'paid' => 'Pago',
        'unavailable' => 'Indisponível',
    ],
    'money' => [
        'eligible_production_cents' => 'Produção elegível',
        'approved_gross_cents' => 'Bruto aprovado',
        'deductions_cents' => 'Descontos',
        'approved_net_cents' => 'Líquido aprovado',
        'billed_cents' => 'Faturado',
        'paid_cents' => 'Pago',
    ],
];
