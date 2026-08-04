@props([
    'label',
    'value',
    'sub' => null,
    'target' => null,
    'status' => 'neutral', // good | warn | bad | neutral
    'href' => null,
])

@php
    // §M: mirror Amazon's own scorecard colours rather than inventing thresholds.
    $ring = match ($status) {
        'good' => 'border-green-300 bg-green-50',
        'warn' => 'border-amber-300 bg-amber-50',
        'bad' => 'border-red-300 bg-red-50',
        default => 'border-gray-200 bg-white',
    };

    $tone = match ($status) {
        'good' => 'text-green-900',
        'warn' => 'text-amber-900',
        'bad' => 'text-red-900',
        default => 'text-gray-900',
    };
@endphp

<{{ $href ? 'a' : 'div' }} @if($href) href="{{ $href }}" @endif
    class="block border rounded-lg p-4 {{ $ring }} {{ $href ? 'hover:shadow-sm transition' : '' }}">
    <div class="text-xs text-gray-600">{{ $label }}</div>
    <div class="text-2xl font-semibold mt-1 {{ $tone }}">{{ $value }}</div>
    @if($sub)
        <div class="text-xs text-gray-600 mt-1">{{ $sub }}</div>
    @endif
    @if($target)
        <div class="text-xs text-gray-500 mt-1">Target {{ $target }}</div>
    @endif
</{{ $href ? 'a' : 'div' }}>
