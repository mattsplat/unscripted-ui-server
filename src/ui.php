<?php
/*
 * @mtjncn/ui: reactive components for Unscripted PHP. A component is a class: its #[State]
 * properties are the data, its public methods are actions, and render() prints its HTML. After
 * every action (and every u-model change) it renders again, so the page always shows the state.
 *
 *   final class Counter extends Component {
 *       #[State] public int $count = 0;
 *       public function add(int $by = 1): void { $this->count += $by; }
 *       protected function render(): void { ?>
 *           <button u-on:click="add">Clicked <?= $this->count ?> times</button>
 *       <?php }
 *   }
 *   new Counter()->mount('#app');
 *
 * In the HTML:
 *   u-on:<event>="action"           calls $this->action() (one listener on the root, not one per element)
 *   u-on:<event>="action(1, \"x\")"  with arguments, as JSON literals
 *   u-on:submit.prevent="action"    calls $event->preventDefault() first
 *   u-model="draft"                 two-way: the element shows $this->draft, input writes it back
 *   u-model="todos.3.done"          a path into an array property
 *
 * Or as one Blade file: the class in a PHP block at the top (an anonymous class, no render()),
 * the template under it. Its public properties are the template's variables.
 *
 *   <?php
 *   use Unscripted\Ui\{Component, State};
 *   new class extends Component {
 *       #[State] public int $count = 0;
 *       public function add(): void { $this->count++; }
 *   };
 *   ?>
 *   <button u-on:click="add">Clicked {{ $count }} times</button>
 *
 * mounted from the page with <script type="text/blade" src="counter.blade.php" data-mount="#app">.
 * The Blade is a subset: {{ }}, {!! !!}, {{-- --}}, @{{ }}, @if/@elseif/@else, @unless, @isset,
 * @empty, @foreach, @forelse/@empty, @for, @while, @break, @continue, @php, @verbatim, @class,
 * @checked/@selected/@disabled/@readonly/@required, @json, @error, @props. Other @words are left
 * as written. @include('row') and <x-row /> partials work through unscripted/ui-server.
 *
 * Served by unscripted/ui-server, a component's #[Server] static methods run on the server: an
 * action yields them ($rows = yield static::search($q);), the page calls them
 * (u-on:click="remove(3)"), or #[Watch('query')] makes one a loader that fills the property
 * named like it; #[Live('key')] on one reruns it on every page when the server says key changed.
 * #[Validate('required|max:100')] on a server method's parameter has the server
 * check its argument first; a failure fills $errors (@error('title') {{ $message }} @enderror).
 *
 * ui-server can also render a component on the server first (@unscripted('todos') in a Laravel
 * view), with this same file: its state comes on the <script> as data-state, and mount() picks
 * up from it.
 *
 * Load it before the page's own PHP: <script type="text/php" src="ui.php"></script>.
 * PHP 8.4. Rendering patches the DOM with the runtime's morph, or replaces the root's HTML where
 * the host has none (keeping a u-model input's focus and caret).
 */

namespace Unscripted\Ui;

use Attribute;
use Closure;
use Error;
use Exception;
use Generator;
use ParseError;
use ReflectionMethod;
use ReflectionObject;
use ReflectionProperty;
use Throwable;
use Unscripted as U;

/** Marks a property as component state: u-model may write it unless it's private(set). */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class State {}

/** Marks a public static method that runs on the server (unscripted/ui-server), not here: the
 * file the browser gets has a stub in its place, which returns a ServerCall. `can` names the
 * Laravel ability the server checks first. */
#[Attribute(Attribute::TARGET_METHOD)]
final class Server
{
    public function __construct(public readonly ?string $can = null) {}
}

/** On a #[Server] method: call it with these properties' values on mount and whenever they
 * change, and put what it returns in the property named like the method. With a `debounce`,
 * only once they've stopped changing for that many milliseconds. With no properties, just on
 * mount (and after an action's server calls, as every loader). */
#[Attribute(Attribute::TARGET_METHOD)]
final class Watch
{
    public array $properties;

    public function __construct(string|array $properties = [], public readonly int $debounce = 0)
    {
        $this->properties = (array) $properties;
    }
}

/**
 * On a #[Server] method's parameter: Laravel validation rules for its argument, which
 * unscripted/ui-server checks before the method runs. A failure is a 422 whose errors fill the
 * component's error bag ($errors, @error), keyed by `as` (the u-model path the argument comes
 * from, say) or else the parameter's name. For an array argument (a form), rules by field:
 * `#[Validate(['title' => 'required', 'due' => 'date'], as: 'form')] array $form`, keyed
 * `form.title` and so on. The browser checks the rules it knows as a field is edited (see
 * Component::validate()).
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Validate
{
    public function __construct(public readonly string|array $rules, public readonly ?string $as = null) {}

    /** Whether the rules are by field (a form): a non-empty array with string keys. */
    public function byField(): bool
    {
        return is_array($this->rules) && $this->rules !== [] && !array_is_list($this->rules);
    }
}

/** Validation messages by field, as a server call's 422 sends them: `$errors` in a template,
 * `$this->errors()` in the component. */
final class ErrorBag
{
    /** @param array<string, list<string>> $messages */
    public function __construct(private array $messages = []) {}

    public function has(string $key): bool
    {
        return ($this->messages[$key] ?? []) !== [];
    }

    public function first(string $key): ?string
    {
        return $this->messages[$key][0] ?? null;
    }

    /** @return list<string> */
    public function get(string $key): array
    {
        return $this->messages[$key] ?? [];
    }

    /** Every message, in one list. */
    public function all(): array
    {
        return array_merge(...array_values($this->messages));
    }

    public function any(): bool
    {
        return $this->all() !== [];
    }

    public function add(string $key, string $message): void
    {
        $this->messages[$key][] = $message;
    }

    /** `$key`'s messages, replacing what it had. */
    public function set(string $key, array $messages): void
    {
        $this->messages[$key] = $messages;
        if ($messages === []) {
            unset($this->messages[$key]);
        }
    }

    /** Clears `$key`'s messages, or all of them. */
    public function clear(?string $key = null): void
    {
        if ($key === null) {
            $this->messages = [];
        } else {
            unset($this->messages[$key]);
        }
    }
}

/** On a #[Watch] loader: run it again whenever the server says `key` changed
 * (unscripted/ui-server's `Live::changed('key')`), on every page that has it. With ui-server's
 * 'reverb' transport the page hears about it on its socket; without, it asks every `poll` ms.
 *
 * On a browser method: called with what the server sends (`Live::send('key', $data)`), then
 * renders, as an action. Only over the socket ('reverb'); what's sent while it's down is lost,
 * so pair it with a #[Live] loader, which runs again when the socket's back. */
#[Attribute(Attribute::TARGET_METHOD)]
final class Live
{
    public function __construct(public readonly string $key, public readonly int $poll = 5000) {}
}

/** On a property: kept in the page's query string (`?q=milk`), under its name or `as`, so a
 * reload or a shared link starts from it. Left out while it has its default value. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Url
{
    public function __construct(public readonly ?string $as = null) {}
}

/** On a method: `$this->name` runs it once per render and keeps the result until the next, as
 * a value the template and actions can read. */
#[Attribute(Attribute::TARGET_METHOD)]
final class Computed {}

/** On a method: called when `event` is dispatched, by a component's `$this->dispatch('event',
 * $detail)` or by JavaScript (`window.dispatchEvent(new CustomEvent('event', {detail}))`), with
 * the detail. Renders after, and may be a generator, as an action. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class On
{
    public function __construct(public readonly string $event) {}
}

/**
 * The browser's share of Laravel validation: the common rules, with Laravel's English messages,
 * so a field can be checked as it's edited. Rules it doesn't know (unique, exists, date, ...)
 * pass here; the server checks them all again anyway.
 */
final class Rules
{
    /** The first failure of `$value` against `$rules`, or null. `$attribute` names the field. */
    public static function check(mixed $value, string|array $rules, string $attribute): ?string
    {
        $parsed = [];
        foreach (is_string($rules) ? explode('|', $rules) : $rules as $rule) {
            if (is_string($rule)) {
                [$name, $params] = array_pad(explode(':', $rule, 2), 2, null);
                $parsed[strtolower(trim($name))] = $params === null ? [] : explode(',', $params);
            }
        }
        if ($value === null || $value === [] || (is_string($value) && trim($value) === '')) {
            return isset($parsed['required']) ? "The $attribute field is required." : null;
        }
        $numeric = isset($parsed['numeric']) || isset($parsed['integer']);
        $kind = is_array($value) ? 'array' : ($numeric ? 'numeric' : 'string');
        $size = match ($kind) {
            'array' => count($value),
            'numeric' => is_numeric($value) ? $value + 0 : null,
            'string' => function_exists('mb_strlen') ? mb_strlen((string) $value) : strlen((string) $value),
        };
        foreach ($parsed as $name => $params) {
            $message = match ($name) {
                'numeric' => is_numeric($value) ? null : "The $attribute field must be a number.",
                // Patterns, not filter_var(): the pack has no filter extension. Looser than
                // Laravel's; the server has the last word.
                'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value)) ? null : "The $attribute field must be an integer.",
                'email' => is_string($value) && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $value) ? null : "The $attribute field must be a valid email address.",
                'url' => is_string($value) && preg_match('~^[a-z][a-z0-9+.-]*://[^\s/?#]+\S*$~i', $value) ? null : "The $attribute field must be a valid URL.",
                'boolean' => in_array($value, [true, false, 0, 1, '0', '1'], true) ? null : "The $attribute field must be true or false.",
                'in' => in_array((string) $value, $params, true) ? null : "The selected $attribute is invalid.",
                'max' => $size !== null && $size > (float) $params[0] ? match ($kind) {
                    'array' => "The $attribute field must not have more than {$params[0]} items.",
                    'numeric' => "The $attribute field must not be greater than {$params[0]}.",
                    'string' => "The $attribute field must not be greater than {$params[0]} characters.",
                } : null,
                'min' => $size !== null && $size < (float) $params[0] ? match ($kind) {
                    'array' => "The $attribute field must have at least {$params[0]} items.",
                    'numeric' => "The $attribute field must be at least {$params[0]}.",
                    'string' => "The $attribute field must be at least {$params[0]} characters.",
                } : null,
                'between' => $size !== null && ($size < (float) $params[0] || $size > (float) $params[1]) ? match ($kind) {
                    'array' => "The $attribute field must have between {$params[0]} and {$params[1]} items.",
                    'numeric' => "The $attribute field must be between {$params[0]} and {$params[1]}.",
                    'string' => "The $attribute field must be between {$params[0]} and {$params[1]} characters.",
                } : null,
                default => null,
            };
            if ($message !== null) {
                return $message;
            }
        }
        return null;
    }
}

