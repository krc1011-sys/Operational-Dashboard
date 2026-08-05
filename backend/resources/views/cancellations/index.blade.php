<x-operon-page title="Cancellations">
    

    <div class="op-legacy">
        <div style="display:flex;flex-direction:column;gap:16px">

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
            <div class="panel">
                <h3 class="font-semibold text-lg">
                    Needs your decision
                    @if($pending->isNotEmpty())
                        <span class="ms-2 inline-block bg-amber-100 text-amber-900 text-sm px-3 py-1 rounded-full">
                            {{ $pending->count() }}
                        </span>
                    @endif
                </h3>

                <p class="text-sm mt-2 max-w-3xl" style="color:var(--muted)">
                    Amazon has cancelled units that we had already booked into a delivery, or already
                    shipped. Nothing has been netted off — no figure moves until you answer, so what
                    you see on every other screen is still the picture you had before these arrived.
                </p>

                @if($pending->isEmpty())
                    <p class="mt-4 text-sm" style="color:var(--faint)">
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
                                <span class="font-mono" style="color:var(--muted)">{{ $cancellation->sku_id }}</span>
                                <span class="text-sm" style="color:var(--muted)">{{ $cancellation->description }}</span>
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
                                    <div class="border border-amber-200 rounded p-3">
                                        <div class="text-xs" style="color:var(--faint)">{{ $label }}</div>
                                        <div class="font-semibold">{{ number_format((int) $value) }}</div>
                                    </div>
                                @endforeach
                            </div>

                            @if($stuck > 0)
                                <p class="mt-3 text-sm note bad p-3" style="color:var(--bad)">
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

                                <p class="mt-2 text-xs" style="color:var(--muted)">
                                    <strong>Pull it</strong> — take {{ number_format(min($cancellation->qty_cancelled, $pullable)) }}
                                    unit(s) back off the delivery and off accepted. ·
                                    <strong>Deliver anyway</strong> — send all
                                    {{ number_format($cancellation->qty_cancelled) }} regardless; they keep counting
                                    as delivered and we accept the chargeback risk.
                                </p>
                            @else
                                <p class="mt-4 text-xs" style="color:var(--muted)">
                                    You can see this queue but not answer it. Answering is Admin-only for
                                    now — it commits us to shipping, or not shipping, against Amazon's
                                    cancellation.
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- What we shipped despite a cancellation: the chargeback exposure (§G, §M). --}}
            <div class="panel">
                <h3 class="font-semibold text-lg">Chargeback exposure</h3>
                <p class="text-sm mt-2 max-w-3xl" style="color:var(--muted)">
                    Units we sent after Amazon cancelled them. Amazon's own notice says anything shipped
                    after the notification can be charged back, so this list is the running risk.
                </p>

                @if($exposure->isEmpty())
                    <p class="mt-4 text-sm" style="color:var(--faint)">None. Nothing has been shipped against a cancellation.</p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="tbl">
                            <thead >
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
                            <tbody >
                                @foreach($exposure as $row)
                                    <tr>
                                        <td class="py-2 pe-4 font-mono">{{ $row->po_number }}</td>
                                        <td class="py-2 pe-4 font-mono">{{ $row->sku_id }}</td>
                                        <td class="py-2 pe-4 text-right">{{ number_format($row->qty_cancelled) }}</td>
                                        <td class="py-2 pe-4 text-right font-semibold" style="color:var(--bad)">
                                            {{ number_format($row->qty_delivered_anyway) }}
                                        </td>
                                        <td class="py-2 pe-4">{{ $row->resolution->label() }}</td>
                                        <td class="py-2 pe-4" style="color:var(--muted)">
                                            {{ $row->resolvedBy?->name ?? '—' }}
                                            @if($row->resolved_at)
                                                <span class="text-xs">{{ $row->resolved_at->format('d M Y') }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2" style="color:var(--muted)">{{ $row->resolution_note }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="shadow-sm sm:rounded-lg p-6 text-sm space-y-1" style="color:var(--muted)">
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
</x-operon-page>
