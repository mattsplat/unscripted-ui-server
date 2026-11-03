# Testing

Drive a component in PHPUnit, without a browser:

```php
use Unscripted\UiServer\Components;

public function test_adding_a_todo(): void
{
    $todos = app(Components::class)->test('todos');

    $todos->set('draft', 'Milk')
        ->call('add')
        ->assertSee('Milk')
        ->assertSet('draft', '')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('todos', ['title' => 'Milk']);
}

public function test_a_title_is_required(): void
{
    app(Components::class)->test('todos')
        ->call('add')
        ->assertHasErrors('draft');
}

public function test_only_finished_todos_can_be_removed(): void
{
    $todo = Todo::create(['title' => 'Milk']);

    app(Components::class)->test('todos')
        ->call('remove', $todo->id)
        ->assertFailed(403);
}
```

The test component is built from the source the browser gets, stubs and all, and its server
calls run in the test's own app. So they go through `can` and `#[Validate]` as a page's do,
just without middleware. Loaders run on mount, as on a page.

| Method | Does |
|---|---|
| `set('path', $value)` | Writes a property, as `u-model` would (hooks included) |
| `call('action', ...$args)` | Runs an action, or a `#[Server]` method, as `u-on` would |
| `get('path')` | Reads a property |
| `html()` | The rendered HTML |
| `assertSee($text)`, `assertDontSee($text)` | The HTML has, or hasn't, the text |
| `assertSet('path', $value)` | A property's value |
| `assertHasErrors('field')`, `assertHasNoErrors()` | The error bag |
| `assertDispatched('event')` | An event was dispatched |
| `assertRedirect('/url')` | The component redirected |
| `assertFailed($status)` | A call failed with that status and the component didn't handle it |

A failed call the component doesn't handle is kept for `assertFailed()`. An exception that's
a bug in your code is thrown to the test.

[Streaming](streaming.md) methods give their text all at once in tests, and there are no timers,
so debounced loaders run straight away.
