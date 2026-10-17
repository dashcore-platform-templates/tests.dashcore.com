<?php

namespace Dashcore\DesignKit;

/**
 * The ratchet. What an app has off the standard on the day it joins is
 * recorded as its allowance, in design/allowance.json, and from then on the
 * audit fails only when a rule's count goes up. The allowance only shrinks:
 * recording it again keeps the lower of the two counts for every rule.
 *
 * It is how an app that looks nothing like the standard can be held to it
 * from day one without its build going red, and how a new rule reaches every
 * app without turning any build red: a rule the allowance has never heard of
 * is allowed at whatever it finds, until the app records it.
 */
final class Allowance
{
    public const FILE = 'design/allowance.json';

    /**
     * @return array{known: list<string>, rules: array<string,int>}|null
     */
    public static function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_array($data['rules'] ?? null)) {
            return null;
        }

        return [
            'known' => array_values(array_filter((array) ($data['known'] ?? []), 'is_string')),
            'rules' => array_map('intval', $data['rules']),
        ];
    }

    /**
     * Today's counts recorded over the old allowance: the lower count for a
     * rule both know, today's for a rule only today knows.
     *
     * @param  array<string,int>  $counts
     * @param  array{known: list<string>, rules: array<string,int>}|null  $old
     */
    public static function record(array $counts, ?array $old): string
    {
        $rules = [];
        foreach (Audit::RULES as $rule) {
            $now = $counts[$rule] ?? 0;
            $rules[$rule] = $old !== null && in_array($rule, $old['known'], true)
                ? min($now, $old['rules'][$rule] ?? 0)
                : $now;
        }

        $rules = array_filter($rules);
        ksort($rules);

        return json_encode(['known' => Audit::RULES, 'rules' => $rules], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * Today's counts against the allowance.
     *
     * @param  array<string,int>  $counts
     * @param  array{known: list<string>, rules: array<string,int>}  $allowance
     * @return array{rises: array<string,array{0:int,1:int}>, unrecorded: array<string,int>, below: array<string,array{0:int,1:int}>}
     */
    public static function compare(array $counts, array $allowance): array
    {
        $rises = [];
        $unrecorded = [];
        $below = [];

        foreach (array_unique([...array_keys($counts), ...array_keys($allowance['rules'])]) as $rule) {
            $now = $counts[$rule] ?? 0;

            if (! in_array($rule, $allowance['known'], true)) {
                if ($now > 0) {
                    $unrecorded[$rule] = $now;
                }

                continue;
            }

            $allowed = $allowance['rules'][$rule] ?? 0;
            if ($now > $allowed) {
                $rises[$rule] = [$now, $allowed];
            } elseif ($now < $allowed) {
                $below[$rule] = [$now, $allowed];
            }
        }

        ksort($rises);
        ksort($unrecorded);
        ksort($below);

        return ['rises' => $rises, 'unrecorded' => $unrecorded, 'below' => $below];
    }
}
