# Live updates

When data changes, every page showing it can update straight away: one person adds a todo, and
everyone's list shows it.

## Reloading a loader

Mark a [loader](server-methods.md#loaders) `#[Live('key')]`, and call `Live::changed('key')` on
the server after changing what it reads:

```php
use Unscripted\Ui\{Component, Live, Server, Watch};
use Unscripted\UiServer\Live as Changed;

new class extends Component {
    public array $todos = [];

    #[Server, Watch, Live('todos')]
    public static function todos(): array
    {
        return Todo::latest()->get(['id', 'title'])->toArray();
    }

    #[Server]
    public static function create(string $title): void
    {
        Todo::create(['title' => $title]);
        Changed::changed('todos');
    }
};
```

(Both classes are called `Live`, so alias the server's: `Unscripted\Ui\Live` is the attribute,
`Unscripted\UiServer\Live` sends the news.)

- **The message only names the key, never the data.** Each page's loader fetches the data with
  its own call, so `can` and your other checks still apply to each viewer.
- **`changed()` works from anywhere** on the server: a server method, a controller, a queued
  job, an Artisan command.
- **The page that made the change isn't told**, since its loaders run after its own calls anyway.

### How pages hear about it

With the [WebSocket transport](websockets.md), pages hear on their socket, on the public
channel `unscripted-live.<key>`. If the socket drops, it reconnects after two seconds and runs
its live loaders again, in case it missed something.

Without it, pages poll instead, every 5 seconds by default:
`#[Live('todos', poll: 10000)]` asks every 10.

## Sending data itself

For changes too frequent to refetch, such as players' positions in a game, send the data:

```php
use Unscripted\UiServer\Live as Changed;

#[Server]
public static function move(string $token, float $x, float $y): void
{
    $id = Player::fromToken($token)->id;   // check who's moving
    Changed::send('game', ['player' => [$id, $x, $y]]);
}

// a browser method, not a loader:
#[Live('game')]
public function heard(array $message): void
{
    [$id, $x, $y] = $message['player'];
    $this->players[$id] = [$x, $y];
}
```

Every page's `#[Live('game')]` method is called with the data, then renders.

- **The channel is public:** send only what anyone may see.
- **It needs the WebSocket transport.** There's nothing to poll.
- **What's sent while a page's socket is down is lost.** Give a loader the same key, and it runs
  again when the socket's back, to catch up.
