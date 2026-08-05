@php($showValue = auth()->user()->canSeeOrderValue())

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Pending — accepted but not booked</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <x-filter-bar
                :filters="$filters"
                :action="route('pending.index')"
                :show="['dates', 'channels', 'fc', 'brand', 'category', 'po', 'search', 'skus']" />

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="text-xs text-gray-500">Units accepted with no delivery booked yet</div>
                        <div class="text-2xl font-semibold">{{ number_format($totals['not_booked']) }}</div>
                        <p class="text-xs text-gray-600 mt-1">
                            Net accepted minus what is on an interim packing list. A line leaves this
                            screen when it is booked onto a delivery, or when it is cancelled.
                        </p>
                    </div>

                    <a href="{{ route('pending.index', $filters->query(['export' => 'csv'])) }}"
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
                            <th class="py-2 pe-4">ASIN / NIN</th>
                            <th class="py-2 pe-4">FC</th>
                            <th class="py-2 pe-4">Expected</th>
                            <th class="py-2 pe-4 text-right">Net accepted</th>
                            <th class="py-2 pe-4 text-right">Booked</th>
                            <th class="py-2 pe-4 text-right">Not booked</th>
                            @if($showValue)<th class="py-2 text-right">Value (AED)</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($lines as $line)
                            <tr>
                                <td class="py-2 pe-4">
                                    <a href="{{ route('po-lookup.show', $line->po_number) }}"
                                       class="font-mono text-teal-800 underline">{{ $line->po_number }}</a>
                                </td>
                                <td class="py-2 pe-4">
                                    <span class="font-mono">{{ $line->sku_id }}</span>
                                    <div class="text-xs text-gray-500">{{ Str::limit($line->title, 50) }}</div>
                                </td>
                                <td class="py-2 pe-4">{{ $line->ship_to_fc }}</td>
                                <td class="py-2 pe-4">{{ $line->expected_date?->format('d M Y') ?? '—' }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format($line->qty_net_accepted) }}</td>
                                <td class="py-2 pe-4 text-right">{{ number_format($line->qty_booked) }}</td>
                                <td class="py-2 pe-4 text-right font-semibold">{{ number_format($line->qty_not_booked) }}</td>
                                @if($showValue)
                                    <td class="py-2 text-right">
                                        {{ number_format($line->qty_not_booked * (float) $line->unit_cost, 2) }}
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-6 text-center text-gray-500">
                                    Nothing pending. Everything accepted is either booked or cancelled.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="mt-4">{{ $lines->links() }}</div>
            </div>

        </div>
    </div>
</x-app-layout>
