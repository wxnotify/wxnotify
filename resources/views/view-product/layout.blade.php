<!DOCTYPE html>
<html lang="en" class="fi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'WxNotify')</title>
        @yield('head')

        @filamentStyles
        {{ filament()->getTheme()->getHtml() }}
        {{ filament()->getFontHtml() }}
        {{ filament()->getMonoFontHtml() }}

        <style>
            :root {
                --font-family: '{!! filament()->getFontFamily() !!}';
                --mono-font-family: '{!! filament()->getMonoFontFamily() !!}';
                --serif-font-family: '{!! filament()->getSerifFontFamily() !!}';
                --default-theme-mode: {{ filament()->getDefaultThemeMode()->value }};
            }
        </style>

        <style>
            /* The Log in button is only for wider screens: on a phone (including the app's in-app browser) it is hidden. */
            @media (max-width: 639px) {
                .product-login-button { display: none; }
            }
        </style>

        <script>
            window.theme = localStorage.getItem('theme') ?? @js(filament()->getDefaultThemeMode()->value)

            if (
                window.theme === 'dark' ||
                (window.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
            ) {
                document.documentElement.classList.add('dark')
            }
        </script>
    </head>
    <body class="fi-body fi-panel-dashboard">
        <header class="border-b border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
                <a href="{{ config('app.url') }}" class="text-lg font-semibold text-gray-950 dark:text-white">
                    {{ generateBrandName() }}
                </a>
                @isset($loginUrl)
                    <x-filament::button tag="a" :href="$loginUrl" size="sm" class="product-login-button">Log in</x-filament::button>
                @endisset
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-8">
            @yield('content')
        </main>

        <footer class="mx-auto max-w-4xl px-4 pb-8 text-xs text-gray-500 dark:text-gray-400">
            Weather product text is published by the National Weather Service.
        </footer>
    </body>
</html>
