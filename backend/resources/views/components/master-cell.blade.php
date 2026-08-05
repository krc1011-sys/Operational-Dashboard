{{--
    One cell of the master grid.

    Editable for an Admin who has entered the PIN, plain text for everyone else — the
    same cell either way, so the grid does not change shape depending on who is looking
    at it. The input saves when you leave it, which is what makes this feel like a
    spreadsheet rather than a form (§S Path A).

    Borderless until hovered so a screenful of inputs reads as a table, not a form.
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
        class="inp"
        style="border-color:transparent;background:transparent;
               {{ $narrow ? 'width:74px;padding:2px 4px;font-size:10.5px;' : 'min-width:110px;padding:4px 6px;' }}
               {{ $align === 'right' ? 'text-align:right;' : '' }}"
        onmouseover="this.style.borderColor='var(--border-2)'"
        onmouseout="if(document.activeElement!==this)this.style.borderColor='transparent'"
        onblur="this.style.borderColor='transparent'"
    >
@else
    <span style="display:block;{{ $narrow ? 'font-size:10.5px;' : 'padding:4px 6px;' }}
                 {{ $align === 'right' ? 'text-align:right;' : '' }}">
        {{ $value === null || $value === '' ? '—' : $value }}
    </span>
@endif
