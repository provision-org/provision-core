<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Default SEO meta tags (overridden per-page via Inertia Head) --}}
        <meta name="description" content="Deploy AI employees that work alongside your team. They join your Slack, get their own email and browser, and learn your workflows by watching you work.">
        <meta name="keywords" content="AI employees, AI agents, AI workforce, OpenClaw, Slack AI, AI automation, AI coworkers, provision">

        {{-- Open Graph --}}
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="Provision">
        <meta property="og:title" content="Provision — Meet your AI workforce">
        <meta property="og:description" content="Deploy AI employees that work alongside your team. They join your Slack, get their own email and browser, and learn your workflows by watching you work.">
        <meta property="og:image" content="{{ url('/og/default.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:url" content="{{ url()->current() }}">

        {{-- Twitter Card --}}
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="Provision — Meet your AI workforce">
        <meta name="twitter:description" content="Deploy AI employees that work alongside your team. They join your Slack, get their own email and browser, and learn your workflows by watching you work.">
        <meta name="twitter:image" content="{{ url('/og/default.png') }}">

        {{-- Canonical URL --}}
        <link rel="canonical" href="{{ url()->current() }}">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(0.98 0.002 275);
            }

            html.dark {
                background-color: oklch(0.155 0.008 60);
            }
        </style>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700" rel="stylesheet" />

        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
