<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    {{-- Never disable zoom: some customers will be reading this at arm's length on a phone. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Hire a car in Zambia')</title>
    <meta name="description" content="@yield('description', 'Book a vehicle in Zambia. The price you see includes the damage waiver, and the refundable deposit is shown before you pay.')">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- display=swap: text renders in a fallback immediately rather than
         staying invisible while the webfont loads. --}}
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;500;600;700&family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex min-h-full flex-col bg-white font-sans text-ink-800 antialiased">

    {{-- Keyboard users should not have to tab through the whole header on
         every page to reach what they came for. --}}
    <a href="#main"
       class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:rounded-lg focus:bg-ink-900 focus:px-4 focus:py-2 focus:text-white">
        Skip to content
    </a>

    <header class="bg-brand-950">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            {{-- min-h-11 is 44px: the smallest target a thumb hits reliably.
                 The mark inside is 36px, which looks right but is not a
                 comfortable tap on its own. --}}
            {{-- An explicit ring rather than the browser's. The UA default is
                 visible here, but its colour is chosen by the browser and this
                 header is dark — a deliberate white ring reads the same in
                 every browser, and matches the buttons further down the page. --}}
            <a href="{{ route('home') }}"
               class="flex min-h-11 items-center gap-2.5 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white">
                {{-- A placeholder mark, not a logo. The operator has no brand
                     yet; everything here is a token swap away from his. --}}
                <span aria-hidden="true" class="flex size-9 items-center justify-center rounded-xl bg-brand-600">
                    <svg class="size-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 17h14M6.5 17V9.5L8 6h8l1.5 3.5V17M7 12h10"/>
                        <circle cx="8" cy="17" r="1.6"/><circle cx="16" cy="17" r="1.6"/>
                    </svg>
                </span>
                <span class="font-display text-lg font-semibold tracking-tight text-white">
                    {{ config('app.name') }}
                </span>
            </a>

            <nav class="flex items-center gap-1 text-sm" aria-label="Main">
                <a href="{{ route('home') }}"
                   class="flex min-h-11 items-center rounded-lg px-3 font-medium text-brand-100 [transition:background-color_150ms_ease,color_150ms_ease] hover:bg-brand-800 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    Find a car
                </a>
                <a href="{{ route('locations') }}"
                   class="flex min-h-11 items-center rounded-lg px-3 font-medium text-brand-100 [transition:background-color_150ms_ease,color_150ms_ease] hover:bg-brand-800 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                    Branches
                </a>
                {{-- Hidden on the narrowest screens, unlike Branches: it is an
                     in-page anchor rather than a destination, and a phone header
                     that wraps to two rows costs more than the link is worth. --}}
                <a href="{{ route('home') }}#how-it-works"
                   class="hidden min-h-11 items-center rounded-lg px-3 font-medium text-brand-100 [transition:background-color_150ms_ease,color_150ms_ease] hover:bg-brand-800 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white sm:flex">
                    How it works
                </a>
            </nav>
        </div>
    </header>

    <main id="main" class="flex-1">
        {{-- Above the content, so an explanation can never be hidden behind
             the thing it is explaining. --}}
        @if (session('notice'))
            <div class="px-4 sm:px-6">
                <x-notice :message="session('notice')" />
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="mt-16 bg-brand-950">
        <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
            <div class="grid gap-8 sm:grid-cols-3">
                <div>
                    <p class="font-display text-sm font-semibold text-white">{{ config('app.name') }}</p>
                    <p class="mt-2 max-w-xs text-sm leading-relaxed text-brand-200">
                        Vehicle hire in Zambia. Prices include the mandatory damage waiver.
                    </p>
                </div>
                <div>
                    <p class="text-sm font-semibold text-white">Before you book</p>
                    <ul class="mt-2 space-y-1.5 text-sm text-brand-200">
                        <li>A refundable security deposit is taken at the branch.</li>
                        <li>The insurance excess is shown at checkout.</li>
                        <li>
                            <a href="{{ route('locations') }}"
                               class="rounded underline decoration-brand-700 underline-offset-2 hover:text-white hover:decoration-brand-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                                Where to collect your car
                            </a>
                        </li>
                    </ul>
                </div>
                <div>
                    <p class="text-sm font-semibold text-white">Payment</p>
                    <p class="mt-2 text-sm leading-relaxed text-brand-200">
                        Cash, bank transfer and mobile money. Every payment is verified by a
                        member of staff before your booking is confirmed.
                    </p>
                </div>
            </div>
            <p class="mt-8 border-t border-brand-800 pt-6 text-xs text-brand-400">
                &copy; {{ now()->year }} {{ config('app.name') }}. Demonstration site.
            </p>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
