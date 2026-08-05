{{--
    An honest empty state. Says WHY there is nothing here and what would fill it, rather
    than leaving a blank panel that reads as a bug.
--}}
@props(['title', 'note' => null])

<div class="empty-state">
    <b>{{ $title }}</b>
    @if ($note){{ $note }}@endif
    {{ $slot }}
</div>
