@props([
    'filters',
    'action',
    'show' => ['dates', 'channels', 'fc', 'brand', 'category', 'status', 'po', 'search', 'skus'],
    'statuses' => null,
    'dateLabel' => 'PO date',
])

@php
    use App\Enums\Channel;
    use App\Services\Reporting\FilterSet;

    $statuses ??= FilterSet::lineStates();
    $brands = in_array('brand', $show, true) ? FilterSet::brands() : [];
    $categories = in_array('category', $show, true) ? FilterSet::categories() : [];
    $fcs = in_array('fc', $show, true) ? FilterSet::fulfilmentCentres() : [];
@endphp

{{--
    The §M self-serve filter set. The same markup on every tab on purpose: the team
    should never have to relearn a screen. A form POST (rather than GET) because the
    bulk list can be thousands of ASINs or an uploaded file; the controller turns it
    straight back into a shareable link.
--}}
<form method="POST" action="{{ $action }}" enctype="multipart/form-data"
      x-data="{ open: {{ $filters->isActive() ? 'true' : 'false' }} }"
      class="bg-white shadow-sm sm:rounded-lg p-5">
    @csrf

    <div class="flex items-center justify-between gap-4">
        <button type="button" @click="open = !open" class="text-sm font-medium text-teal-800">
            <span x-show="!open">Filters ▸</span>
            <span x-show="open" x-cloak>Filters ▾</span>
        </button>

        <div class="flex items-center gap-3">
            @if($filters->isActive())
                <span class="text-xs text-gray-600">{{ implode(' · ', $filters->summary()) }}</span>
                <a href="{{ $action }}" class="text-xs underline text-gray-600">Clear</a>
            @else
                <span class="text-xs text-gray-500">Showing everything</span>
            @endif
        </div>
    </div>

    <div x-show="open" x-cloak class="mt-5 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">

            @if(in_array('dates', $show, true))
                <div>
                    <x-input-label value="{{ $dateLabel }} from" />
                    <x-text-input type="date" name="from" class="mt-1 block w-full"
                                  :value="$filters->from?->toDateString()" />
                </div>
                <div>
                    <x-input-label value="{{ $dateLabel }} to" />
                    <x-text-input type="date" name="to" class="mt-1 block w-full"
                                  :value="$filters->to?->toDateString()" />
                </div>
            @endif

            @if(in_array('channels', $show, true))
                <div>
                    <x-input-label value="Channel" />
                    <div class="mt-1 space-y-1">
                        @foreach(Channel::cases() as $channel)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="channels[]" value="{{ $channel->value }}"
                                       class="rounded border-gray-300"
                                       @checked(collect($filters->channels)->contains($channel)) />
                                {{ $channel->label() }}
                            </label>
                        @endforeach
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Tick none to see all.</p>
                </div>
            @endif

            @if(in_array('fc', $show, true))
                <div>
                    <x-input-label value="Fulfilment centre" />
                    <select name="fc" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                        <option value="">All</option>
                        @foreach($fcs as $fc)
                            <option value="{{ $fc }}" @selected($filters->fc === $fc)>{{ $fc }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if(in_array('status', $show, true))
                <div>
                    <x-input-label value="Status" />
                    <select name="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                        <option value="">Any</option>
                        @foreach($statuses as $value => $label)
                            <option value="{{ $value }}" @selected($filters->status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if(in_array('brand', $show, true))
                <div>
                    <x-input-label value="Brand" />
                    <select name="brand" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm"
                            @disabled($brands === [])>
                        <option value="">All</option>
                        @foreach($brands as $brand)
                            <option value="{{ $brand }}" @selected($filters->brand === $brand)>{{ $brand }}</option>
                        @endforeach
                    </select>
                    @if($brands === [])
                        <p class="text-xs text-gray-500 mt-1">Available once the master sheet is loaded.</p>
                    @endif
                </div>
            @endif

            @if(in_array('category', $show, true))
                <div>
                    <x-input-label value="Category" />
                    <select name="category" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm"
                            @disabled($categories === [])>
                        <option value="">All</option>
                        @foreach($categories as $category)
                            <option value="{{ $category }}" @selected($filters->category === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                    @if($categories === [])
                        <p class="text-xs text-gray-500 mt-1">Available once the master sheet is loaded.</p>
                    @endif
                </div>
            @endif

            @if(in_array('po', $show, true))
                <div>
                    <x-input-label value="PO number" />
                    <x-text-input name="po" class="mt-1 block w-full" :value="$filters->po"
                                  placeholder="Whole or part of a PO number" />
                </div>
            @endif

            @if(in_array('search', $show, true))
                <div>
                    <x-input-label value="Search" />
                    <x-text-input name="search" class="mt-1 block w-full" :value="$filters->search"
                                  placeholder="ASIN / NIN / barcode / title" />
                </div>
            @endif

            @if(in_array('group', $show, true))
                <div>
                    <x-input-label value="Group by" />
                    <select name="group_by" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm">
                        @foreach([
                            FilterSet::GROUP_NONE => 'No grouping (one row per PO line)',
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

        @if(in_array('skus', $show, true))
            {{-- §M: "the team can paste or upload a list of ASINs/NINs as a filter input". --}}
            <div class="border-t pt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <x-input-label value="Paste a list of ASINs / NINs" />
                    <textarea name="sku_list" rows="3"
                              class="mt-1 block w-full border-gray-300 rounded-md shadow-sm text-sm font-mono"
                              placeholder="One per line, or comma separated"></textarea>
                    <p class="text-xs text-gray-500 mt-1">
                        Paste a column straight out of Excel. Up to {{ number_format(FilterSet::MAX_IDENTIFIERS) }}.
                        @if($filters->skus !== [])
                            <span class="text-teal-800 font-medium">
                                {{ count($filters->skus) }} currently applied — paste again to replace.
                            </span>
                        @endif
                    </p>
                </div>
                <div>
                    <x-input-label value="…or upload a list" />
                    <input type="file" name="sku_file" accept=".txt,.csv,.xlsx,.xls"
                           class="mt-1 block w-full text-sm" />
                    <p class="text-xs text-gray-500 mt-1">
                        A text file, or a spreadsheet with the identifiers down the first column.
                    </p>
                </div>
            </div>
        @endif

        <div class="flex items-center gap-3 pt-2">
            <x-primary-button>Apply filters</x-primary-button>
            {{-- Keeps the list applied when only other fields change. --}}
            <input type="hidden" name="sku_key" value="{{ $filters->skuKey }}" />
            {{ $slot ?? '' }}
        </div>
    </div>
</form>
