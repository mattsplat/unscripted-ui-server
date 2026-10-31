<?php
// composer test: Components::strip and Components::call, with no Laravel.

require __DIR__ . '/../src/component.php';
require __DIR__ . '/../src/Components.php';

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
try {
    $components->call('todos', 'add', ['throw']);
} catch (RuntimeException $e) {
    check('an error names the component file', $e->getFile(), "$folder/todos.blade.php");
    check('at its line', explode("\n", $source)[$e->getLine() - 1], "        if (\$title === 'throw') { throw new RuntimeException('thrown'); }");
}
check('compiled once', count(glob("$cache/unscripted-todos-*.php")), 1);
file_put_contents("$folder/todos.blade.php", str_replace('strlen($title) + count($tags)', '100', $source));
check('an edit is picked up', $components->call('todos', 'add', ['abc']), 100);
check('the old compiled file is gone', count(glob("$cache/unscripted-todos-*.php")), 1);

array_map('unlink', [...glob("$cache/*"), "$folder/todos.blade.php"]);
rmdir($cache);
rmdir($folder);

echo $failed ? "\n$failed failed\n" : "\nall passed\n";
exit($failed ? 1 : 0);
