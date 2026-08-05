<x-operon-page title="PO lookup" sub="{{ number_format($orders->total()) }} purchase orders in view">
    <x-slot:controls>
        {{-- The PO's own state, which is not the same thing as a line's state. --}}
        <div class="seg">
            @foreach (['' => 'All', 'open' => 'Open', 'complete' => 'Complete', 'late' => 'Late'] as $value => $label)
                <a class="{{ ($poStatus ?? '') === $value ? 'on' : '' }}"
                   href="{{ route('po-lookup.index', $filters->query(array_filter(['po_status' => $value ?: null]))) }}">{{ $label }}</a>
            @endforeach
        </div>
    </x-slot:controls>

    <x-filters :filters="$filters" :action="route('po-lookup.index')"
               :exportHref="route('po-lookup.index', $filters->query(array_filter(['export' => 'csv', 'po_status' => $poStatus])))"
               :show="['dates', 'channels', 'fc', 'brand', 'category', 'po', 'search', 'skus']" />

    <x-panel flush title="Purchase orders"
             sub="Search one to see every delivery its units went into, and the turnaround clock">
        <div class="scroll-x">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>PO</th>
                        <th>Ordered</th>
                        <th>FC</th>
                        <th class="num">Lines</th>
                        <th class="num">Net accepted</th>
                        <th class="num">Shipped</th>
                        <th>Fill rate</th>
                        <th>Turnaround</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        @php
                            $accepted = (int) $order->accepted;
                            $fill = $accepted > 0 ? round((int) $order->shipped / $accepted * 100, 1) : null;
                            $days = $order->turnaroundDays();
                            $late = $order->isBreachingBenchmark();
                        @endphp
                        <tr>
                            <td><a class="mono" href="{{ route('po-lookup.show', $order->po_number) }}">{{ $order->po_number }}</a></td>
                            <td>{{ $order->order_date?->format('d M Y') ?? '—' }}</td>
                            <td>{{ $order->ship_to_fc ?? '—' }}</td>
                            <td class="num">{{ number_format($order->lines_count) }}</td>
                            <td class="num">{{ number_format($accepted) }}</td>
                            <td class="num">{{ number_format((int) $order->shipped) }}</td>
                            <td>
                                @if ($fill === null)
                                    <span style="color:var(--faint)">—</span>
                                @else
                                    <x-mini-bar :pct="$fill" />
                                    {{ $fill }}%
                                @endif
                            </td>
                            <td>
                                @if ($order->is_complete)
                                    <span class="tag {{ $late ? 'bad' : 'good' }}">
                                        Complete{{ $days === null ? '' : ' in '.$days.'d' }}
                                    </span>
                                @elseif ($days !== null)
                                    <span class="tag {{ $late ? 'bad' : '' }}">{{ $days }}d and counting</span>
                                @else
                                    <span class="tag">Open</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty">No POs match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pg">{{ $orders->links() }}</div>
    </x-panel>
</x-operon-page>
