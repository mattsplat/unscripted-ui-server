<?php

namespace Unscripted\UiServer;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * php artisan unscripted:convert counter            (App\Livewire\Counter)
 * php artisan unscripted:convert admin.users-table  (App\Livewire\Admin\UsersTable)
 * php artisan unscripted:convert "App\Livewire\Counter" --name=my-counter --dry-run
 *
 * Writes resources/components/<name>.blade.php from a Livewire component's class and view, and
 * lists what it couldn't convert (each is a TODO(unscripted) in the file). The Livewire
 * component is left as it was.
 */
final class ConvertCommand extends Command
{
    protected $signature = 'unscripted:convert
        {component : The Livewire component: its name (counter, admin.users-table) or its class}
        {--name= : The Unscripted component\'s name (default: the Livewire name, dots as dashes)}
        {--dry-run : Print the result instead of writing it}
        {--force : Overwrite a component that exists}';

    protected $description = 'Convert a Livewire component to an Unscripted one';

    public function handle(): int
    {
        $given = $this->argument('component');
        $class = class_exists($given) ? $given : 'App\\Livewire\\' . implode('\\', array_map(
            fn($part) => Str::studly($part), explode('.', $given)));
        if (!class_exists($class)) {
            $this->error("There's no Livewire component $given (looked for the class $class).");
            return self::FAILURE;
        }

        $file = (new ReflectionClass($class))->getFileName();
        $source = file_get_contents($file);
        if (!preg_match('/view\(\s*[\'"]([^\'"]+)[\'"]/', $source, $m)) {
            $this->error("$class's render() doesn't name its view: single-file and inline-view components aren't supported yet.");
            return self::FAILURE;
        }
        $viewFile = view()->getFinder()->find($m[1]);

        $result = (new LivewireConverter())->convert($source, file_get_contents($viewFile));

        $name = $this->option('name') ?: str_replace('.', '-', class_exists($given)
            ? Str::kebab(class_basename($class)) : $given);
        $target = rtrim(config('unscripted.components', resource_path('components')), '/') . "/$name.blade.php";

        if ($this->option('dry-run')) {
            $this->line($result['source']);
        } else {
            if (file_exists($target) && !$this->option('force')) {
                $this->error("$target exists: pass --force to overwrite it.");
                return self::FAILURE;
            }
            @mkdir(dirname($target), 0755, true);
            file_put_contents($target, $result['source']);
            $this->info('Wrote ' . str_replace(base_path() . '/', '', $target) . " from $class.");
            $this->line("Use it with @unscripted('$name').");
        }

        if ($result['notes'] === []) {
            $this->info('Nothing left to do by hand.');
        } else {
            $this->newLine();
            $this->warn(count($result['notes']) . ' to finish by hand (each is a TODO(unscripted) in the file, or noted here):');
            foreach ($result['notes'] as $note) {
                $this->line("  - $note");
            }
        }
        return self::SUCCESS;
    }
}
