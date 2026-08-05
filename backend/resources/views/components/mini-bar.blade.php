{{-- A mini progress bar (§5). Tone follows the value against its benchmark. --}}
@props(['pct' => 0, 'tone' => null, 'target' => 95])

@php
    $pct = max(0, min(100, (float) $pct));
    $tone ??= $pct >= $target ? '' : ($pct >= $target - 7 ? 'w' : 'b');
@endphp

<span class="mini {{ $tone }}"><i style="width:{{ $pct }}%"></i></span>
