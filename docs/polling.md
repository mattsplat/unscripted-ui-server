# Polling

## On a timer

```blade
<div u-poll.5s="refresh">
    {{ $visitors }} people here
</div>
```

`u-poll` calls the action every 5 seconds while the element is on the page. Other intervals:
`.500ms`, `.30s`. With no interval, it's every 2 seconds.

The action is usually one that calls the server, and after a server call every loader runs
again, so polling a no-op action that just calls the server is enough to refresh them all:

```php
#[Server, Watch]
public static function visitors(): int
{
    return Visit::where('created_at', '>', now()->subMinutes(5))->count();
}

#[Server]
public static function ping(): void {}

public function refresh(): Generator
{
    yield static::ping();
}
```

For updates the moment data changes, rather than on a timer, see [live updates](live-updates.md):
they cost nothing while nothing changes.

## Once, when it appears

```blade
<div u-init="loadStats">...</div>
```

`u-init` calls the action once, just after the element first appears. For data a component
needs on mount, a [loader](server-methods.md#loaders) is usually simpler.
