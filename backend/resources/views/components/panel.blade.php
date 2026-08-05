{{--
    A panel (§5): rounded card, soft shadow, header = title + sub-caption + optional
    right-aligned action link.

        <x-panel title="Fulfilment centres" sub="PO value & fill rate by FC"
                 link="All FCs →" :linkHref="route('deliveries.index')">
--}}
@props(['title' => null, 'sub' => null, 'link' => null, 'linkHref' => null, 'flush' => false])

<section {{ $attributes->merge(['class' => 'panel'.($flush ? ' flush' : '')]) }}>
    @if ($title || $link)
        <div class="ph">
            <div>
                @if ($title)<h2>{{ $title }}</h2>@endif
                @if ($sub)<div class="sub">{{ $sub }}</div>@endif
            </div>
            @if ($link)
                <a class="link" href="{{ $linkHref ?? '#' }}">{{ $link }}</a>
            @endif
        </div>
    @endif

    {{ $slot }}
</section>
