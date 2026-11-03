# Events

Components talk to each other with events. An event is a browser `CustomEvent` on `window`, so
JavaScript can send and hear them too.

## Dispatching

```php
public function save(): Generator
{
    $id = yield static::store($this->form);
    $this->dispatch('note-saved', ['id' => $id]);
}
```

## Listening

```php
use Unscripted\Ui\On;

#[On('note-saved')]
public function saved(?array $detail): void
{
    $this->lastSaved = $detail['id'];
}
```

Any component on the page with an `#[On('note-saved')]` method hears it, gets the detail, and
renders again. A listener can be a generator, like an action, to call the server. One method
can listen for several events: repeat the attribute.

## With JavaScript

Hear a component's event:

```js
window.addEventListener('note-saved', (e) => console.log(e.detail.id));
```

Send one to the components:

```js
window.dispatchEvent(new CustomEvent('note-saved', { detail: { id: 3 } }));
```

Call an action on a component directly, from its root element:

```js
document.getElementById('unscripted-notes')
    .dispatchEvent(new CustomEvent('unscripted:call', { detail: { action: 'save', args: [] } }));
```

This is how a component works with code that isn't PHP: a chart library, a map, a game written
in Rust and compiled to WebAssembly. Let the other code send events, and listen with `#[On]`;
in the other direction, call it through `U::window()`:

```php
use Unscripted as U;

U::window()->game->play($this->level);
```

Mark the element the other code draws in with `u-ignore`, so re-renders leave it alone.

## Between parents and children

A child tells its parent something with `dispatch()`, and the parent listens with `#[On]`.
See [nested components](nested-components.md).
