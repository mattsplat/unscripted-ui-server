# Coming from Livewire

If you know Livewire, you know most of this already: a component is a PHP class with a Blade
template, public properties are its state, methods are actions, and it renders again after each
one. Many of the names are the same. What's different is **where the component runs.**

## Where the code runs

In Livewire, the component lives on the server. Each action sends the component's state to
the server, runs the method there, renders the template there, and sends back HTML.

Here, the component lives in the browser. PHP 8.4 runs there, compiled to WebAssembly, so an
action runs locally and the template renders locally, with no request at all. When you need the
server (a database, a mail, a secret), you write a `#[Server]` method, and the browser calls it.

| | Livewire | Unscripted |
|---|---|---|
| A click or keystroke | A request, unless it's Alpine | Runs in the browser, no request |
| Rendering | On the server, for every update | In the browser; on the server only for the first load |
| Server code | Any method, with the component's state | Only `#[Server]` methods, which are `static` and get only their arguments |
| State | Sent to the server with each request, checksummed | Stays in the browser |
| First load | Light | The PHP runtime, about 1.5 MB compressed, once, then cached |

### What follows from that

- **Server methods are `static`.** A server method can't read `$this`, because the component
  is in the browser, under the user's control. It gets only the arguments the page sends, and
  must check them as it would a form submission. See [security](security.md).
- **Nothing is locked.** Livewire's `#[Locked]` stops the browser changing a property. Here
  the browser owns all the state, so a property can always be changed by someone with dev
  tools. `#[State]` only marks which properties `u-model` may write: think of a property
  *without* it as locked against forms, not against users.
- **Templates run in both places**, the browser and (for the first load) the server, so they
  can use only what both have: no `route()`, `auth()`, `config()` or other Laravel helpers.
  Get those values from a loader, or pass them in.
- **Actions can't touch the database.** Eloquent isn't in the browser. Put queries in
  `#[Server]` methods and call them.
- **Waiting is `yield`.** An action calls a server method and waits with
  `$result = yield static::save($data);`. There's no `async`/`await` in PHP, and no Fibers in
  the browser's build, so generators do that job.

## Side by side

| Livewire | Unscripted |
|---|---|
| `php artisan make:livewire counter` | Create `resources/components/counter.blade.php` |
| A single-file (Volt-style) component | The only kind: a class in a `<?php ?>` block, the template under it |
| `<livewire:counter />`, `@livewire('counter')` | `@unscripted('counter')` |
| `Route::get('/counter', Counter::class)` | `Route::unscripted('/counter', 'counter')` |
| `public $count` | `public int $count`; add `#[State]` for `u-model` to write it |
| `#[Locked]` | A property without `#[State]` (see above) |
| `#[Computed]` | `#[Computed]`, cached for one render |
| `#[Url]` | `#[Url]`, also read on the server for the first render |
| `wire:model.live` | `u-model` (live by default) |
| `wire:model.blur`, `.debounce.300ms` | `u-model.blur`, `u-model.debounce.300ms` |
| `wire:model` (deferred) | `u-model.blur` is the closest |
| `wire:click="save"`, `wire:submit="save"` | `u-on:click="save"`, `u-on:submit.prevent="save"` |
| `wire:keydown.enter` | `u-on:keydown.enter`; also `.ctrl`, `.outside`, `.window`... |
| `wire:key` | `u-key` |
| `wire:loading`, `wire:target="save"` | `u-loading="save"` |
| `wire:offline` | `u-offline` |
| `wire:poll.5s` | `u-poll.5s="refresh"` |
| `wire:init` | `u-init="load"` |
| `wire:confirm` | `u-confirm` |
| `wire:ignore` | `u-ignore` |
| `wire:navigate`, `@persist` | `u-navigate`, `u-persist="id"` |
| `wire:stream` | `yield static::reply($q)->into('text')` |
| `wire:dirty`, `wire:transition` | Not available (use CSS for transitions) |
| `mount()` | Props from the parent, and `mounted()` after the first render |
| `updatingX`, `updatedX`, `updated` | The same names |
| `$refresh`, `$this->dispatch`, `#[On]` | `$this->refresh()`, `$this->dispatch`, `#[On]` |
| `$this->redirect('/done')` | `$this->redirect('/done')` |
| `#[Validate]` on a property, `$this->validate()` | `#[Validate]` on a server method's *parameter*; `$this->validate()` checks before calling |
| `@error('title')` | `@error('title')` |
| `WithFileUploads`, `TemporaryUploadedFile` | `u-model` on a file input, then `Upload::find()` in a server method |
| `WithPagination` | A loader that watches a `$page` property |
| `#[Lazy]` | `@unscripted('stats', ['lazy' => true, 'placeholder' => '...'])` |
| Nested `<livewire:item :item="$item" :key="$item->id" />` | `@child('item', ['item' => $item], $item['id'])` |
| `#[Reactive]` props | Props are always reactive: the child's property updates when the parent's value changes |
| Echo listeners, `#[On('echo:...')]` | `#[Live('key')]`, with `Live::changed('key')` or `Live::send('key', $data)` on the server |
| `Livewire::test(Counter::class)` | `app(Components::class)->test('counter')` |

## Things to unlearn

- **Don't pass models into the browser.** State has to be JSON. Return
  `->get(['id', 'title'])->toArray()` or `->only(...)`, never the model, and pick only the
  columns the page may see.
- **Don't trust properties in a server method.** Livewire's checksum stops users editing
  state. Here, nothing does, and server methods don't see state anyway: pass what they need as
  arguments, then check it.
- **`int` is 32-bit in the browser.** It tops out at 2,147,483,647 there. A bigger number from
  the server (a millisecond timestamp, say) arrives as a `float`. See
  [how it works](how-it-works.md#32-bit-integers).
