<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $title }}</title>
</head>
<body>
  {{ $slot }}
  <script type="text/php" src="{{ url('unscripted/ui.php') }}"></script>
  <script src="{{ config('unscripted.runtime', 'https://cdn.jsdelivr.net/npm/@unscripted/runtime/auto.js') }}"
          data-php="{{ config('unscripted.pack', 'https://cdn.jsdelivr.net/npm/@unscripted/php84/php_pack.wasm') }}"></script>
</body>
</html>