/** A call to a #[Server] method, made when an action yields it: `$rows = yield static::search($q);`. */
final class ServerCall
{
    public function __construct(
        public readonly string $component,
        public readonly string $method,
        public readonly array $args,
        /** The property a streaming method's chunks are appended to, as they come. */
        public readonly ?string $into = null,
    ) {}

    /** For a #[Server] method that's a generator: append each chunk it yields to property
     * `$property` as it arrives (over HTTP; elsewhere all at the end), rendering after each.
     * The yield gets its return value, or else the whole text. */
    public function into(string $property): self
    {
        return new self($this->component, $this->method, $this->args, $property);
    }
}

/** Thrown into an action when a server call it yielded fails. `$errors` has a validation
 * failure's messages, by field. */
final class ServerError extends Exception
{
    public function __construct(string $message, public readonly int $status = 0, public readonly array $errors = [])
    {
        parent::__construct($message, $status);
    }
}

/** Text for HTML, attributes included. `<?= ?>` doesn't escape: use `<?= e($value) ?>`. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** @class: the keys whose values are true, and the values with no key. */
function classes(array $classes): string
{
    $names = [];
    foreach ($classes as $key => $value) {
        if (is_int($key)) {
            $names[] = $value;
        } elseif ($value) {
            $names[] = $key;
        }
    }
    return e(implode(' ', $names));
}

abstract class Component
{
    private ?object $root = null;
    /** Event type => the u-on attribute names seen for it (with their modifiers). */
    private array $listening = [];
    /** A Blade component's compiled template, and its file's name for error messages. */
    private ?Closure $view = null;
    private ?string $file = null;
    /** Whether the host has unscriptedMorph; null until the first render asks. */
    private static ?bool $morph = null;
    /** Flows waiting on the server, by name (an action's, or a loader's): [id, Generator, is a loader]. */
    private array $flows = [];
    private int $flowId = 0;
    /** Each #[Watch] loader's arguments when it last ran, as JSON. */
    private array $watched = [];
    /** Debounced loaders' timers, by name. */
    private array $timers = [];
    /** Set when an action's server calls are done, so every loader runs again for fresh data. */
    private bool $stale = false;
    private ?ErrorBag $errors = null;
    /** The u-model attribute names seen (`u-model`, `u-model.blur`, ...). */
    private array $models = ['u-model'];
    /** The path of a u-model checkbox or radio being clicked: until its change event, renders
     * leave it be (another click handler may render first, with the old state). */
    private ?string $toggling = null;
    /** Uploads in progress, by u-model path: percent done. */
    private array $uploads = [];
    /** u-model.debounce timers, by path: the state isn't written until they fire. */
    private array $typing = [];
    /** #[Computed] results for this render, by name. */
    private array $computed = [];
    /** u-on .once handlers that have run, and u-init expressions that have. */
    private array $done = [];
    /** u-poll intervals, by "ms expression". */
    private array $polls = [];
    /** Event types with a window listener (for u-on's .window and .outside). */
    private array $global = [];
    /** Child components, by key: [the component (null while its file loads), name, props as JSON]. */
    private array $children = [];
    /** This render's @child calls: [name, props], by key. */
    private array $placed = [];
    private int $childCount = 0;
    private bool $unmounted = false;
    /** Child sources fetched, by URL. */
    private static array $sources = [];
    /** The components mounted on the page, for u-navigate to take off when it changes pages. */
    public static array $mounted = [];
    /** On the server (ui-server): renders a child into its parent's first HTML,
     * `fn(string $name, array $props): array{html, state}`. */
    public static ?Closure $prerenderChild = null;

    /**
     * What the page shares with every component on it, as `$shared` in a template: set from an
     * @unscripted script's data-shared (unscripted/ui-server's `share` config), and on the
     * server before it renders, so both render the same thing.
     *
     * Plain data, like state: the user can change any of it, so a #[Server] method must never
     * trust it coming back. It's what the page may see, not what the server believes.
     */
    public static array $shared = [];

    /** Where server calls are posted (over HTTP): set from an @unscripted script's data-endpoint. */
    public static string $endpoint = '/unscripted/call';
    /** Off the page (unscripted/ui-server's tests): makes the server calls instead of the
     * network, `fn(array $calls, Closure $done)`, and records what would have left the page. */
    public static ?Closure $transport = null;
    public static array $dispatched = [];
    public static ?string $redirected = null;

