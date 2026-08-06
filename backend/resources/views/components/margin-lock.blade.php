{{--
    The unlock nudge (§Profitability, M7).

        <x-margin-lock>Margin and profit on this PO</x-margin-lock>

    Shown ONLY to someone the PIN would actually help — a person holding `view-margin` who
    has not entered it. Everyone else sees nothing at all, because a padlock you have no
    key to is noise rather than security, and it invites a request that will be refused.

    Rendering nothing is the common case and is deliberate: most roles never learn that
    these columns exist.
--}}
@props(['inline' => false])

@if (\App\Support\MoneyGate::needsUnlock())
    <div class="note {{ $inline ? '' : 'warn' }}"
         style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
             stroke-width="2" style="flex:none" aria-hidden="true">
            <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
        </svg>

        <div style="flex:1;min-width:200px">
            <b>{{ $slot->isEmpty() ? 'Margin and profit are hidden' : $slot }}</b> — enter the PIN to show
            {{ $inline ? 'the money columns' : 'them' }}. They stay visible while you work and lock again
            after {{ \App\Support\MoneyGate::timeoutMinutes() }} idle minutes.
        </div>

        <a class="btn" href="{{ route('money-pin.prompt') }}">Unlock</a>
    </div>
@endif
