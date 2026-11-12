<x-layouts.portal>
    <x-slot name="title">
        {{ trans('cooperative-production::general.member_history') }}
    </x-slot>

    <x-slot name="content">
        <section class="max-w-6xl mx-auto px-4 py-6 space-y-6" aria-labelledby="production-history-title">
            <header>
                <h1 id="production-history-title" class="text-2xl font-semibold">
                    {{ trans('cooperative-production::general.member_history') }}
                </h1>
                <p class="text-sm mt-2 text-gray-600">
                    {{ trans('cooperative-production::general.private_history_notice') }}
                </p>
            </header>

            @forelse ($records as $record)
                @if ($loop->first)
                    <section aria-labelledby="production-chart-title" class="rounded-lg border p-4">
                        <h2 id="production-chart-title" class="font-semibold mb-3">
                            {{ trans('cooperative-production::general.paid_chart') }}
                        </h2>
                        <p class="text-xs text-gray-600 mb-4">
                            {{ trans('cooperative-production::general.chart_explanation') }}
                        </p>
                        @php
                            $paidValues = array_values(array_filter(
                                array_map(static fn (array $item): ?int => $item['money']['paid_cents'], $records),
                                static fn (?int $amount): bool => $amount !== null && $amount >= 0
                            ));
                            $maxPaid = max(1, ...$paidValues);
                        @endphp
                        @if ($paidValues === [])
                            <p>{{ trans('cooperative-production::general.no_confirmed_payments') }}</p>
                        @else
                            <div class="space-y-3">
                                @foreach ($records as $row)
                                    @if ($row['money']['paid_cents'] !== null && $row['money']['paid_cents'] >= 0)
                                        @php
                                            $paid = $row['money']['paid_cents'];
                                            $percent = min(100, max(0, round(100 * $paid / $maxPaid, 1)));
                                        @endphp
                                        <div class="grid grid-cols-4 gap-3 items-center">
                                            <span class="text-sm">{{ $row['worked_competence'] }}</span>
                                            <div class="col-span-2 bg-gray-100 rounded h-4" role="img"
                                                aria-label="{{ trans('cooperative-production::general.payment_for') }} {{ $row['worked_competence'] }}">
                                                <div class="bg-blue-700 rounded h-4" style="width: {{ $percent }}%"></div>
                                            </div>
                                            <span class="text-sm text-right" aria-label="{{ trans('cooperative-production::general.paid') }}">
                                                {{ number_format($paid / 100, 2, ',', '.') }}
                                            </span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endif

                <article class="rounded-lg border p-4 space-y-3">
                    <header class="flex flex-wrap justify-between gap-2">
                        <h2 class="font-semibold">{{ $record['worked_competence'] }}</h2>
                        <span class="text-sm">{{ trans('cooperative-production::general.statuses.' . $record['status']) }}</span>
                    </header>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
                        <div><dt>{{ trans('cooperative-production::general.worked_competence') }}</dt>
                            <dd>{{ $record['worked_competence'] }}</dd></div>
                        <div><dt>{{ trans('cooperative-production::general.financial_competence') }}</dt>
                            <dd>{{ $record['financial_competence'] }}</dd></div>
                        <div><dt>{{ trans('cooperative-production::general.payment_competence') }}</dt>
                            <dd>{{ $record['payment_competence'] }}</dd></div>
                        <div><dt>{{ trans('cooperative-production::general.observed_hours') }}</dt>
                            <dd>{{ $record['hours']['observed_seconds'] === null ? trans('cooperative-production::general.unavailable') : number_format($record['hours']['observed_seconds'] / 3600, 2, ',', '.') }}</dd></div>
                        @foreach (['eligible_production_cents', 'approved_gross_cents', 'deductions_cents', 'approved_net_cents', 'billed_cents', 'paid_cents'] as $field)
                            <div>
                                <dt>{{ trans('cooperative-production::general.money.' . $field) }}</dt>
                                <dd>{{ $record['money'][$field] === null ? trans('cooperative-production::general.unavailable') : 'R$ ' . number_format($record['money'][$field] / 100, 2, ',', '.') }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="text-xs text-gray-600">
                        {{ trans('cooperative-production::general.last_source_update') }}:
                        {{ $record['observed_at'] }}
                    </p>
                </article>
            @empty
                <section role="status" class="rounded-lg border p-6">
                    {{ trans('cooperative-production::general.no_history') }}
                </section>
            @endforelse
        </section>
    </x-slot>
</x-layouts.portal>
