<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('lookout.title') ?: 'Lookout' }} · {{ config('app.name') }}</title>
    <script>
        // Theme before first paint (the SPA keeps it in localStorage: dark | light | system).
        (function () {
            var t = 'dark';
            try { t = localStorage.getItem(@json(($boot['storage_prefix'] ?? 'lookout-').'theme')) || 'dark'; } catch (e) {}
            if (t === 'system') { t = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark'; }
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Geist+Mono:wght@400;500&display=swap">
    @if ($style)
        <link rel="stylesheet" href="{{ $style }}">
    @else
        <style>html, body { margin: 0; height: 100%; background: #161616; color: #ededed; font-family: ui-sans-serif, system-ui, sans-serif; }</style>
    @endif
</head>
<body>
    {{-- The SPA (bin/build.sh builds it into dist/) mounts here and reads window.LookoutBoot. --}}
    <div id="lookout-app" data-api-base="{{ $boot['api_base'] }}">
        @unless ($script)
            <p style="padding: 24px;">{{ $boot['app_name'] ?? 'Lookout' }}</p>
        @endunless
    </div>
    <noscript><p style="padding: 24px;">Lookout needs JavaScript.</p></noscript>
    <script>window.LookoutBoot = @json($boot);</script>
    @if ($script)
        <script type="module" src="{{ $script }}"></script>
    @endif
</body>
</html>