    /** Prints the component's HTML. Runs on every render, so it should only read state. A Blade
     * component's template is its render(). */
    protected function render(): void
    {
        if ($this->view === null) {
            throw new Error(static::class . ' needs a render() method or a Blade template');
        }
        $data = [];
        foreach (new ReflectionObject($this)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic()) {
                $data[$property->name] = $property->getValue($this);
            }
        }
        $data['errors'] ??= $this->errors();
        $data['shared'] ??= self::$shared;
        ($this->view)($data);
    }

    /** The validation messages from the last action's server calls (see Validate). Cleared when
     * an action's call succeeds; add your own with `->add()`. */
    public function errors(): ErrorBag
    {
        return $this->errors ??= new ErrorBag();
    }

    /** Renders into the element `$selector` finds, and keeps it up to date from then on. With
     * `$state` (from prerender(), on the server) the element already shows this component: the
     * state is taken as it was there, the loaders aren't run again, and the DOM is patched
     * rather than replaced. */
    public function mount(string|object $target, ?array $state = null): static
    {
        foreach (new ReflectionObject($this)->getMethods() as $method) {
            if ($method->getAttributes(Server::class) && (string) $method->getReturnType() !== ServerCall::class) {
                throw new Error(($this->file ?? static::class) . " came to the browser with its #[Server] method {$method->name} as written: serve it with unscripted/ui-server, which keeps the method on the server");
            }
        }
        $root = is_object($target) ? $target : U::document()->querySelector($target);
        if ($root === null) {
            throw new Error("there's no element $target to mount " . static::class . ' on');
        }
        $this->root = $root;
        if ($state !== null) {
            $this->adopt($state);
        }
        parse_str(ltrim((string) U::window()->location->search, '?'), $query);
        if ($this->fromQuery($query) && $state !== null) {
            $this->adopt([]); // the server rendered with the same query: its loaders still count
        }
        $this->listen('input', []);
        $this->listen('change', []);
        $this->listen('click', []);
        // From JavaScript: root.dispatchEvent(new CustomEvent('unscripted:call', {detail: {action, args}})).
        $root->addEventListener('unscripted:call', function ($event) {
            $detail = json_decode((string) U::window()->JSON->stringify($event->detail ?? null), true) ?? [];
            if ($this->unmounted || !is_string($detail['action'] ?? null)) {
                return;
            }
            $this->call($detail['action'] . '(' . substr(json_encode(array_values($detail['args'] ?? [])), 1, -1) . ')');
            $this->refresh();
        });
        self::$mounted[] = $this;
        $this->refresh();
        $this->live();
        $this->events();
        foreach (['online', 'offline'] as $type) {
            U::window()->addEventListener($type, fn() => $this->unmounted || $this->refresh()); // for u-offline
        }
        $this->mounted();
        return $this;
    }

    /** Runs when the component leaves the page (a child its parent no longer renders): stop
     * timers you started here. */
    protected function unmounted(): void {}

    /** Takes the component off the page: its timers stop, its children go, it stops listening. */
    public function unmount(): void
    {
        if ($this->unmounted) {
            return;
        }
        foreach ([...$this->polls, ...$this->typing, ...$this->timers] as $handle) {
            U::clearTimeout($handle);
        }
        $this->polls = $this->typing = $this->timers = $this->flows = [];
        foreach ($this->children as [$child]) {
            $child?->unmount();
        }
        $this->children = [];
        $this->unmounted = true;
        self::$mounted = array_values(array_filter(self::$mounted, fn($c) => $c !== $this));
        $this->unmounted();
    }

    /** Whether it's on the page, under `$element`. */
    public function within(object $element): bool
    {
        return !$this->unmounted && $this->root !== null && $element->contains($this->root);
    }

    /** Sets public properties from `$props`, as a parent passes them to a child. */
    public function with(array $props): static
    {
        foreach ($props as $name => $value) {
            if (!property_exists($this, $name) || !new ReflectionProperty($this, $name)->isPublic()) {
                throw new Error(static::class . " has no public property \$$name to pass");
            }
            $this->assign($name, $value);
        }
        return $this;
    }

    /**
     * A child component in the template: `@child('todo-item', ['todo' => $todo], $todo['id'])`.
     * `$props` set its public properties, again whenever they change; the key tells children
     * in a list apart. Its file is fetched from beside this one's; on the server (ui-server)
     * it's rendered here, state and all, so the page arrives whole.
     */
    public function child(string $name, array $props = [], string|int|null $key = null): string
    {
        if (!preg_match('/^[\w-]+$/', $name)) {
            throw new Error("@child('$name'): a component's name is letters, digits, - and _");
        }
        $key = $key === null ? "$name#" . $this->childCount++ : "$name:$key";
        $this->placed[$key] = [$name, $props];
        $attributes = 'u-child="' . e($name) . '" u-key="' . e("child:$key") . '" u-ignore';
        $inner = '';
        if (self::$prerenderChild !== null && $this->root === null) {
            ['html' => $inner, 'state' => $state] = (self::$prerenderChild)($name, $props);
            $attributes .= ' u-state="' . e(json_encode($state, JSON_THROW_ON_ERROR)) . '"';
        }
        return "<div $attributes>$inner</div>";
    }

    /** Whether `$element` is this component's, not inside a child component's root. */
    private function owns(object $element): bool
    {
        $boundary = $element->closest('[u-child]');
        return $boundary === null || $boundary->isSameNode($this->root) || !$this->root->contains($boundary);
    }

    /** After a render: mounts new children, passes changed props down, unmounts gone ones. */
    private function children(): void
    {
        foreach ($this->placed as $key => [$name, $props]) {
            $element = $this->root->querySelector('[u-key="' . addcslashes("child:$key", '"\\') . '"]');
            if ($element === null) {
                continue;
            }
            $json = json_encode($props);
            if (isset($this->children[$key])) {
                [$child, , $was] = $this->children[$key];
                if ($child !== null && $was !== $json) {
                    $this->children[$key][2] = $json;
                    $child->with($props)->refresh();
                }
                continue;
            }
            $this->children[$key] = [null, $name, $json];
            $state = $element->getAttribute('u-state');
            $this->childSource($name, function (string $source, string $file) use ($key, $element, $props, $state) {
                if (!isset($this->children[$key]) || $this->unmounted) {
                    return; // gone while it loaded
                }
                $child = blade($source, $file)->with($props);
                $this->children[$key][0] = $child;
                $child->mount($element, $state === null ? null : json_decode($state, true));
            });
        }
        foreach (array_diff_key($this->children, $this->placed) as $key => [$child]) {
            $child?->unmount();
            unset($this->children[$key]);
        }
    }

    /** Child component `$name`'s source, from beside this one's file (fetched once a page). */
    private function childSource(string $name, Closure $then): void
    {
        $url = preg_replace('~[^/]*$~', '', $this->file ?? '') . "$name.blade.php";
        if (isset(self::$sources[$url])) {
            $then(self::$sources[$url], $url);
            return;
        }
        U::fetch($url)->then(function ($response) use ($url, $then) {
            if (!$response->ok) {
                throw new Error("couldn't load the child component $url: {$response->status}");
            }
            $response->text()->then(function (string $source) use ($url, $then) {
                self::$sources[$url] = $source;
                $then($source, $url);
            });
        });
    }

    /** Runs once the component is on the page, after its first render: start timers here. */
    protected function mounted(): void {}

    /** Before each render, and after (once the DOM shows it). */
    protected function rendering(): void {}
    protected function rendered(): void {}

    /** A server call failed and the action didn't catch it (a validation failure aside, which
     * the error bag shows): by default it's thrown, to the console. Override to show it. */
    protected function failed(ServerError $e): void
    {
        throw $e;
    }

    /** Checks the fields the #[Server] methods' #[Validate] rules name (by `as` or parameter
     * name) that are state here, with the rules the browser knows: fills the error bag, and
     * says whether they all passed. `$only` limits it to those fields. Before a call, it saves
     * a trip: `if (!$this->validate()) return;`. */
    protected function validate(?array $only = null): bool
    {
        $passed = true;
        foreach ($this->rules() as $field => [$rules, $attribute]) {
            if ($only !== null && !in_array($field, $only, true)) {
                continue;
            }
            try {
                $value = $this->readPath($field);
            } catch (Error) {
                continue; // not a state path here
            }
            $message = Rules::check($value, $rules, $attribute);
            $this->errors()->set($field, $message === null ? [] : [$message]);
            $passed = $passed && $message === null;
        }
        return $passed;
    }

    /** The #[Validate] rules on this component's #[Server] methods' parameters: [rules, the
     * name messages use], by field. */
    private function rules(): array
    {
        $found = [];
        foreach (new ReflectionObject($this)->getMethods(ReflectionMethod::IS_STATIC) as $method) {
            if (!$method->getAttributes(Server::class)) {
                continue;
            }
            foreach ($method->getParameters() as $parameter) {
                foreach ($parameter->getAttributes(Validate::class) as $validate) {
                    $validate = $validate->newInstance();
                    $key = $validate->as ?? $parameter->name;
                    if ($validate->byField()) {
                        foreach ($validate->rules as $field => $rules) {
                            $found["$key.$field"] = [$rules, str_replace('_', ' ', (string) $field)];
                        }
                    } elseif (!$parameter->isVariadic()) {
                        $found[$key] = [$validate->rules, str_replace('_', ' ', $parameter->name)];
                    }
                }
            }
        }
        return $found;
    }

    /** Leaves the page for `$url`. */
    protected function redirect(string $url): void
    {
        if (!class_exists(\Unscripted::class, false)) {
            self::$redirected = $url; // off the page
            return;
        }
        U::window()->location->assign($url);
    }

    /** Tells the page's components (#[On('event')]) and its JavaScript (a CustomEvent on
     * window) that `$event` happened, with `$detail`. */
    public function dispatch(string $event, mixed $detail = null): void
    {
        if (!class_exists(\Unscripted::class, false)) {
            self::$dispatched[] = [$event, $detail]; // off the page
            return;
        }
        U::window()->dispatchEvent(U::window()->CustomEvent($event, ['detail' => $detail]));
    }

    /** #[Computed] methods, read as properties: `$this->total`. */
    public function __get(string $name): mixed
    {
        if (!method_exists($this, $name) || !new ReflectionMethod($this, $name)->getAttributes(Computed::class)) {
            throw new Error('Undefined property: ' . static::class . "::\$$name");
        }
        if (!array_key_exists($name, $this->computed)) {
            $this->computed[$name] = new ReflectionMethod($this, $name)->invoke($this);
        }
        return $this->computed[$name];
    }

    /** Each #[On] method listens for its event on window. */
    private function events(): void
    {
        foreach (new ReflectionObject($this)->getMethods() as $method) {
            foreach ($method->getAttributes(On::class) as $on) {
                U::window()->addEventListener($on->newInstance()->event, function ($event) use ($method) {
                    if ($this->unmounted) {
                        return;
                    }
                    // The detail as PHP values, whoever sent it.
                    $detail = json_decode((string) U::window()->JSON->stringify($event->detail ?? null), true);
                    $this->computed = [];
                    $this->invoke($method, $method->getNumberOfParameters() > 0 ? [$detail] : []);
                    $this->refresh();
                });
            }
        }
    }

    /** Each #[Live] loader listens for its key's changes on the page's socket, and runs again;
     * each #[Live] browser method, for what's sent with its key. */
    private function live(): void
    {
        $loaders = $this->loaders();
        foreach ($loaders as $name => [$method]) {
            $live = $method->getAttributes(Live::class)[0] ?? null;
            if ($live === null) {
                continue;
            }
            // A change, or null: the socket's back after missing some. Not something sent.
            $reload = function (?array $message = null) use ($name) {
                if ($this->unmounted || array_key_exists('data', $message ?? [])) {
                    return;
                }
                unset($this->watched[$name]); // so load() runs it now, debounce or not
                $this->refresh();
            };
            $live = $live->newInstance();
            if (Socket::$page !== null) {
                Socket::$page->listen("unscripted-live.$live->key", $reload);
            } else {
                U::setInterval($reload, $live->poll); // no socket (the 'http' transport): ask
            }
        }
        foreach (new ReflectionObject($this)->getMethods() as $method) {
            $live = $method->getAttributes(Live::class)[0] ?? null;
            if ($live === null || isset($loaders[$method->name])) {
                continue;
            }
            $key = $live->newInstance()->key;
            if (Socket::$page === null) {
                U::console()->warn("#[Live('$key')] on {$method->name}() needs ui-server's 'reverb' transport: nothing will arrive");
                continue;
            }
            Socket::$page->listen("unscripted-live.$key", function (?array $message = null) use ($method) {
                if ($this->unmounted || !array_key_exists('data', $message ?? [])) {
                    return;
                }
                $this->computed = [];
                $this->invoke($method, $method->getNumberOfParameters() > 0 ? [$message['data']] : []);
                $this->refresh();
            });
        }
    }

    /**
     * The first render, on the server (unscripted/ui-server): each #[Watch] loader is run with
     * `$call` (its name and arguments; it returns the result), then the template. Returns the
     * HTML and the state to send with it, for mount() to pick up from.
     */
    public function prerender(Closure $call, array $query = []): array
    {
        $this->fromQuery($query);
        foreach ($this->loaders() as $name => [, $properties]) {
            $this->assign($name, $call($name, array_map(fn($property) => $this->{$property}, $properties)));
        }
        $state = [];
        foreach (new ReflectionObject($this)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic() && !$property->isReadOnly() && $property->isInitialized($this)) {
                $state[$property->name] = $property->getValue($this);
            }
        }
        return ['html' => $this->withModels($this->html()), 'state' => $state];
    }

    /** On the server: `$html` with each u-model element showing its state (value, checked,
     * selected, a textarea's text), so the page reads right before the browser takes over. */
    private function withModels(string $html): string
    {
        if (!class_exists(\Dom\HTMLDocument::class) || !preg_match('/\su-model[\s.=]/i', $html)) {
            return $html;
        }
        $document = \Dom\HTMLDocument::createFromString("<!doctype html><body>$html", LIBXML_NOERROR);
        foreach ($document->body->querySelectorAll('*') as $element) {
            $path = null;
            foreach ($element->attributes as $attribute) {
                if (preg_match('/^u-model(\.|$)/', $attribute->name)) {
                    $path = $attribute->value;
                    break;
                }
            }
            if ($path === null || $element->parentElement?->closest('[u-child]') !== null) {
                continue; // a child's: it filled in its own
            }
            try {
                $value = $this->readPath($path);
            } catch (Error) {
                continue;
            }
            $type = strtolower($element->getAttribute('type') ?? '');
            match (true) {
                $element->localName === 'textarea' => $element->textContent = (string) $value,
                $element->localName === 'select' => array_map(
                    fn($option) => in_array($option->getAttribute('value') ?? $option->textContent, array_map('strval', (array) $value), true)
                        ? $option->setAttribute('selected', '') : $option->removeAttribute('selected'),
                    iterator_to_array($element->querySelectorAll('option')),
                ),
                $type === 'checkbox' => $value ? $element->setAttribute('checked', '') : $element->removeAttribute('checked'),
                $type === 'radio' => $element->getAttribute('value') === (string) $value ? $element->setAttribute('checked', '') : $element->removeAttribute('checked'),
                $type === 'file' => null,
                default => $element->setAttribute('value', is_array($value) ? '' : (string) $value),
            };
        }
        return $document->body->innerHTML;
    }

    /** prerender()'s state, put back, and each loader marked as run with it. */
    private function adopt(array $state): void
    {
        foreach ($state as $name => $value) {
            if (property_exists($this, $name)) {
                $this->assign($name, $value);
            }
        }
        foreach ($this->loaders() as $name => [, $properties]) {
            $this->watched[$name] = json_encode(array_map(fn($property) => $this->{$property}, $properties));
        }
    }

    /** Renders again. Actions and u-model changes do this already; call it after changing
     * state anywhere else (a timer, a fetch). */
    public function refresh(): void
    {
        if ($this->unmounted) {
            return;
        }
        $this->load();
        if ($this->root === null) {
            return; // not on a page (unscripted/ui-server's tests drive it without one)
        }
        $this->rendering();
        $html = $this->html();

        // Patch the DOM where the host can (@mtjncn/runtime's morph), so nodes that didn't
        // change are kept, focus and scroll with them. Elsewhere, replace it, then put back the
        // focus and caret of a bound input at least.
        self::$morph ??= U::window()->unscriptedMorph !== null;
        if (self::$morph) {
            U::window()->unscriptedMorph($this->root, $html);
        } else {
            $focus = $this->focused();
            $this->root->innerHTML = $html;
        }

        // Listen for each event type the HTML uses, once per type: on the root, or on window
        // for .window and .outside.
        preg_match_all('/\su-on:([a-z]+)((?:\.[a-z0-9-]+)*)\s*=/i', $html, $found, PREG_SET_ORDER);
        $seen = [];
        foreach ($found as [, $type, $modifiers]) {
            $type = strtolower($type);
            $name = strtolower("u-on:$type$modifiers");
            if (preg_match('/\.(window|outside)(\.|$)/', $name)) {
                $this->listenGlobally($type, $name);
            } else {
                $seen[$type][] = $name;
            }
        }
        foreach ($seen as $type => $names) {
            $this->listen($type, $names);
        }
        preg_match_all('/\s(u-model(?:\.[a-z0-9-]+)*)\s*=/i', $html, $found);
        $this->models = array_values(array_unique([...$this->models, ...array_map('strtolower', $found[1])]));

        $this->showModels();
        if (!self::$morph) {
            $this->restore($focus);
        }
        $this->children();
        $this->attributes($html);
        $this->toUrl();
        $this->rendered();
    }

    /** The #[Url] properties: [property, its key in the query], by name. */
    private function urlProperties(): array
    {
        $found = [];
        foreach (new ReflectionObject($this)->getProperties() as $property) {
            $url = $property->getAttributes(Url::class)[0] ?? null;
            if ($url !== null) {
                $found[$property->name] = [$property, $url->newInstance()->as ?? $property->name];
            }
        }
        return $found;
    }

    /** #[Url] properties from `$query`: whether any was there. */
    private function fromQuery(array $query): bool
    {
        $any = false;
        foreach ($this->urlProperties() as $name => [$property, $key]) {
            if (!array_key_exists($key, $query)) {
                continue;
            }
            $value = $query[$key];
            $type = $property->getType()?->getName();
            $this->assign($name, $type === 'array' ? (array) $value : $this->coerce(is_array($value) ? '' : $value, $type));
            $any = true;
        }
        return $any;
    }

    /** #[Url] properties into the address bar, if they changed it. */
    private function toUrl(): void
    {
        $properties = $this->urlProperties();
        if ($properties === []) {
            return;
        }
        $location = U::window()->location;
        parse_str(ltrim((string) $location->search, '?'), $query);
        foreach ($properties as $name => [$property, $key]) {
            $value = $this->{$name};
            if ($property->hasDefaultValue() && $value === $property->getDefaultValue()) {
                unset($query[$key]);
            } else {
                $query[$key] = is_bool($value) ? (int) $value : $value;
            }
        }
        $search = $query === [] ? '' : '?' . http_build_query($query);
        if ($search !== (string) $location->search) {
            U::window()->history->replaceState(null, '', $location->pathname . $search . $location->hash);
            Navigator::settled();
        }
    }

    /** u-loading, u-offline, u-poll and u-init, on the HTML just rendered. */
    private function attributes(string $html): void
    {
        foreach ($this->root->querySelectorAll('[u-loading], [u-loading\\.remove]') as $element) {
            if (!$this->owns($element)) {
                continue;
            }
            $remove = $element->hasAttribute('u-loading.remove');
            $target = $element->getAttribute($remove ? 'u-loading.remove' : 'u-loading');
            $loading = $this->loading($target === '' ? null : $target);
            $element->hidden = $remove ? $loading : !$loading;
        }
        $online = (bool) U::window()->navigator->onLine;
        foreach ($this->root->querySelectorAll('[u-offline]') as $element) {
            $element->hidden = $this->owns($element) ? $online : $element->hidden;
        }

        // u-poll.5s="action": an interval each, kept while the HTML has it.
        preg_match_all('/\su-poll((?:\.[a-z0-9]+)*)\s*=\s*"([^"]*)"/i', $html, $found, PREG_SET_ORDER);
        $polls = [];
        foreach ($found as [, $modifier, $expression]) {
            $ms = preg_match('/\.(\d+)(ms|s)?/', $modifier, $m) ? (int) $m[1] * (($m[2] ?? 'ms') === 's' ? 1000 : 1) : 2000;
            $expression = html_entity_decode($expression, ENT_QUOTES | ENT_HTML5);
            $polls["$ms $expression"] = [$ms, $expression];
        }
        foreach (array_diff_key($this->polls, $polls) as $key => $handle) {
            U::clearTimeout($handle);
            unset($this->polls[$key]);
        }
        foreach (array_diff_key($polls, $this->polls) as $key => [$ms, $expression]) {
            $this->polls[$key] = U::setInterval(function () use ($expression) {
                $this->call($expression);
                $this->refresh();
            }, $ms);
        }

        // u-init="action": once for each expression, just after the render that shows it.
        foreach ($this->root->querySelectorAll('[u-init]') as $element) {
            if (!$this->owns($element)) {
                continue;
            }
            $expression = $element->getAttribute('u-init');
            if (!isset($this->done["init $expression"])) {
                $this->done["init $expression"] = true;
                U::setTimeout(function () use ($expression) {
                    $this->call($expression);
                    $this->refresh();
                }, 0);
            }
        }
    }

    private function html(): string
    {
        $this->computed = [];
        $this->placed = [];
        $this->childCount = 0;
        ob_start();
        try {
            $this->render();
        } catch (Throwable $e) {
            throw $this->located($e);
        } finally {
            $html = ob_get_clean();
        }
        return $html;
    }

    private function listen(string $type, array $names): void
    {
        if (!isset($this->listening[$type])) {
            $this->listening[$type] = [];
            $this->root->addEventListener($type, fn($event) => $this->handle($type, $event));
        }
        $this->listening[$type] = array_values(array_unique([...$this->listening[$type], ...$names]));
    }

    /** For u-on's .window and .outside: one window listener per event type. */
    private function listenGlobally(string $type, string $name): void
    {
        if (!isset($this->global[$type])) {
            $this->global[$type] = [];
            U::window()->addEventListener($type, function ($event) use ($type) {
                if ($this->unmounted) {
                    return;
                }
                $changed = false;
                foreach ($this->global[$type] as $name) {
                    $selector = '[' . addcslashes($name, ':.') . ']';
                    foreach ($this->root->querySelectorAll($selector) as $element) {
                        if (!$this->owns($element)) {
                            continue;
                        }
                        if (str_contains($name, '.outside') && $element->contains($event->target)) {
                            continue;
                        }
                        $changed = $this->fire($name, $element, $event) || $changed;
                    }
                }
                if ($changed) {
                    $this->refresh();
                }
            });
        }
        $this->global[$type] = array_values(array_unique([...$this->global[$type], $name]));
    }

    private function handle(string $type, object $event): void
    {
        if ($type === 'click' && in_array($event->target->type, ['checkbox', 'radio'], true)
            && ($model = $this->modelOf($event->target)) !== null && $this->owns($event->target)) {
            $this->toggling = $model[1];
            U::setTimeout(fn() => $this->toggling = null, 0); // its change comes in this same task
        }
        $changed = false;
        if ($type === 'input' || $type === 'change') {
            $changed = $this->model($event->target, $type);
        }

        $names = $this->listening[$type];
        if ($names) {
            $selector = implode(', ', array_map(fn($name) => '[' . addcslashes($name, ':.') . ']', $names));
            $element = $event->target->closest($selector);
            if ($element !== null && $this->root->contains($element) && $this->owns($element)) {
                // Each of its attributes for this event (keydown.enter and keydown.escape, say).
                foreach ($names as $name) {
                    if ($element->hasAttribute($name)) {
                        $changed = $this->fire($name, $element, $event) || $changed;
                    }
                }
            }
        }

        if ($changed) {
            $this->refresh();
        }
    }

    /** Runs `$element`'s u-on attribute `$name` for `$event`, if its modifiers let it: whether it
     * did. */
    private function fire(string $name, object $element, object $event): bool
    {
        $modifiers = array_slice(explode('.', $name), 1);
        foreach ($modifiers as $modifier) {
            $allowed = match ($modifier) {
                'prevent', 'stop', 'once', 'window', 'outside' => true,
                'self' => $event->target === $element || $event->target->isSameNode($element),
                'ctrl', 'shift', 'alt', 'meta' => (bool) $event->{"{$modifier}Key"},
                default => $this->key($modifier, $name, $event),
            };
            if (!$allowed) {
                return false;
            }
        }
        $expression = $element->getAttribute($name);
        if (in_array('once', $modifiers, true)) {
            if (isset($this->done["$name $expression"])) {
                return false;
            }
            $this->done["$name $expression"] = true;
        }
        if (in_array('prevent', $modifiers, true)) {
            $event->preventDefault();
        }
        if (in_array('stop', $modifiers, true)) {
            $event->stopPropagation();
        }
        $confirm = $element->getAttribute('u-confirm');
        if ($confirm !== null && !U::window()->confirm($confirm)) {
            return false;
        }
        $this->call($expression);
        return true;
    }

    /** A key modifier (.enter, .escape, .a, ...): whether `$event` is that key. */
    private function key(string $modifier, string $name, object $event): bool
    {
        $keys = [
            'enter' => 'Enter', 'escape' => 'Escape', 'esc' => 'Escape', 'space' => ' ', 'tab' => 'Tab',
            'up' => 'ArrowUp', 'down' => 'ArrowDown', 'left' => 'ArrowLeft', 'right' => 'ArrowRight',
            'delete' => 'Delete', 'backspace' => 'Backspace', 'home' => 'Home', 'end' => 'End',
        ];
        if (!isset($keys[$modifier]) && strlen($modifier) !== 1) {
            throw new Error("u-on doesn't know the modifier .$modifier (in $name)");
        }
        $key = $event->key;
        return $key !== null && strtolower((string) $key) === strtolower($keys[$modifier] ?? $modifier);
    }

    /** `name` or `name(args)`, the arguments as JSON literals. */
    private function call(string $expression): void
    {
        if (!preg_match('/^\s*([A-Za-z_]\w*)\s*(?:\((.*)\))?\s*$/s', $expression, $m)) {
            throw new Error("u-on=\"$expression\" isn't an action call; write name or name(args)");
        }
        $name = $m[1];
        $args = trim($m[2] ?? '') === '' ? [] : json_decode("[$m[2]]", true, 512, JSON_THROW_ON_ERROR);

        // Actions are the component's own public methods: not Component's API, not static, not
        // magic. (Component's private helpers don't count: a component may use their names.)
        if (!method_exists($this, $name) || str_starts_with($name, '__')
            || (method_exists(self::class, $name) && !new ReflectionMethod(self::class, $name)->isPrivate())) {
            throw new Error("$name isn't an action of " . static::class);
        }
        $method = new ReflectionMethod($this, $name);
        if ($method->isPublic() && $method->isStatic() && $method->getAttributes(Server::class)) {
            // A server method called straight from the page: what it returns isn't kept, but
            // the loaders run again once it's done.
            $call = $method->invokeArgs(null, $args);
            $this->run($name, (function () use ($call) {
                yield $call;
            })());
            return;
        }
        if (!$method->isPublic() || $method->isStatic()) {
            throw new Error("$name isn't an action of " . static::class . ': actions are public, non-static methods');
        }
        $this->invoke($method, $args);
    }

    /** Calls method `$method` with `$args`, running it as a flow if it's a generator. */
    private function invoke(ReflectionMethod $method, array $args): void
    {
        $this->computed = []; // what the action reads is now, not the last render's
        try {
            $result = $method->invokeArgs($this, $args);
        } catch (Throwable $e) {
            throw $this->located($e);
        }
        if ($result instanceof Generator) {
            $this->run($method->name, $result);
        }
    }

    /** Whether the flow `$name` (an action or a loader) is waiting on the server; with no name,
     * whether any is. */
    public function loading(?string $name = null): bool
    {
        return $name === null
            ? $this->flows !== [] || $this->timers !== [] || $this->uploads !== []
            : isset($this->flows[$name]) || isset($this->timers[$name]) || isset($this->uploads[$name]);
    }

    /** Starts each #[Watch] loader whose properties changed since it last ran: all of them after
     * an action's server calls. */
    private function load(): void
    {
        $stale = $this->stale;
        $this->stale = false;
        foreach ($this->loaders() as $name => [$method, $properties, $debounce]) {
            $args = array_map(fn($property) => $this->{$property}, $properties);
            $key = json_encode($args);
            if (!$stale && ($this->watched[$name] ?? null) === $key) {
                continue;
            }
            $first = !isset($this->watched[$name]);
            $this->watched[$name] = $key;
            if (isset($this->timers[$name])) {
                U::clearTimeout($this->timers[$name]);
                unset($this->timers[$name]);
            }

            // Off the page (in tests) there are no timers: it runs now.
            if ($debounce > 0 && !$first && !$stale && class_exists(\Unscripted::class, false)) {
                // Wait for a pause. What's in flight is dropped, so its older result can't land
                // in the meantime.
                unset($this->flows[$name]);
                $this->timers[$name] = U::setTimeout(function () use ($name, $method, $args) {
                    unset($this->timers[$name]);
                    $this->fill($name, $method, $args);
                }, $debounce);
                continue;
            }
            $this->fill($name, $method, $args);
        }
    }

    /** The #[Watch] loaders: [method, the properties it watches, debounce], by name. */
    private function loaders(): array
    {
        $loaders = [];
        foreach (new ReflectionObject($this)->getMethods(ReflectionMethod::IS_STATIC) as $method) {
            $watch = $method->getAttributes(Watch::class)[0] ?? null;
            if ($watch === null) {
                continue;
            }
            if (!property_exists($this, $method->name)) {
                throw new Error("the #[Watch] method {$method->name} needs a property \${$method->name} to load into");
            }
            $watch = $watch->newInstance();
            $loaders[$method->name] = [$method, $watch->properties, $watch->debounce];
        }
        return $loaders;
    }

    /** Runs loader `$name`: its server call, the result into the property of its name. */
    private function fill(string $name, ReflectionMethod $method, array $args): void
    {
        $call = $method->invokeArgs(null, $args);
        $this->run($name, (function () use ($name, $call) {
            $this->assign($name, yield $call);
        })(), loader: true);
    }

    /** Sets property `$name`, private(set) or not: a loader's, which the page can't write. */
    private function assign(string $name, mixed $value): void
    {
        new ReflectionProperty($this, $name)->setValue($this, $value);
    }

    /** Runs a flow: each server call it yields is sent, and the result sent back in (or the
     * failure thrown in, a ServerError), rendering after each step. A flow started with the name
     * of one still waiting replaces it, so an old result can't land after a newer one. */
    private function run(string $name, Generator $flow, bool $loader = false): void
    {
        $this->flows[$name] = [++$this->flowId, $flow, $loader];
        $this->advance($name, fn() => $flow->current());
    }

    /** Resumes `$name`'s flow with `$resume`, then sends the server calls it yields next. */
    private function advance(string $name, Closure $resume): void
    {
        [$id, $flow, $loader] = $this->flows[$name];
        try {
            $resume();
            $yielded = $flow->valid() ? $flow->current() : null;
        } catch (Throwable $e) {
            unset($this->flows[$name]);
            if (!$loader && $e instanceof ServerError && $e->status === 422 && $e->errors !== []) {
                return; // a validation failure the action didn't catch: the error bag shows it
            }
            if ($e instanceof ServerError) {
                $this->failed($e);
                return;
            }
            throw $this->located($e);
        }
        if (!$flow->valid()) {
            unset($this->flows[$name]);
            return;
        }

        $calls = $yielded instanceof ServerCall ? [$yielded] : $yielded;
        if (!is_array($calls) || $calls === [] || array_filter($calls, fn($call) => !$call instanceof ServerCall)) {
            unset($this->flows[$name]);
            throw new Error("$name yielded something that isn't a server call: yield static::method(...), or an array of them");
        }
        $this->send(array_values($calls), function (?array $results, ?ServerError $error) use ($name, $id, $loader, $yielded) {
            if (($this->flows[$name][0] ?? null) !== $id) {
                return; // replaced by a newer flow
            }
            try {
                $this->land($name, $loader, $yielded, $results, $error);
            } finally {
                if (!$loader && !isset($this->flows[$name])) {
                    $this->stale = true;
                }
                $this->refresh();
            }
        });
    }

    /** A server reply for `$name`'s flow: an action's replaces the error bag (a 422's messages, or
     * none on success), then the flow goes on with the results, or the error thrown in. */
    private function land(string $name, bool $loader, mixed $yielded, ?array $results, ?ServerError $error): void
    {
        if (!$loader && ($error === null || $error->status === 422)) {
            $this->errors = new ErrorBag($error?->errors ?? []);
        }
        $flow = $this->flows[$name][1];
        $this->advance($name, fn() => $error !== null ? $flow->throw($error) : $flow->send(
            $yielded instanceof ServerCall ? $results[0] : array_combine(array_keys($yielded), $results),
        ));
    }

    /** Sends the calls to the server together, over the page's socket if it has one, else in
     * one POST; `$done` gets their results, or the error. */
    private function send(array $calls, Closure $done): void
    {
        $streams = array_filter($calls, fn(ServerCall $call) => $call->into !== null);
        if ($streams !== [] && count($calls) > 1) {
            throw new Error("a streaming call (->into()) goes on its own: yield it alone, not in an array");
        }
        if ($streams !== [] && self::$transport === null && Socket::$page === null) {
            $this->stream($calls[0], $done);
            return;
        }
        // A streaming method called without a stream (the socket, tests, no ->into()) comes back
        // run through: its text (for ->into()'s property) and its result.
        $original = $calls;
        $done = function (?array $results, ?ServerError $error) use ($original, $done) {
            foreach ($results ?? [] as $i => $result) {
                if (is_array($result) && array_key_exists('unscripted:stream', $result)) {
                    $into = $original[$i]->into;
                    if ($into !== null) {
                        $this->assign($into, ($this->{$into} ?? '') . $result['unscripted:stream']['text']);
                    }
                    $results[$i] = $result['unscripted:stream']['result'];
                }
            }
            $done($results, $error);
        };
        $calls = array_map(fn(ServerCall $call) => [
            'component' => $call->component,
            'method' => $call->method,
            'args' => $call->args,
        ], $calls);
        if (self::$transport !== null) {
            (self::$transport)($calls, $done);
            return;
        }
        if (Socket::$page !== null) {
            Socket::$page->send($calls, $done);
            return;
        }
        $body = json_encode(['calls' => $calls], JSON_THROW_ON_ERROR);
        U::fetch(self::$endpoint, ['method' => 'POST', 'headers' => headers(), 'body' => $body])->then(
            function ($response) use ($done) {
                $ok = $response->ok;
                $status = $response->status;
                // Nested, not chained: a PHP callback's return value doesn't reach the next then().
                $response->text()->then(function (string $text) use ($done, $ok, $status) {
                    $reply = json_decode($text, true);
                    if ($ok && is_array($reply['results'] ?? null)) {
                        $done($reply['results'], null);
                    } else {
                        $done(null, new ServerError($reply['message'] ?? "the server answered $status", $status, $reply['errors'] ?? []));
                    }
                });
            },
            fn($error) => $done(null, new ServerError("couldn't reach the server: {$error->message}")),
        );
    }

    /** A streaming call over HTTP: each JSON line is a chunk (appended to the call's property,
     * then a render), the result, or an error. */
    private function stream(ServerCall $call, Closure $done): void
    {
        $body = json_encode(['calls' => [['component' => $call->component, 'method' => $call->method, 'args' => $call->args]], 'stream' => true], JSON_THROW_ON_ERROR);
        $headers = [...headers(), 'Accept' => 'application/x-ndjson'];
        U::fetch(self::$endpoint, ['method' => 'POST', 'headers' => $headers, 'body' => $body])->then(function ($response) use ($call, $done) {
            $status = $response->status;
            if (!$response->ok) {
                $response->text()->then(function (string $text) use ($done, $status) {
                    $reply = json_decode($text, true);
                    $done(null, new ServerError($reply['message'] ?? "the server answered $status", $status, $reply['errors'] ?? []));
                });
                return;
            }
            $reader = $response->body->getReader();
            $decoder = U::window()->TextDecoder();
            $buffer = '';
            $finished = false;
            $read = function () use (&$read, &$buffer, &$finished, $reader, $decoder, $call, $done) {
                $reader->read()->then(function ($part) use (&$read, &$buffer, &$finished, $decoder, $call, $done) {
                    if ($part->done) {
                        if (!$finished) {
                            $done(null, new ServerError('the stream ended without a result'));
                        }
                        return;
                    }
                    $buffer .= $decoder->decode($part->value, ['stream' => true]);
                    while (!$finished && ($end = strpos($buffer, "\n")) !== false) {
                        $line = json_decode(substr($buffer, 0, $end), true) ?? [];
                        $buffer = substr($buffer, $end + 1);
                        if (array_key_exists('chunk', $line)) {
                            $this->assign($call->into, ($this->{$call->into} ?? '') . $line['chunk']);
                            $this->refresh();
                        } elseif (array_key_exists('result', $line)) {
                            $finished = true;
                            $done([$line['result']], null);
                        } elseif (isset($line['error'])) {
                            $finished = true;
                            $done(null, new ServerError($line['error']['message'] ?? 'the server failed', $line['error']['status'] ?? 500, $line['error']['errors'] ?? []));
                        }
                    }
                    if (!$finished) {
                        $read();
                    }
                }, fn($error) => $done(null, new ServerError("the stream broke: {$error->message}")));
            };
            $read();
        }, fn($error) => $done(null, new ServerError("couldn't reach the server: {$error->message}")));
    }

    /** A Blade component's code runs from eval(), which PHP reports as "eval()'d code": name
     * the file instead (the line numbers are the file's). */
    private function located(Throwable $e): Throwable
    {
        if ($this->file === null || !str_contains($e->getFile(), "eval()'d code")) {
            return $e;
        }
        return new Error("{$e->getMessage()} in {$this->file}:{$e->getLine()}", 0, $e);
    }

    /** `$element`'s u-model attribute: [its name, the path, its modifiers], or null. */
    private function modelOf(object $element): ?array
    {
        foreach ($this->models as $name) {
            $path = $element->getAttribute($name);
            if ($path !== null) {
                return [$name, $path, array_slice(explode('.', $name), 1)];
            }
        }
        return null;
    }

    /**
     * An input or change event from a u-model element: its value into state, unless the
     * modifiers say to wait. False if nothing changed yet. Checkboxes, radios and selects are
     * read on change (once per click), text on input, or on change with .blur.
     */
    private function model(object $element, string $type): bool
    {
        $model = $this->modelOf($element);
        if ($model === null || !$this->root->contains($element) || !$this->owns($element)) {
            return false;
        }
        [, $path, $modifiers] = $model;
        if ($path === $this->toggling) {
            $this->toggling = null;
        }
        $changes = in_array($element->type, ['checkbox', 'radio', 'select-one', 'select-multiple', 'file'], true)
            || in_array('blur', $modifiers, true);
        if ($type !== ($changes ? 'change' : 'input')) {
            return false;
        }
        if ($element->type === 'file') {
            $this->upload($element, $path);
            return true;
        }
        $debounce = array_search('debounce', $modifiers, true);
        if ($debounce !== false) {
            $ms = preg_match('/^(\d+)(ms|s)?$/', $modifiers[$debounce + 1] ?? '', $m) ? (int) $m[1] * (($m[2] ?? 'ms') === 's' ? 1000 : 1) : 250;
            U::clearTimeout($this->typing[$path] ?? null);
            $this->typing[$path] = U::setTimeout(function () use ($element, $path, $modifiers) {
                unset($this->typing[$path]);
                $this->write($path, $this->valueOf($element, $modifiers));
                $this->refresh();
            }, $ms);
            return false;
        }
        $this->write($path, $this->valueOf($element, $modifiers));
        return true;
    }

    /** How far `$path`'s upload has got, in percent, or null if there's none going. */
    protected function progress(string $path): ?int
    {
        return $this->uploads[$path] ?? null;
    }

    /**
     * A u-model file input's files, each sent to unscripted/ui-server's upload route as it's
     * chosen (with progress). Then `$path` holds what came back, `{id, name, size, type, url}`
     * (a list, for `multiple`), for a #[Server] method to take with `Upload::find()`. A refused
     * file (too big, say) puts the server's message in `$path`'s errors.
     */
    private function upload(object $element, string $path): void
    {
        $files = $element->files;
        $count = (int) $files->length;
        if ($count === 0) {
            return;
        }
        $multiple = (bool) $element->multiple;
        $url = preg_replace('~/call$~', '/upload', self::$endpoint);
        $done = array_fill(0, $count, 0.0);
        $results = [];
        $pending = $count;
        $this->uploads[$path] = 0;
        $this->errors()->clear($path);
        $finish = function () use (&$results, &$pending, $path, $multiple) {
            if (--$pending > 0) {
                return;
            }
            unset($this->uploads[$path]);
            if ($results !== []) {
                ksort($results);
                $this->write($path, $multiple ? array_values($results) : reset($results));
            }
            $this->refresh();
        };
        for ($i = 0; $i < $count; $i++) {
            $xhr = U::window()->XMLHttpRequest();
            $xhr->open('POST', $url);
            foreach (headers() as $name => $value) {
                if ($name !== 'Content-Type') { // FormData sets its own
                    $xhr->setRequestHeader($name, $value);
                }
            }
            $xhr->upload->addEventListener('progress', function ($event) use (&$done, $i, $path) {
                if ($event->lengthComputable && $event->total > 0) {
                    $done[$i] = $event->loaded / $event->total;
                    $this->uploads[$path] = (int) round(100 * array_sum($done) / count($done));
                    $this->refresh();
                }
            });
            $xhr->addEventListener('load', function () use ($xhr, &$results, $i, $path, $finish) {
                $status = (int) $xhr->status;
                $reply = json_decode((string) $xhr->responseText, true) ?? [];
                if ($status === 200 && isset($reply['id'])) {
                    $results[$i] = $reply;
                } else {
                    $this->errors()->add($path, $reply['errors']['file'][0] ?? $reply['message'] ?? "the upload failed ($status)");
                }
                $finish();
            });
            $xhr->addEventListener('error', function () use ($path, $finish) {
                $this->errors()->add($path, "the upload couldn't reach the server");
                $finish();
            });
            $form = U::window()->FormData();
            $form->append('file', $files->item($i));
            $xhr->send($form);
        }
    }

    private function valueOf(object $element, array $modifiers): mixed
    {
        $value = match ($element->type) {
            'checkbox' => (bool) $element->checked,
            'select-multiple' => array_map(fn($option) => (string) $option->value, iterator_to_array($element->querySelectorAll('option:checked'))),
            default => (string) $element->value,
        };
        if (in_array('number', $modifiers, true) && is_string($value)) {
            return is_numeric($value) ? $value + 0 : $value;
        }
        if (in_array('boolean', $modifiers, true) && is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }
        return $value;
    }

    /** Puts `$value` at u-model path `$path`, with the updating/updated hooks around it, and
     * clears the path's validation messages: it's been edited. */
    private function write(string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $property = $this->modelProperty($keys[0], $path);
        $rest = count($keys) > 1 ? implode('.', array_slice($keys, 1)) : null;
        $hook = ucfirst($keys[0]);
        if (method_exists($this, "updating$hook")) {
            $this->{"updating$hook"}($value, $rest);
        }
        $this->put($keys, $property, $value, $path);
        // Edited: the field's errors are now whatever the rules the browser knows say.
        $rules = $this->rules()[$path] ?? null;
        $message = $rules === null ? null : Rules::check($this->readPath($path), $rules[0], $rules[1]);
        $this->errors()->set($path, $message === null ? [] : [$message]);
        if (method_exists($this, "updated$hook")) {
            $this->{"updated$hook"}($this->{$keys[0]}, $rest);
        }
        if (method_exists($this, 'updated')) {
            $this->updated($path, $value);
        }
    }

    private function put(array $keys, ReflectionProperty $property, mixed $value, string $path): void
    {
        if (count($keys) === 1) {
            $this->{$keys[0]} = is_array($value) ? $value : $this->coerce($value, $property->getType()?->getName());
            return;
        }
        // Copy, change, assign: works for hooked properties too, which can't be referenced.
        $data = $this->{$keys[0]};
        $slot = &$data;
        foreach (array_slice($keys, 1) as $key) {
            if (!is_array($slot) || !array_key_exists($key, $slot)) {
                throw new Error("u-model=\"$path\": there's nothing at $key");
            }
            $slot = &$slot[$key];
        }
        // Typed like what was there, unless the modifiers made it a number (or it's a list).
        $slot = is_array($value) || is_int($value) || is_float($value) ? $value : $this->coerce($value, get_debug_type($slot));
        unset($slot);
        $this->{$keys[0]} = $data;
    }

    private function modelProperty(string $name, string $path): ReflectionProperty
    {
        if (!property_exists($this, $name)) {
            throw new Error("u-model=\"$path\": " . static::class . " has no property $name");
        }
        $property = new ReflectionProperty($this, $name);
        if (!$property->getAttributes(State::class) || !$property->isPublic() || $property->isStatic()) {
            throw new Error("u-model=\"$path\": $name isn't a public #[State] property");
        }
        if ($property->isReadOnly() || $property->isPrivateSet() || $property->isProtectedSet()) {
            throw new Error("u-model=\"$path\": $name is read-only to the page");
        }
        return $property;
    }

    private function coerce(string|bool|int|float $value, ?string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => is_bool($value) ? $value : $value !== '',
            'string' => (string) $value,
            default => $value,
        };
    }

    /** Each u-model element shows its state (new HTML has none of what was typed). */
    private function showModels(): void
    {
        $selector = implode(', ', array_map(fn($name) => '[' . addcslashes($name, '.') . ']', $this->models));
        foreach ($this->root->querySelectorAll($selector) as $element) {
            if (!$this->owns($element)) {
                continue;
            }
            [, $path] = $this->modelOf($element);
            if (isset($this->typing[$path]) || $path === $this->toggling) {
                continue; // what's typed or clicked is ahead of the state, and stays
            }
            $value = $this->readPath($path);
            $type = $element->type;
            if ($type === 'file') {
                continue;
            } elseif ($type === 'checkbox') {
                $element->checked = (bool) $value;
            } elseif ($type === 'radio') {
                $element->checked = $element->value === (string) $value;
            } elseif ($type === 'select-multiple') {
                foreach ($element->querySelectorAll('option') as $option) {
                    $option->selected = in_array((string) $option->value, array_map('strval', (array) $value), true);
                }
            } elseif ($element->value !== (string) $value) {
                // Only when it differs: it may be the input being typed in, kept by morph.
                $element->value = (string) $value;
            }
        }
    }

    private function readPath(string $path): mixed
    {
        $keys = explode('.', $path);
        $this->modelProperty($keys[0], $path);
        $value = $this->{$keys[0]};
        foreach (array_slice($keys, 1) as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }
        return $value;
    }

    /** The focused u-model element and its caret, to put back after the HTML is replaced. */
    private function focused(): ?array
    {
        $active = U::document()->activeElement;
        if ($active === null || !$this->root->contains($active)) {
            return null;
        }
        $path = $active->getAttribute('u-model');
        if ($path === null) {
            return null;
        }
        return [$path, $active->selectionStart, $active->selectionEnd];
    }

    private function restore(?array $focus): void
    {
        if ($focus === null) {
            return;
        }
        [$path, $start, $end] = $focus;
        $element = $this->root->querySelector('[u-model="' . addcslashes($path, '"\\') . '"]');
        if ($element === null) {
            return;
        }
        $element->focus();
        if ($start !== null) {
            $element->setSelectionRange($start, $end);
        }
    }
}

