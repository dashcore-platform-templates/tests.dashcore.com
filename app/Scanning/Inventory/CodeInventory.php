<?php

namespace App\Scanning\Inventory;

use App\Enums\SurfaceKind;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Every public method under `app/` that a suite owes a test, read from the
 * source rather than by booting the app.
 *
 * One surface per public method, because that is the unit a regression lands
 * in: a job whose `handle()` is tested but whose `failed()` never ran has an
 * untested failure path, and saying "the job is covered" would hide it.
 * Livewire components are listed here too, with their actions, because a
 * class component's action can be proven by its lines as well as its call.
 */
class CodeInventory
{
    /** Directories whose classes are wiring, not behaviour. */
    private const SKIP = ['Providers/', 'Enums/', 'Exceptions/', 'View/Components/'];

    /** Livewire hooks the framework calls; they are not actions a user takes. */
    private const LIFECYCLE = [
        'mount', 'boot', 'booted', 'hydrate', 'dehydrate', 'render', 'rendering', 'rendered',
        'updating', 'updated', 'exception', 'placeholder', 'rules', 'messages',
        'validationattributes', 'getlisteners', 'with',
    ];

    private const RELATIONS = [
        'hasOne', 'hasMany', 'belongsTo', 'belongsToMany', 'hasOneThrough', 'hasManyThrough',
        'morphTo', 'morphOne', 'morphMany', 'morphToMany', 'morphedByMany',
    ];

    private Parser $parser;

    /** @var list<string> */
    public array $errors = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * @return list<DiscoveredSurface>
     */
    public function discover(string $root): array
    {
        $classes = $this->classes($root);
        $parents = array_map(fn (array $c) => $c['parent'], $classes);
        $componentOnly = $this->componentOnlyTraits($classes, $parents);
        $surfaces = [];

        foreach ($classes as $fqcn => $class) {
            $isComponent = $this->extendsComponent($fqcn, $parents);
            $kind = $isComponent ? null : $this->kind($class['path']);

            if (! $isComponent && $kind === null) {
                continue;
            }

            // Its methods are listed as actions of each component using it.
            if (isset($componentOnly[$fqcn])) {
                continue;
            }

            if ($isComponent) {
                $class['methods'] = array_map(fn (array $m) => [...$m, 'path' => $class['path']], $class['methods']);
                $own = array_column($class['methods'], 'name');

                foreach ($this->traitMethods($class['traits'], $classes) as $method) {
                    if (! in_array($method['name'], $own, true)) {
                        $class['methods'][] = $method;
                        $own[] = $method['name'];
                    }
                }
            }

            if ($isComponent && ! $class['abstract']) {
                $surfaces[] = new DiscoveredSurface(
                    kind: SurfaceKind::Component,
                    identifier: $fqcn,
                    label: $this->componentName($class['path']),
                    file: $class['path'],
                    match: [$fqcn, $this->componentName($class['path'])],
                );
            }

            foreach ($class['methods'] as $method) {
                if ($isComponent && $this->isLifecycle($method['name'])) {
                    continue;
                }

                if (! $isComponent && $kind === SurfaceKind::Model && $method['relation']) {
                    continue;
                }

                $surfaces[] = new DiscoveredSurface(
                    kind: $isComponent ? SurfaceKind::Action : $kind,
                    identifier: $fqcn.'::'.$method['name'],
                    label: ($isComponent ? $this->componentName($class['path']).'@' : $this->short($fqcn).'::').$method['name'],
                    file: $method['path'] ?? $class['path'],
                    startLine: $method['start'],
                    endLine: $method['end'],
                    match: $isComponent ? [$fqcn.'@'.$method['name'], $this->componentName($class['path']).'@'.$method['name']] : [],
                    hash: $method['hash'],
                );
            }
        }

        return $surfaces;
    }

