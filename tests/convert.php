<?php
// composer test: LivewireConverter on a few Livewire components, with no Laravel.

require __DIR__ . '/../src/ui.php';
require __DIR__ . '/../src/LivewireConverter.php';

use Unscripted\UiServer\LivewireConverter;

// Stand-ins for what an app has: a helper (a function PHP doesn't define) and a model.
function auth(): object { return new stdClass(); }

$failed = 0;
function has(string $label, string $haystack, string $needle, bool $want = true): void
{
    global $failed;
    if (str_contains($haystack, $needle) === $want) {
        echo "ok   $label\n";
        return;
    }
    $failed++;
    echo "FAIL $label\n  " . ($want ? 'missing' : 'unexpected') . ": $needle\n";
}

$convert = fn(string $class, string $view) => (new LivewireConverter())->convert($class, $view);

// A counter: nothing to do by hand.
['source' => $source, 'notes' => $notes] = $convert(<<<'PHP'
<?php
namespace App\Livewire;

use Livewire\Component;

class Counter extends Component
{
    public int $count = 0;

    public function add(int $by = 1): void
    {
        $this->count += $by;
    }

    public function render()
    {
        return view('livewire.counter');
    }
}
PHP, <<<'BLADE'
<div>
    <button wire:click="add(-1)">-</button> {{ $count }} <button wire:click="add">+</button>
</div>
BLADE);
has('counter: one import', $source, "use Unscripted\\Ui\\Component;\n");
has('counter: an anonymous class', $source, 'new class extends Component {');
has('counter: render() is gone', $source, 'function render', false);
has('counter: wire:click with an argument', $source, 'u-on:click="add(-1)"');
has('counter: wire:click without', $source, 'u-on:click="add"');
has('counter: no #[State] when nothing binds it', $source, '#[State]', false);
has('counter: no notes', implode("\n", $notes), '', true);
echo count($notes) === 0 ? "ok   counter: nothing to do by hand\n" : (++$failed && print("FAIL counter notes: " . implode(' | ', $notes) . "\n"));

// Todos: Eloquent, validation, mount() with a parameter, #[Locked], #[Url], a trait, and a
// template with wire:loading, string arguments, a child component and route().
['source' => $source, 'notes' => $notes] = $convert(<<<'PHP'
<?php
namespace App\Livewire;

use App\Models\Todo;
use Livewire\Attributes\{Locked, Url, Validate, Computed};
use Livewire\Component;
use Livewire\WithPagination;

class Todos extends Component
{
    use WithPagination;

    #[Validate('required|max:100')]
    public string $title = '';

    #[Url(as: 'q')]
    public string $query = '';

    #[Locked]
    public int $listId;

    public function mount(int $listId)
    {
        $this->listId = $listId;
    }

    #[Computed]
    public function remaining(): int
    {
        return Todo::where('done', false)->count();
    }

    public function add()
    {
        $this->validate();
        Todo::create([
            'title' => $this->title,
            'user_id' => auth()->id(),
        ]);
        $this->reset('title');
    }

    public function sortBy(string $column): void
    {
        if ($column === 'bad') {
            $this->addError('column', 'No.');
        }
    }

    public function render()
    {
        return view('livewire.todos', ['todos' => Todo::latest()->get()]);
    }
}
PHP, <<<'BLADE'
<div>
    <form wire:submit="add">
        @csrf
        <input wire:model.live.debounce.300ms="title">
        <input wire:model="query" type="search">
        <button wire:loading.attr="disabled" wire:target="add">Add</button>
    </form>
    <a href="{{ route('home') }}">Home</a>
    <th wire:click="sortBy('title')">Title</th>
    @foreach ($todos as $todo)
        <livewire:todo-item :todo="$todo" :key="$todo->id" />
    @endforeach
</div>
BLADE);
$all = implode("\n", $notes);
has('todos: Livewire imports go', $source, 'use Livewire\\', false);
has('todos: other imports stay', $source, 'use App\Models\Todo;');
has('todos: #[State] on bound properties', $source, '#[State] public string $title');
has('todos: #[Url] kept beside #[State]', $source, "#[State, Url(as: 'q')] public string \$query");
has('todos: a #[Locked] property gets no #[State]', $source, '#[State] public int $listId', false);
has('todos: #[Locked] is noted', $all, 'was #[Locked]');
has('todos: the trait is commented out', $source, '// use WithPagination;');
has('todos: mount() is mounted()', $source, 'protected function mounted(): void');
has('todos: mount()\'s parameters are noted', $all, 'mount() takes (int $listId)');
has('todos: a validated server method', $source, "#[Validate('required|max:100', as: 'title')] string \$title");
has('todos: the action validates, then yields it', $source, "if (!\$this->validate()) {");
has('todos: ...with the properties', $source, 'yield static::addOnServer($this->title);');
has('todos: the action returns a Generator', $source, 'public function add(): \Generator');
has('todos: Eloquent in the action is commented out', $source, "// Todo::create([");
has('todos: ...to the end of the statement', $source, "//     'user_id' => auth()->id(),");
has('todos: ...and noted', $all, 'add() used Todo, auth()');
has('todos: reset() has no equivalent', $all, "\$this->reset()");
has('todos: addError() is errors()->add()', $source, "\$this->errors()->add('column', 'No.')");
has('todos: a server-only #[Computed] is noted', $all, 'remaining() used Todo');
has('todos: a #[Computed] stays a computed property', $source, '#[Computed] public function remaining(): int');
has('todos: ...with no server method', $source, 'remainingOnServer', false);
has('todos: the signature keeps its line break', $source, "public function add(): \\Generator\n    {");
has('todos: render()\'s data is viewData()', $source, 'public function viewData(): array');
has('todos: ...returning something while its data is commented out', $source, "return []; // TODO(unscripted)");
has('todos: ...unpacked in the template', $source, '@php extract($this->viewData) @endphp');
has('todos: wire:submit prevents', $source, 'u-on:submit.prevent="add"');
has('todos: @csrf goes', $source, '@csrf', false);
has('todos: .live is the default', $source, 'u-model.debounce.300ms="title"');
has('todos: deferred wire:model is u-model', $source, 'u-model="query"');
has('todos: wire:target is noted', $all, 'u-loading="add"');
has('todos: string arguments in JSON quotes', $source, "u-on:click='sortBy(\"title\")'");
has('todos: a child component', $source, "@child('todo-item', ['todo' => \$todo], \$todo->id)");
has('todos: route() is marked', $source, '{{-- TODO(unscripted): route() only exist');

// It still parses as PHP.
$file = tempnam(sys_get_temp_dir(), 'conv');
file_put_contents($file, preg_replace('/\?>.*$/s', '', $source));
exec('php -l ' . escapeshellarg($file) . ' 2>&1', $lint, $status);
unlink($file);
echo $status === 0 ? "ok   todos: the class is valid PHP\n" : (++$failed && print("FAIL todos: the class isn't valid PHP\n" . implode("\n", $lint) . "\n$source\n"));

echo $failed ? "\n$failed failed\n" : "\nall passed\n";
exit($failed ? 1 : 0);
