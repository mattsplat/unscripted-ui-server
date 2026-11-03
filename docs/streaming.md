# Streaming

A server method that's a generator can send its output as it's made, which suits text that
takes a while, such as a reply from a language model.

```php
use Unscripted\Ui\{Component, Server, State};

new class extends Component {
    #[State] public string $topic = '';
    public string $story = '';

    #[Server]
    public static function write(string $topic): Generator
    {
        foreach (Story::about($topic) as $chunk) {   // your own source of chunks
            yield $chunk;
        }
        return 'done';
    }

    public function tell(): Generator
    {
        $this->story = '';
        $result = yield static::write($this->topic)->into('story');
    }
};
```

```blade
<input u-model="topic"> <button u-on:click="tell">Tell me a story</button>
<p>{{ $story }}</p>
```

- **`->into('story')`** appends each chunk to `$story` as it arrives, rendering each time.
- **The `yield`** gets the method's return value when it's done, or the whole text if it
  returns nothing.
- **Over HTTP** the browser gets one JSON line per chunk (`application/x-ndjson`), as they're
  yielded.
- **Over the [WebSocket transport](websockets.md)**, and in [tests](testing.md), the method runs
  to the end first and the text arrives all at once.
- **Without `->into()`**, the method is run through and the result is its return value, or the
  chunks joined.