/**
 * A PHP promise over the host's: `promise(U::fetch($url))->then(fn($r) => $r->text())->then(...)`.
 * The host drops a PHP callback's return value, so its own promises' then() can't chain; these
 * can. A callback may return a value, a host promise or another Promise: the next then() gets
 * what it settles to. A callback that throws rejects the next. (Until HOST_API 3, when the
 * host keeps return values.)
 */
final class Promise
{
    private string $state = 'pending';
    private mixed $value = null;
    /** [on fulfilled, on rejected], waiting for it to settle. */
    private array $waiting = [];

    public static function from(object $host): self
    {
        $promise = new self();
        $host->then(fn($value) => $promise->settle('fulfilled', $value), fn($reason) => $promise->settle('rejected', $reason));
        return $promise;
    }

    public static function resolve(mixed $value): self
    {
        $promise = new self();
        $promise->adopt($value);
        return $promise;
    }

    public function then(?Closure $fulfilled = null, ?Closure $rejected = null): self
    {
        $next = new self();
        $this->waiting[] = [$fulfilled, $rejected, $next];
        if ($this->state !== 'pending') {
            $this->flush();
        }
        return $next;
    }

    public function catch(Closure $rejected): self
    {
        return $this->then(null, $rejected);
    }

    private function adopt(mixed $value): void
    {
        if ($value instanceof self) {
            $value->then(fn($v) => $this->settle('fulfilled', $v), fn($r) => $this->settle('rejected', $r));
        } elseif (is_object($value) && get_class($value) === 'HostObject' && $value->then !== null) {
            self::from($value)->then(fn($v) => $this->settle('fulfilled', $v), fn($r) => $this->settle('rejected', $r));
        } else {
            $this->settle('fulfilled', $value);
        }
    }

