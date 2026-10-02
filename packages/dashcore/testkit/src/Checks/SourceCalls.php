<?php

namespace Dashcore\Testkit\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Function calls in the app's own PHP that must not be there: debugging
 * helpers left behind, and env() outside config/. Parsed, not grepped, so a
 * method named dump() or a string containing "env(" is not a hit.
 */
class SourceCalls
{
    /**
     * @param  list<string>  $functions
     * @param  list<string>  $directories  relative to the app root
     * @return list<string> "path:line name()"
     */
    public function find(array $functions, array $directories, ?string $root = null): array
    {
        $root ??= base_path();
        $wanted = array_map('strtolower', $functions);
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $found = [];

        foreach ($directories as $directory) {
            $dir = $root.DIRECTORY_SEPARATOR.$directory;

            if (! is_dir($dir)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                try {
                    $ast = $parser->parse((string) file_get_contents($file->getPathname())) ?? [];
                } catch (Throwable) {
                    continue;
                }

                $relative = ltrim(substr($file->getPathname(), strlen($root)), DIRECTORY_SEPARATOR);

                foreach ($finder->findInstanceOf($ast, Node\Expr\FuncCall::class) as $call) {
                    if ($call->name instanceof Node\Name && in_array(strtolower($call->name->getLast()), $wanted, true)) {
                        $found[] = $relative.':'.$call->getStartLine().' '.$call->name->getLast().'()';
                    }
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Entries not covered by an allow list of "path:line" (or "path" for a
     * whole file).
     *
     * @param  list<string>  $found
     * @param  list<string>  $allow
     * @return list<string>
     */
    public function except(array $found, array $allow): array
    {
        return array_values(array_filter($found, function (string $entry) use ($allow) {
            $location = strtok($entry, ' ');
            $file = explode(':', (string) $location)[0];

            return ! in_array($location, $allow, true) && ! in_array($file, $allow, true);
        }));
    }
}
