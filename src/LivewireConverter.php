<?php

namespace Unscripted\UiServer;

use ReflectionClass;
use ReflectionFunction;

/**
 * A Livewire component (its class and its view) as an Unscripted one: a single file, the class
 * in a `<?php ?>` block and the template under it. `php artisan unscripted:convert` runs it.
 *
 * What maps one to one is converted: wire:* attributes, <livewire:...> tags, #[Validate]
 * properties (into a #[Server] method's validated parameters), #[Computed], #[Url], #[On],
 * mount(), render()'s data, addError(). What can't be, it doesn't guess at: code that only
 * exists on the server (models, facades, Laravel's helpers) is commented out where it stood,
 * with a `TODO(unscripted)` saying where it has to go, because in Unscripted the component
 * runs in the browser and only #[Server] methods reach the server. Each TODO is in `notes`.
 */
final class LivewireConverter
{
    /** Livewire attributes with an Unscripted one of the same name and meaning. */
    private const SAME_ATTRIBUTES = ['Computed', 'Url', 'On'];

    /** Methods Unscripted's Component has: an action with one of these names would clash. */
    private const FRAMEWORK_METHODS = ['child', 'dispatch', 'errors', 'loading', 'mount', 'prerender', 'refresh',
        'unmount', 'with', 'within', 'failed', 'mounted', 'progress', 'redirect', 'render', 'rendered', 'rendering',
        'unmounted', 'validate'];

    /** Livewire's own $this-> methods, as Unscripted's (null: no equivalent, so a TODO). */
    private const THIS_METHODS = [
        'addError' => 'errors()->add', 'resetErrorBag' => 'errors()->clear', 'resetValidation' => 'errors()->clear',
        'dispatch' => 'dispatch', 'redirect' => 'redirect', 'validate' => 'validate', 'refresh' => 'refresh',
        'reset' => null, 'only' => null, 'all' => null, 'fill' => null, 'pull' => null, 'js' => null,
        'skipRender' => null, 'redirectRoute' => null, 'redirectAction' => null, 'redirectIntended' => null,
        'validateOnly' => null, 'stream' => null, 'getErrorBag' => null,
    ];

    /** @var list<string> */
    private array $notes = [];
    /** @var array<string, string> imported short name => full class name */
    private array $imports = [];

