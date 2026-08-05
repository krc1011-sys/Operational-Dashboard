{{-- Thin wrapper so a screen writes <x-operon-page> rather than repeating the shell. --}}
@props(['title' => 'OperON', 'sub' => null])

<x-layouts.operon :title="$title" :sub="$sub">
    @isset($controls)
        <x-slot:controls>{{ $controls }}</x-slot:controls>
    @endisset

    {{ $slot }}
</x-layouts.operon>
