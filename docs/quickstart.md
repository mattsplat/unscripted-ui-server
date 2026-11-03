# Quickstart

Build a counter, then a todo list backed by your database. You'll need a Laravel app with
`unscripted/ui-server` [installed](installation.md).

## 1. A counter

Create `resources/components/counter.blade.php`:

```blade
<?php
use Unscripted\Ui\{Component, State};

new class extends Component {
    #[State] public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function decrement(): void
    {
        $this->count--;
    }
};
?>

<div>
    <button u-on:click="decrement">−</button>
    <span>{{ $count }}</span>
    <button u-on:click="increment">+</button>
</div>
```

A component is one file: a class at the top, in a PHP block, and its Blade template under it.
The template sees the class's public properties as variables. `u-on:click="increment"` calls
`increment()` when the button is clicked, and the component renders again.

Give it a page:

```php
// routes/web.php
Route::unscripted('/counter', 'counter', ['title' => 'Counter']);
```

Visit `/counter` and click. The count changes with no request to your server: the class is
running in the browser. Open the network tab to check.

## 2. A todo list

Todos live in the database, so this component needs the server. Make the model:

```sh
php artisan make:model Todo -m
```

```php
// the migration's up()
Schema::create('todos', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->boolean('done')->default(false);
    $table->timestamps();
});
```

```php
// app/Models/Todo.php
class Todo extends Model
{
    protected $fillable = ['title', 'done'];
    protected $casts = ['done' => 'boolean'];
}
```

```sh
php artisan migrate
```

Now `resources/components/todos.blade.php`:

```blade
<?php
use App\Models\Todo;
use Unscripted\Ui\{Component, Server, State, Validate, Watch};

new class extends Component {
    #[State] public string $draft = '';
    public array $todos = [];

    // Runs on the server when the component mounts, and its result goes in $todos.
    #[Server, Watch]
    public static function todos(): array
    {
        return Todo::latest()->get(['id', 'title', 'done'])->toArray();
    }

    // Runs on the server, after Laravel's validator has checked the title.
    #[Server]
    public static function create(#[Validate('required|max:100', as: 'draft')] string $title): void
    {
        Todo::create(['title' => $title]);
    }

    #[Server]
    public static function toggle(int $id): void
    {
        $todo = Todo::findOrFail($id);
        $todo->update(['done' => !$todo->done]);
    }

    // Runs in the browser. `yield` waits for the server.
    public function add(): Generator
    {
        yield static::create($this->draft);
        $this->draft = '';
    }
};
?>

<form u-on:submit.prevent="add">
    <input u-model="draft" placeholder="What needs doing?">
    <button>Add</button>
    <span u-loading="add">Saving…</span>
</form>
@error('draft') <p class="error">{{ $message }}</p> @enderror

<ul>
    @foreach ($todos as $todo)
        <li u-key="{{ $todo['id'] }}">
            <label>
                <input type="checkbox" @checked($todo['done']) u-on:change="toggle({{ $todo['id'] }})">
                {{ $todo['title'] }}
            </label>
        </li>
    @endforeach
</ul>
```

And a route: `Route::unscripted('/todos', 'todos', ['title' => 'Todos']);`

What's happening:

- **`#[Server]` methods stay on the server.** The browser gets the file with their bodies
  replaced by stubs, so `Todo` queries never leave your app. They're `static`: they get only
  their arguments, never the component, because the component lives in the user's browser.
- **`#[Watch]` makes a loader.** `todos()` runs when the component mounts, and its result
  fills the property of the same name, `$todos`.
- **`add()` runs in the browser** and calls `create()` with `yield`, which waits for the
  result. If validation fails, `add()` stops at the `yield` and `@error('draft')` shows the
  message. `as: 'draft'` ties the error to the input, so the browser checks the rules it knows
  as you type, too.
- **After any server call, loaders run again**, so `$todos` shows what the database has.
- **`toggle(...)` is called straight from the page**, with no browser method in between.
- **`u-key`** keeps each row's element as rows are added and removed.
- **The first page load is rendered on the server**, loaders included, so the list is there
  before PHP has loaded in the browser.

## Next

- [Coming from Livewire](coming-from-livewire.md), if you know it.
- [Server methods](server-methods.md), for loaders, errors and permissions.
- [Live updates](live-updates.md), to show other people's todos as they're added.
