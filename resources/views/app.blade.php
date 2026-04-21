<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>Cellar — Your backups, preserved.</title>
    <link rel="icon" type="image/svg+xml" href="/logo.svg" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=Geist:wght@400;500;600&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body data-theme="dark" data-motion="full" class="noise-overlay">
    <div id="app"></div>
</body>
</html>