    private function settle(string $state, mixed $value): void
    {
        if ($this->state !== 'pending') {
            return;
        }
        [$this->state, $this->value] = [$state, $value];
        $this->flush();
    }

    private function flush(): void
    {
        $waiting = $this->waiting;
        $this->waiting = [];
        foreach ($waiting as [$fulfilled, $rejected, $next]) {
            $handler = $this->state === 'fulfilled' ? $fulfilled : $rejected;
            if ($handler === null) {
                $next->settle($this->state, $this->value);
                continue;
            }
            try {
                $next->adopt($handler($this->value));
            } catch (Throwable $e) {
                $next->settle('rejected', $e);
            }
        }
    }
}

/** A Promise over a host promise (U::fetch(...), $response->json(), ...). */
function promise(object $host): Promise
{
    return Promise::from($host);
}

/** Headers for a JSON request to the app, with its CSRF token from <meta name="csrf-token">. */
function headers(): array
{
    $token = U::document()->querySelector('meta[name="csrf-token"]')?->getAttribute('content');
    $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    if ($token !== null) {
        $headers['X-CSRF-TOKEN'] = $token;
    }
    return $headers;
}

/**
 * Server calls over a WebSocket to Laravel Reverb, when unscripted/ui-server's transport is
 * 'reverb': one socket for the page, opened by the first call. It speaks enough of Reverb's
 * (Pusher's) protocol to join the user's private channel, signed by `auth` with the session,
 * so the calls run as them. If it closes, the calls waiting on it fail and the next one opens
 * it again.
 *
 * #[Live] loaders listen on it too: public channels (`unscripted-live.<key>`), subscribed while
 * the socket is up. While anything listens, a closed socket is opened again after a pause, and
 * the listeners are told, since they may have missed an update.
 */
