<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">PO lookup</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <x-filter-bar
                :filters="$filters"
                :action="route('po-lookup.index')"
                :show="['dates', 'channels', 'fc', 'brand', 'category', 'po', 'search', 'skus']" />

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    {{-- The PO's own state, which is not the same thing as a line's state. --}}
                    <div class="flex flex-wrap gap-2">
                        @foreach(['' => 'All POs', 'open' => 'Open', 'complete' => 'Complete', 'late' => 'Past the ' . $benchmark . '-day benchmark'] as $value => $label)
                            <a href="{{ route('po-lookup.index', $filters->query(array_filter(['po_status' => $value ?: null]))) }}"
                               class="text-sm px-3 py-1.5 rounded-full border
                                      {{ ($poStatus ?? '') === $value ? 'bg-teal-700 text-white border-teal-700' : 'border-gray-300 text-gray-700 hover:bg-gray-50' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>

                    <a href="{{ route('po-lookup.index', $filters->query(array_filter(['export' => 'csv', 'po_status' => $poStatus]))) }}"
                       class="text-sm px-4 py-2 border border-teal-700 text-teal-800 rounded-md hover:bg-teal-50">
                        Export CSV
                    </a>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-gray-500 border-b">
                        <tr>
                            <th class="py-2 pe-4">PO</th>
                            <th class="py-2 pe-4">Ordered</th>
                            <th class="py-2 pe-4">FC</th>
                            <th class="py-2 pe-4 text-right">Lines</th>
                            <th class="py-2 pe-4 text-right">Net accepted</th>
                            <th class="py-2 pe-4 text-right">Shipped</th>
                            <th class="py-2 pe-4 text-right">Fill %</th>
                            <th class="py-2">Turnaround</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($orders as $order)
                            @php
                                $accepted = (int) $order->accepted;
                                $fill = $accepted > 0 ? round((int) $order->shipped / $accepted * 100, 1) : null;
                                $days = $order->turnaroundDays();
                                $late = $order->isBreachingBenchmark();
                            @endphp
                            <tr>
                                <td class="py-2 pe-4">
                                    <a href="{{ route('po-lookup.show', $order->po_number) }}"
                                       class="font-mono text-teal-800 underline">{{ $order->po_number }}</a>
                                </td>
                                <td class="py-2 pe-4">{{ $order->order_date?->format('d M Y') ?? '—' }}</td>
                                <td class="py-2 pe-4">{{ $order->ship_to_fc }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format($order->lines_count) }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format($accepted) }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format((int) $order->shipped) }}</td>
                                <td class="py-2 pe-4 text-right">{{ $fill === null ? '—' : $fill . '%' }}</td>
                                <td class="py-2 {{ $late ? 'text-red-800 font-semibold' : '' }}">
                                    @if($order->is_complete)
                                        Complete{{ $days === null ? '' : ' in ' . $days . ' days' }}
                                    @elseif($days !== null)
                                        {{ $days }} days and counting
                                    @else
                                        Open
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-6 text-center text-gray-500">No POs match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="mt-4">{{ $orders->links() }}</div>
            </div>

        </div>
    </div>
</x-app-layout>
