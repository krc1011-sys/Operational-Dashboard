{{--
    One amount, rendered with its own currency's symbol.

        <x-money :amount="$line->qty_shipped * $line->unit_cost" :currency="$line->currency" />

    Always pass the currency stored on the row being displayed. Leaving it out falls back
    to the configured default, which is right for a total the engine has already confirmed
    is single-currency and wrong for anything else.

    When `mixed` is true the caller is telling us the rows it summed were not all in one
    currency, so we refuse to print a figure that would be nonsense.
--}}
@props(['amount', 'currency' => null, 'mixed' => false])

@if ($mixed)
    <span {{ $attributes->merge(['class' => 'whitespace-nowrap text-amber-700']) }}
          title="These rows are in more than one currency, so they cannot be added up.">
        mixed currencies
    </span>
@else
    <span {{ $attributes }}>{!! \App\Support\Currency::html($amount, $currency) !!}</span>
@endif
