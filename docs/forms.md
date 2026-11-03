# Forms

## Binding inputs

`u-model` ties an input to a `#[State]` property both ways: the input shows the property, and
typing writes it back, re-rendering as you go.

```blade
<input u-model="title">
<textarea u-model="body"></textarea>
<input type="checkbox" u-model="done">           {{-- a bool --}}
<input type="radio" u-model="size" value="s">    {{-- the chosen value --}}
<select u-model="country">...</select>
<select multiple u-model="tags">...</select>     {{-- a list --}}
<input u-model="todos.3.title">                  {{-- a path into an array property --}}
```

Text inputs write on every keystroke. Checkboxes, radios and selects write on change.

| Modifier | Writes |
|---|---|
| `u-model.blur` | When the field loses focus, not on each keystroke |
| `u-model.debounce.300ms` | Once typing has paused that long (250 ms without a number) |
| `u-model.number` | A number: `"4.5"` becomes `4.5` |
| `u-model.boolean` | A bool: `"true"`, `"1"`, `"on"` and `"yes"` are true |

`u-model` can only write public `#[State]` properties that aren't `readonly` or `private(set)`.

## Submitting

```blade
<form u-on:submit.prevent="save">
    <input u-model="form.title">
    <input u-model="form.email" type="email">
    <button>Save</button>
</form>
```

```php
#[State] public array $form = ['title' => '', 'email' => ''];

public function save(): Generator
{
    yield static::store($this->form);
    $this->form = ['title' => '', 'email' => ''];
}
```

## Validation

Rules go on the **server method's parameters**, because the server is where they're enforced:

```php
use Unscripted\Ui\{Server, Validate};

#[Server]
public static function store(
    #[Validate(['title' => 'required|max:100', 'email' => 'required|email'], as: 'form')] array $form,
): void {
    Contact::create($form);
}
```

- **One value:** `#[Validate('required|max:100')] string $title`. Errors are keyed by the
  parameter's name, `title`.
- **A form:** an array argument with rules by field. Errors are keyed `form.title`,
  `form.email`.
- **`as:`** keys the errors by the `u-model` path the value comes from (`as: 'draft'`, or
  `as: 'form'` for a form). That's what lets the browser check the field as it's edited.
- Any of Laravel's rules work: the server runs Laravel's validator, before the method and
  after `can`. A missing argument is checked with the parameter's default; a variadic
  parameter's rules apply to each value.
- The values aren't trimmed first (Laravel's middleware doesn't run for calls), though
  `required` treats a blank string as missing anyway.

### In the browser, as people type

With `as:`, the browser checks a field's rules each time it changes, with Laravel's English
messages, so mistakes show at once. It knows `required`, `string`, `min`, `max` and `between`
(for lengths and numbers), `numeric`, `integer`, `email`, `url`, `in` and `boolean`. Rules it
doesn't know, such as `unique`, `exists` or `date`, are left for the server, which checks
everything again anyway.

To check every field before calling the server:

```php
public function save(): Generator
{
    if (!$this->validate()) {
        return;
    }
    yield static::store($this->form);
}
```

`$this->validate(['form.title'])` checks just those.

## Showing errors

```blade
<input u-model="form.title">
@error('form.title') <p class="error">{{ $message }}</p> @enderror
```

`@error` shows a field's first message. A failed validation fills the error bag, replacing
what was there, and the action's next successful call empties it.

The bag is `$errors` in a template and `$this->errors()` in the component: `has()`, `first()`,
`get()`, `all()`, `any()`, `add()` and `clear()`.

```php
$this->errors()->add('form.email', 'That address is already on the list.');
```

## File inputs

A `u-model` file input uploads as soon as a file is chosen. See [file uploads](file-uploads.md).
