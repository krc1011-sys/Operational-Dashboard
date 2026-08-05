{{-- Nav icons, taken from v3. One file so a screen never inlines its own path data. --}}
@props(['name'])

@php
    $paths = [
        'Overview'      => '<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>',
        'PO Lookup'     => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'Fulfilment'    => '<path d="M9 11l3 3 8-8"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/>',
        'Deliveries'    => '<rect x="1" y="3" width="15" height="13"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'Products'      => '<path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/>',
        'Pending'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'Committed'     => '<path d="M20 7h-9M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
        'Cancellations' => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'Margin'        => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'Master Sheet'  => '<path d="M3 3h18v6H3z"/><path d="M3 9v12h18V9"/><path d="M9 13h6"/>',
        'Upload'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5-5 5 5"/><path d="M12 5v12"/>',
    ];
@endphp

<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    {!! $paths[$name] ?? $paths['Overview'] !!}
</svg>