    /**
     * @return array<string, array{path: string, parent: ?string, abstract: bool, trait: bool, traits: list<string>, methods: list<array{name: string, start: int, end: int, relation: bool, hash: string}>}>
     */
    private function classes(string $root): array
    {
        $dir = $root.'/app';

        if (! is_dir($dir)) {
            return [];
        }

        $classes = [];
        $finder = new NodeFinder;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = substr($file->getPathname(), strlen($root) + 1);

            $source = (string) file_get_contents($file->getPathname());

            try {
                $ast = $this->parser->parse($source) ?? [];
            } catch (Throwable $e) {
                $this->errors[] = $path.': '.$e->getMessage();

                continue;
            }

            $traverser = new NodeTraverser(new NameResolver);
            $ast = $traverser->traverse($ast);

            /** @var list<ClassLike> $found */
            $found = $finder->find($ast, fn (Node $n) => ($n instanceof Class_ && $n->name !== null) || $n instanceof Trait_);

            foreach ($found as $node) {
                $fqcn = $node->namespacedName?->toString() ?? (string) $node->name;

                $classes[$fqcn] = [
                    'path' => $path,
                    'parent' => $node instanceof Class_ ? $node->extends?->toString() : null,
                    'abstract' => $node instanceof Class_ && $node->isAbstract(),
                    'trait' => $node instanceof Trait_,
                    'traits' => array_values(array_map(
                        fn (Node\Name $name) => $name->toString(),
                        array_merge(...array_map(fn (Node\Stmt\TraitUse $use) => $use->traits, $node->getTraitUses())),
                    )),
                    'methods' => $this->methods($node, $source),
                ];
            }
        }