    /**
     * @return array{source: string, notes: list<string>}
     */
    public function convert(string $class, string $view): array
    {
        $this->notes = [];
        $parsed = $this->parseClass($class);
        $this->imports = $parsed['imports'];
        $bound = $this->boundProperties($view);

        $properties = [];
        $methods = [];
        $rules = [];          // property => its #[Validate] arguments
        $needs = ['Component' => true];
        $renderData = null;

        foreach ($parsed['members'] as $member) {
            if ($member['kind'] === 'trait') {
                $this->note("the class uses {$member['text']}: Livewire traits don't carry over (pagination is a loader watching \$page, uploads are u-model on a file input). Its line is commented out", $member);
                $properties[] = $this->commentOut($member['text'], 'a Livewire trait: see the notes');
                continue;
            }
            if ($member['kind'] === 'other') {
                $properties[] = $member['text'];
                continue;
            }
            [$attributes, $dropped] = $this->mapAttributes($member, $needs, $rules);

            if ($member['kind'] === 'property') {
                $name = $member['name'];
                if (in_array($name, $bound, true) && $member['public'] && !$dropped['Locked']) {
                    array_unshift($attributes, 'State');
                    $needs['State'] = true;
                }
                $properties[] = $this->withAttributes($member['indent'], $attributes, $member['declaration']);
                continue;
            }

            // A method.
            $name = $member['name'];
            if ($name === 'render') {
                $renderData = $this->renderData($member);
                continue;
            }
            $text = $member['declaration'];
            if ($name === 'mount') {
                if (trim($member['params']) !== '') {
                    $this->note("mount() takes ({$member['params']}): in Unscripted a parent passes those as props, which fill public properties of the same names. Its body is now mounted(), which runs after the first render", $member);
                }
                $text = preg_replace('/function\s+mount\s*\([^)]*\)(\s*:\s*\w+)?/', 'protected function mounted(): void', $text, 1);
                $text = preg_replace('/\bpublic\s+(?=protected function mounted)/', '', $text, 1);
            } elseif (in_array($name, self::FRAMEWORK_METHODS, true)) {
                $this->note("$name() has the name of one of Unscripted's own Component methods: rename it (and where the template calls it)", $member);
            }
            $methods[] = ['member' => $member, 'attributes' => $attributes, 'text' => $text];
        }

        // Validation: Livewire's rules are on properties and $this->validate() checks them on the
        // server. Here rules belong to a #[Server] method's parameters (checked in the browser as
        // people type, by u-model path, then on the server), so each action that validates gets
        // one, called with those properties.
        $server = [];
        $out = [];
        foreach ($methods as $method) {
            $member = $method['member'];
            $body = $method['text'];
            $validates = $rules !== [] && preg_match('/\$this->validate\(\s*\)\s*;/', $body);
            if (preg_match('/\$this->validate\(\s*[^)\s]/', $body)) {
                $this->note("{$member['name']}() calls \$this->validate() with its own rules: put them on a #[Server] method's parameters, #[Validate('...', as: 'property')]", $member);
            }
            $body = $this->thisMethods($body, $member);
            $serverOnly = $this->serverOnly($body);
            $body = $this->commentServerOnly($body, $serverOnly);

            $action = $member['public'] && !in_array('Computed', $method['attributes'], true)
                && !preg_match('/^(mount|boot|booted|hydrate|dehydrate|updating|updated|rendering|rendered|exception)/', $member['name']);
            if ($action && ($validates || $serverOnly !== [])) {
                $serverName = $member['name'] . 'OnServer';
                $needs['Server'] = true;
                $params = [];
                $args = [];
                if ($validates) {
                    $needs['Validate'] = true;
                    foreach ($rules as $property => $rule) {
                        $type = $parsed['types'][$property] ?? 'mixed';
                        $params[] = "        #[Validate($rule, as: '$property')] $type \$$property,";
                        $args[] = "\$this->$property";
                    }
                }
                $todo = $serverOnly === []
                    ? "            // Livewire ran {$member['name']}() on the server. Validation now happens here,\n            // before this runs: do any server work in it (it can't read \$this)."
                    : "            // TODO(unscripted): {$member['name']}() used " . implode(', ', $serverOnly) . ", which only exist on the server.\n            // That code is commented out in {$member['name']}() below: move it here, passing what it\n            // needs as arguments (this method can't read \$this), and check them as user input.";
                $server[] = "    #[Server]\n    public static function $serverName(" . ($params === [] ? '' : "\n" . implode("\n", $params) . "\n    ") . "): void\n    {\n" . str_replace('            ', '        ', $todo) . "\n    }";
                $call = "        yield static::$serverName(" . implode(', ', $args) . ");";
                if ($validates) {
                    $body = preg_replace('/^(\s*)\$this->validate\(\s*\)\s*;/m', "\$1if (!\$this->validate()) {\n\$1    return;\n\$1}\n$call", $body, 1);
                } else {
                    $body = preg_replace('/\{/', "{\n$call", $body, 1);
                }
                $body = preg_replace('/(function\s+' . preg_quote($member['name'], '/') . '\s*\([^)]*\))(\s*:\s*[\w\\\\?|]+)?/', '$1: \Generator', $body, 1);
                if ($serverOnly !== []) {
                    $this->note("{$member['name']}() used " . implode(', ', $serverOnly) . ": those lines are commented out, and $serverName() is where they go", $member);
                }
            } elseif ($serverOnly !== []) {
                $what = $member['kind'] === 'method' && in_array('Computed', $method['attributes'], true)
                    ? 'a #[Computed] property runs in the browser: load the data with a #[Server, Watch(...)] loader instead'
                    : 'it runs in the browser now: move that code into a #[Server] method and call it';
                $this->note("{$member['name']}() used " . implode(', ', $serverOnly) . ": $what", $member);
            }
            $out[] = $this->withAttributes($member['indent'], $method['attributes'], $body);
        }

        if ($renderData !== null) {
            $needs['Computed'] = true;
            $out[] = $renderData;
        }

        $template = $this->template($view, $renderData !== null);

        $use = array_keys($needs);
        sort($use);
        $use = array_values(array_unique(array_merge(['Component'], array_diff($use, ['Component']))));
        $imports = array_filter($parsed['importLines'], fn($line) => !preg_match('/^use\s+Livewire\\\\/', $line));
        $imports[] = count($use) === 1 ? 'use Unscripted\Ui\Component;' : 'use Unscripted\Ui\{' . implode(', ', $use) . '};';

        $members = array_filter([...$properties, ...$server, ...$out], fn($m) => trim($m) !== '');
        $source = "<?php\n" . implode("\n", $imports) . "\n\nnew class extends Component {\n"
            . implode("\n\n", array_map(fn($m) => rtrim($m), $members)) . "\n};\n?>\n\n" . trim($template) . "\n";

        return ['source' => $source, 'notes' => $this->notes];
    }

