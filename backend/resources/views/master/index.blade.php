@php
    use App\Models\MasterAnomaly;
    use App\Support\Currency;

    $kindLabels = MasterAnomaly::kindLabels();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Master catalog</h2>
            <div class="text-sm text-gray-500">
                {{ number_format($stats['products']) }} products ·
                {{ number_format($stats['channel_rows']) }} channel rows
            </div>
        </div>
    </x-slot>

    <div class="py-8" x-data="masterGrid()">
        <div class="max-w-full mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(session('status'))
                <div class="bg-green-50 border border-green-200 text-green-900 rounded-lg p-4 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            {{-- The money gate, said plainly rather than by an empty column (§S). --}}
            @unless($canSeeMoney)
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-700">
                    <strong>Cost and margin are hidden.</strong>
                    This screen is showing the catalog only — what each product is, who owns it and
                    where it sells. Prices, costs and profit are Admin-only and behind the PIN (§S).
                    @can('manage-master')
                        <a href="{{ $pinUnlockUrl }}" class="text-teal-800 underline ms-1">Enter the PIN to unlock and edit</a>.
                    @endcan
                </div>
            @endunless

            {{-- The review queue. Sits above the grid because a flagged product's
                 margin cannot be trusted until somebody answers it. --}}
            @if($anomalies->isNotEmpty())
                {{-- Collapsed when you have arrived from a flag: you came here to fix one
                     product, not to re-read the list you just clicked. --}}
                <div x-data="{ open: {{ ($reviewCount > 0 && ! $focus) ? 'true' : 'false' }} }"
                     class="border rounded-lg {{ $reviewCount > 0 ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white' }}">
                    <button @click="open = !open" class="w-full text-start p-4 flex items-center justify-between">
                        <div>
                            <div class="font-semibold {{ $reviewCount > 0 ? 'text-amber-900' : 'text-gray-800' }}">
                                @if($reviewCount > 0)
                                    {{ $reviewCount }} thing(s) in the catalog need someone to look at them
                                @else
                                    Nothing needs a decision
                                @endif
                            </div>
                            <div class="text-xs text-gray-600 mt-1">
                                Loaded as they are, not corrected.
                                @if($noteCount > 0) {{ $noteCount }} further note(s) worth knowing. @endif
                            </div>
                        </div>
                        <span class="text-sm text-gray-500" x-text="open ? 'Hide' : 'Show'"></span>
                    </button>

                    <div x-show="open" x-cloak class="px-4 pb-4 space-y-2">
                        @foreach($anomalies as $anomaly)
                            <div class="bg-white border rounded p-3 text-sm
                                        {{ $anomaly->severity === MasterAnomaly::SEVERITY_REVIEW ? 'border-amber-300' : 'border-gray-200' }}">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <span class="inline-block text-xs px-2 py-0.5 rounded-full
                                            {{ $anomaly->severity === MasterAnomaly::SEVERITY_REVIEW
                                                ? 'bg-amber-100 text-amber-900' : 'bg-gray-100 text-gray-700' }}">
                                            {{ $kindLabels[$anomaly->kind] ?? $anomaly->kind }}
                                        </span>
                                        @if($anomaly->company_product_code)
                                            {{-- Lands on the product's own row, scrolled to, highlighted and
                                                 with the first cell focused - so the flag leads straight to
                                                 the fix rather than to a filtered page that looks unchanged. --}}
                                            <a href="{{ route('master.index', [
                                                    'q' => $anomaly->company_product_code,
                                                    'focus' => $anomaly->company_product_code,
                                               ]) }}#product-{{ $anomaly->company_product_code }}"
                                               class="font-mono text-teal-800 underline ms-2">{{ $anomaly->company_product_code }}</a>
                                        @endif
                                        <p class="mt-1 text-gray-800">{{ $anomaly->message }}</p>
                                    </div>

                                    @if($canEdit)
                                        <form method="POST" action="{{ route('master.anomalies.resolve', $anomaly) }}">
                                            @csrf
                                            <button class="text-xs whitespace-nowrap border rounded px-2 py-1 hover:bg-gray-50">
                                                Mark reviewed
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Filters. Brand / category / sub-category / owner all come from the
                 catalog this screen loads, which is what M5's filters were waiting for. --}}
            <form method="GET" class="bg-white border rounded-lg p-4 grid gap-3 md:grid-cols-6 items-end">
                <div class="md:col-span-2">
                    <label class="text-xs text-gray-600">Search</label>
                    <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="code, name, brand, ASIN, NIN, barcode"
                           class="w-full border-gray-300 rounded text-sm">
                </div>
                @foreach([['brand','Brand',$brands], ['category','Category',$categories],
                          ['sub_category','Sub-category',$subCategories], ['owner','Owner',$owners]] as [$key, $label, $options])
                    <div>
                        <label class="text-xs text-gray-600">{{ $label }}</label>
                        <select name="{{ $key }}" class="w-full border-gray-300 rounded text-sm">
                            <option value="">All</option>
                            @foreach($options as $option)
                                <option value="{{ $option }}" @selected(($filters[$key] ?? '') === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <div class="md:col-span-6 flex items-center gap-4">
                    <label class="text-sm text-gray-700 flex items-center gap-2">
                        <input type="checkbox" name="flagged" value="1" @checked($filters['flagged'] ?? false)
                               class="rounded border-gray-300">
                        Only products with something to review
                    </label>
                    <button class="bg-teal-700 text-white text-sm rounded px-4 py-2">Apply</button>
                    <a href="{{ route('master.index') }}" class="text-sm text-gray-600 underline">Clear</a>
                    <a href="{{ route('master.index', array_merge($filters, ['export' => 'csv'])) }}"
                       class="text-sm text-teal-800 underline ms-auto">Export CSV</a>
                </div>
            </form>

            @if($canEdit)
                <div class="bg-white border rounded-lg p-4">
                    <form method="POST" action="{{ route('master.store') }}" class="flex flex-wrap gap-3 items-end">
                        @csrf
                        <div>
                            <label class="text-xs text-gray-600">New product code</label>
                            <input name="company_product_code" required placeholder="BD#####"
                                   class="border-gray-300 rounded text-sm font-mono">
                        </div>
                        <div><label class="text-xs text-gray-600">Name</label>
                            <input name="name" class="border-gray-300 rounded text-sm"></div>
                        <div><label class="text-xs text-gray-600">Brand</label>
                            <input name="brand" class="border-gray-300 rounded text-sm"></div>
                        <div><label class="text-xs text-gray-600">Category</label>
                            <input name="category" class="border-gray-300 rounded text-sm"></div>
                        <button class="bg-gray-800 text-white text-sm rounded px-4 py-2">Add product</button>
                        @error('company_product_code')
                            <span class="text-sm text-red-700">{{ $message }}</span>
                        @enderror
                    </form>
                </div>
            @endif

            {{-- The grid. Click a cell, type, leave it - it saves on its own (§S Path A). --}}
            <div class="bg-white border rounded-lg overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-gray-500 border-b bg-gray-50">
                        <tr>
                            <th class="py-2 px-3">Code</th>
                            <th class="py-2 px-3">Name</th>
                            <th class="py-2 px-3">Brand</th>
                            <th class="py-2 px-3">Category</th>
                            <th class="py-2 px-3">Sub-category</th>
                            <th class="py-2 px-3">Owner</th>
                            <th class="py-2 px-3">Origin</th>
                            <th class="py-2 px-3">Identifiers</th>
                            <th class="py-2 px-3">Suppliers</th>
                            @if($canSeeMoney)
                                <th class="py-2 px-3 text-right">Cost</th>
                                <th class="py-2 px-3">Channel economics</th>
                            @endif
                            @if($canEdit)<th class="py-2 px-3"></th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($products as $product)
                            @php($isFocused = $focus !== null && $focus === $product->company_product_code)
                            <tr id="product-{{ $product->company_product_code }}"
                                @if($isFocused) data-focused="1" @endif
                                class="align-top {{ $product->is_active ? '' : 'opacity-50' }}
                                       {{ $isFocused ? 'ring-2 ring-teal-500 bg-teal-50/60' : '' }}">
                                <td class="py-2 px-3 font-mono whitespace-nowrap">
                                    {{ $product->company_product_code }}
                                    @unless($product->is_active)
                                        <div class="text-xs text-gray-500">retired</div>
                                    @endunless
                                </td>

                                @foreach(['name', 'brand', 'category', 'sub_category', 'owner', 'origin'] as $field)
                                    <td class="py-1 px-1">
                                        <x-master-cell :editable="$canEdit" :id="$product->id" kind="products"
                                                       :field="$field" :value="$product->{$field}" />
                                    </td>
                                @endforeach

                                <td class="py-2 px-3 text-xs">
                                    @forelse($product->identifiers as $identifier)
                                        <div class="font-mono whitespace-nowrap">
                                            {{ $identifier->sku_id }}
                                            <span class="text-gray-400">{{ $identifier->marketplace }}</span>
                                        </div>
                                    @empty
                                        <span class="text-gray-400">none</span>
                                    @endforelse
                                </td>

                                <td class="py-2 px-3 text-xs max-w-48">
                                    @if($canSeeMoney)
                                        {{ $product->suppliers ?: '—' }}
                                    @else
                                        <span class="text-gray-400">hidden</span>
                                    @endif
                                </td>

                                @if($canSeeMoney)
                                    <td class="py-1 px-1 text-right">
                                        <x-master-cell :editable="$canEdit" :id="$product->id" kind="products"
                                                       field="product_cost" :value="$product->product_cost" align="right" />
                                    </td>

                                    <td class="py-2 px-3">
                                        @forelse($product->economics as $economics)
                                            <div class="mb-3 last:mb-0 border-s-2 ps-2
                                                        {{ $economics->is_manual ? 'border-teal-400' : 'border-gray-200' }}">
                                                <div class="text-xs font-semibold text-gray-700">
                                                    {{ $economics->channel->label() }}
                                                    @if($economics->is_manual)
                                                        <span class="text-teal-700 font-normal">· edited by hand</span>
                                                    @endif
                                                </div>

                                                <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1 text-xs">
                                                    @foreach([
                                                        'rsp_ex_vat' => 'RSP ex VAT',
                                                        'invoice_pct_of_rsp' => 'Front (inv ÷ RSP)',
                                                        'net_pct_of_invoice' => 'Back (net ÷ inv)',
                                                        'marketing' => 'Marketing',
                                                        'opex' => 'OPEX',
                                                        'packaging' => 'Packaging',
                                                    ] as $field => $label)
                                                        <label class="text-gray-500">
                                                            {{ $label }}
                                                            <x-master-cell :editable="$canEdit" :id="$economics->id"
                                                                           kind="economics" :field="$field"
                                                                           :value="$economics->{$field}" narrow />
                                                        </label>
                                                    @endforeach
                                                </div>

                                                <div class="mt-1 text-xs" id="derived-{{ $economics->id }}">
                                                    <span class="text-gray-500">Invoice</span>
                                                    <strong data-derived="invoice_value">
                                                        @if($economics->invoice_value !== null)
                                                            <x-money :amount="$economics->invoice_value" :currency="$economics->currency" />
                                                        @else — @endif
                                                    </strong>
                                                    <span class="text-gray-500 ms-2">Net receivable</span>
                                                    <strong data-derived="net_receivable">
                                                        @if($economics->net_receivable !== null)
                                                            <x-money :amount="$economics->net_receivable" :currency="$economics->currency" />
                                                        @else — @endif
                                                    </strong>
                                                    <span class="text-gray-500 ms-2">COGS</span>
                                                    <strong data-derived="cogs">
                                                        @if($economics->cogs !== null)
                                                            <x-money :amount="$economics->cogs" :currency="$economics->currency" />
                                                        @else — @endif
                                                    </strong>
                                                    <span class="text-gray-500 ms-2">Profit</span>
                                                    <strong data-derived="profit"
                                                            class="{{ (float) $economics->profit < 0 ? 'text-red-700' : 'text-green-800' }}">
                                                        @if($economics->profit !== null)
                                                            <x-money :amount="$economics->profit" :currency="$economics->currency" />
                                                        @else — @endif
                                                    </strong>
                                                    <span class="text-gray-500 ms-2">Margin</span>
                                                    <strong data-derived="margin_pct">
                                                        {{ $economics->margin_pct === null ? '—' : round((float) $economics->margin_pct * 100, 2).'%' }}
                                                    </strong>
                                                </div>

                                                @if($economics->net_receivable === null)
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        No selling price, so no margin — this is something we buy, not something we sell.
                                                    </div>
                                                @else
                                                    <div class="text-xs text-gray-400 mt-0.5">
                                                        The marketplace keeps
                                                        {{ round(\App\Services\Margin\NetMarginEngine::marketplaceTakePct($economics) * 100, 2) }}%
                                                        of the retail price. Seller-Central fees do not apply to us and are not deducted.
                                                    </div>
                                                @endif
                                            </div>
                                        @empty
                                            <span class="text-xs text-gray-400">No channel economics loaded.</span>
                                        @endforelse
                                    </td>
                                @endif

                                @if($canEdit)
                                    <td class="py-2 px-3">
                                        @if($product->is_active)
                                            <form method="POST" action="{{ route('master.products.destroy', $product) }}"
                                                  onsubmit="return confirm('Retire {{ $product->company_product_code }}? Past orders keep showing it; it stops being a current product.')">
                                                @csrf @method('DELETE')
                                                <button class="text-xs text-red-700 underline">Retire</button>
                                            </form>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="12" class="py-8 text-center text-gray-500">
                                No products match. @if($stats['products'] === 0)
                                    Upload the master sheet from the Uploads tab to fill the catalog.
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $products->links() }}</div>

            {{-- §S: the cost rule is interim, and anything built on it says so. --}}
            <p class="text-xs text-gray-500">
                Costs use the <strong>{{ $costBasis }}</strong> supplier price. A product often has
                several suppliers, and until supplier POs are uploaded (Phase 3) there is nothing to
                weight an average with — so the most recent price stands, and every margin here rests
                on it. Profit and margin are OperON's own calculation, not the spreadsheet's (§S).
            </p>
        </div>
    </div>

    <script>
        // Bring the flagged row into view and put the cursor in it. Guarded because the
        // product may not be on this page of results.
        document.addEventListener('DOMContentLoaded', () => {
            const row = document.querySelector('tr[data-focused="1"]');
            if (!row) return;

            row.scrollIntoView({ behavior: 'smooth', block: 'center' });

            const firstCell = row.querySelector('input');
            if (firstCell) firstCell.focus({ preventScroll: true });
        });
    </script>

    @if($canEdit)
        <script>
            function masterGrid() {
                return {
                    /** Save one cell, then repaint whatever the engine recomputed from it. */
                    async save(el, kind, id, field) {
                        const original = el.dataset.original ?? '';

                        if (el.value === original) return;

                        el.classList.remove('bg-red-50', 'bg-green-50');

                        try {
                            const response = await fetch(`/master/${kind}/${id}`, {
                                method: 'PATCH',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                    'Accept': 'application/json',
                                },
                                body: JSON.stringify({ field, value: el.value }),
                            });

                            if (!response.ok) {
                                const body = await response.json().catch(() => ({}));
                                throw new Error(body.message ?? 'That value was not accepted.');
                            }

                            const data = await response.json();
                            el.dataset.original = el.value;
                            el.classList.add('bg-green-50');
                            setTimeout(() => el.classList.remove('bg-green-50'), 900);

                            if (data.derived) this.repaint(id, data.derived);
                            if (data.economics) {
                                for (const [economicsId, derived] of Object.entries(data.economics)) {
                                    this.repaint(economicsId, derived);
                                }
                            }
                        } catch (error) {
                            // Put the old value back rather than leave a number on screen
                            // that was never saved.
                            el.value = original;
                            el.classList.add('bg-red-50');
                            alert(error.message + '\n\nThe old value has been put back.');
                        }
                    },

                    repaint(economicsId, derived) {
                        const row = document.getElementById(`derived-${economicsId}`);
                        if (!row) return;

                        for (const [key, value] of Object.entries(derived)) {
                            const cell = row.querySelector(`[data-derived="${key}"]`);
                            if (cell) cell.textContent = value ?? '—';
                        }
                    },
                };
            }
        </script>
    @endif
</x-app-layout>
