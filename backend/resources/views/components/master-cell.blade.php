{{--
    One cell of the master grid.

    Editable for an Admin who has entered the PIN, plain text for everyone else — the
    same cell, so the grid does not change shape depending on who is looking at it. The
    input saves when you leave it, which is what makes this feel like a spreadsheet
    rather than a form (§S Path A).
--}}
@props([
    'editable' => false,
    'id',
    'kind',        // products | economics
    'field',
    'value' => null,
    'align' => 'left',
    'narrow' => false,
])

@if ($editable)
    <input
        value="{{ $value }}"
        data-original="{{ $value }}"
        @change="save($el, '{{ $kind }}', {{ $id }}, '{{ $field }}')"
        class="border-transparent hover:border-gray-300 focus:border-teal-500 focus:ring-teal-500
               rounded text-sm bg-transparent transition-colors
               {{ $narrow ? 'w-20 px-1 py-0.5 text-xs' : 'w-full px-2 py-1' }}
               {{ $align === 'right' ? 'text-right' : '' }}"
    >
@else
    <span class="block {{ $narrow ? 'text-xs' : 'px-2 py-1' }} {{ $align === 'right' ? 'text-right' : '' }}">
        {{ $value === null || $value === '' ? '—' : $value }}
    </span>
@endif
