# Nested components

A component can render others with `@child`. Each child is a component of its own, with its own
state, actions and loaders.

```blade
{{-- resources/components/todos.blade.php --}}
@foreach ($todos as $todo)
    @child('todo-item', ['todo' => $todo], $todo['id'])
@endforeach
```

```blade
{{-- resources/components/todo-item.blade.php --}}
<?php
use Unscripted\Ui\{Component, State};

new class extends Component {
    public array $todo = [];
    #[State] public bool $editing = false;

    public function edit(): void
    {
        $this->editing = true;
    }
};
?>

<li>
    @if ($editing) <input value="{{ $todo['title'] }}"> @else {{ $todo['title'] }} @endif
    <button u-on:click="edit">Edit</button>
</li>
```

## Props

`@child('name', $props, $key)` sets the child's public properties from `$props` when it's
created, and again whenever the parent passes different values: props are always up to date.
The rest of the child's state (`$editing` above) is its own, and survives the parent's
re-renders.

## Keys

The third argument tells children in a list apart, so each keeps its state as the list is
reordered, added to or shortened. Use something stable, such as the row's id. A child its parent
stops rendering is unmounted.

## Talking to the parent

A child's buttons call the child's actions, never the parent's: each component handles events
only up to its children's roots. To tell the parent something, dispatch an [event](events.md):

```php
// in the child
public function done(): void
{
    $this->dispatch('todo-done', ['id' => $this->todo['id']]);
}
```

```php
// in the parent
#[On('todo-done')]
public function markDone(?array $detail): Generator
{
    yield static::complete($detail['id']);
}
```

## On the server

With `@unscripted(...)`, children are rendered with their parent on the first load, each with
its own loaders and state. In the browser, a child's file is fetched from beside its parent's.

## Partials instead

For markup with no state of its own, a [partial](components.md#partials) is lighter: it's
inlined into the parent, with no component of its own.
