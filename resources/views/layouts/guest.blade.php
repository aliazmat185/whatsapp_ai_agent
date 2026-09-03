<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="relative bg-slate-950 text-gray-900 antialiased min-h-screen flex items-center justify-center p-4 overflow-hidden">
        <div aria-hidden="true" class="absolute inset-0 bg-gradient-to-br from-indigo-950 via-slate-950 to-slate-900"></div>
        <div aria-hidden="true" class="absolute -top-40 -left-40 h-96 w-96 rounded-full bg-indigo-600/30 blur-3xl"></div>
        <div aria-hidden="true" class="absolute -bottom-40 -right-40 h-96 w-96 rounded-full bg-violet-600/20 blur-3xl"></div>

        <div class="relative z-10 w-full flex items-center justify-center">
            {{ $slot }}
        </div>

        @livewireScripts
    </body>
</html>
