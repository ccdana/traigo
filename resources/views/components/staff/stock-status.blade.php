@props(['available'])

@use('App\Services\Warehouse\StockOverview')

@php($status = StockOverview::status($available))

<span {{ $attributes->class([
    'badge',
    'bg-danger-soft text-danger' => $status === 'out',
    'bg-warn-soft text-warn' => $status === 'low',
    'bg-ok-soft text-ok' => $status === 'ok',
]) }}>{{ StockOverview::STATUSES[$status] }}</span>
