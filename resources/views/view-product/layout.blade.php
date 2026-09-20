<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'WxNotify')</title>
        @yield('head')
        @vite('resources/css/app.css')
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
                <a href="{{ config('app.url') }}" class="flex items-center gap-2 font-semibold">
                    <img src="{{ asset('images/logo.png') }}" alt="" class="h-8 w-8">
                    WxNotify
                </a>
                @isset($loginUrl)
                    <a href="{{ $loginUrl }}" class="text-sm font-medium text-blue-600 hover:underline">Log in</a>
                @endisset
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-8">
            @yield('content')
        </main>

        <footer class="mx-auto max-w-4xl px-4 pb-8 text-xs text-slate-500">
            Weather product text is published by the National Weather Service.
        </footer>
    </body>
</html>