final class Socket
{
    /** The page's, set by mountPage() from an @unscripted script's data-socket. */
    public static ?self $page = null;

    private ?object $ws = null;
    /** Whether Reverb has said hello (so channels can be subscribed to)... */
    private bool $established = false;
    /** ...and whether calls can go: the user's channel is joined, or there's none. */
    private bool $open = false;
    /** Handlers for each live channel's events, by channel. */
    private array $listeners = [];
    /** The live channels that were listened on when the socket closed: each one's listeners are
     * told when it's subscribed again. */
    private array $missed = [];
    /** Messages waiting for the socket to open. */
    private array $queue = [];
    /** Each sent call's callback, by id. */
    private array $waiting = [];
    private int $next = 0;

    /** `$config`: url, channel (null for a guest) and auth, from UiServiceProvider::socket(). */
    public function __construct(private readonly array $config) {}

    public function send(array $calls, Closure $done): void
    {
        $id = ++$this->next;
        $this->waiting[$id] = $done;
        // Reverb wants a channel on every message; a guest's names none it's in.
        $message = json_encode([
            'event' => 'unscripted:call',
            'channel' => $this->config['channel'] ?? 'unscripted',
            'data' => ['id' => $id, 'calls' => $calls],
        ], JSON_THROW_ON_ERROR);
        if ($this->open) {
            $this->ws->send($message);
            return;
        }
        $this->queue[] = $message;
        $this->connect();
    }

