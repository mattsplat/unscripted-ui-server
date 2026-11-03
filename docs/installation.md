# Installation

## Requirements

- PHP 8.4 and Laravel 12 or 13.
- A browser with WebAssembly. Chromium is tested; try Firefox and Safari before relying on them.

## The package

`unscripted/ui-server` isn't on Packagist yet, so add its Git repository first:

```sh
composer config repositories.unscripted vcs https://github.com/mattsplat/unscripted-ui-server
composer require unscripted/ui-server:dev-main
```

Laravel discovers its service provider. It adds:

| | |
|---|---|
| `GET /unscripted/ui.php` | The browser's copy of the framework, the same version the server renders with |
| `GET /unscripted/components/<name>.blade.php` | A component as the browser gets it, with each `#[Server]` method's body replaced by a stub |
| `POST /unscripted/call` | Runs `#[Server]` methods: `{"calls": [{"component": "todos", "method": "create", "args": ["Milk"]}]}` returns `{"results": [...]}` |
| `POST /unscripted/upload`, `GET /unscripted/upload/<id>` | [File uploads](file-uploads.md) |
| `@unscripted('name')` | A Blade directive that puts a component on a page, rendered on the server |
| `Route::unscripted('/uri', 'name')` | A route whose page is a component |

The routes are in the `web` middleware group, so they have sessions, auth and CSRF
protection. Change that with `middleware` in the [configuration](configuration.md).

## Components folder

Components live in `resources/components/`, one file each: `resources/components/counter.blade.php`
is the component `counter`. Create the folder; there's no `make` command.

## The page

A page that shows components needs three things: Laravel's CSRF token (for server calls), the
components, and two scripts after them. One loads the framework; the other loads the runtime
and PHP itself.

```blade
<!doctype html>
<html>
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body>
    @unscripted('counter')

    <script type="text/php" src="/unscripted/ui.php"></script>
    <script src="https://cdn.jsdelivr.net/npm/@mtjncn/runtime@0.1/auto.js"
            data-php="https://cdn.jsdelivr.net/npm/@mtjncn/php84@0.1/php_pack.wasm"></script>
</body>
</html>
```

Put the scripts in your layout once, and use `@unscripted(...)` wherever a component goes.

- **`/unscripted/ui.php`** comes from the package, not the CDN, so the browser runs the exact
  version the server renders with.
- **The runtime and PHP** come from jsDelivr. The PHP pack is about 5 MB (1.5 MB compressed),
  downloaded once and then cached. `@0.1` takes the newest 0.1.x release; write `@0.1.0` to
  stay on exactly one. To serve them yourself, `npm install @mtjncn/runtime
  @mtjncn/php84` and copy `auto.js`, `index.js` and `php_pack.wasm` into `public/`.
- **Until PHP has loaded,** the page shows the server's HTML, but buttons don't do anything yet.
  See [server rendering](server-rendering.md).

## A whole page

For a page that's just one component, skip the view:

```php
// routes/web.php
Route::unscripted('/counter', 'counter', ['title' => 'Counter']);
```

It uses the package's own minimal page. To use your layout instead, pass
`['layout' => 'layouts.app']`: the layout gets the component as `$slot` and the `$title`, and
must load the two scripts itself.

## Serving `.blade.php` files

Components reach the browser through the package's `/unscripted/components/...` route, so
Laravel serves them. Don't put them in `public/`: a web server set up for PHP would run them
instead of sending them.

## Next

Try the [quickstart](quickstart.md), or read [how it differs from Livewire](coming-from-livewire.md).
