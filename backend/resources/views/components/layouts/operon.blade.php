{{--
    The application shell (DESIGN_BRIEF §9): sidebar, page header, content well.

    Every screen renders inside this, so navigation, theme and identity are solved once.
    Screens supply a title, an optional sub-caption, optional header controls, and their
    content.

        <x-operon-page title="Fulfilment" sub="41 POs">
            <x-slot:controls> ... </x-slot:controls>
            ...
        </x-operon-page>
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name', 'OperON') }}</title>

    {{-- Set the theme before first paint, so a dark-mode user never gets a white flash. --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('operon-theme');
                if (t) document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="margin:0;background:var(--bg)">
<div class="op">

    <aside class="side" id="sidebar">
        <a class="brand" href="{{ route('overview.index') }}">
            <x-operon-logo />
            <span class="wm">Oper<b>O</b>N</span>
        </a>

        @php
            // The IA from §8. Each entry lists the permissions that grant it - a screen
            // that merged two old tabs accepts either, so nobody loses a screen they had.
            $nav = [
                ['Overview', 'overview.index', 'overview.*', ['view-overview']],
                ['PO Lookup', 'po-lookup.index', 'po-lookup.*', ['view-po-status']],
                ['Fulfilment', 'fulfilment.index', 'fulfilment.*', ['view-fulfillment', 'view-pending']],
                ['Deliveries', 'deliveries.index', 'deliveries.*', ['view-shipments', 'view-committed-deliveries']],
                ['Products', 'products.index', 'products.*', ['view-analytics', 'view-master']],
                ['Cancellations', 'cancellations.index', 'cancellations.*', ['view-cancelled-items']],
            ];
        @endphp

        @foreach ($nav as [$label, $route, $pattern, $permissions])
            @if (auth()->user()->canAny($permissions))
                <a class="nav {{ request()->routeIs($pattern) ? 'on' : '' }}" href="{{ route($route) }}">
                    <x-operon-icon :name="$label" />{{ $label }}
                </a>
            @endif
        @endforeach

        @canany(['view-margin', 'view-master', 'upload-master-sku'])
            <div class="navlbl">Money · Admin</div>
        @endcanany

        @can('view-margin')
            <a class="nav {{ request()->routeIs('money.*') ? 'on' : '' }}" href="{{ route('money.index') }}">
                <x-operon-icon name="Margin" />Profitability
                {{-- Padlock shut says "this asks for the PIN"; open says the PIN is already in. --}}
                @php($moneyUnlocked = \App\Support\MoneyGate::unlocked())
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                     stroke-width="2" aria-hidden="true"
                     style="margin-left:auto;opacity:{{ $moneyUnlocked ? '.95' : '.55' }};{{ $moneyUnlocked ? 'color:var(--good)' : '' }}">
                    <rect x="5" y="11" width="14" height="10" rx="2"/>
                    <path d="{{ $moneyUnlocked ? 'M8 11V7a4 4 0 0 1 7.7-1.4' : 'M8 11V7a4 4 0 0 1 8 0v4' }}"/>
                </svg>
            </a>
        @endcan

        @can('view-master')
            <a class="nav {{ request()->routeIs('master.*') ? 'on' : '' }}" href="{{ route('master.index') }}">
                <x-operon-icon name="Master Sheet" />Master Sheet
            </a>
        @endcan

        @if (auth()->user()->canUploadAnything())
            <a class="nav {{ request()->routeIs('uploads.*') ? 'on' : '' }}" href="{{ route('uploads.index') }}">
                <x-operon-icon name="Upload" />Upload
            </a>
        @endif

        <div class="foot">
            <div class="avatar">{{ \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('') }}</div>
            <div style="min-width:0">
                <div class="n">{{ auth()->user()->name }}</div>
                <div class="r">{{ auth()->user()->getRoleNames()->first() ?? 'No role' }} · UAE</div>
            </div>
            <a href="{{ route('profile.edit') }}" style="margin-left:auto;color:var(--faint)" title="Profile" aria-label="Profile">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>
                </svg>
            </a>
        </div>
    </aside>

    <div class="main">
        <header class="top">
            <button class="pill" onclick="document.getElementById('sidebar').classList.toggle('open')"
                    style="display:none" id="navToggle" aria-label="Menu">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 6h18M3 12h18M3 18h18"/>
                </svg>
            </button>

            <div style="min-width:0">
                <h1>{{ $title ?? 'OperON' }}</h1>
                @isset($sub)<div class="sb">{{ $sub }}</div>@endisset
            </div>

            <div class="spacer"></div>

            {{ $controls ?? '' }}

            {{--
                An unlocked session is visible from every screen, not only the money ones.
                Money figures are on shared screens now (M7), so "am I currently showing
                profit to whoever is behind me?" has to be answerable at a glance — and
                locking again has to be one click from wherever that question is asked.
            --}}
            @if (\App\Support\MoneyGate::unlocked() && ! request()->routeIs('money.*'))
                <form method="POST" action="{{ route('money-pin.lock') }}" style="margin:0">
                    @csrf
                    <button class="pill on" type="submit"
                            title="Money figures are showing. Click to hide them now.">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                             stroke-width="2" aria-hidden="true">
                            <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.7-1.4"/>
                        </svg>
                        Money showing
                    </button>
                </form>
            @endif

            <button class="pill" onclick="operonTheme()" title="Light / dark" aria-label="Toggle light or dark">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
                </svg>
            </button>

            <form method="POST" action="{{ route('logout') }}" style="margin:0">
                @csrf
                <button class="pill" type="submit" title="Log out" aria-label="Log out">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>
                    </svg>
                </button>
            </form>
        </header>

        <div class="content">
            @if (session('status'))
                <div class="note good flash">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2"
                         style="flex:none;margin-top:1px" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                    <div>{{ session('status') }}</div>
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>
</div>

<script>
    // The toggle stamps data-theme on <html>, which beats the media query by specificity
    // and order - so a person's choice wins over their operating system's (§3).
    function operonTheme() {
        var root = document.documentElement;
        var current = root.getAttribute('data-theme');

        if (!current) {
            current = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }

        var next = current === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);

        try { localStorage.setItem('operon-theme', next); } catch (e) {}
    }

    if (window.matchMedia('(max-width: 860px)').matches) {
        document.getElementById('navToggle').style.display = 'flex';
    }
</script>
</body>
</html>
