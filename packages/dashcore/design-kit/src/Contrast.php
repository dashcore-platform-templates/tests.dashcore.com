<?php

namespace Dashcore\DesignKit;

/**
 * WCAG 2 contrast on the resolved tokens. Standalone on purpose: the kit must
 * be able to check a design file without the tool that produced it.
 */
final class Contrast
{
    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        [$hi, $lo] = $la > $lb ? [$la, $lb] : [$lb, $la];

        return round(($hi + 0.05) / ($lo + 0.05), 2);
    }

    /**
     * Every text role against the surface it sits on, both registers, plus the
     * status text shades. Same pairs the tool measures.
     *
     * @param  array<string,mixed>  $tokens
     * @return array{passes: bool, lowest: array{pair: string, ratio: float}|null, pairs: list<array{pair: string, ratio: float, passes: bool}>}
     */
    public static function measure(array $tokens): array
    {
        $pairs = [];
        $lowest = null;
        $passes = true;

        foreach (['light', 'dark'] as $register) {
            $r = $tokens[$register];
            $checks = [
                'text on page' => [$r['text'], $r['page']],
                'text on surface' => [$r['text'], $r['surface']],
                'text-strong on page' => [$r['text-strong'], $r['page']],
                'text-muted on page' => [$r['text-muted'], $r['page']],
                'text-muted on surface' => [$r['text-muted'], $r['surface']],
                'text-muted-on-sunken on sunken' => [$r['text-muted-on-sunken'] ?? $r['text-muted'], $r['sunken']],
                'accent-content on page' => [$r['accent-content'], $r['page']],
                'on-accent on accent' => [$r['on-accent'], $r['accent']],
            ];
            $mutedOnQuiet = $r['text-muted-on-sunken'] ?? $r['text-muted'];

            // The accent tint 04 puts behind selected rows and active list items.
            // accent-soft carries alpha, so it has to be composited before it can
            // be measured. Skipping this is how dark sat at 3.40:1 while every
            // design reported passing.
            $accentTint = self::over($r['accent'], $r['surface'], $register === 'dark' ? 0.16 : 0.10);
            $checks['accent-content on accent-soft'] = [$r['accent-content'], $accentTint];
            $checks['text-muted-on-sunken on accent-soft'] = [$mutedOnQuiet, $accentTint];

            foreach ($tokens['status'] ?? [] as $name => $s) {
                $soft = $s["soft-{$register}"];
                $checks["{$name} text on page"] = [$s["text-{$register}"], $r['page']];
                // The badge, and secondary text on a tinted row. Plain text-muted
                // fails the second of those in light, so 04 sends it to the
                // on-sunken step and both are measured here.
                $checks["{$name} text on {$name}-soft"] = [$s["text-{$register}"], $soft];
                $checks["text-muted-on-sunken on {$name}-soft"] = [$mutedOnQuiet, $soft];
            }
            foreach ($checks as $label => [$fg, $bg]) {
                $ratio = self::ratio($fg, $bg);
                $ok = $ratio >= 4.5;
                $passes = $passes && $ok;
                $pairs[] = ['pair' => "{$register}: {$label}", 'ratio' => $ratio, 'passes' => $ok];
                if ($lowest === null || $ratio < $lowest['ratio']) {
                    $lowest = ['pair' => "{$register}: {$label}", 'ratio' => $ratio];
                }
            }
        }

        return ['passes' => $passes, 'lowest' => $lowest, 'pairs' => $pairs];
    }

    /** One colour laid over another at an alpha, as a solid hex. */
    private static function over(string $top, string $bottom, float $alpha): string
    {
        $t = self::channels($top);
        $b = self::channels($bottom);
        $mix = [];

        foreach ([0, 1, 2] as $i) {
            $mix[] = (int) round($t[$i] * $alpha + $b[$i] * (1 - $alpha));
        }

        return sprintf('#%02x%02x%02x', ...$mix);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function channels(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $lin = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $lin[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }
}
