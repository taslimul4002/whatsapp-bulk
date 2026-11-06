<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Laravel') }}</title>
    </head>
    <body>
        <main>
            <h1>{{ config('app.name', 'Laravel') }}</h1>
            <p>Application ready.</p>
        </main>
    </body>
</html>
