<?php

namespace Unscripted\UiServer;

use Closure;
use Error;
use PhpToken;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use Unscripted\Ui\Component;
use Unscripted\Ui\Server;
use Unscripted\Ui\Validate;

use function Unscripted\Ui\blade;

/**
 * The app's components, `<name>.blade.php` files in one folder. The browser gets each with its
 * #[Server] methods kept back, and calls them through call().
 */
final class Components
{
    /** Each loaded component's source hash and [class, file, compiled files], by name. */
    private array $classes = [];

    /** `$cache` holds each component's PHP block and template, compiled to files of their own
     * (see make()), so opcache keeps them. */
    public function __construct(
        private string $folder,
        private string $cache,
        /** Checks `#[Server(can: ...)]`: called with the ability and the call's arguments, throws if it's denied. */
        private ?Closure $authorize = null,
        /** Checks `#[Validate]` parameters: called with their arguments and rules, by parameter
         * name, throws if they fail (Laravel's ValidationException, a 422). */
        private ?Closure $validate = null,
    ) {}

    /** The file as the browser gets it, or null if there's no component `$name`. */
    public function browserSource(string $name): ?string
    {
        $source = $this->source($name);
        return $source === null ? null : self::strip($source, $name);
    }

    /** Component `$name`'s file with its @include and <x-...> partials inlined, or null. */
    public function source(string $name): ?string
    {
        $path = $this->path($name);
        return $path === null ? null : $this->inline(file_get_contents($path), $name);
    }

