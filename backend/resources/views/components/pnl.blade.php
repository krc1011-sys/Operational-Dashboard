{{--
    A P&L statement, read top to bottom (§Profitability).

        <x-pnl :statement="$pnl" />

    One column of figures where every line either adds to or takes from the one above it,
    and the bottom line is the profit. Deductions carry their minus sign rather than being
    shown as bare positives with a word next to them — a column you can add up in your head
    is the point of a statement.

    The zero lines are deliberate: a cost the master sheet has no figure for still gets its
    row, reading 0 and tagged, because a P&L with a cost line missing reads as a P&L with
    no such cost — a different and much more flattering claim.
--}}
@props(['statement', 'compact' => false])

@php
    use App\Services\Margin\ProfitAndLoss;

    $currency = $statement['currency'];
    $mixed = $currency === null;
@endphp

@if (! $statement['costable'])
    <x-empty title="No line on this PO can be costed"
             note="Every SKU on it is missing from the master catalog, so there is no cost to set against the invoice. Order value is still shown on the PO itself — what is unknown here is what we made, not what it was worth." />
@else
    <table class="pnl {{ $compact ? 'compact' : '' }}">
        <tbody>
            @foreach ($statement['lines'] as $line)
                <tr class="{{ $line['kind'] }}{{ $line['kind'] === ProfitAndLoss::RESULT && (float) $line['amount'] < 0 ? ' loss' : '' }}">
                    <th scope="row">
                        <span class="l">
                            {{ $line['label'] }}
                            @if (! empty($line['pending']))
                                <span class="tag amber">{{ ProfitAndLoss::UNTIL_DATA_ADDED }}</span>
                            @endif
                        </span>
                        @if (! $compact && $line['note'])<span class="n">{{ $line['note'] }}</span>@endif
                    </th>
                    <td>
                        @if ($line['amount'] === null)
                            <span style="color:var(--faint)">—</span>
                        @else
                            @if ($line['amount'] < 0)−@endif<x-money :amount="abs($line['amount'])"
                                     :currency="$currency" :mixed="$mixed" />
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="pnlfoot">
        <div>
            <b>Margin
                {{ $statement['margin_pct'] === null ? '—' : $statement['margin_pct'].'%' }}</b>
            of what we bank, not of what we billed.
            @if ($statement['margin_pct'] !== null && $statement['net_receivable'] > 0)
                Billed {{ \App\Support\Currency::plain($statement['billed'], $currency) }} →
                net {{ \App\Support\Currency::plain($statement['profit'], $currency) }}.
            @endif
        </div>

        @if ($statement['pending'] !== [])
            <div>
                <b>{{ implode(', ', $statement['pending']) }}</b>
                {{ count($statement['pending']) === 1 ? 'reads' : 'read' }} 0 because the master sheet
                carries no figure for {{ count($statement['pending']) === 1 ? 'it' : 'them' }} yet. The
                {{ count($statement['pending']) === 1 ? 'line is' : 'lines are' }} wired to the same engine as
                every other cost and will fill in on their own once the data is added — nothing here needs rebuilding.
            </div>
        @endif

        @unless ($statement['coverage']['complete'])
            <div class="warn">
                <b>{{ $statement['coverage']['lines_uncosted'] }} line(s)</b> of this PO
                ({{ number_format($statement['coverage']['units_uncosted']) }} units) are not in the master
                catalog. They are left out of BOTH the revenue and the cost, so they cannot read as pure
                profit — but it does mean this profit covers only part of the order.
            </div>
        @endunless

        <div>
            Product cost is the <b>{{ $statement['cost_basis'] }}</b> supplier price. Where a product has
            several suppliers this takes the most recent; it becomes a weighted average across supplier POs
            in Phase 3, when those uploads arrive.
        </div>
    </div>
@endif
