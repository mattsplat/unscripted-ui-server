# Actions

An action is a public method on the component. The page calls it with `u-on`, it runs in the
browser, and the component renders again afterwards.

```blade
<?php
use Unscripted\Ui\{Component, State};

new class extends Component {
    #[State] public int $count = 0;

    public function add(int $by = 1): void
    {
        $this->count += $by;
    }
};
?>

<button u-on:click="add">+1</button>
<button u-on:click="add(10)">+10</button>
```

## Calling actions

`u-on:<event>="method"` works for any DOM event: `click`, `submit`, `input`, `change`,
`keydown`, `mouseenter` and so on. Arguments are JSON literals: numbers, `true`, `false`,
`null`, and strings in double quotes. For a string, put the attribute in single quotes:

```blade
<button u-on:click="move(1, -1)">↘</button>
<button u-on:click="remove({{ $todo['id'] }})">×</button>
<button u-on:click='show("done")'>Done</button>
```

An element can have several `u-on` attributes for the same event, each with its own
modifiers.

## Modifiers

| | |
|---|---|
| `.prevent` | `preventDefault()` first: `u-on:submit.prevent="save"` |
| `.stop` | `stopPropagation()` |
| `.self` | Only when the event is on this element, not a child |
| `.once` | Only the first time |
| `.window` | Listens on the whole window: `u-on:keydown.window.escape="close"` |
| `.outside` | A click outside the element, for closing menus: `u-on:click.outside="close"` |
| Keys | `.enter`, `.escape`, `.space`, `.tab`, `.up`, `.down`, `.left`, `.right`, `.delete`, `.backspace`, or one character: `.k` |
| Modifier keys | `.ctrl`, `.shift`, `.alt`, `.meta`, combined: `u-on:keydown.ctrl.k="search"` |

## Asking first

```blade
<button u-on:click="delete" u-confirm="Delete this list?">Delete</button>
```

If the answer is no, nothing happens.

## Waiting for the server

An action that calls a [server method](server-methods.md) returns `Generator`, and waits with
`yield`:

```php
public function save(): Generator
{
    $id = yield static::store($this->title, $this->body);
    $this->redirect("/notes/$id");
}
```

- **Several at once:** `[$a, $b] = yield [static::one(), static::two()];` makes both calls in
  one request and gets both results.
- **Failures** are thrown into the action at its `yield` as a `ServerError`. A validation
  failure needn't be caught: the action stops there, and `@error` shows the messages. See
  [errors](server-methods.md#errors).
- **Starting again replaces it.** If an action starts while its last run is still waiting,
  the old run's result is dropped, so a quick double-click or fast typing never shows a stale
  answer. The server still did what the dropped call asked.
- **Afterwards, loaders run again**, so the page shows what the server now has.

## Redirecting

```php
$this->redirect('/thanks');
```

## Re-rendering

Actions re-render the component when they finish. State changed elsewhere (a timer, a
JavaScript callback) needs `$this->refresh()`.

## What can't be called

`u-on` calls only the component's own public, non-static methods. It can't call the
`Component` class's own methods (`mount`, `refresh`), private or protected ones, or magic
methods. Static `#[Server]` methods are the exception: `u-on:click="remove(3)"` calls one
straight from the page, though what it returns isn't kept.

A child component's buttons call the child's actions, not the parent's. See
[nested components](nested-components.md).
