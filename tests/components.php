<?php
// composer test: Components::strip, call and render, with no Laravel.

require __DIR__ . '/../src/ui.php';
require __DIR__ . '/../src/Components.php';
require __DIR__ . '/../src/SocketCalls.php';
require __DIR__ . '/../src/ComponentTest.php';

use Unscripted\UiServer\{BadArguments, Components, UnknownCall};

$failed = 0;
function check(string $label, mixed $got, mixed $want): void
{
    global $failed;
    if ($got === $want) {
        echo "ok   $label\n";
        return;
    }
    $failed++;
    echo "FAIL $label\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n";
}
function fails(string $label, Closure $f, string $class, string $contains = ''): void
{
    try {
        $f();
    } catch (Throwable $e) {
        check($label, $e instanceof $class && str_contains($e->getMessage(), $contains), true);
        return;
    }
    check("$label (didn't throw)", false, true);
}

$source = <<<'BLADE'
<?php
use App\Models\Todo;
use Unscripted\Ui\{Component, State, Server, Watch};

new class extends Component {
    #[State] public array $todos = [];
    #[State] public string $query = '';

    #[Server, Watch('query')]
    public static function todos(string $query = ''): array
    {
        return self::secret("{$query}%");
    }

    #[Server]
    private static function secret(string $like): array
    {
        return ['SELECT * FROM todos WHERE title LIKE ' . $like];
    }

    #[\Unscripted\Ui\Server]
    #[Other(['a' => '{'])]
    public static function add(#[SensitiveParameter] string $title, int ...$tags): int
    {
        if ($title === '}') { return 0; }
        if ($title === 'bug') { return strlen([]); }
        if ($title === 'throw') { throw new RuntimeException('thrown'); }
        return strlen($title) + count($tags);
    }

    #[Server(can: 'shout')]
    public static function shout(string $word): string
    {
        return strtoupper($word);
    }

    #[Other('Server')]
    public function client(int $n): void { $this->query = "$n"; }
};
?>
<p>{{ $query }} {{-- #[Server] in the template stays --}}</p>
BLADE;

$stripped = Components::strip($source, 'todos');
check('same lines', substr_count($stripped, "\n"), substr_count($source, "\n"));
check('no server bodies', str_contains($stripped, 'SELECT') || str_contains($stripped, 'strlen'), false);
check('private server method gone', str_contains($stripped, 'secret'), false);
check('stub', str_contains($stripped, "#[Server, Watch('query')] public static function todos(string \$query = ''): \\Unscripted\\Ui\\ServerCall { return new \\Unscripted\\Ui\\ServerCall('todos', 'todos', func_get_args()); }"), true);
check('stub keeps parameter attributes and variadics', str_contains($stripped, 'function add(#[SensitiveParameter] string $title, int ...$tags)'), true);
check('client method kept', str_contains($stripped, 'public function client(int $n): void { $this->query = "$n"; }'), true);
check('template kept', str_contains($stripped, '{{-- #[Server] in the template stays --}}'), true);
check('stub on its line', str_starts_with(explode("\n", $stripped)[8], "    #[Server, Watch('query')] public static function todos("), true);
check('stripped parses', (function () use ($stripped) {
    $end = strpos($stripped, '?>');
    try {
        token_get_all(substr($stripped, 0, $end + 2), TOKEN_PARSE);
        return true;
    } catch (ParseError $e) {
        return $e->getMessage();
    }
})(), true);
fails('non-static server method', fn() => Components::strip("<?php new class extends Component {\n#[Server] public function x() {}\n}; ?>", 'x'),
    Error::class, 'must be static, in x.blade.php:2');

$folder = sys_get_temp_dir() . '/ui-server-test-' . getmypid();
mkdir($folder);
file_put_contents("$folder/todos.blade.php", $source);
$cache = "$folder/cache";
$components = new Components($folder, $cache);
check('browser source', $components->browserSource('todos'), $stripped);
check('a TypeError inside the method is its own', (function () use ($components) {
    try {
        $components->call('todos', 'add', ['}']);
        return 'no error';
    } catch (Throwable $e) {
        return get_class($e);
    }
})(), 'no error');
check('no component', $components->browserSource('nope'), null);
check('no path tricks', $components->browserSource('../todos'), null);
check('call', $components->call('todos', 'todos', ['bu']), ['SELECT * FROM todos WHERE title LIKE bu%']);
check('call with defaults', $components->call('todos', 'todos', []), ['SELECT * FROM todos WHERE title LIKE %']);
check('variadic', $components->call('todos', 'add', ['abc', 1, 2]), 5);
check('coerced', $components->call('todos', 'add', ['abc', '4']), 4);
fails('private server method', fn() => $components->call('todos', 'secret', ['x']), UnknownCall::class, 'no server method secret');
fails('client method', fn() => $components->call('todos', 'client', [1]), UnknownCall::class);
fails('missing method', fn() => $components->call('todos', 'nope', []), UnknownCall::class);
fails('missing component', fn() => $components->call('nope', 'x', []), UnknownCall::class);
fails('bad argument', fn() => $components->call('todos', 'add', [[1]]), BadArguments::class,
    'todos::add(): Argument #1 ($title) must be of type string, array given');
fails('a TypeError inside the method is a bug, not bad arguments', fn() => $components->call('todos', 'add', ['bug']), TypeError::class, 'strlen()');
fails('too few arguments', fn() => $components->call('todos', 'add', []), BadArguments::class,
    'todos::add(): too few arguments, 0 passed');
fails('no path in argument errors', fn() => $components->call('todos', 'add', [[1]]), BadArguments::class, '');
check('no path in the message', (function () use ($components) {
    try {
        $components->call('todos', 'add', [[1]]);
    } catch (BadArguments $e) {
        return str_contains($e->getMessage(), sys_get_temp_dir()) || str_contains($e->getMessage(), 'anonymous');
    }
})(), false);
fails('can, with nothing to check it', fn() => $components->call('todos', 'shout', ['hi']), Error::class, "has #[Server(can: 'shout')], but nothing");
$asked = [];
$guarded = new Components($folder, $cache, function (string $ability, array $args) use (&$asked) {
    $asked[] = [$ability, $args];
    if ($args[0] === 'no') {
        throw new RuntimeException('denied');
    }
});
check('can: allowed', $guarded->call('todos', 'shout', ['hi']), 'HI');
fails('can: denied', fn() => $guarded->call('todos', 'shout', ['no']), RuntimeException::class, 'denied');
check('can: asked with the arguments', $asked, [['shout', ['hi']], ['shout', ['no']]]);
check('no can, not asked', [$guarded->call('todos', 'add', ['ab']), count($asked)], [2, 2]);
check('the stub keeps can', str_contains(Components::strip($source, 'todos'), "#[Server(can: 'shout')] public static function shout(string \$word): \\Unscripted\\Ui\\ServerCall"), true);

try {
    $components->call('todos', 'add', ['throw']);
} catch (RuntimeException $e) {
    check('an error names the component file', $e->getFile(), "$folder/todos.blade.php");
    check('at its line', explode("\n", $source)[$e->getLine() - 1], "        if (\$title === 'throw') { throw new RuntimeException('thrown'); }");
}
check('compiled once, the block and the template', count(glob("$cache/unscripted-todos-*.php")), 2);
file_put_contents("$folder/todos.blade.php", str_replace('strlen($title) + count($tags)', '100', $source));
check('an edit is picked up', $components->call('todos', 'add', ['abc']), 100);
check('the old compiled files are gone', count(glob("$cache/unscripted-todos-*.php")), 2);

// Rendering on the server: the loaders run first, then the template, with the real ui.php.
file_put_contents("$folder/list.blade.php", <<<'BLADE'
<?php
use Unscripted\Ui\{Component, Server, State, Watch};
new class extends Component {
    #[State] public string $query = 'a<b';
    public array $rows = [];
    public readonly int $fixed;
    public function __construct() { $this->fixed = 7; }
    #[Server, Watch('query')]
    public static function rows(string $query): array { return ["$query 1", "$query 2"]; }
};
?>
<ul>@foreach ($rows as $row)<li>{{ $row }}</li>@endforeach</ul>
<p>{{ $missing }}</p>
BLADE);
$rendered = (function () use ($components) {
    set_error_handler(fn() => true); // the undefined $missing, a warning
    try {
        return $components->render('list');
    } finally {
        restore_error_handler();
    }
})();
check('rendered with the loader run', trim(explode("\n", $rendered['html'])[0]), '<ul><li>a&lt;b 1</li><li>a&lt;b 2</li></ul>');
check('its state, not readonly properties', $rendered['state'], ['query' => 'a<b', 'rows' => ['a<b 1', 'a<b 2']]);
$tag = (function () use ($components) {
    set_error_handler(fn() => true);
    try {
        return $components->tag('list', '/unscripted/components/list.blade.php');
    } finally {
        restore_error_handler();
    }
})();
check('the tag: the HTML in its root', str_starts_with($tag, '<div id="unscripted-list"><ul><li>a&lt;b 1'), true);
check('the tag: the script', str_contains($tag, '<script type="text/blade" src="/unscripted/components/list.blade.php" data-mount="#unscripted-list" data-state="'), true);
preg_match('/data-state="([^"]*)"/', $tag, $m);
check('the tag: the state, escaped', json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true), $rendered['state']);
file_put_contents("$folder/broken.blade.php", "<?php\nuse Unscripted\\Ui\\Component;\nnew class extends Component {};\n?>\n<p>\n{{ throw new RuntimeException('in the template') }}\n</p>\n");
try {
    $components->render('broken');
    check('a template error (didn\'t throw)', false, true);
} catch (RuntimeException $e) {
    check('a template error names the component file and line', [$e->getFile(), $e->getLine()], ["$folder/broken.blade.php", 6]);
}

// run(): a request's calls, as both transports get them.
check('run: in order', $components->run([
    ['component' => 'todos', 'method' => 'todos', 'args' => ['a']],
    ['component' => 'todos', 'method' => 'todos', 'args' => ['b']],
]), [['SELECT * FROM todos WHERE title LIKE a%'], ['SELECT * FROM todos WHERE title LIKE b%']]);
fails('run: not a list', fn() => $components->run(['component' => 'todos']), BadArguments::class, '1 to 20');
fails('run: none', fn() => $components->run([]), BadArguments::class, '1 to 20');
fails('run: too many', fn() => $components->run(array_fill(0, 21, ['component' => 'todos', 'method' => 'add', 'args' => ['a']])), BadArguments::class, '1 to 20');
fails('run: a malformed call', fn() => $components->run([['component' => 'todos', 'method' => 'add', 'args' => ['x' => 1]]]), BadArguments::class, 'list of args');
fails('run: an unknown one', fn() => $components->run([['component' => 'todos', 'method' => 'nope', 'args' => []]]), UnknownCall::class);

$socket = ['url' => 'ws://127.0.0.1:8080/app/key?protocol=7', 'channel' => 'private-unscripted.1', 'auth' => '/broadcasting/auth'];
$withSocket = (function () use ($components, $socket) {
    set_error_handler(fn() => true);
    try {
        return $components->tag('list', '/unscripted/components/list.blade.php', $socket);
    } finally {
        restore_error_handler();
    }
})();
preg_match('/data-socket="([^"]*)"/', $withSocket, $m);
check('the tag: the socket, escaped', json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5), true), $socket);
check('the tag: no socket over HTTP', str_contains($tag, 'data-socket'), false);

