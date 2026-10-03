<?php

namespace App\Library;

/**
 * PHP source as HTML, coloured by PHP's own highlighter but with theme
 * classes instead of its fixed colours, so it reads in both light and dark.
 */
class Highlighter
{
    private const CLASSES = [
        'highlight.default' => ['#000001', 'hl-default'],
        'highlight.keyword' => ['#000002', 'hl-keyword'],
        'highlight.string' => ['#000003', 'hl-string'],
        'highlight.comment' => ['#000004', 'hl-comment'],
        'highlight.html' => ['#000005', 'hl-html'],
    ];

    public function highlight(string $source): string
    {
        $previous = [];

        foreach (self::CLASSES as $setting => [$sentinel]) {
            $previous[$setting] = ini_get($setting);
            ini_set($setting, $sentinel);
        }

        try {
            $html = highlight_string($source, true);
        } finally {
            foreach ($previous as $setting => $value) {
                ini_set($setting, (string) $value);
            }
        }

        foreach (self::CLASSES as [$sentinel, $class]) {
            $html = str_replace('style="color: '.$sentinel.'"', 'class="'.$class.'"', $html);
        }

        // Drop the outer <pre><code> PHP wraps it in; the view supplies its own.
        $html = preg_replace('#^<pre><code[^>]*>|</code></pre>$#', '', trim($html));

        return str_replace('<br />', "\n", (string) $html);
    }
}
