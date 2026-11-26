<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>Услуги | Смета</title>
    @if (!(app()->isLocal() && config('miniapp.local_demo') && !config('miniapp.token')))
        <script src="https://telegram.org/js/telegram-web-app.js"></script>
    @endif
    @vite('resources/js/mini.js')
</head>
<body><div id="mini-app"></div><noscript>Включите JavaScript.</noscript></body>
</html>