    /**
     * `$source` with each `@include('row', [...])` and `<x-row :a="$b">slot</x-row>` in its
     * template replaced by that partial (`<folder>/row.blade.php`, a template with no PHP
     * block), run in a function of its own: it sees the template's variables and the ones
     * given (an <x-...>'s attributes, kebab-case made camelCase; `:attr` is PHP), and `$slot`.
     * Inlined here because the browser can't fetch files in the middle of a render. Lines shift
     * below an inlined partial, so an error's line there is approximate.
     */
    private function inline(string $source, string $name, int $depth = 0): string
    {
        if ($depth > 10) {
            throw new Error("$name: partials nested over 10 deep (one includes itself?)");
        }
        $end = str_starts_with(ltrim($source), '<?php') ? strpos($source, '?>') : false;
        $block = $end === false ? '' : substr($source, 0, $end + 2);
        $template = substr($source, strlen($block));
        $parens = '(\((?:[^()\'"]++|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+"|(?-1))*\))';
        $again = false;

        $template = preg_replace_callback('/@include\s*' . $parens . '/', function ($m) use ($name, &$again) {
            if (!preg_match('/^\(\s*([\'"])([\w.\/-]+)\1\s*(?:,(.*))?\)$/s', $m[1], $args)) {
                throw new Error("$name: @include needs a partial's name in quotes, then maybe an array: {$m[0]}");
            }
            $again = true;
            return $this->partial($args[2], trim($args[3] ?? '') === '' ? '[]' : $args[3], null, $name);
        }, $template);

        // <x-name ...>slot</x-name> (innermost first) and <x-name ... />.
        $attributes = '((?:\s+:?[\w.-]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'))?)*)\s*';
        do {
            $before = $template;
            $template = preg_replace_callback('/<x-([\w.-]+)' . $attributes . '(?:\/>|>((?:(?!<x-).)*?)<\/x-\1\s*>)/s', function ($m) use ($name, &$again) {
                $again = true;
                return $this->partial($m[1], self::props($m[2]), $m[3] ?? null, $name);
            }, $template);
        } while ($template !== $before);

        $source = $block . $template;
        return $again ? $this->inline($source, $name, $depth + 1) : $source;
    }

    /** A partial's template, run in a function with `$vars` (PHP for an array) and the
     * template's own variables, and `$slot` (Blade) captured first. */
    private function partial(string $partial, string $vars, ?string $slot, string $from): string
    {
        $path = $this->path(str_replace(['.', '/'], '-', $partial)) ?? $this->path($partial)
            ?? throw new Error("$from: there's no partial $partial ({$this->folder}/$partial.blade.php)");
        $template = file_get_contents($path);
        if (str_starts_with(ltrim($template), '<?php')) {
            throw new Error("$from: $partial has a PHP block, so it's a component, not a partial: include templates only");
        }
        $capture = $slot === null ? "<?php \$__slot = ''; ?>" : "<?php ob_start(); ?>$slot<?php \$__slot = ob_get_clean(); ?>";
        return $capture . "<?php (function (array \$__vars) { extract(\$__vars); ?>$template<?php })->call(\$this, [...get_defined_vars(), 'slot' => \$__slot, ...($vars)]); ?>";
    }

    /** An <x-...>'s attributes as a PHP array: `a="text"` a string, `:a="expr"` PHP, `a` true. */
    private static function props(string $attributes): string
    {
        preg_match_all('/(:?)([\w.-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'))?/', $attributes, $found, PREG_SET_ORDER);
        $items = [];
        foreach ($found as $m) {
            $key = lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '.'], ' ', $m[2]))));
            $value = ($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '');
            $items[] = var_export($key, true) . ' => ' . ($m[1] === ':' ? "($value)" : (isset($m[3]) || isset($m[4]) ? var_export($value, true) : 'true'));
        }
        return '[' . implode(', ', $items) . ']';
    }

    /**
     * `$source` with each public #[Server] method's body swapped for a stub that returns a
     * ServerCall, and private and protected #[Server] methods (server-only helpers) removed.
     * Lines are kept, so the browser's line numbers are the file's.
     */
    public static function strip(string $source, string $name): string
    {
        $tokens = PhpToken::tokenize($source);
        $out = '';
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $method = $tokens[$i]->is(T_ATTRIBUTE) ? self::method($tokens, $i) : null;
            if ($method === null || !$method['server']) {
                $out .= $tokens[$i]->text;
                continue;
            }
            $where = "$name.blade.php:{$tokens[$i]->line}";
            if (!$method['static']) {
                throw new Error("#[Server] method {$method['name']} must be static, in $where: it gets only its arguments, not the component's state");
            }
            $stub = '';
            if ($method['public']) {
                $stub = "{$method['attributes']} public static function {$method['name']}{$method['params']}: \\Unscripted\\Ui\\ServerCall"
                    . ' { return new \Unscripted\Ui\ServerCall(' . var_export($name, true) . ", '{$method['name']}', func_get_args()); }";
            }
            $original = implode('', array_map(fn($t) => $t->text, array_slice($tokens, $i, $method['end'] - $i + 1)));
            $out .= $stub . str_repeat("\n", max(0, substr_count($original, "\n") - substr_count($stub, "\n")));
            $i = $method['end'];
        }
        return $out;
    }

    /**
     * The method whose attributes start at `$tokens[$start]`, or null if they're not a method's
     * (a parameter's, a property's).
     */
    private static function method(array $tokens, int $start): ?array
    {
        $attributes = [];
        $server = $static = false;
        $public = true;
        for ($i = $start, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if ($token->isIgnorable()) {
                continue;
            }
            if ($token->is(T_ATTRIBUTE)) {
                // To the group's closing ]. Attribute names are at depth 1, outside parentheses.
                $group = '';
                for ($depth = 0, $parens = 0; $i < $n; $i++) {
                    $t = $tokens[$i];
                    $group .= $t->text;
                    $depth += match (true) { $t->is([T_ATTRIBUTE, '[']) => 1, $t->is(']') => -1, default => 0 };
                    $parens += match (true) { $t->is('(') => 1, $t->is(')') => -1, default => 0 };
                    if ($depth === 1 && $parens === 0 && $t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                        && ($t->text === 'Server' || str_ends_with($t->text, '\Server'))) {
                        $server = true;
                    }
                    if ($depth === 0) {
                        break;
                    }
                }
                $attributes[] = $group;
                continue;
            }
            if ($token->is([T_PRIVATE, T_PROTECTED])) {
                $public = false;
            } elseif ($token->is(T_STATIC)) {
                $static = true;
            } elseif ($token->is(T_FUNCTION)) {
                break;
            } elseif (!$token->is([T_PUBLIC, T_FINAL, T_ABSTRACT])) {
                return null;
            }
        }

        // function name(params): type { body }
        $name = null;
        $params = '';
        for ($i++; $i < $n && $name === null; $i++) {
            if ($tokens[$i]->is(T_STRING)) {
                $name = $tokens[$i]->text;
            }
        }
        for ($depth = 0; $i < $n; $i++) {
            $params .= $tokens[$i]->text;
            $depth += match (true) { $tokens[$i]->is('(') => 1, $tokens[$i]->is(')') => -1, default => 0 };
            if ($depth === 0 && $tokens[$i]->is(')')) {
                break;
            }
        }
        $params = ltrim($params);
        for ($i++; $i < $n && !$tokens[$i]->is(['{', ';']); $i++);
        if ($i < $n && $tokens[$i]->is('{')) {
            for ($depth = 0; $i < $n; $i++) {
                $depth += match (true) {
                    $tokens[$i]->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) => 1,
                    $tokens[$i]->is('}') => -1,
                    default => 0,
                };
                if ($depth === 0) {
                    break;
                }
            }
        }
        if ($name === null || $i >= $n) {
            return null;
        }
        return [
            'name' => $name,
            'params' => $params,
            'attributes' => implode(' ', $attributes),
            'server' => $server,
            'static' => $static,
            'public' => $public,
            'end' => $i,
        ];
    }

    /** Makes a request's calls, `[{"component", "method", "args"}, ...]`, in order: their
     * results. Throws BadArguments if they aren't calls (1 to 20 of them), as call() does. */
    public function run(mixed $calls): array
    {
        if (!is_array($calls) || !array_is_list($calls) || $calls === [] || count($calls) > 20) {
            throw new BadArguments('expected {"calls": [...]}, 1 to 20 of them');
        }
        $results = [];
        foreach ($calls as $call) {
            if (!is_string($call['component'] ?? null) || !is_string($call['method'] ?? null)
                || !is_array($call['args'] ?? null) || !array_is_list($call['args'])) {
                throw new BadArguments('each call needs a component, a method and a list of args');
            }
            $results[] = $this->call($call['component'], $call['method'], $call['args']);
        }
        return $results;
    }

    /** Calls component `$name`'s #[Server] method `$method`. Throws UnknownCall if there's no
     * such method, or BadArguments if `$args` don't fit it. */
    public function call(string $name, string $method, array $args, bool $drain = true): mixed
    {
        [$class, $path, $compiled] = $this->load($name);
        if (!method_exists($class, $method)) {
            throw new UnknownCall("$name has no server method $method");
        }
        $reflection = new ReflectionMethod($class, $method);
        $server = $reflection->getAttributes(Server::class)[0] ?? null;
        if ($server === null || !$reflection->isPublic() || !$reflection->isStatic()) {
            throw new UnknownCall("$name has no server method $method");
        }
        $can = $server->newInstance()->can;
        if ($can !== null) {
            if ($this->authorize === null) {
                throw new Error("$name::$method has #[Server(can: '$can')], but nothing to check it with");
            }
            ($this->authorize)($can, array_values($args));
        }
        $this->validated($name, $reflection, array_values($args));
        try {
            $result = $reflection->invokeArgs(null, array_values($args));
            // A generator streams; asked for its result, it's run through: its return value,
            // or what it yielded, joined.
            return $result instanceof \Generator && $drain ? self::drain($result) : $result;
        } catch (\TypeError $e) {
            // The arguments' fault, not a TypeError inside the method: PHP reports both from the
            // method's frame, but the arguments' at the line it's declared on. The anonymous
            // class's name (the file's path) isn't for the browser.
            $trace = $e->getTrace();
            if (($trace[0]['function'] ?? null) === $method && ($trace[1]['function'] ?? null) === 'invokeArgs'
                && $e->getLine() === $reflection->getStartLine()
                && preg_match('/^(?:.*?\(\): (Argument #.*)|Too few arguments to function .*?\(\), (.*))$/s', $e->getMessage(), $m)) {
                throw new BadArguments("$name::$method(): " . ($m[1] !== '' ? $m[1] : "too few arguments, {$m[2]}"), 0, $e);
            }
            throw $this->located($e, $compiled, $path);
        } catch (Throwable $e) {
            throw $this->located($e, $compiled, $path);
        }
    }

    /**
     * Checks the arguments of `$method`'s #[Validate] parameters, keyed by `as` or the
     * parameter's name (a form's by field under it). A parameter that wasn't given is checked
     * with its default, if it has one; a variadic one's rules apply to each value. Messages name
     * the parameter (or the form's field), not the key.
     */
    private function validated(string $name, ReflectionMethod $method, array $args): void
    {
        $data = $rules = $attributes = [];
        foreach ($method->getParameters() as $i => $parameter) {
            $validate = $parameter->getAttributes(Validate::class)[0] ?? null;
            if ($validate === null) {
                continue;
            }
            $validate = $validate->newInstance();
            $key = $validate->as ?? $parameter->name;
            $label = str_replace('_', ' ', $parameter->name);
            if ($parameter->isVariadic()) {
                $data[$key] = array_slice($args, $i);
                $rules["$key.*"] = $validate->rules;
                $attributes["$key.*"] = $label;
                continue;
            }
            if (array_key_exists($i, $args)) {
                $data[$key] = $args[$i];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $data[$key] = $parameter->getDefaultValue();
            }
            if ($validate->byField()) {
                foreach ($validate->rules as $field => $fieldRules) {
                    $rules["$key.$field"] = $fieldRules;
                    $attributes["$key.$field"] = str_replace('_', ' ', (string) $field);
                }
            } else {
                $rules[$key] = $validate->rules;
                $attributes[$key] = $label;
            }
        }
        if ($rules === []) {
            return;
        }
        if ($this->validate === null) {
            throw new Error("$name::{$method->name} has #[Validate] parameters, but nothing to check them with");
        }
        ($this->validate)($data, $rules, $attributes);
    }

    /** A generator run through, for a call that isn't streamed: the text it yielded, and its
     * result (its return value, or the text), which the browser unpacks. */
    private static function drain(\Generator $stream): array
    {
        $text = '';
        foreach ($stream as $chunk) {
            $text .= $chunk;
        }
        return ['unscripted:stream' => ['text' => $text, 'result' => $stream->getReturn() ?? $text]];
    }

    /** An error from a compiled file, said to be from the component's file: the line is the
     * same. */
    private function located(Throwable $e, array $compiled, string $path): Throwable
    {
        if (in_array($e->getFile(), $compiled, true)) {
            new ReflectionProperty($e, 'file')->setValue($e, $path);
        }
        return $e;
    }

    private function path(string $name): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            return null;
        }
        $path = "{$this->folder}/$name.blade.php";
        return is_file($path) ? $path : null;
    }

    /**
     * Component `$name`'s first HTML, its #[Watch] loaders run first (through call(), so `can`
     * is checked), and its state: `['html' => ..., 'state' => [...]]`.
     */
    public function render(string $name, array $query = [], array $props = []): array
    {
        [$component, $path, $compiled] = $this->make($name);
        // Its @child components render with it, each with its own loaders and state.
        $previous = Component::$prerenderChild;
        Component::$prerenderChild = fn(string $child, array $props) => $this->render($child, [], $props);
        try {
            return $component->with($props)->prerender(fn(string $method, array $args) => $this->call($name, $method, $args), $query);
        } catch (Throwable $e) {
            throw $this->located($e, $compiled, $path);
        } finally {
            Component::$prerenderChild = $previous;
        }
    }

    /**
     * What @unscripted('name') puts in a page: the component rendered into a <div>, and the
     * <script> that mounts it there once PHP has loaded in the browser, picking up from the
     * state it was rendered with. `$src` is where the browser gets the component; `$socket`,
     * if calls go over a WebSocket, says where (see UiServiceProvider::socket()).
     *
     * `$options`: `id`, the root's (`unscripted-<name>` by default, so give one to put a
     * component on a page twice); `lazy`, to skip the server render and show `placeholder`
     * until the browser has it (its loaders run there); `query`, the request's, for #[Url]
     * properties; `endpoint`, where calls go over HTTP.
     */
    public function tag(string $name, string $src, ?array $socket = null, array $options = []): string
    {
        $id = $options['id'] ?? "unscripted-$name";
        if (!preg_match('/^[A-Za-z][\w-]*$/', $id)) {
            throw new \InvalidArgumentException("@unscripted: the id '$id' isn't one an element can have");
        }
        $attribute = fn(string $value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        if ($options['lazy'] ?? false) {
            $html = $options['placeholder'] ?? '';
            $state = null;
        } else {
            ['html' => $html, 'state' => $state] = $this->render($name, $options['query'] ?? []);
        }
        return "<div id=\"$id\">$html</div>\n"
            . '<script type="text/blade" src="' . $attribute($src) . "\" data-mount=\"#$id\""
            . ($state === null ? '' : ' data-state="' . $attribute(json_encode($state, JSON_THROW_ON_ERROR)) . '"')
            . ($socket === null ? '' : ' data-socket="' . $attribute(json_encode($socket, JSON_THROW_ON_ERROR)) . '"')
            . (isset($options['endpoint']) ? ' data-endpoint="' . $attribute($options['endpoint']) . '"' : '')
            . '></script>';
    }

    /** Component `$name`, to drive in a test without a browser: see ComponentTest. It's made
     * from the source the browser gets, stubs and all, so its server calls go through call()
     * as a page's do (`can`, #[Validate]). */
    public function test(string $name, array $query = []): ComponentTest
    {
        $path = $this->path($name) ?? throw new UnknownCall("there's no component $name");
        return new ComponentTest($this, $name, blade(self::strip($this->source($name), $name), $path), $query);
    }

    /** Component `$name`'s class, its file and its compiled files, for call(): made again only
     * when the file has changed. */
    private function load(string $name): array
    {
        $path = $this->path($name) ?? throw new UnknownCall("there's no component $name");
        $hash = md5_file($path);
        if (($this->classes[$name][0] ?? null) !== $hash) {
            [$component, $path, $compiled] = $this->make($name);
            $this->classes[$name] = [$hash, [$component::class, $path, $compiled]];
        }
        return $this->classes[$name][1];
    }

    /**
     * A new component `$name`, made by @unscripted/ui's blade() as in the browser, but from its
     * file as written (#[Server] methods and all), and with its PHP block and template compiled
     * to files (unscripted-<name>-<part>-<hash>.php in the cache, as Laravel compiles views) so
     * opcache keeps them. Returns it, its file, and the compiled files.
     */
    private function make(string $name): array
    {
        $path = $this->path($name) ?? throw new UnknownCall("there's no component $name");
        $source = $this->source($name);
        $hash = md5($source); // of the partials too: changing one compiles again
        $compiled = [];
        $component = blade($source, $path, function (string $code, string $part) use ($name, $hash, $path, &$compiled) {
            $file = "{$this->cache}/unscripted-$name-$part-$hash.php";
            if (!is_file($file)) {
                is_dir($this->cache) || mkdir($this->cache, 0775, true);
                foreach (glob("{$this->cache}/unscripted-$name-$part-*.php") as $old) {
                    unlink($old);
                }
                // A comment on the first line, so the lines stay the component file's.
                $code = preg_replace('/<\?php/', "<?php /* $path, compiled by unscripted/ui-server */", $code, 1);
                $temporary = tempnam($this->cache, $name);
                file_put_contents($temporary, $code);
                rename($temporary, $file);
            }
            $compiled[] = realpath($file); // as PHP names it in errors
            return (static fn() => require $file)();
        });
        return [$component, $path, $compiled];
    }
}

/** A call to something that isn't a component's #[Server] method. */
final class UnknownCall extends RuntimeException {}

/** A server method called with arguments that don't fit it. */
final class BadArguments extends RuntimeException {}
