<?php

namespace Dashcore\Testkit\Checks;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * DashCore rule: every Livewire action gives feedback while it runs — no dead
 * space after a click. Read from the views: each wire:click, wire:submit or
 * wire:change that calls a method needs, in the same view, a wire:loading
 * element aimed at that method or at nothing in particular, or a
 * wire:loading.attr / .class on the element itself.
 */
class LoadingFeedback
{
    /**
     * @return list<string> "view-path@method"
     */
    public function missing(?string $root = null): array
    {
        $root ??= base_path();
        $views = $root.'/resources/views';

        if (! is_dir($views)) {
            return [];
        }

        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();

            if (! str_ends_with($path, '.blade.php') || str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);

            foreach ($this->unfedActions((string) file_get_contents($path)) as $method) {
                $found[] = $relative.'@'.$method;
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /** An opening tag, with `>` allowed inside quoted attribute values. */
    private const TAG = '/<[a-zA-Z][\w:.-]*(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s';

    /**
     * @return list<string>
     */
    public function unfedActions(string $view): array
    {
        preg_match_all(self::TAG, $view, $tags);

        $actions = [];
        $targeted = [];

        foreach ($tags[0] as $tag) {
            $isAction = (bool) preg_match('/\swire:(?:click|submit|change)(?:\.[\w.-]+)?="([^"]+)"/', $tag, $call);

            if ($isAction) {
                $actions[] = [$this->method($call[1]), $tag];
            }

            if (preg_match('/\swire:loading(?:[.=\s>]|$)/', $tag)) {
                if (preg_match('/\swire:target="([^"]*)"/', $tag, $target)) {
                    foreach (explode(',', $target[1]) as $method) {
                        $targeted[$this->method($method)] = true;
                    }
                } elseif (! $isAction) {
                    // A standalone indicator with no target shows for every action in the view.
                    return [];
                }
            }
        }

        $missing = [];

        foreach ($actions as [$method, $tag]) {
            if ($method === '' || str_starts_with($method, '$')) {
                continue;
            }

            // Feedback on the element itself: it disables or restyles while its action runs.
            if (preg_match('/\swire:loading(?:[.=\s>]|$)/', $tag) && ! preg_match('/\swire:target=/', $tag)) {
                continue;
            }

            if (! isset($targeted[$method])) {
                $missing[] = $method;
            }
        }

        return array_values(array_unique($missing));
    }

    private function method(string $call): string
    {
        return trim((string) preg_replace('/\(.*$/s', '', trim($call)));
    }
}
