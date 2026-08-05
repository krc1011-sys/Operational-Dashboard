{{--
    A KPI tile (§5): severity stripe · tiny label · big figure · one line of context.

        <x-kpi label="Fill rate" value="95.8" unit="%" tone="good"
               chip="▲ 1.2" chipTone="up" context="completed POs · target 95%" />

    `tone` drives the left stripe, which is how state reads before the number does:
    good / warn / bad / n (neutral) / omitted (teal, the brand default).

    `prefix` carries "AED " so the currency sits inside the figure at the smaller weight,
    as in v3. Plain text, never a glyph (§2).
--}}
@props([
    'label',
    'value',
    'unit' => null,
    'prefix' => null,
    'tone' => '',
    'chip' => null,
    'chipTone' => 'n',
    'context' => null,
    'href' => null,
])

<{{ $href ? 'a' : 'div' }} {{ $href ? 'href='.$href : '' }} {{ $attributes->merge(['class' => 'kpi '.$tone]) }}>
    <div class="k">{{ $label }}</div>
    <div class="v">
        @if ($prefix)<small>{{ $prefix }}</small>@endif{{ $value }}@if ($unit)<small>{{ $unit }}</small>@endif
    </div>
    @if ($chip || $context)
        <div class="d">
            @if ($chip)<span class="chip {{ $chipTone }}">{{ $chip }}</span>@endif
            @if ($context)<span>{{ $context }}</span>@endif
        </div>
    @endif
</{{ $href ? 'a' : 'div' }}>
