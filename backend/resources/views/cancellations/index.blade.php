<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Cancellations</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(session('status'))
                <div class="bg-green-50 border border-green-200 text-green-900 rounded-lg p-4 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            @if(session('error'))
                <div class="bg-red-50 border border-red-200 text-red-900 rounded-lg p-4 text-sm">
                    {{ session('error') }}
                </div>
            @endif

            {{-- The queue: cancellations the system refused to guess about (§G). --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-lg">
                    Needs your decision
                    @if($pending->isNotEmpty())
                        <span class="ms-2 inline-block bg-amber-100 text-amber-900 text-sm px-3 py-1 rounded-full">
                            {{ $pending->count() }}
                        </span>
                    @endif
                </h3>

                <p class="text-sm text-gray-600 mt-2 max-w-3xl">
                    Amazon has cancelled units that we had already booked into a delivery, or already
                    shipped. Nothing has been netted off — no figure moves until you answer, so what
                    you see on every other screen is still the picture you had before these arrived.
                </p>

                @if($pending->isEmpty())
                    <p class="mt-4 text-sm text-gray-500">
                        Nothing waiting. Cancellations that only touch units nobody had claimed yet are
                        netted off automatically and never appear here.
                    </p>
                @endif

                <div class="mt-6 space-y-6">
                    @foreach($pending as $cancellation)
                        @php
                            $line = $cancellation->poLine;
                            $free = $decider->freeUnits($line);
                            $pullable = $decider->pullableUnits($line);
                            $stuck = max(0, $cancellation->qty_cancelled - $pullable);
                        @endphp

                        <div class="border border-amber-300 bg-amber-50 rounded-lg p-5">
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <span class="font-mono font-semibold">{{ $cancellation->po_number }}</span>
                                <span class="font-mono text-gray-700">{{ $cancellation->sku_id }}</span>
                                <span class="text-gray-600 text-sm">{{ $cancellation->description }}</span>
                            </div>

                            <p class="mt-3 text-sm">
                                Amazon wants to cancel
                                <strong>{{ number_format($cancellation->qty_cancelled) }}</strong> unit(s), but only
                                <strong>{{ number_format($free) }}</strong> are still free.
                            </p>

                            <div class="mt-3 grid grid-cols-2 sm:grid-cols-5 gap-3 text-sm">
                                @foreach([
                                    'Accepted' => $line?->qty_accepted,
                                    'Booked' => $line?->qty_booked,
                                    'Shipped' => $line?->qty_shipped,
                                    'Still free' => $free,
                                    'Cancelled' => $cancellation->qty_cancelled,
                                ] as $label => $value)
                                    <div class="bg-white border border-amber-200 rounded p-3">
                                        <div class="text-xs text-gray-500">{{ $label }}</div>
                                        <div class="font-semibold">{{ number_format((int) $value) }}</div>
                                    </div>
                                @endforeach
                            </div>

                            @if($stuck > 0)
                                <p class="mt-3 text-sm text-red-800 bg-red-50 border border-red-200 rounded p-3">
                                    <strong>{{ number_format($stuck) }}</strong> of these units have already
                                    shipped and cannot be pulled back whichever way you answer. Those units stay
                                    counted as delivered, and the line is flagged for chargeback exposure.
                                </p>
                            @endif

                            @if($canDecide)
                                <form method="POST" action="{{ route('cancellations.decide', $cancellation) }}"
                                      class="mt-4 flex flex-wrap items-center gap-3">
                                    @csrf

                                    <input type="text" name="note" maxlength="500"
                                           placeholder="Note (optional) — e.g. who confirmed it"
                                           class="border-gray-300 rounded-md shadow-sm text-sm grow min-w-64" />

                                    <button type="submit" name="choice" value="pulled_back"
                                            class="px-4 py-2 bg-gray-800 text-white text-sm rounded-md hover:bg-gray-700">
                                        Pull it
                                    </button>

                                    <button type="submit" name="choice" value="delivered_anyway"
                                            class="px-4 py-2 bg-red-700 text-white text-sm rounded-md hover:bg-red-600">
                                        Deliver anyway
                                    </button>
                                </form>

                                <p class="mt-2 text-xs text-gray-600">
                                    <strong>Pull it</strong> — take {{ number_format(min($cancellation->qty_cancelled, $pullable)) }}
                                    unit(s) back off the delivery and off accepted. ·
                                    <strong>Deliver anyway</strong> — send all
                                    {{ number_format($cancellation->qty_cancelled) }} regardless; they keep counting
                                    as delivered and we accept the chargeback risk.
                                </p>
                            @else
                                <p class="mt-4 text-xs text-gray-600">
                                    You can see this queue but not answer it — that needs the
                                    “edit fulfilment” permission.
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- What we shipped despite a cancellation: the chargeback exposure (§G, §M). --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-lg">Chargeback exposure</h3>
                <p class="text-sm text-gray-600 mt-2 max-w-3xl">
                    Units we sent after Amazon cancelled them. Amazon's own notice says anything shipped
                    after the notification can be charged back, so this list is the running risk.
                </p>

                @if($exposure->isEmpty())
                    <p class="mt-4 text-sm text-gray-500">None. Nothing has been shipped against a cancellation.</p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-gray-500 border-b">
                                <tr>
                                    <th class="py-2 pe-4">PO</th>
                                    <th class="py-2 pe-4">ASIN</th>
                                    <th class="py-2 pe-4 text-right">Cancelled</th>
                                    <th class="py-2 pe-4 text-right">Delivered anyway</th>
                                    <th class="py-2 pe-4">Decision</th>
                                    <th class="py-2 pe-4">Who / when</th>
                                    <th class="py-2">Note</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @foreach($exposure as $row)
                                    <tr>
                                        <td class="py-2 pe-4 font-mono">{{ $row->po_number }}</td>
                                        <td class="py-2 pe-4 font-mono">{{ $row->sku_id }}</td>
                                        <td class="py-2 pe-4 text-right">{{ number_format($row->qty_cancelled) }}</td>
                                        <td class="py-2 pe-4 text-right font-semibold text-red-700">
                                            {{ number_format($row->qty_delivered_anyway) }}
                                        </td>
                                        <td class="py-2 pe-4">{{ $row->resolution->label() }}</td>
                                        <td class="py-2 pe-4 text-gray-600">
                                            {{ $row->resolvedBy?->name ?? '—' }}
                                            @if($row->resolved_at)
                                                <span class="text-xs">{{ $row->resolved_at->format('d M Y') }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 text-gray-600">{{ $row->resolution_note }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm text-gray-600 space-y-1">
                <p><strong>{{ number_format($netted) }}</strong> unit(s) have been netted off accepted quantities in total.</p>
                @if($waiting > 0)
                    <p>
                        <strong>{{ $waiting }}</strong> cancellation(s) name a PO we have not uploaded yet. They are
                        stored, and they will net by themselves the moment that PO arrives — nothing to do.
                    </p>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