    /** The class: its imports and its members, each with its attributes, as written. */
    private function parseClass(string $source): array
    {
        $tokens = token_get_all($source);
        $imports = [];
        $importLines = [];
        $members = [];
        $types = [];
        $count = count($tokens);
        $text = fn($t) => is_array($t) ? $t[1] : $t;

        // Imports before the class.
        $i = 0;
        for (; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && $t[0] === T_CLASS) {
                break;
            }
            if (is_array($t) && $t[0] === T_USE) {
                $line = '';
                for (; $i < $count && $text($tokens[$i]) !== ';'; $i++) {
                    $line .= $text($tokens[$i]);
                }
                $line = trim(preg_replace('/\s+/', ' ', $line)) . ';';
                $importLines[] = $line;
                foreach ($this->importNames($line) as $short => $full) {
                    $imports[$short] = $full;
                }
            }
        }

        // The class body: from its first { to the matching }.
        for (; $i < $count && $text($tokens[$i]) !== '{'; $i++);
        $start = $i + 1;
        $depth = 0;
        $end = $start;
        for ($j = $i; $j < $count; $j++) {
            $s = $text($tokens[$j]);
            if ($s === '{' || (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($s === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $j;
                    break;
                }
            }
        }

        // Members: split at the class's top level, where a member ends at ; or at the } that
        // closes its body.
        $buffer = [];
        $depth = 0;
        $flush = function () use (&$buffer, &$members, &$types, $text) {
            $raw = implode('', array_map($text, $buffer));
            $buffer = [];
            if (trim($raw) === '') {
                return;
            }
            $members[] = $this->member($raw, $types);
        };
        for ($j = $start; $j < $end; $j++) {
            $t = $tokens[$j];
            $s = $text($t);
            $buffer[] = $t;
            if ($s === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($s === '}') {
                $depth--;
                if ($depth === 0) {
                    $flush();
                }
            } elseif ($s === ';' && $depth === 0) {
                $flush();
            }
        }
        $flush();

        return ['imports' => $imports, 'importLines' => $importLines, 'members' => $members, 'types' => $types];
    }

    /** One member's text as a property, method, trait use or something else (a constant). */
    private function member(string $raw, array &$types): array
    {
        // Leading blank lines go; the member's own indentation stays.
        $raw = preg_replace('/^\s*\n/', '', $raw);
        $raw = rtrim($raw);
        preg_match('/^[ \t]*/', $raw, $m);
        $indent = $m[0];
        $line = $this->lineOf($raw);

        // Attributes (#[...]) before it, at its top level.
        $attributes = [];
        $rest = ltrim($raw);
        $doc = '';
        while (true) {
            if (preg_match('/^(\/\*\*.*?\*\/|\/\/[^\n]*)\s*/s', $rest, $c)) {
                $doc .= $indent . trim($c[1]) . "\n";
                $rest = substr($rest, strlen($c[0]));
                continue;
            }
            if (str_starts_with($rest, '#[')) {
                $close = $this->closing($rest, 1, '[', ']');
                $attributes = [...$attributes, ...$this->splitTop(substr($rest, 2, $close - 2))];
                $rest = ltrim(substr($rest, $close + 1));
                continue;
            }
            break;
        }

        $base = ['raw' => $raw, 'indent' => $indent, 'attributes' => $attributes, 'line' => $line, 'public' => false, 'name' => ''];
        if (preg_match('/^use\s+([^;]+);/', $rest, $u)) {
            return ['kind' => 'trait', 'text' => $indent . $rest] + $base;
        }
        if (preg_match('/^((?:(?:public|protected|private|static|readonly|final|abstract)\s+)*)function\s+&?(\w+)\s*\(/', $rest, $f)) {
            $open = strpos($rest, '(', strlen($f[0]) - 1);
            $close = $this->closing($rest, $open, '(', ')');
            return ['kind' => 'method', 'name' => $f[2], 'public' => !preg_match('/protected|private/', $f[1]),
                'params' => substr($rest, $open + 1, $close - $open - 1),
                'declaration' => $doc . $indent . $rest] + $base;
        }
        if (preg_match('/^((?:(?:public|protected|private|static|readonly|var)\s+)+)([?\w\\\\|]+\s+)?\$(\w+)/', $rest, $p)) {
            if (trim($p[2] ?? '') !== '') {
                $types[$p[3]] = trim($p[2]);
            }
            return ['kind' => 'property', 'name' => $p[3], 'public' => str_contains($p[1], 'public'),
                'declaration' => $doc . $indent . $rest] + $base;
        }
        return ['kind' => 'other', 'text' => $doc . $indent . $rest] + $base;
    }

    /**
     * The member's attributes as Unscripted's: the same ones kept, #[Validate] collected into
     * `$rules` (it moves to a server method), the rest dropped with a note.
     *
     * @return array{0: list<string>, 1: array{Locked: bool}}
     */
    private function mapAttributes(array $member, array &$needs, array &$rules): array
    {
        $kept = [];
        $dropped = ['Locked' => false];
        foreach ($member['attributes'] as $attribute) {
            preg_match('/^\\\\?([\w\\\\]+)\s*(?:\((.*)\))?$/s', trim($attribute), $a);
            $short = substr(strrchr('\\' . $a[1], '\\'), 1);
            $full = $this->imports[$short] ?? $a[1];
            $livewire = str_starts_with(ltrim($full, '\\'), 'Livewire\\');
            if (!$livewire) {
                $kept[] = trim($attribute);
                continue;
            }
            if (in_array($short, self::SAME_ATTRIBUTES, true)) {
                $kept[] = trim($attribute);
                $needs[$short] = true;
                continue;
            }
            match ($short) {
                'Validate', 'Rule' => $member['kind'] === 'property'
                    ? $rules[$member['name']] = $this->firstArgument($a[2] ?? "''", $member)
                    : $this->note("#[$short] on {$member['name']}(): put the rules on a #[Server] method's parameters", $member),
                'Locked' => [$dropped['Locked'] = true, $this->note("\${$member['name']} was #[Locked]: it has no #[State] here, so inputs can't write it, but the browser owns all state: don't trust it in server methods", $member)],
                'Reactive' => null, // props are always reactive here
                'Title', 'Layout' => $this->note("#[$short] isn't needed: the page around the component is yours (Route::unscripted() takes a title)", $member),
                'Renderless' => $this->note("#[Renderless] on {$member['name']}() was dropped: actions here always render (in the browser, so it's cheap)", $member),
                default => $this->note("#[$short] on {$member['name']} has no Unscripted equivalent and was dropped", $member),
            };
        }
        return [$kept, $dropped];
    }

    /** #[Validate]'s rules, without its other arguments (messages and the like). */
    private function firstArgument(string $arguments, array $member): string
    {
        $parts = $this->splitTop($arguments);
        foreach ($parts as $part) {
            if (!preg_match('/^\w+\s*:/', trim($part))) {
                if (count($parts) > 1) {
                    $this->note("#[Validate] on \${$member['name']} had other arguments besides its rules: only the rules were kept", $member);
                }
                return trim($part);
            }
            if (preg_match('/^rule\s*:\s*(.+)$/s', trim($part), $r)) {
                return trim($r[1]);
            }
        }
        return "''";
    }

    /** render(): its data, as a #[Computed] viewData() the template unpacks; null if it has none. */
    private function renderData(array $member): ?string
    {
        $body = $member['declaration'];
        $open = strpos($body, '{');
        $inner = substr($body, $open + 1, strrpos($body, '}') - $open - 1);
        if (!preg_match('/return\s+view\(\s*([\'"])[^\'"]+\1\s*(?:,\s*(.+?))?\)\s*(->with\((.+)\))?\s*;\s*$/s', trim($inner), $r)) {
            if (!preg_match('/return\s+view\(/', $inner)) {
                $this->note('render() doesn\'t return view(...): there\'s no render() here (the template is under the class); move what it did into #[Computed] methods', $member);
            }
            return null;
        }
        $data = trim($r[2] ?? '') !== '' ? trim($r[2]) : (isset($r[4]) ? trim($r[4]) : '');
        if ($data === '' || $data === '[]') {
            return null;
        }
        $before = rtrim(substr(trim($inner), 0, strrpos(trim($inner), 'return')));
        $method = "    // What render() gave the view; the template unpacks it.\n    #[Computed]\n    public function viewData(): array\n    {\n"
            . ($before === '' ? '' : "        " . trim($before) . "\n\n") . "        return $data;\n    }";
        $serverOnly = $this->serverOnly($method);
        if ($serverOnly !== []) {
            $this->note('render() used ' . implode(', ', $serverOnly) . ' to build the view\'s data, which only exist on the server: load that data with a #[Server, Watch(...)] loader (a static method that fills a property, again when the properties it watches change) and work from the property in viewData()', $member);
            $method = $this->commentServerOnly($method, $serverOnly);
            if (!preg_match('/^\s*return\b/m', $method)) {
                $method = preg_replace('/\n    \}$/', "\n        return []; // TODO(unscripted): the view's data, once it's loaded\n    }", $method);
            }
        }
        return $method;
    }

    /** Livewire's $this-> methods: renamed where Unscripted has one, a TODO where it doesn't. */
    private function thisMethods(string $body, array $member): string
    {
        return preg_replace_callback('/\$this->(\w+)\(/', function ($m) use ($member) {
            if (!array_key_exists($m[1], self::THIS_METHODS)) {
                return $m[0];
            }
            $to = self::THIS_METHODS[$m[1]];
            if ($to === null) {
                $this->note("{$member['name']}() calls Livewire's \$this->{$m[1]}(), which Unscripted doesn't have", $member);
                return $m[0];
            }
            return "\$this->$to(";
        }, $body);
    }

    /**
     * What `$code` uses that the browser doesn't have: classes from outside PHP itself (models,
     * facades, the app's own) and functions defined outside PHP (Laravel's helpers).
     *
     * @return list<string>
     */
    private function serverOnly(string $code): array
    {
        $found = [];
        $tokens = token_get_all("<?php $code");
        $significant = array_values(array_filter($tokens, fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        foreach ($significant as $k => $t) {
            if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $prev = $significant[$k - 1] ?? null;
            $next = $significant[$k + 1] ?? null;
            $prevText = is_array($prev) ? $prev[1] : $prev;
            $nextText = is_array($next) ? $next[1] : $next;
            if (in_array($prevText, ['->', '?->', '::', 'function', 'const'], true)) {
                continue;
            }
            $name = $t[1];
            if ($nextText === '::' || $prevText === 'new' || $prevText === 'instanceof') {
                if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
                    continue;
                }
                $full = ltrim($this->imports[strtok($name, '\\')] ?? $name, '\\');
                if (str_contains($name, '\\') && isset($this->imports[strtok($name, '\\')])) {
                    $full = $this->imports[strtok($name, '\\')] . substr($name, strlen(strtok($name, '\\')));
                }
                if (str_starts_with($full, 'Unscripted\\') || $this->internalClass($full)) {
                    continue;
                }
                $found[$name] = $name;
            } elseif ($nextText === '(' && $prevText !== 'new') {
                $fn = ltrim($name, '\\');
                if (in_array(strtolower($fn), ['isset', 'empty', 'unset', 'list', 'array', 'exit', 'die', 'eval', 'match', 'fn', 'function', 'if', 'elseif', 'while', 'for', 'foreach', 'switch', 'return', 'echo', 'print', 'include', 'require', 'yield'], true)) {
                    continue;
                }
                if (function_exists($fn) && !(new ReflectionFunction($fn))->isInternal()) {
                    $found["$fn()"] = "$fn()";
                }
            }
        }
        return array_values($found);
    }

    private function internalClass(string $class): bool
    {
        foreach ([fn($c) => class_exists($c, false), fn($c) => interface_exists($c, false), fn($c) => enum_exists($c, false)] as $exists) {
            if ($exists($class)) {
                return (new ReflectionClass($class))->isInternal();
            }
        }
        return false;
    }

    /** Comments out each line using something from `$serverOnly`, with a TODO above it. */
    private function commentServerOnly(string $code, array $serverOnly): string
    {
        if ($serverOnly === []) {
            return $code;
        }
        $lines = explode("\n", $code);
        $out = [];
        $open = false;      // inside a statement already commented out, until its ;
        $indent = '';
        foreach ($lines as $line) {
            $uses = array_filter($serverOnly, fn($name) => preg_match('/(?<![\w$>:\\\\])' . preg_quote(rtrim($name, '()'), '/') . '\b' . (str_ends_with($name, '()') ? '\s*\(' : '') . '/', $line));
            if (($uses !== [] || $open) && !preg_match('/^\s*(public|protected|private|function|#\[|\/\/|\{|\})/', $line)) {
                if (!$open) {
                    preg_match('/^\s*/', $line, $ws);
                    $indent = $ws[0];
                    $out[] = $indent . '// TODO(unscripted): server-only (' . implode(', ', $uses) . '): see the notes';
                }
                $out[] = $indent . '// ' . (str_starts_with($line, $indent) ? substr($line, strlen($indent)) : ltrim($line));
                $open = !preg_match('/[;{}]\s*$/', rtrim($line));
                continue;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    private function commentOut(string $text, string $why): string
    {
        preg_match('/^\s*/', $text, $ws);
        return $ws[0] . "// TODO(unscripted): $why\n" . $ws[0] . '// ' . ltrim($text);
    }

    private function withAttributes(string $indent, array $attributes, string $declaration): string
    {
        if ($attributes === []) {
            return $declaration;
        }
        // After any doc comment, before the declaration.
        if (preg_match('/^((?:\s*(?:\/\*\*.*?\*\/|\/\/[^\n]*)\n)*)(.*)$/s', $declaration, $m)) {
            return $m[1] . $indent . '#[' . implode(', ', $attributes) . '] ' . ltrim($m[2]);
        }
        return $indent . '#[' . implode(', ', $attributes) . '] ' . ltrim($declaration);
    }

    /** The top-level names wire:model binds in the view: they need #[State]. */
    private function boundProperties(string $view): array
    {
        preg_match_all('/wire:model(?:\.[\w.-]+)*\s*=\s*["\']([\w]+)/', $view, $m);
        return array_values(array_unique($m[1]));
    }

    /** The view: wire:* as u-*, <livewire:...> as @child, TODOs where it uses server-only helpers. */
    private function template(string $view, bool $unpack): string
    {
        $lines = explode("\n", $view);
        foreach ($lines as $n => &$line) {
            $line = $this->livewireTags($line, $n + 1);
            $line = preg_replace_callback('/\bwire:([\w.:-]+)(?:\s*=\s*("([^"]*)"|\'([^\']*)\'))?/', fn($m) => $this->wire($m, $n + 1), $line);
            $line = preg_replace('/^\s*@csrf\s*$/', '', $line) ?? $line;
            $helpers = [];
            foreach (['route', 'auth', 'config', 'url', 'asset', 'session', 'request', 'old', 'trans', 'app', 'csrf_token', 'now', 'can'] as $helper) {
                if (preg_match('/(?<![\w$>:])' . $helper . '\s*\(/', $line)) {
                    $helpers[] = "$helper()";
                }
            }
            foreach (['@auth', '@guest', '@can', '@cannot', '@vite', '@livewireScripts', '@livewireStyles'] as $directive) {
                if (str_contains($line, $directive)) {
                    $helpers[] = $directive;
                }
            }
            if ($helpers !== []) {
                $this->notes[] = 'template line ' . ($n + 1) . ': ' . implode(', ', $helpers) . " isn't available in the browser: pass the value in as a prop or load it";
                preg_match('/^\s*/', $line, $ws);
                $line = $ws[0] . '{{-- TODO(unscripted): ' . implode(', ', $helpers) . ' only exist on the server --}}' . "\n" . $line;
            }
        }
        $view = implode("\n", $lines);
        return $unpack ? "@php extract(\$this->viewData) @endphp\n" . $view : $view;
    }

    /** One wire:* attribute as Unscripted's. */
    private function wire(array $m, int $line): string
    {
        [$name, $modifiers] = array_pad(explode('.', $m[1], 2), 2, '');
        $value = $m[3] ?? '';
        if (isset($m[4]) && $m[4] !== '') {
            $value = $m[4];
        }
        $hasValue = isset($m[2]);
        $mods = $modifiers === '' ? [] : explode('.', $modifiers);
        $attr = fn(string $n, ?string $v = null) => $v === null ? $n : (str_contains($v, '"') ? "$n='$v'" : "$n=\"$v\"");

        switch ($name) {
            case 'model':
                // u-model is live already (it writes locally, so there's nothing to save up).
                $mods = array_values(array_diff($mods, ['live', 'defer', 'lazy']));
                if (in_array('lazy', explode('.', $modifiers), true)) {
                    $mods[] = 'blur';
                }
                return $attr('u-model' . ($mods === [] ? '' : '.' . implode('.', $mods)), $value);
            case 'key':
                return $attr('u-key', $value);
            case 'loading':
                return $attr('u-loading' . ($mods === [] ? '' : '.' . implode('.', array_diff($mods, ['delay', 'shortest', 'short', 'long', 'longest']))), $hasValue ? $value : null);
            case 'target':
                $this->notes[] = "template line $line: wire:target=\"$value\" goes in u-loading's value: u-loading=\"$value\"";
                return '';
            case 'poll':
                return $attr('u-poll' . ($mods === [] ? '' : '.' . implode('.', $mods)), $hasValue && $value !== '' ? $this->action($value, $line) : 'refresh');
            case 'init':
            case 'confirm':
            case 'ignore':
            case 'navigate':
            case 'offline':
                return $attr("u-$name" . ($mods === [] ? '' : '.' . implode('.', $mods)), $hasValue ? ($name === 'init' ? $this->action($value, $line) : $value) : null);
            case 'dirty':
            case 'transition':
            case 'stream':
            case 'replace':
                $this->notes[] = "template line $line: wire:$name has no Unscripted equivalent and was removed" . ($name === 'transition' ? ' (use CSS)' : '');
                return '';
            case 'submit':
                if (!in_array('prevent', $mods, true)) {
                    array_unshift($mods, 'prevent'); // Livewire's wire:submit always prevents
                }
                // fall through
            default:
                // Any DOM event: wire:click, wire:keydown.enter, ...
                return $attr("u-on:$name" . ($mods === [] ? '' : '.' . implode('.', $mods)), $this->action($value, $line));
        }
    }

    /** An action call as u-on takes it: strings in double quotes (JSON), Livewire's magics mapped. */
    private function action(string $value, int $line): string
    {
        $value = trim($value);
        if ($value === '$refresh') {
            return 'refresh';
        }
        if (str_starts_with($value, '$')) {
            $this->notes[] = "template line $line: \"$value\" is one of Livewire's magic actions: write a method for it";
            return $value;
        }
        if (!preg_match('/^(\w+)\s*(?:\((.*)\))?$/s', $value, $m)) {
            $this->notes[] = "template line $line: \"$value\" isn't a plain method call: make it one";
            return $value;
        }
        if (!isset($m[2]) || trim($m[2]) === '') {
            return $m[1] . (isset($m[2]) ? '()' : '');
        }
        $args = array_map(function ($arg) use ($line, $value) {
            $arg = trim($arg);
            if (preg_match("/^'((?:[^'\\\\]|\\\\.)*)'$/s", $arg, $s)) {
                return json_encode(stripslashes($s[1]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (!preg_match('/^(-?\d+(\.\d+)?|true|false|null|"[^"]*"|\{\{.*\}\})$/s', $arg)) {
                $this->notes[] = "template line $line: an argument in \"$value\" isn't a literal (numbers, strings, true, false, null, or {{ }}): pass a value instead";
            }
            return $arg;
        }, $this->splitTop($m[2]));
        return $m[1] . '(' . implode(', ', $args) . ')';
    }

    /** <livewire:name :prop="$x" /> and @livewire('name', [...]) as @child('name', [...], $key). */
    private function livewireTags(string $line, int $n): string
    {
        $line = preg_replace_callback('/<livewire:([\w.-]+)((?:\s+[:\w-]+(?:="[^"]*")?)*)\s*\/?>/', function ($m) use ($n) {
            preg_match_all('/(:?)([\w-]+)(?:="([^"]*)")?/', $m[2], $attrs, PREG_SET_ORDER);
            $props = [];
            $key = null;
            foreach ($attrs as $a) {
                $value = $a[1] === ':' ? $a[3] : var_export($a[3] ?? true, true);
                if ($a[2] === 'key' || $a[2] === 'wire:key') {
                    $key = $value;
                    continue;
                }
                $props[] = "'" . lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $a[2])))) . "' => $value";
            }
            $name = str_replace('.', '-', $m[1]);
            $this->notes[] = "template line $n: <livewire:{$m[1]}> is @child('$name'): convert that component too";
            return "@child('$name', [" . implode(', ', $props) . ']' . ($key === null ? '' : ", $key") . ')';
        }, $line);
        return preg_replace_callback('/@livewire\(\s*[\'"]([\w.-]+)[\'"]\s*(,.*)?\)/', function ($m) use ($n) {
            $name = str_replace('.', '-', $m[1]);
            $this->notes[] = "template line $n: @livewire('{$m[1]}') is @child('$name'): convert that component too";
            return "@child('$name'" . ($m[2] ?? '') . ')';
        }, $line);
    }

    /** "a, [b, c], 'd,e'" split at its top-level commas. */
    private function splitTop(string $s): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                $current .= $c;
                if ($c === '\\' && $i + 1 < $len) {
                    $current .= $s[++$i];
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif (in_array($c, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($c, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $c;
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }
        return $parts;
    }

    /** The index of the bracket closing the one at `$open`, skipping strings. */
    private function closing(string $s, int $open, string $o, string $c): int
    {
        $depth = 0;
        $quote = null;
        for ($i = $open, $len = strlen($s); $i < $len; $i++) {
            $ch = $s[$i];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === $o) {
                $depth++;
            } elseif ($ch === $c && --$depth === 0) {
                return $i;
            }
        }
        return $len - 1;
    }

    /** `use A\B;`, `use A\B as C;` and `use A\{B, C as D};` as short name => full name. */
    private function importNames(string $line): array
    {
        $line = trim(preg_replace('/^use\s+(function\s+|const\s+)?/', '', rtrim($line, ';')));
        $names = [];
        if (preg_match('/^(.*)\\\\\{(.*)\}$/s', $line, $g)) {
            foreach (explode(',', $g[2]) as $part) {
                $this->importOne(trim($g[1]) . '\\' . trim($part), $names);
            }
        } else {
            foreach (explode(',', $line) as $part) {
                $this->importOne(trim($part), $names);
            }
        }
        return $names;
    }

    private function importOne(string $part, array &$names): void
    {
        if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $part, $m)) {
            $names[$m[2]] = $m[1];
        } elseif ($part !== '') {
            $names[substr(strrchr('\\' . $part, '\\'), 1)] = $part;
        }
    }

    private function lineOf(string $raw): string
    {
        preg_match('/function\s+(\w+)|\$(\w+)/', $raw, $m);
        return $m[1] ?? $m[2] ?? '';
    }

    private function note(string $text, array $member): mixed
    {
        $this->notes[] = $text;
        return null;
    }
}
