<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Workspace') }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Geist+Mono:wght@400..600&family=Geist:wght@400..600&display=swap" rel="stylesheet">

        <script>
            (() => {
                try {
                    if (localStorage.getItem('workspace-theme') === 'dark') {
                        document.documentElement.classList.add('dark');
                    }
                } catch {}
            })();
        </script>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @endif

        @inertiaHead
    </head>
    <body class="bg-canvas text-primary font-sans antialiased selection:bg-accent-primary/15 selection:text-primary">
        @inertia
    </body>
</html>
