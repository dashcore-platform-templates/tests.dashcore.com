<?php

namespace App\Simulation\Pulse;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * A pulse from config alone, for an app without a pulse of its own: each
 * metric counts the rows of one table (optionally only those matching
 * `where`), and says how many arrived since the switch went on — or today —
 * when the table has a created_at.
 *
 *   'pulse_metrics' => [
 *       ['label' => 'Employees', 'table' => 'employees'],
 *       ['label' => 'Time off waiting', 'table' => 'time_off_requests', 'where' => ['status' => 'pending']],
 *   ]
 */
class TablePulse implements Pulse
{
    /**
     * @param  list<array{label: string, table: string, where?: array<string, mixed>}>  $metrics
     */
    public function __construct(private array $metrics = []) {}

    public function read(ConnectionInterface $db, Carbon $now, ?Carbon $since): array
    {
        $schema = $db->getSchemaBuilder();

        return array_map(function (array $metric) use ($db, $schema, $now, $since) {
            if (! $schema->hasTable($metric['table'])) {
                return ['label' => $metric['label'], 'value' => '—', 'hint' => "no {$metric['table']} table"];
            }

            $rows = $db->table($metric['table'])->where($metric['where'] ?? []);
            $hint = null;

            if ($schema->hasColumn($metric['table'], 'created_at')) {
                $added = (clone $rows)->where('created_at', '>=', $since ?? $now->copy()->startOfDay())->count();
                $hint = '+'.number_format($added).($since ? ' since switched on' : ' today');
            }

            return ['label' => $metric['label'], 'value' => number_format($rows->count()), 'hint' => $hint];
        }, $this->metrics);
    }
}
