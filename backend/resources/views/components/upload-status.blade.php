@props(['status'])

@php
    $classes = match ($status) {
        \App\Enums\SourceFileStatus::Imported => 'bg-green-100 text-green-800',
        \App\Enums\SourceFileStatus::Validated => 'bg-amber-100 text-amber-800',
        \App\Enums\SourceFileStatus::Rejected,
        \App\Enums\SourceFileStatus::Failed => 'bg-red-100 text-red-800',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-block whitespace-nowrap text-xs px-2.5 py-1 rounded-full font-medium $classes"]) }}>
    {{ $status->label() }}
</span>