    /** Calls `$handler` with each event on public channel `$channel`, from now on. */
    public function listen(string $channel, Closure $handler): void
    {
        $subscribed = isset($this->listeners[$channel]);
        $this->listeners[$channel][] = $handler;
        if ($this->established && !$subscribed) {
            $this->subscribe($channel);
        }
        $this->connect();
    }

    private function connect(): void
    {
        if ($this->ws !== null) {
            return;
        }
        $ws = $this->ws = U::window()->WebSocket($this->config['url']);
        $ws->addEventListener('message', fn($event) => $this->receive((string) $event->data));
        // Only while it's the page's socket: a closed one's late event mustn't fail the next.
        $ws->addEventListener('close', function () use ($ws) {
            if ($this->ws === $ws) {
                $this->closed($this->open ? 'the connection to the server closed' : "couldn't reach the server's socket");
            }
        });
    }

    private function subscribe(string $channel, ?string $auth = null): void
    {
        $this->ws->send(json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, ...($auth === null ? [] : ['auth' => $auth])]]));
    }

    private function established(string $socketId): void
    {
        $this->established = true;
        foreach (array_keys($this->listeners) as $channel) {
            $this->subscribe($channel);
        }
        $this->join($socketId);
    }

    /** A channel's subscription went through: the user's channel opens the socket for calls,
     * and a live channel's listeners hear about updates they missed while it was down. */
    private function subscribed(?string $channel): void
    {
        if ($channel === $this->config['channel']) {
            $this->opened();
        } elseif (in_array($channel, $this->missed, true)) {
            $this->missed = array_values(array_diff($this->missed, [$channel]));
            $this->event($channel, null);
        }
    }

    private function event(?string $channel, mixed $data): void
    {
        foreach ($this->listeners[$channel] ?? [] as $handler) {
            $handler($data);
        }
    }

    private function receive(string $text): void
    {
        $message = json_decode($text, true) ?? [];
        $data = $message['data'] ?? null;
        $data = is_string($data) ? json_decode($data, true) : $data;
        match ($message['event'] ?? null) {
            'pusher:connection_established' => $this->established($data['socket_id']),
            'pusher_internal:subscription_succeeded' => $this->subscribed($message['channel'] ?? null),
            'pusher:ping' => $this->ws->send('{"event":"pusher:pong","data":{}}'),
            'pusher:error' => U::console()->error('unscripted socket: ' . ($data['message'] ?? $text)),
            'unscripted:result' => $this->result($data),
            default => $this->event($message['channel'] ?? null, $data),
        };
    }

    /** Joins the user's private channel, once the app has signed it for this socket. */
    private function join(string $socketId): void
    {
        $channel = $this->config['channel'];
        if ($channel === null) {
            $this->opened();
            return;
        }
        $body = json_encode(['socket_id' => $socketId, 'channel_name' => $channel], JSON_THROW_ON_ERROR);
        U::fetch($this->config['auth'], ['method' => 'POST', 'headers' => headers(), 'body' => $body])->then(
            function ($response) use ($channel) {
                $status = $response->status;
                $response->text()->then(function (string $text) use ($channel, $status) {
                    $auth = json_decode($text, true)['auth'] ?? null;
                    if ($status !== 200 || !is_string($auth)) {
                        $this->closed("couldn't join $channel: the server answered $status");
                        return;
                    }
                    $this->subscribe($channel, $auth);
                });
            },
            fn($error) => $this->closed("couldn't join $channel: {$error->message}"),
        );
    }

    private function opened(): void
    {
        $this->open = true;
        foreach ($this->queue as $message) {
            $this->ws->send($message);
        }
        $this->queue = [];
    }

    private function result(array $data): void
    {
        $done = $this->waiting[$data['id'] ?? null] ?? null;
        if ($done === null) {
            return;
        }
        unset($this->waiting[$data['id']]);
        if (($data['status'] ?? 0) === 200 && is_array($data['results'] ?? null)) {
            $done($data['results'], null);
        } else {
            $done(null, new ServerError($data['message'] ?? 'the server failed', $data['status'] ?? 0, $data['errors'] ?? []));
        }
    }

    /** Fails what's waiting; the next call opens a new socket. */
    private function closed(string $why): void
    {
        $ws = $this->ws;
        $this->ws = null;
        $this->established = $this->open = false;
        $this->queue = [];
        $waiting = $this->waiting;
        $this->waiting = [];
        if ($ws !== null && $ws->readyState < 2) {
            $ws->close();
        }
        if ($this->listeners !== []) {
            $this->missed = array_keys($this->listeners);
            U::setTimeout(fn() => $this->connect(), 2000);
        }
        foreach ($waiting as $done) {
            $done(null, new ServerError($why));
        }
    }
}

/**
 * A component from a Blade file's source: the PHP block at the top makes it (`new class extends
 * Component { ... };`), the rest is its template. A file with no PHP block is a template with no
 * state. `$file` names it in error messages.
 *
 * `$load` runs each part's PHP (a file's contents: 'class', the block; 'view', the template) and
 * returns what it returns. By default that's eval(); unscripted/ui-server writes them to files.
 */
