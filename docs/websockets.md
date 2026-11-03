# WebSockets

By default each server call is a `POST`. Opt in, and a page's calls go over one WebSocket to
[Laravel Reverb](https://reverb.laravel.com) instead: faster for chatty components, and needed
to [send data live](live-updates.md#sending-data-itself).

## Setting it up

```sh
php artisan install:broadcasting --reverb
```

```php
// config/unscripted.php
return ['transport' => 'reverb'];   // default 'http'
```

Then run Reverb beside your app:

```sh
php artisan reverb:start
```

Components must go on the page with `@unscripted(...)`, which tells the browser where the
socket is: the `reverb` broadcasting connection's address, or `socket_url` in the
[config](configuration.md) if the browser reaches Reverb somewhere else. Octane isn't needed:
Reverb is its own long-running process.

`POST /unscripted/call` stays up either way.

## What's different over the socket

- **Who's calling:** a logged-in user's socket joins the private channel
  `private-unscripted.<id>`, signed by `/broadcasting/auth` with their session (the package
  registers the channel). Each call then runs as that user, so `auth()`, gates and `can` work as
  over HTTP. With no channel, calls are a guest's. Logging out doesn't close the socket: the
  user stays logged in on it until the page is closed.
- **No middleware per message.** Nothing in the `web` group runs for a call: no session, no
  CSRF check (the channel's signature stands in), no throttling but Reverb's own rate limit.
  `session()` in a server method doesn't have the user's session.
- **One process for everyone.** Methods run in the Reverb process, one message at a time. A
  slow method holds up every socket on that server until it returns, so keep them quick, or run
  more Reverb servers with Reverb's scaling. Like Octane, the process lives on, so static state
  in your code is shared between users' calls.
- **Errors** arrive as over HTTP: a validation failure is a 422 with its errors, a denied gate
  a 403, and other exceptions are reported and sent as a 500 ("Server Error" unless `app.debug`).
- **Size:** Reverb refuses messages over its `max_message_size`, 10 KB by default, which limits
  a call's arguments.
- **[Streaming](streaming.md)** arrives all at once, when the method is done.
- **If Reverb is down,** the waiting calls fail with a `ServerError`, and the next call opens a
  new socket.

## Deploying

**Restart Reverb after every deploy**, as you would Octane: it keeps the code it started with.
