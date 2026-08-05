@php
    use App\Models\MasterAnomaly;
    use App\Services\Margin\NetMarginEngine;
    use App\Support\Currency;

    $kindLabels = MasterAnomaly::kindLabels();
@endphp

<x-operon-page title="Master catalog"
               sub="{{ number_format($stats['products']) }} products · {{ number_format($stats['channel_rows']) }} channel rows">
    <x-slot:controls>
        @unless ($canEdit)
            @can('manage-master')
                <a class="pill" href="{{ $pinUnlockUrl }}">
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                    </svg>
                    Unlock with PIN
                </a>
            @endcan
        @endunless
    </x-slot:controls>

    <div x-data="masterGrid()" style="display:flex;flex-direction:column;gap:16px">

        {{-- The money gate, said in a sentence rather than shown as empty columns (§S). --}}
        @unless ($canSeeMoney)
            <div class="note">
                <b>Cost and margin are hidden.</b> This is the catalog only — what each product
                is, who owns it and where it sells. Prices, costs and profit are Admin-only and
                behind the PIN.
                @can('manage-master')
                    <a href="{{ $pinUnlockUrl }}" style="color:var(--teal-2);font-weight:650">Enter the PIN to unlock and edit</a>.
                @endcan
            </div>
        @endunless

        {{-- The review queue. Above the grid, because a flagged product's margin cannot be
             trusted until somebody answers it. --}}
        @if ($anomalies->isNotEmpty())
            <x-panel>
                <div x-data="{ open: {{ ($reviewCount > 0 && ! $focus) ? 'true' : 'false' }} }">
                    <div class="ph" style="margin:0;cursor:pointer" @click="open = !open">
                        <div>
                            <h2 style="{{ $reviewCount > 0 ? 'color:var(--warn)' : '' }}">
                                @if ($reviewCount > 0)
                                    {{ $reviewCount }} thing(s) need someone to look at them
                                @else
                                    Nothing needs a decision
                                @endif
                            </h2>
                            <div class="sub">
                                Loaded as they are, never silently corrected.
                                @if ($noteCount > 0) {{ $noteCount }} further note(s) worth knowing. @endif
                            </div>
                        </div>
                        <span class="link" x-text="open ? 'Hide' : 'Show'"></span>
                    </div>

                    <div x-show="open" x-cloak style="display:flex;flex-direction:column;gap:8px;margin-top:12px">
                        @foreach ($anomalies as $anomaly)
                            <div class="att">
                                <div class="a {{ $anomaly->severity === MasterAnomaly::SEVERITY_REVIEW ? 'w' : '' }}">
                                    <span class="sev {{ $anomaly->severity === MasterAnomaly::SEVERITY_REVIEW ? 'a' : 'g' }}"></span>
                                    <div class="tx">
                                        <span class="tag {{ $anomaly->severity === MasterAnomaly::SEVERITY_REVIEW ? 'amber' : '' }}">
                                            {{ $kindLabels[$anomaly->kind] ?? $anomaly->kind }}
                                        </span>
                                        @if ($anomaly->company_product_code)
                                            {{-- Jump to fix: filters to the product, scrolls to its row,
                                                 highlights it and puts the cursor in the first cell. --}}
                                            <a class="mono" style="color:var(--teal-2);font-weight:650;margin-left:6px"
                                               href="{{ route('master.index', [
                                                    'q' => $anomaly->company_product_code,
                                                    'focus' => $anomaly->company_product_code,
                                               ]) }}#product-{{ $anomaly->company_product_code }}">{{ $anomaly->company_product_code }}</a>
                                        @endif
                                        <span class="s">{{ $anomaly->message }}</span>
                                    </div>

                                    @if ($canEdit)
                                        <form method="POST" action="{{ route('master.anomalies.resolve', $anomaly) }}" style="margin-left:auto">
                                            @csrf
                                            <button class="pill" style="font-size:11px">Mark reviewed</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-panel>
        @endif

        {{-- Filters. Brand / category / sub-category / owner all come from this catalog. --}}
        <form method="GET" class="panel" style="padding:13px 16px">
            <div class="filters">
                <div class="grid">
                    <div class="fld">
                        <label>Search</label>
                        <input class="inp" name="q" value="{{ $filters['q'] ?? '' }}"
                               placeholder="code, name, brand, ASIN, NIN, barcode">
                    </div>
                    @foreach ([['brand','Brand',$brands], ['category','Category',$categories],
                               ['sub_category','Sub-category',$subCategories], ['owner','Owner',$owners]] as [$key, $label, $options])
                        <div class="fld">
                            <label>{{ $label }}</label>
                            <select class="inp" name="{{ $key }}">
                                <option value="">All</option>
                                @foreach ($options as $option)
                                    <option value="{{ $option }}" @selected(($filters[$key] ?? '') === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                <div class="actions">
                    <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--muted)">
                        <input type="checkbox" name="flagged" value="1" @checked($filters['flagged'] ?? false)>
                        Only products with something to review
                    </label>
                    <button class="btn" type="submit">Apply</button>
                    <a class="pill" href="{{ route('master.index') }}">Clear</a>
                    <a class="pill" style="margin-left:auto"
                       href="{{ route('master.index', array_merge($filters, ['export' => 'csv'])) }}">Export</a>
                </div>
            </div>
        </form>

        @if ($canEdit)
            <x-panel title="Add a product" sub="For something the master sheet does not carry yet">
                <form method="POST" action="{{ route('master.store') }}"
                      style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                    @csrf
                    <div class="fld"><label>Product code</label>
                        <input class="inp mono" name="company_product_code" required placeholder="BD#####"></div>
                    <div class="fld"><label>Name</label><input class="inp" name="name"></div>
                    <div class="fld"><label>Brand</label><input class="inp" name="brand"></div>
                    <div class="fld"><label>Category</label><input class="inp" name="category"></div>
                    <button class="btn">Add</button>
                    @error('company_product_code')<span style="color:var(--bad);font-size:12px">{{ $message }}</span>@enderror
                </form>
            </x-panel>
        @endif

        {{-- The grid. Click a cell, type, leave it — it saves on its own (§S Path A). --}}
        <x-panel flush title="Catalog"
                 :sub="$canEdit ? 'Click a cell and type — it saves when you leave it' : 'Read-only'">
            <div class="scroll-x">
                <table class="tbl" style="min-width:1500px">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Brand</th>
                            <th>Category</th>
                            <th>Sub-category</th>
                            <th>Owner</th>
                            <th>Origin</th>
                            <th>Identifiers</th>
                            @if ($canSeeMoney)
                                <th>Suppliers</th>
                                <th class="num">Cost</th>
                                <th>Channel economics</th>
                            @endif
                            @if ($canEdit)<th></th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($products as $product)
                            @php $isFocused = $focus !== null && $focus === $product->company_product_code; @endphp

                            <tr id="product-{{ $product->company_product_code }}"
                                @if($isFocused) data-focused="1" @endif
                                style="vertical-align:top;
                                       {{ $product->is_active ? '' : 'opacity:.5;' }}
                                       {{ $isFocused ? 'box-shadow:inset 0 0 0 2px var(--teal);background:var(--teal-tint)' : '' }}">
                                <td class="mono" style="white-space:nowrap">
                                    {{ $product->company_product_code }}
                                    @unless ($product->is_active)<div><span class="tag">retired</span></div>@endunless
                                </td>

                                @foreach (['name', 'brand', 'category', 'sub_category', 'owner', 'origin'] as $field)
                                    <td><x-master-cell :editable="$canEdit" :id="$product->id" kind="products"
                                                       :field="$field" :value="$product->{$field}" /></td>
                                @endforeach

                                <td style="font-size:11px">
                                    @forelse ($product->identifiers as $identifier)
                                        <div class="mono" style="white-space:nowrap">
                                            {{ $identifier->sku_id }}
                                            <span style="color:var(--faint)">{{ $identifier->marketplace }}</span>
                                        </div>
                                    @empty
                                        <span style="color:var(--faint)">none</span>
                                    @endforelse
                                </td>

                                @if ($canSeeMoney)
                                    <td style="font-size:11px;max-width:170px">{{ $product->suppliers ?: '—' }}</td>
                                    <td class="num">
                                        <x-master-cell :editable="$canEdit" :id="$product->id" kind="products"
                                                       field="product_cost" :value="$product->product_cost" align="right" />
                                    </td>
                                    <td style="min-width:340px">
                                        @forelse ($product->economics as $economics)
                                            <div style="margin-bottom:10px;padding-left:8px;
                                                        border-left:2px solid {{ $economics->is_manual ? 'var(--teal)' : 'var(--border-2)' }}">
                                                <div style="font-size:11px;font-weight:700;color:var(--muted)">
                                                    {{ $economics->channel->label() }}
                                                    @if ($economics->is_manual)<span class="tag teal">edited by hand</span>@endif
                                                </div>

                                                <div style="display:flex;flex-wrap:wrap;gap:3px 10px;margin-top:4px;font-size:10.5px;color:var(--faint)">
                                                    @foreach ([
                                                        'rsp_ex_vat' => 'RSP ex VAT',
                                                        'invoice_pct_of_rsp' => 'Front',
                                                        'net_pct_of_invoice' => 'Back',
                                                        'marketing' => 'Marketing',
                                                        'opex' => 'OPEX',
                                                        'packaging' => 'Packaging',
                                                    ] as $field => $label)
                                                        <label>{{ $label }}
                                                            <x-master-cell :editable="$canEdit" :id="$economics->id"
                                                                           kind="economics" :field="$field"
                                                                           :value="$economics->{$field}" narrow />
                                                        </label>
                                                    @endforeach
                                                </div>

                                                <div style="margin-top:4px;font-size:11px" id="derived-{{ $economics->id }}">
                                                    <span style="color:var(--faint)">Invoice</span>
                                                    <b data-derived="invoice_value">{{ $economics->invoice_value === null ? '—' : Currency::plain($economics->invoice_value, $economics->currency) }}</b>
                                                    <span style="color:var(--faint);margin-left:8px">Net receivable</span>
                                                    <b data-derived="net_receivable">{{ $economics->net_receivable === null ? '—' : Currency::plain($economics->net_receivable, $economics->currency) }}</b>
                                                    <span style="color:var(--faint);margin-left:8px">COGS</span>
                                                    <b data-derived="cogs">{{ $economics->cogs === null ? '—' : Currency::plain($economics->cogs, $economics->currency) }}</b>
                                                    <span style="color:var(--faint);margin-left:8px">Profit</span>
                                                    <b data-derived="profit" style="color:{{ (float) $economics->profit < 0 ? 'var(--bad)' : 'var(--good)' }}">
                                                        {{ $economics->profit === null ? '—' : Currency::plain($economics->profit, $economics->currency) }}
                                                    </b>
                                                    <span style="color:var(--faint);margin-left:8px">Margin</span>
                                                    <b data-derived="margin_pct">{{ $economics->margin_pct === null ? '—' : round((float) $economics->margin_pct * 100, 2).'%' }}</b>
                                                </div>

                                                @if ($economics->net_receivable === null)
                                                    <div style="font-size:10.5px;color:var(--faint);margin-top:2px">
                                                        No selling price, so no margin — something we buy, not something we sell.
                                                    </div>
                                                @else
                                                    <div style="font-size:10.5px;color:var(--faint);margin-top:2px">
                                                        The marketplace keeps
                                                        {{ round(NetMarginEngine::marketplaceTakePct($economics) * 100, 2) }}%.
                                                        Seller-Central fees do not apply to us.
                                                    </div>
                                                @endif
                                            </div>
                                        @empty
                                            <span style="font-size:11px;color:var(--faint)">No channel economics loaded.</span>
                                        @endforelse
                                    </td>
                                @endif

                                @if ($canEdit)
                                    <td>
                                        @if ($product->is_active)
                                            <form method="POST" action="{{ route('master.products.destroy', $product) }}"
                                                  onsubmit="return confirm('Retire {{ $product->company_product_code }}? Past orders keep showing it.')">
                                                @csrf @method('DELETE')
                                                <button style="background:none;border:0;color:var(--bad);font-size:11px;cursor:pointer;font-weight:650">
                                                    Retire
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="12" class="empty">
                                No products match.
                                @if ($stats['products'] === 0) Upload the master sheet to fill the catalog. @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pg">{{ $products->links() }}</div>
        </x-panel>

        {{-- §S: the cost rule is interim, and anything built on it says so. --}}
        <p style="font-size:11px;color:var(--faint);line-height:1.7">
            Costs use the <b>{{ $costBasis }}</b> supplier price. A product often has several
            suppliers, and until supplier POs are uploaded (Phase 3) there is nothing to weight
            an average with — so the most recent price stands, and every margin here rests on it.
            Profit and margin are OperON's own calculation, not the spreadsheet's.
        </p>
    </div>

    <script>
        // Bring a flagged row into view and put the cursor in it. Guarded because the
        // product may not be on this page of results.
        document.addEventListener('DOMContentLoaded', () => {
            const row = document.querySelector('tr[data-focused="1"]');
            if (!row) return;

            row.scrollIntoView({ behavior: 'smooth', block: 'center' });

            const firstCell = row.querySelector('input');
            if (firstCell) firstCell.focus({ preventScroll: true });
        });

        function masterGrid() {
            return {
                /** Save one cell, then repaint whatever the engine recomputed from it. */
                async save(el, kind, id, field) {
                    const original = el.dataset.original ?? '';
                    if (el.value === original) return;

                    el.style.background = '';

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
                        el.style.background = 'var(--good-soft)';
                        setTimeout(() => { el.style.background = ''; }, 900);

                        if (data.derived) this.repaint(id, data.derived);
                        if (data.economics) {
                            for (const [economicsId, derived] of Object.entries(data.economics)) {
                                this.repaint(economicsId, derived);
                            }
                        }
                    } catch (error) {
                        // Put the old value back rather than leave a number on screen that
                        // was never saved.
                        el.value = original;
                        el.style.background = 'var(--bad-soft)';
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
</x-operon-page>