function blade(string $source, string $file, ?Closure $load = null): Component
{
    $load ??= fn(string $code, string $part) => eval('?>' . $code);
    $block = '';
    $template = $source;
    if (str_starts_with(ltrim($source), '<?php')) {
        $end = strpos($source, '?>');
        if ($end === false) {
            throw new Error("$file: the PHP block at the top needs a closing ?>");
        }
        $block = substr($source, 0, $end + 2);
        $template = substr($source, $end + 2);
    }

    // The block's `use` lines, for the template too: it's compiled separately.
    $uses = '';
    $class = preg_match('/\bnew\s+class\b/', $block, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;
    if ($class !== null) {
        preg_match_all('/^\s*use\s+[^;]+;/m', substr($block, 0, $class), $found);
        $uses = implode(' ', array_map('trim', $found[0]));
    }

    if ($block === '') {
        $component = new class extends Component {};
    } elseif ($class === null) {
        throw new Error("$file: the PHP block must make the component: new class extends Component { ... };");
    } else {
        // From the block's own opening tag, so its line numbers are the file's.
        $code = substr_replace($block, 'return new class', $class, strlen($m[0][0]));
        try {
            $component = $load($code, 'class');
        } catch (ParseError $e) {
            throw new Error("{$e->getMessage()} in $file:{$e->getLine()}", 0, $e);
        }
        if (!$component instanceof Component) {
            throw new Error("$file: the PHP block's class must extend " . Component::class);
        }
    }

    // The template's first line is the file's line after the block's last.
    $padding = str_repeat("\n", substr_count($block, "\n"));
    $code = "<?php $uses return function (array \$__data): void { extract(\$__data, EXTR_SKIP);$padding?>"
        . Blade::compile($template) . '<?php };';
    try {
        $view = $load($code, 'view');
    } catch (ParseError $e) {
        throw new Error("{$e->getMessage()} in $file:{$e->getLine()} (in the template)", 0, $e);
    }

    // The template runs as the component: $this, and its private methods, work in it.
    Closure::bind(function () use ($view, $component, $file) {
        $this->view = Closure::bind($view, $component, get_class($component));
        $this->file = $file;
    }, $component, Component::class)();
    return $component;
}

/** Blade, compiled to PHP. A subset: see the top of this file. */
final class Blade
{
    public static function compile(string $template): string
    {
        // Text that's printed as written: @verbatim blocks, @{{ }} and @{!! !!}. @php blocks are
        // already PHP. Each is set aside until the end, so nothing below touches it.
        $kept = [];
        $keep = function (string $text) use (&$kept): string {
            $kept[] = $text;
            return "\x00" . (count($kept) - 1) . "\x00";
        };
        $t = preg_replace_callback('/@verbatim(.*?)@endverbatim/s', fn($m) => $keep($m[1]), $template);
        $t = preg_replace_callback('/@php(?!\s*\()(.*?)@endphp/s', fn($m) => $keep("<?php{$m[1]}?>"), $t);
        $t = preg_replace_callback('/@(\{\{.*?\}\}|\{!!.*?!!\})/s', fn($m) => $keep($m[1]), $t);
        $t = preg_replace('/\{\{--.*?--\}\}/s', '', $t);

        // @directives, with balanced parentheses (quoted strings may hold any).
        $forelse = [];
        $next = 0;
        $t = preg_replace_callback(
            '/\B@(@?\w+)([ \t]*)(\((?:[^()\'"]++|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+"|(?3))*\))?/s',
            function ($m) use (&$forelse, &$next) {
                $args = isset($m[3]) ? substr($m[3], 1, -1) : null;
                $compiled = self::directive($m[1], $args, $forelse, $next);
                return $compiled ?? $m[0];
            },
            $t,
        );
        if ($forelse) {
            throw new Error('@forelse without @endforelse');
        }

        $t = preg_replace_callback('/\{!!\s*(.+?)\s*!!\}/s', fn($m) => "<?php echo {$m[1]}; ?>", $t);
        $t = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/s', fn($m) => "<?php echo \\Unscripted\\Ui\\e({$m[1]}); ?>", $t);

        return preg_replace_callback('/\x00(\d+)\x00/', fn($m) => $kept[(int) $m[1]], $t);
    }

    /** One directive as PHP, or null for an @word that isn't one (an email, a CSS @media). */
    private static function directive(string $name, ?string $args, array &$forelse, int &$next): ?string
    {
        $needs = fn() => $args ?? throw new Error("@$name needs its (...)");
        return match ($name) {
            'if' => "<?php if ({$needs()}): ?>",
            'elseif' => "<?php elseif ({$needs()}): ?>",
            'else' => '<?php else: ?>',
            'unless' => "<?php if (!({$needs()})): ?>",
            'isset' => "<?php if (isset({$needs()})): ?>",
            'endif', 'endunless', 'endisset', 'endempty' => '<?php endif; ?>',
            'foreach' => "<?php foreach ({$needs()}): ?>",
            'endforeach' => '<?php endforeach; ?>',
            'for' => "<?php for ({$needs()}): ?>",
            'endfor' => '<?php endfor; ?>',
            'while' => "<?php while ({$needs()}): ?>",
            'endwhile' => '<?php endwhile; ?>',
            'forelse' => (function () use ($needs, &$forelse, &$next) {
                $forelse[] = $empty = '$__empty' . $next++;
                return "<?php $empty = true; foreach ({$needs()}): $empty = false; ?>";
            })(),
            // @empty is forelse's else, or with arguments, `if (empty(...))`.
            'empty' => $args !== null
                ? "<?php if (empty($args)): ?>"
                : '<?php endforeach; if (' . (end($forelse) ?: throw new Error('@empty outside @forelse')) . '): ?>',
            'endforelse' => array_pop($forelse) !== null ? '<?php endif; ?>' : throw new Error('@endforelse without @forelse'),
            'break', 'continue' => $args === null ? "<?php $name; ?>" : "<?php if ($args) $name; ?>",
            'php' => "<?php ({$needs()}); ?>",
            'class' => "class=\"<?php echo \\Unscripted\\Ui\\classes({$needs()}); ?>\"",
            'checked', 'selected', 'disabled', 'readonly', 'required' => "<?php if ({$needs()}) echo '$name'; ?>",
            // A partial's defaults (unscripted/ui-server inlines partials into the template).
            'props' => "<?php foreach ({$needs()} as \$__key => \$__default) { if (is_int(\$__key)) { \$\$__default ??= null; } else { \$\$__key ??= \$__default; } } ?>",
            'include' => throw new Error('@include needs unscripted/ui-server, which inlines the partial (the browser can\'t fetch it mid-render)'),
            // $errors is the component's ErrorBag, from render().
            'error' => "<?php if (\$errors->has({$needs()})): \$message = \$errors->first({$needs()}); ?>",
            'enderror' => '<?php unset($message); endif; ?>',
            'child' => "<?php echo \$this->child({$needs()}); ?>",
            'json' => "<?php echo json_encode({$needs()}, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>",
            default => str_starts_with($name, '@') ? $name . ($args !== null ? "($args)" : '') : null,
        };
    }
}

/** Mounts the page's Blade components: <script type="text/blade" src="x.blade.php" data-mount="#app">,
 * or with the Blade inline instead of a src. */
function mountPage(array $skip = []): void
{
    foreach (U::document()->querySelectorAll('script[type="text/blade"]') as $script) {
        $target = $script->getAttribute('data-mount');
        if ($target === null) {
            throw new Error('a <script type="text/blade"> needs data-mount, the selector to mount it on');
        }
        // After u-navigate: one inside a persisted element is still mounted.
        $persisted = false;
        foreach ($skip as $id) {
            $persisted = $persisted || $script->closest('[u-persist="' . addcslashes($id, '"\\') . '"]') !== null;
        }
        if ($persisted) {
            continue;
        }
        // Rendered on the server already (unscripted/ui-server's @unscripted): its state, and
        // the socket its calls go over, if they do.
        Component::$endpoint = $script->getAttribute('data-endpoint') ?? Component::$endpoint;
        // The page's, so the first script tag that carries it sets it for every component.
        $shared = $script->getAttribute('data-shared');
        if ($shared !== null) {
            Component::$shared = json_decode($shared, true, 512, JSON_THROW_ON_ERROR);
        }
        $socket = $script->getAttribute('data-socket');
        if ($socket !== null) {
            Socket::$page ??= new Socket(json_decode($socket, true, 512, JSON_THROW_ON_ERROR));
        }
        $state = $script->getAttribute('data-state');
        $state = $state === null ? null : json_decode($state, true, 512, JSON_THROW_ON_ERROR);
        $src = $script->src;
        if ($src === '') {
            blade($script->textContent, 'inline Blade for ' . $target)->mount($target, $state);
            continue;
        }
        U::fetch($src)->then(function ($response) use ($src, $target, $state) {
            if (!$response->ok) {
                throw new Error("couldn't load $src: {$response->status}");
            }
            $response->text()->then(fn(string $source) => blade($source, $src)->mount($target, $state));
        }, fn($error) => U::console()->error("couldn't load $src: " . $error));
    }
}

/**
 * u-navigate: a click on `<a u-navigate href="...">` fetches that page and swaps its body in,
 * without loading PHP again. Its head's styles replace this one's, its title too; elements
 * with `u-persist="id"` are carried over (with any component in them) where the new page has
 * the same id; the other components are unmounted and the new page's mounted. Back and forward
 * work. A failed fetch loads the page the ordinary way.
 *
 * In-page links (href="#section") are the browser's own: their history entries differ from the
 * page shown only in the fragment, so going to them, or back and forward between them, fetches
 * nothing and keeps the scroll the browser gives. A visit to a URL with a fragment lands on it.
 */
final class Navigator
{
    private static bool $installed = false;

    /** The page on screen: path and query, without the fragment. */
    private static string $shown = '';

    /** The address bar now names the page on screen (after a visit, or a #[Url] change). */
    public static function settled(): void
    {
        $location = U::window()->location;
        self::$shown = (string) $location->pathname . (string) $location->search;
    }

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;
        self::settled();
        U::document()->addEventListener('click', function ($event) {
            $link = $event->target->closest('a[u-navigate]');
            if ($link === null || $event->ctrlKey || $event->metaKey || $event->shiftKey || $event->button !== 0) {
                return;
            }
            $url = (string) $link->href;
            if (!str_starts_with($url, (string) U::window()->location->origin)) {
                return; // another site: an ordinary link
            }
            $event->preventDefault();
            self::visit($url, true);
        });
        U::window()->addEventListener('popstate', function () {
            $location = U::window()->location;
            if ((string) $location->pathname . (string) $location->search === self::$shown) {
                return; // only the fragment moved: the browser scrolls, nothing to fetch
            }
            self::visit((string) $location->href, false);
        });
    }

    public static function visit(string $url, bool $push): void
    {
        U::fetch($url, ['headers' => ['X-Unscripted-Navigate' => '1']])->then(function ($response) use ($url, $push) {
            if (!$response->ok) {
                U::window()->location->assign($url);
                return;
            }
            $response->text()->then(fn(string $html) => self::swap($html, $url, $push));
        }, fn() => U::window()->location->assign($url));
    }

    private static function swap(string $html, string $url, bool $push): void
    {
        $document = U::document();
        $next = U::window()->DOMParser()->parseFromString($html, 'text/html');

        // Kept across: what has u-persist, where the new page has the same.
        $kept = [];
        foreach ($document->querySelectorAll('[u-persist]') as $element) {
            $kept[$element->getAttribute('u-persist')] = $element;
        }
        foreach (Component::$mounted as $component) {
            $persisted = false;
            foreach ($kept as $element) {
                $persisted = $persisted || $component->within($element);
            }
            if (!$persisted) {
                $component->unmount();
            }
        }

        // The head's styles, and the title.
        foreach ($document->head->querySelectorAll('style, link[rel="stylesheet"]') as $old) {
            $old->remove();
        }
        $styles = '';
        foreach ($next->head->querySelectorAll('style, link[rel="stylesheet"]') as $style) {
            $styles .= $style->outerHTML;
        }
        $document->head->insertAdjacentHTML('beforeend', $styles);
        $document->title = $next->title;
        $token = $next->querySelector('meta[name="csrf-token"]')?->getAttribute('content');
        if ($token !== null) {
            $document->querySelector('meta[name="csrf-token"]')?->setAttribute('content', $token);
        }

        $document->body->innerHTML = $next->body->innerHTML;
        foreach ($document->querySelectorAll('[u-persist]') as $placeholder) {
            $id = $placeholder->getAttribute('u-persist');
            if (isset($kept[$id])) {
                $placeholder->replaceWith($kept[$id]);
            }
        }
        if ($push) {
            U::window()->history->pushState(null, '', $url);
        }
        self::settled();
        mountPage(skip: array_keys($kept));
        // After mounting: components render into the page, which moves what follows them.
        $fragment = (string) parse_url($url, PHP_URL_FRAGMENT);
        $target = $fragment === '' ? null : $document->getElementById(rawurldecode($fragment));
        if ($target !== null) {
            $target->scrollIntoView();
        } else {
            U::window()->scrollTo(0, 0);
        }
    }
}

// Only in the page: on the server (unscripted/ui-server) this file just defines the classes.
if (class_exists(\Unscripted::class, false)) {
    Navigator::install();
    mountPage();
}
