<?php

namespace App\Scanning\Inventory;

use App\Enums\SurfaceKind;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Livewire components that live in Blade files: Volt components and Livewire
 * 4 single-file components. They are anonymous classes, so the only thing a
 * test leaves behind to match them by is the component's name — and Livewire
 * names them by path, relative to whichever directory registered them.
 *
 * Rather than re-implement every registration rule, each file carries the
 * names it could plausibly answer to; the analyser accepts any of them.
 */
class ViewComponentInventory
{
    /** Directories under resources/views that Volt and Livewire register by default. */
    private const MOUNTS = ['livewire', 'pages', 'components', 'layouts'];

    private const LIFECYCLE = ['mount', 'boot', 'booted', 'render', 'rendering', 'rendered', 'rules', 'messages', 'with', 'placeholder', 'exception'];

    private const PREFIXED_HOOKS = ['hydrate', 'dehydrate', 'updating', 'updated', 'mount', 'boot'];

    /** @var list<string> */
    public array $errors = [];

    /**
     * @return list<DiscoveredSurface>
     */
    public function discover(string $root): array
    {
        $views = $root.'/resources/views';

        if (! is_dir($views)) {
            return [];
        }

        $surfaces = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $pathname = $file->getPathname();

            if (! str_ends_with($pathname, '.blade.php') || str_contains($pathname, '/views/vendor/')) {
                continue;
            }

            $contents = (string) file_get_contents($pathname);

            if (! str_contains($contents, 'Livewire') || ! preg_match('/new\s+class\b|\bstate\(|\bVolt\\\\/', $contents)) {
                continue;
            }

            $relative = substr($pathname, strlen($views) + 1, -strlen('.blade.php'));
            $path = 'resources/views/'.$relative.'.blade.php';
            $names = $this->names($relative);
            $label = $names[0];

            $surfaces[] = new DiscoveredSurface(
                kind: SurfaceKind::Component,
                identifier: $path,
                label: $label,
                file: $path,
                match: $names,
            );

            foreach ($this->actions($contents, $path) as $action => $lines) {
                $surfaces[] = new DiscoveredSurface(
                    kind: SurfaceKind::Action,
                    identifier: $path.'@'.$action,
                    label: $label.'@'.$action,
                    file: $path,
                    startLine: $lines['start'] ?? null,
                    endLine: $lines['end'] ?? null,
                    match: array_map(fn (string $name) => $name.'@'.$action, $names),
                    hash: $lines['hash'] ?? null,
                );
            }
        }

        return $surfaces;
    }

    /**
     * Every name this file could be registered under, most specific first:
     * relative to each default mount, with the `⚡` marker and a trailing
     * `index` dropped, and Livewire 4's `pages::` namespace form.
     *
     * @return list<string>
     */
    private function names(string $relative): array
    {
        $dotted = str_replace(['/', '⚡'], ['.', ''], $relative);
        $names = [];
        $first = explode('.', $dotted)[0];

        if (in_array($first, self::MOUNTS, true)) {
            $inner = substr($dotted, strlen($first) + 1);
            $names[] = $inner;

            if ($first !== 'livewire' && $first !== 'components') {
                $names[] = $first.'::'.$inner;
            }
        }

        $names[] = $dotted;

        foreach ($names as $name) {
            if (str_ends_with($name, '.index')) {
                $names[] = substr($name, 0, -strlen('.index'));
            }
        }

        return array_values(array_unique(array_filter($names, fn (string $n) => $n !== '')));
    }

    /**
     * Public methods of the anonymous class, or closures assigned to variables
     * in Volt's functional API — the things wire:click can call.
     *
     * A class component's methods carry their body's lines and a hash, so they
     * can be mutation-checked like any other method. The whole file is parsed
     * (the template is inline HTML to the parser) so the lines are the file's
     * own; functional closures have no method body to swap and carry none.
     *
     * @return array<string, array{start: int, end: int, hash: string}|array{}>
     */
    private function actions(string $contents, string $path): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse($contents) ?? [];
            $positioned = true;
        } catch (Throwable) {
            if (! preg_match('/<\?php(.*?)(\?>|$)/s', $contents, $m)) {
                return [];
            }

            try {
                $ast = $parser->parse('<?php'.$m[1]) ?? [];
                $positioned = false;
            } catch (Throwable $e) {
                $this->errors[] = $path.': '.$e->getMessage();

                return [];
            }
        }

        $finder = new NodeFinder;
        $actions = [];

        foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
            foreach ($class->getMethods() as $method) {
                $name = $method->name->toString();

                if (! $method->isPublic() || $method->isStatic() || str_starts_with($name, '__') || $this->isLifecycle($name)) {
                    continue;
                }

                $stmts = $method->stmts ?? [];

                $actions[$name] = $positioned && $stmts !== [] ? [
                    'start' => $stmts[0]->getStartLine(),
                    'end' => $method->getEndLine(),
                    'hash' => sha1(substr($contents, $method->getStartFilePos(), $method->getEndFilePos() - $method->getStartFilePos() + 1)),
                ] : [];
            }
        }

        // Only top-level assignments: a closure inside a method is not an action.
        foreach ($ast as $stmt) {
            if ($stmt instanceof Node\Stmt\Expression
                && $stmt->expr instanceof Node\Expr\Assign
                && $stmt->expr->var instanceof Node\Expr\Variable
                && is_string($stmt->expr->var->name)
                && ($stmt->expr->expr instanceof Node\Expr\Closure || $stmt->expr->expr instanceof Node\Expr\ArrowFunction)) {
                $actions[$stmt->expr->var->name] ??= [];
            }
        }

        return $actions;
    }

    private function isLifecycle(string $method): bool
    {
        $lower = strtolower($method);

        if (in_array($lower, self::LIFECYCLE, true)) {
            return true;
        }

        foreach (self::PREFIXED_HOOKS as $hook) {
            if (str_starts_with($lower, $hook)) {
                return true;
            }
        }

        return false;
    }
}