        return $classes;
    }

    /**
     * @return list<array{name: string, start: int, end: int, relation: bool, hash: string}>
     */
    private function methods(ClassLike $node, string $source): array
    {
        $methods = [];

        foreach ($node->getMethods() as $method) {
            if (! $method->isPublic() || $method->isAbstract() || str_starts_with($method->name->toString(), '__')) {
                continue;
            }

            $stmts = $method->stmts ?? [];

            if ($stmts === []) {
                continue;
            }

            $methods[] = [
                'name' => $method->name->toString(),
                'start' => $stmts[0]->getStartLine(),
                'end' => $method->getEndLine(),
                'relation' => $this->isRelation($method),
                'hash' => sha1(substr($source, $method->getStartFilePos(), $method->getEndFilePos() - $method->getStartFilePos() + 1)),
            ];
        }

        return $methods;
    }

    /**
     * A model method whose whole body is `return $this->hasMany(...)` and the
     * like. Relations are exercised by every test that touches the data;
     * listing them would bury the methods that hold logic.
     */
    private function isRelation(ClassMethod $method): bool
    {
        $stmts = $method->stmts ?? [];

        if (count($stmts) !== 1 || ! $stmts[0] instanceof Node\Stmt\Return_) {
            return false;
        }

        $expr = $stmts[0]->expr;

        while ($expr instanceof Node\Expr\MethodCall) {
            if ($expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this') {
                return $expr->name instanceof Node\Identifier && in_array($expr->name->toString(), self::RELATIONS, true);
            }

            $expr = $expr->var;
        }

        return false;
    }

    /**
     * The public methods traits give a class, traits of traits included, each
     * carrying the trait's file so its lines prove it.
     *
     * @param  list<string>  $traits
     * @param  array<string, array<string, mixed>>  $classes
     * @param  array<string, true>  $seen
     * @return list<array{name: string, start: int, end: int, relation: bool, hash: string, path: string}>
     */
    private function traitMethods(array $traits, array $classes, array $seen = []): array
    {
        $methods = [];

        foreach ($traits as $trait) {
            if (isset($seen[$trait]) || ! isset($classes[$trait])) {
                continue;
            }

            $seen[$trait] = true;

            foreach ($classes[$trait]['methods'] as $method) {
                $methods[] = [...$method, 'path' => $classes[$trait]['path']];
            }

            array_push($methods, ...$this->traitMethods($classes[$trait]['traits'], $classes, $seen));
        }

        return $methods;
    }

    /**
     * Traits used only by Livewire components (directly or through another
     * trait): they are listed through those components, as actions.
     *
     * @param  array<string, array<string, mixed>>  $classes
     * @param  array<string, ?string>  $parents
     * @return array<string, true>
     */
    private function componentOnlyTraits(array $classes, array $parents): array
    {
        $users = [];

        foreach ($classes as $fqcn => $class) {
            if ($class['trait']) {
                continue;
            }

            $isComponent = $this->extendsComponent($fqcn, $parents);
            $pending = $class['traits'];
            $seen = [];

            while ($pending !== []) {
                $trait = array_shift($pending);

                if (isset($seen[$trait]) || ! isset($classes[$trait])) {
                    continue;
                }

                $seen[$trait] = true;
                $users[$trait][] = $isComponent;
                array_push($pending, ...$classes[$trait]['traits']);
            }
        }

        return array_map(fn () => true, array_filter($users, fn (array $isComponent) => ! in_array(false, $isComponent, true)));
    }

    /**
     * @param  array<string, ?string>  $parents
     */
    private function extendsComponent(string $fqcn, array $parents): bool
    {
        $seen = [];
        $parent = $parents[$fqcn] ?? null;

        while ($parent !== null && ! isset($seen[$parent])) {
            if ($parent === 'Livewire\\Component' || $parent === 'Livewire\\Volt\\Component') {
                return true;
            }

            $seen[$parent] = true;
            $parent = $parents[$parent] ?? null;
        }

        return false;
    }

    private function isLifecycle(string $method): bool
    {
        $lower = strtolower($method);

        foreach (self::LIFECYCLE as $hook) {
            if ($lower === $hook || (in_array($hook, ['hydrate', 'dehydrate', 'updating', 'updated', 'mount', 'boot', 'rendering', 'rendered'], true) && str_starts_with($lower, $hook))) {
                return true;
            }
        }

        return false;
    }

    private function kind(string $path): ?SurfaceKind
    {
        $relative = substr($path, strlen('app/'));

        foreach (self::SKIP as $skip) {
            if (str_starts_with($relative, $skip)) {
                return null;
            }
        }

        return match (true) {
            str_starts_with($relative, 'Http/Controllers/') => SurfaceKind::Controller,
            str_starts_with($relative, 'Http/Middleware/') => SurfaceKind::Middleware,
            str_starts_with($relative, 'Jobs/') => SurfaceKind::Job,
            str_starts_with($relative, 'Console/Commands/') => SurfaceKind::Command,
            str_starts_with($relative, 'Listeners/'), str_starts_with($relative, 'Observers/') => SurfaceKind::Listener,
            str_starts_with($relative, 'Mail/') => SurfaceKind::Mail,
            str_starts_with($relative, 'Notifications/') => SurfaceKind::Notification,
            str_starts_with($relative, 'Policies/') => SurfaceKind::Policy,
            str_starts_with($relative, 'Rules/') => SurfaceKind::Rule,
            str_starts_with($relative, 'Models/') => SurfaceKind::Model,
            str_starts_with($relative, 'Mcp/Tools/') => SurfaceKind::McpTool,
            str_starts_with($relative, 'Services/'), str_starts_with($relative, 'Actions/'), str_starts_with($relative, 'Livewire/Actions/') => SurfaceKind::Service,
            default => SurfaceKind::Code,
        };
    }

    /**
     * Livewire's name for a class component: `app/Livewire/Settings/AiSettings.php`
     * is `settings.ai-settings`, and a trailing `Index` is dropped.
     */
    private function componentName(string $path): string
    {
        $relative = preg_replace('#^app/Livewire/|\.php$#', '', $path) ?? $path;
        $segments = array_map(
            fn (string $s) => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $s)),
            explode('/', $relative),
        );

        if (count($segments) > 1 && end($segments) === 'index') {
            array_pop($segments);
        }

        return implode('.', $segments);
    }

    private function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
