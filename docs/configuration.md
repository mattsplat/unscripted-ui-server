# Configuration

Everything has a default. To change something, create `config/unscripted.php`:

```php
<?php

return [
    // Where components live.
    'components' => resource_path('components'),

    // Middleware for the package's routes (calls and uploads). Add rate limiting, or 'auth'
    // to require login for every call.
    'middleware' => ['web'],

    // How pages call server methods: 'http' (a POST each) or 'reverb' (one WebSocket).
    'transport' => env('UNSCRIPTED_TRANSPORT', 'http'),

    // Where the browser reaches Reverb, if not at the broadcasting connection's address.
    'socket_url' => null,

    // Checked for every upload, before your server method sees it.
    'upload_rules' => 'required|file|max:10240',

    // For Route::unscripted()'s built-in page: the runtime and PHP pack's URLs. The CDN when null.
    'runtime' => null,
    'pack' => null,
];
```

| Key | Default | |
|---|---|---|
| `components` | `resource_path('components')` | The folder components are read from |
| `middleware` | `['web']` | Middleware for `/unscripted/...` routes |
| `transport` | `'http'` | `'reverb'` for [WebSockets](websockets.md) |
| `socket_url` | `null` | The address the browser uses for Reverb, when it differs from the server's (behind a proxy, say) |
| `upload_rules` | `'required\|file\|max:10240'` | Laravel rules for [uploads](file-uploads.md) |
| `runtime` | jsDelivr | `auto.js`'s URL, for `Route::unscripted()`'s page |
| `pack` | jsDelivr | `php_pack.wasm`'s URL, for the same |

Your own pages load the scripts themselves, so `runtime` and `pack` matter only for
`Route::unscripted()` without a `layout`.
