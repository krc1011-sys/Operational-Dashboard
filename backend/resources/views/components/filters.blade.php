{{--
    The one filter bar (DESIGN_BRIEF §7). Same set on every data screen, so nobody has to
    relearn a tab.

    This is a re-skin of the M5 filter bar and nothing about its behaviour changed: the
    same field names, the same POST-then-redirect (a pasted list can be thousands of
    identifiers and will not fit in a query string, so it is stashed and only a key
    travels), the same session-backed bulk list.

    Collapsed by default and open when something is applied, because a filter you cannot
    see is a filter you forget is on — the summary line always shows what is narrowing
    the screen even when the panel is shut.
--}}
@props([
    'filters',
    'action',
    'show' => ['dates', 'channels', 'fc', 'brand', 'category', 'status', 'po', 'search', 'skus'],
    'statuses' => null,
    'dateLabel' => 'PO date',
    'exportHref' => null,
])

@php
    use App\Enums\Channel;
    use App\Services\Reporting\FilterSet;

    $statuses ??= FilterSet::lineStates();
    $brands = in_array('brand', $show, true) ? FilterSet::brands() : [];
    $categories = in_array('category', $show, true) ? FilterSet::categories() : [];
    $fcs = in_array('fc', $show, true) ? FilterSet::fulfilmentCentres() : [];
@endphp

<form method="POST" action="{{ $action }}" enctype="multipart/form-data"
      x-data="{ open: {{ $filters->isActive() ? 'true' : 'false' }} }"
      class="panel" style="padding:13px 16px">
    @csrf

    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <button type="button" class="pill" @click="open = !open">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M3 5h18M6 12h12M10 19h4"/>
            </svg>
            Filters
            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"
                 aria-hidden="true" x-bind:style="open ? 'transform:rotate(180deg)' : ''"><path d="m6 9 6 6 6-6"/></svg>
        </button>

        <div class="summary" style="flex:1;min-width:0">
            @if ($filters->isActive())
                {{ implode(' · ', $filters->summary()) }}
            @else
                Showing everything
            @endif
        </div>

        @if ($filters->isActive())
            <a class="pill" href="{{ $action }}">Clear</a>
        @endif

        {{ $actions ?? '' }}

        @if ($exportHref)
            <a class="pill" href="{{ $exportHref }}">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>
                </svg>
                Export
            </a>
        @endif

        <button class="btn" type="submit">Apply</button>
    </div>

    <div x-show="open" x-cloak class="filters" style="margin-top:14px">
        <div class="grid">
            @if (in_array('dates', $show, true))
                <div class="fld">
                    <label>{{ $dateLabel }} from</label>
                    <input class="inp" type="date" name="from" value="{{ $filters->from?->toDateString() }}">
                </div>
                <div class="fld">
                    <label>{{ $dateLabel }} to</label>
                    <input class="inp" type="date" name="to" value="{{ $filters->to?->toDateString() }}">
                </div>
            @endif

            @if (in_array('channels', $show, true))
                <div class="fld">
                    <label>Channel</label>
                    <div style="display:flex;flex-wrap:wrap;gap:4px 10px;padding-top:3px">
                        @foreach (Channel::cases() as $channel)
                            <label style="display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--muted)">
                                <input type="checkbox" name="channels[]" value="{{ $channel->value }}"
                                       @checked(collect($filters->channels)->contains($channel))>
                                {{ $channel->label() }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (in_array('fc', $show, true))
                <div class="fld">
                    <label>Fulfilment centre</label>
                    <select class="inp" name="fc">
                        <option value="">All FCs</option>
                        @foreach ($fcs as $fc)
                            <option value="{{ $fc }}" @selected($filters->fc === $fc)>{{ $fc }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if (in_array('status', $show, true))
                <div class="fld">
                    <label>Status</label>
                    <select class="inp" name="status">
                        <option value="">Any status</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected($filters->status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if (in_array('brand', $show, true))
                <div class="fld">
                    <label>Brand</label>
                    <select class="inp" name="brand" @disabled($brands === [])>
                        <option value="">All brands</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand }}" @selected($filters->brand === $brand)>{{ $brand }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if (in_array('category', $show, true))
                <div class="fld">
                    <label>Category</label>
                    <select class="inp" name="category" @disabled($categories === [])>
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category }}" @selected($filters->category === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if (in_array('po', $show, true))
                <div class="fld">
                    <label>PO number</label>
                    <input class="inp" name="po" value="{{ $filters->po }}" placeholder="Whole or part">
                </div>
            @endif

            @if (in_array('search', $show, true))
                <div class="fld">
                    <label>Search</label>
                    <input class="inp" name="search" value="{{ $filters->search }}" placeholder="ASIN / NIN / barcode / title">
                </div>
            @endif

            @if (in_array('group', $show, true))
                <div class="fld">
                    <label>Group by</label>
                    <select class="inp" name="group_by">
                        @foreach ([
                            FilterSet::GROUP_NONE => 'No grouping',
                            FilterSet::GROUP_SKU => 'SKU',
                            FilterSet::GROUP_BRAND => 'Brand',
                            FilterSet::GROUP_CATEGORY => 'Category',
                        ] as $value => $label)
                            <option value="{{ $value }}" @selected($filters->groupBy === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        @if (in_array('skus', $show, true))
            <div style="border-top:1px solid var(--border);padding-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="fld">
                    <label>Paste a list of ASINs / NINs</label>
                    <textarea class="inp" name="sku_list" rows="3" placeholder="One per line, or comma separated"></textarea>
                    <div style="font-size:11px;color:var(--faint)">
                        Paste a column straight out of Excel. Up to {{ number_format(FilterSet::MAX_IDENTIFIERS) }}.
                        @if ($filters->skus !== [])
                            <b style="color:var(--teal-2)">{{ count($filters->skus) }} applied — paste again to replace.</b>
                        @endif
                    </div>
                </div>
                <div class="fld">
                    <label>…or upload a list</label>
                    <input class="inp" type="file" name="sku_file" accept=".txt,.csv,.xlsx,.xls">
                    <div style="font-size:11px;color:var(--faint)">
                        A text file, or a spreadsheet with the identifiers down the first column.
                    </div>
                </div>
            </div>
        @endif

        {{-- Keeps a pasted list applied when only other fields change. --}}
        <input type="hidden" name="sku_key" value="{{ $filters->skuKey }}">

        {{ $slot ?? '' }}
    </div>
</form>
