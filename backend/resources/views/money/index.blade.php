@php
    use App\Http\Controllers\MoneyController;
    use App\Services\Margin\SkuMargin;
    use App\Support\Currency;
    use App\Support\MoneyGate;

    $isPo = $view === MoneyController::VIEW_PO;
@endphp

<x-operon-page title="Profitability"
               sub="{{ $isPo ? 'What each order made' : 'What each product makes' }} · Admin only, PIN unlocked for {{ MoneyGate::minutesRemaining() }} more min">

    <x-slot:controls>
        {{-- The two questions of §Profitability, one section. --}}
        <div class="seg">
            <a class="{{ $isPo ? 'on' : '' }}"
               href="{{ route('money.index', $filters->query(['view' => MoneyController::VIEW_PO])) }}">By PO</a>
            <a class="{{ $isPo ? '' : 'on' }}"
               href="{{ route('money.index', $filters->query(['view' => MoneyController::VIEW_SKU])) }}">By SKU</a>
        </div>

        <form method="POST" action="{{ route('money-pin.lock') }}" style="margin:0">
            @csrf
            <button class="pill" type="submit" title="Hide money figures now">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                     stroke-width="2" aria-hidden="true">
                    <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                </svg>
                Lock
            </button>
        </form>
    </x-slot:controls>

    @if ($isPo)
        @include('money.partials.by-po')
    @else
        @include('money.partials.by-sku')
    @endif
</x-operon-page>