// #[Validate] on parameters: checked before the method runs, after `can`.
file_put_contents("$folder/form.blade.php", <<<'BLADE'
<?php
use Unscripted\Ui\{Component, Server, Validate};
new class extends Component {
    #[Server(can: 'save')]
    public static function save(#[Validate('required|max:5')] string $title, int $plain = 1, #[Validate(['in:a,b'])] string $kind = 'a', #[Validate('max:2')] string ...$tags): string
    {
        return "saved $title";
    }
    #[Server]
    public static function open(#[Validate('required')] string $x): string { return $x; }
};
?>
BLADE);
$seen = [];
$validating = new Components($folder, $cache,
    function (string $ability, array $args) use (&$seen) {
        $seen[] = 'can';
    },
    function (array $data, array $rules) use (&$seen) {
        $seen[] = [$data, $rules];
        if (strlen($data['title'] ?? '') > 5) {
            throw new RuntimeException('invalid');
        }
    },
);
check('validated: the method runs', $validating->call('form', 'save', ['milk']), 'saved milk');
check('validated: can first, then the data and rules by parameter, defaults filled in', $seen, [
    'can',
    [['title' => 'milk', 'kind' => 'a', 'tags' => []], ['title' => 'required|max:5', 'kind' => ['in:a,b'], 'tags.*' => 'max:2']],
]);
$seen = [];
$validating->call('form', 'save', ['milk', 2, 'b', 'x', 'y']);
check('validated: a variadic parameter as a list', $seen[1][0], ['title' => 'milk', 'kind' => 'b', 'tags' => ['x', 'y']]);
$seen = [];
fails('validated: a missing argument is left out, for `required` to catch', fn() => $validating->call('form', 'open', []), BadArguments::class, 'too few');
check('validated: ...before the method is called', $seen, [[[], ['x' => 'required']]]);
fails('validated: a failure stops the call', fn() => $validating->call('form', 'save', ['too long']), RuntimeException::class, 'invalid');
fails('#[Validate], with nothing to check it', fn() => $components->call('form', 'open', ['x']), Error::class, 'has #[Validate] parameters, but nothing');

// #[Validate] as: and by field (a form), with messages naming the parameter or field.
file_put_contents("$folder/forms.blade.php", <<<'BLADE'
<?php
use Unscripted\Ui\{Component, Server, Validate};
new class extends Component {
    #[Server]
    public static function save(
        #[Validate('required', as: 'draft')] string $todo_title,
        #[Validate(['title' => 'required|max:5', 'due_date' => 'date'], as: 'form')] array $form = [],
    ): string { return 'ok'; }
};
?>
BLADE);
$asked = [];
$forms = new Components($folder, $cache, null, function (array $data, array $rules, array $attributes) use (&$asked) {
    $asked = [$data, $rules, $attributes];
});
$forms->call('forms', 'save', ['x', ['title' => 'a', 'due_date' => '2026-01-01']]);
check('as: and by field', $asked, [
    ['draft' => 'x', 'form' => ['title' => 'a', 'due_date' => '2026-01-01']],
    ['draft' => 'required', 'form.title' => 'required|max:5', 'form.due_date' => 'date'],
    ['draft' => 'todo title', 'form.title' => 'title', 'form.due_date' => 'due date'],
]);

// ComponentTest: a component driven without a browser.
file_put_contents("$folder/shop.blade.php", <<<'BLADE'
<?php
use Unscripted\Ui\{Component, Computed, Server, ServerError, State, Validate, Watch};
new class extends Component {
    #[State] public string $draft = '';
    public array $items = [];
    public ?string $note = null;
    public static array $db = ['tea'];
    #[Server, Watch] public static function items(): array { return self::$db; }
    #[Server] public static function add(#[Validate('required|max:5', as: 'draft')] string $item): void { self::$db[] = $item; }
    #[Server] public static function boom(): void { throw new LogicException('a bug'); }
    #[Computed] public function count(): int { return count($this->items); }
    public function save(): Generator { yield static::add($this->draft); $this->draft = ''; $this->dispatch('saved', ['n' => $this->count]); }
    public function explode(): Generator { yield static::boom(); }
    public function leave(): void { $this->redirect('/bye'); }
};
?>
<ul>@foreach ($items as $item)<li>{{ $item }}</li>@endforeach</ul><p>{{ $this->count }} items</p>
BLADE);
// No Laravel here: a stand-in for its ValidationException, and a validator from ui.php's Rules.
eval('namespace Illuminate\\Validation; class ValidationException extends \\RuntimeException {
    public function __construct(private array $bag) { parent::__construct(reset($bag)[0]); }
    public function errors(): array { return $this->bag; } }');
$validating = new Components($folder, $cache, null, function (array $data, array $rules, array $attributes) {
    $errors = [];
    foreach ($rules as $key => $rule) {
        $message = Unscripted\Ui\Rules::check($data[$key] ?? null, $rule, $attributes[$key]);
        if ($message !== null) {
            $errors[$key] = [$message];
        }
    }
    if ($errors) {
        throw new Illuminate\Validation\ValidationException($errors);
    }
});
$test = $validating->test('shop');
$test->assertSee('<li>tea</li>', false)->assertSee('1 items');
$test->set('draft', 'milk')->call('save')->assertSee('<li>milk</li>', false)->assertSet('draft', '')->assertHasNoErrors()->assertSet('items', ['tea', 'milk']);
check('test: an action, its server call, and the loader after', $test->get('items'), ['tea', 'milk']);
$test->assertDispatched('saved', ['n' => 1]); // before the loaders rerun, as on a page
$test->set('draft', 'way too long')->assertHasErrors('draft');
check('test: the field checked as it was set', $test->component()->errors()->first('draft'), 'The item field must not be greater than 5 characters.');
$test->call('save')->assertHasErrors('draft')->assertSet('items', ['tea', 'milk']);
check('test: the server\'s 422, in the error bag', $test->component()->errors()->first('draft'), 'The item field must not be greater than 5 characters.');
fails('test: a bug in a server method is thrown to the test', fn() => $test->call('explode'), LogicException::class, 'a bug');
$test->call('leave')->assertRedirect('/bye');
fails('test: a failing assertion', fn() => $test->assertSee('coffee'), AssertionError::class, 'coffee');

// Partials: @include and <x-...>, inlined.
file_put_contents("$folder/row.blade.php", '<li>{{ $prefix }}{{ $item }}</li>');
file_put_contents("$folder/badge.blade.php", "@props(['tone' => 'grey', 'count'])<b class=\"{{ \$tone }}\">{{ \$count }} {!! \$slot !!}</b>");
file_put_contents("$folder/listing.blade.php", <<<'BLADE'
<?php
use Unscripted\Ui\{Component, State};
new class extends Component {
    #[State] public array $items = ['a', 'b'];
    public string $prefix = '> ';
};
?>
<ul>@foreach ($items as $item)@include('row')@endforeach</ul>
@include('row', ['item' => 'given', 'prefix' => ''])
<x-badge :count="count($items)" tone="red">items <i>here</i></x-badge>
<x-badge count="0" />
BLADE);
$listing = trim(preg_replace('/\s+/', ' ', $components->render('listing')['html']));
check('partials: @include, with the template\'s variables and its own', str_contains($listing, '<ul><li>&gt; a</li><li>&gt; b</li></ul> <li>given</li>'), true);
check('partials: <x-...> with props, a default and a slot', str_contains($listing, '<b class="red">2 items <i>here</i></b><b class="grey">0 </b>'), true);
check('partials: in what the browser gets too', str_contains($components->browserSource('listing'), "->call(\$this, [...get_defined_vars()"), true);
file_put_contents("$folder/loop.blade.php", "@include('loop')");
fails('partials: one that includes itself', fn() => $components->source('loop'), Error::class, 'over 10 deep');
fails('partials: a component isn\'t one', fn() => $components->source('listing') && file_put_contents("$folder/usesform.blade.php", "@include('forms')") && $components->source('usesform'), Error::class, 'has a PHP block');

// The server render shows u-model state.
file_put_contents("$folder/modeled.blade.php", <<<'BLADE'
<?php
use Unscripted\Ui\{Component, State};
new class extends Component {
    #[State] public string $name = 'Ada <3';
    #[State] public bool $agree = true;
    #[State] public string $size = 'm';
    #[State] public array $tags = ['b'];
    #[State] public string $bio = 'Hi & bye';
};
?>
<input u-model="name"><input type="checkbox" u-model="agree"><input type="radio" u-model="size" value="s"><input type="radio" u-model="size" value="m">
<select multiple u-model.number="tags"><option>a</option><option>b</option></select><textarea u-model.blur="bio"></textarea>
BLADE);
$modeled = $components->render('modeled')['html'];
check('server render: text, checkbox, radio', [str_contains($modeled, '<input u-model="name" value="Ada <3">') || str_contains($modeled, 'value="Ada &lt;3"'), str_contains($modeled, 'u-model="agree" checked=""'), str_contains($modeled, 'value="m" checked=""'), str_contains($modeled, 'value="s" checked')], [true, true, true, false]);
check('server render: select and textarea', [str_contains($modeled, '<option selected="">b</option>'), str_contains($modeled, '<option>a</option>'), str_contains($modeled, '>Hi &amp; bye</textarea>')], [true, true, true]);

// Streaming methods, without a stream (tests, the socket): the text, then the result.
file_put_contents("$folder/teller.blade.php", <<<'BLADE'
<?php
use Unscripted\Ui\{Component, Server};
new class extends Component {
    public string $text = '>';
    public mixed $result = null;
    #[Server] public static function story(): Generator { yield 'a '; yield 'b'; return 42; }
    #[Server] public static function plain(): Generator { yield 'x'; yield 'y'; }
    public function tell(): Generator { $this->result = yield static::story()->into('text'); }
    public function told(): Generator { $this->result = yield static::plain(); }
};
?>
BLADE);
$teller = $components->test('teller');
$teller->call('tell')->assertSet('text', '>a b')->assertSet('result', 42);
$teller->call('told')->assertSet('result', 'xy')->assertSet('text', '>a b');
check('streams: a plain call gets the joined text', $components->call('teller', 'plain', []), ['unscripted:stream' => ['text' => 'xy', 'result' => 'xy']]);

// tag() options, and #[Url] properties from the request's query.
file_put_contents("$folder/search.blade.php", <<<'BLADE'
<?php
use Unscripted\Ui\{Component, Server, State, Url, Watch};
new class extends Component {
    #[State, Url(as: 'q')] public string $query = '';
    #[State, Url] public int $page = 1;
    public array $hits = [];
    #[Server, Watch(['query', 'page'])]
    public static function hits(string $query, int $page): array { return ["$query p$page"]; }
};
?>
<p>{{ $hits[0] ?? '' }}</p>
BLADE);
$searched = $components->render('search', ['q' => 'milk', 'page' => '3']);
check('#[Url]: from the query, before the loaders', [trim($searched['html']), $searched['state']['query'], $searched['state']['page']], ['<p>milk p3</p>', 'milk', 3]);
$tagged = $components->tag('search', '/s.blade.php', null, ['id' => 'second', 'endpoint' => '/api/call', 'query' => ['q' => 'x']]);
check('tag: its own id', str_starts_with($tagged, '<div id="second"><p>x p1</p>'), true);
check('tag: mounted there, with the endpoint', str_contains($tagged, 'data-mount="#second"') && str_contains($tagged, 'data-endpoint="/api/call"'), true);
$lazy = $components->tag('search', '/s.blade.php', null, ['lazy' => true, 'placeholder' => '<p>Loading…</p>']);
check('tag: lazy, a placeholder and no state', [str_starts_with($lazy, '<div id="unscripted-search"><p>Loading…</p></div>'), str_contains($lazy, 'data-state')], [true, false]);
fails('tag: an id that isn\'t one', fn() => $components->tag('search', '/s', null, ['id' => '"><script>']), InvalidArgumentException::class);

// ui-server's ui.php is a copy of @mtjncn/ui's, so the server renders what the browser does.
$browser = __DIR__ . '/../../ui/ui.php';
if (is_file($browser)) {
    check('src/ui.php is packages/ui/ui.php (npm run sync-ui)', md5_file(__DIR__ . '/../src/ui.php'), md5_file($browser));
}

array_map('unlink', [...glob("$cache/*"), ...glob("$folder/*.blade.php")]);
rmdir($cache);
rmdir($folder);

echo $failed ? "\n$failed failed\n" : "\nall passed\n";
exit($failed ? 1 : 0);
