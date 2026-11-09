<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'CTE Portal')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="error-body">
    <main class="error-card">
        <div class="brand-mark">CTE</div>
        <p class="eyebrow">@yield('code')</p>
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>
        <a class="primary-link" href="/">Ana panele dön</a>
    </main>
</body>
</html>
