<?php

namespace App\Demo;

use App\Enums\BuildKind;
use App\Enums\BuildStatus;
use App\Enums\MutationResult;
use App\Enums\ScanKind;
use App\Enums\ScanStatus;
use App\Enums\SurfaceChange;
use App\Enums\SurfaceKind;
use App\Enums\SurfaceStatus;
use App\Enums\VerdictReason;
use App\Models\Build;
use App\Models\FleetApp;
use App\Models\Scan;
use App\Models\Surface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Three made-up apps, three nightly scans each, told as a story the fleet
 * pages can show: coverage climbing, a method whose tests a mutant slipped
 * past, coverage lost and new code left untested in the latest scan, and a
 * builder PR waiting for review.
 *
 * Statuses per scan read oldest first: T tested, W weak, M missing, null
 * not there yet. Test outcomes: P passed, F failed, R risky (no assertions).
 */
class DemoFleet
{
    /**
     * @var array<string, array{name: string, group: string, surfaces: list<array{0: string, 1: string, 2: string, 3: ?string, 4: ?int, 5: list<?string>, 6: ?string, 7: list<string>}>, tests: array<string, array{0: string, 1: string, 2: list<?string>, 3: int, 4: int}>}>
     */
    public const APPS = [
        'demo-sales' => [
            'name' => 'Demo Sales',
            'group' => 'Domain service',
            'surfaces' => [
                ['route', 'GET leads', 'GET /leads', 'routes/web.php', 18, ['T', 'T', 'T'], null, ['leads_index']],
                ['route', 'POST leads', 'POST /leads', 'routes/web.php', 19, ['M', 'T', 'T'], null, ['leads_store']],
                ['route', 'GET deals/{deal}', 'GET /deals/{deal}', 'routes/web.php', 24, ['T', 'T', 'T'], null, ['deal_show']],
                ['route', 'GET reports/pipeline', 'GET /reports/pipeline', 'routes/web.php', 31, [null, null, 'M'], null, []],
                ['component', 'leads.board', 'leads.board', 'resources/views/livewire/leads/board.blade.php', 1, ['T', 'T', 'T'], null, ['leads_index']],
                ['action', 'leads.board@assign', 'leads.board@assign', 'resources/views/livewire/leads/board.blade.php', 42, ['M', 'T', 'W'], null, ['assign_lead']],
                ['action', 'deals.convert@win', 'deals.convert@win', 'resources/views/livewire/deals/convert.blade.php', 57, ['M', 'M', 'M'], null, []],
                ['service', 'App\\Sales\\LeadScorer::score', 'LeadScorer::score', 'app/Sales/LeadScorer.php', 21, ['T', 'T', 'W'], 'survived', ['lead_scorer']],
                ['service', 'App\\Sales\\Pipeline::forecast', 'Pipeline::forecast', 'app/Sales/Pipeline.php', 34, ['W', 'T', 'T'], 'killed', ['pipeline_forecast']],
                ['job', 'App\\Jobs\\SendFollowUp::handle', 'SendFollowUp::handle', 'app/Jobs/SendFollowUp.php', 28, ['M', 'M', 'T'], 'killed', ['follow_up']],
                ['mail', 'App\\Mail\\LeadAssigned::content', 'LeadAssigned::content', 'app/Mail/LeadAssigned.php', 33, ['W', 'W', 'W'], null, ['lead_mail']],
                ['policy', 'App\\Policies\\DealPolicy::update', 'DealPolicy::update', 'app/Policies/DealPolicy.php', 19, ['M', 'T', 'T'], 'killed', ['deal_policy']],
                ['model', 'App\\Models\\Lead::fullName', 'Lead::fullName', 'app/Models/Lead.php', 47, ['T', 'T', 'T'], 'killed', ['leads_index', 'leads_store']],
            ],
            'tests' => [
                'leads_index' => ['the leads page lists the rep\'s leads', 'tests/Feature/LeadsTest.php', ['P', 'P', 'P'], 4, 210],
                'leads_store' => ['a lead can be added from the board', 'tests/Feature/LeadsTest.php', [null, 'P', 'P'], 6, 260],
                'assign_lead' => ['an owner can assign a lead to a rep', 'tests/Feature/LeadsTest.php', [null, 'P', 'F'], 3, 340],
                'deal_show' => ['a deal page shows its stage and value', 'tests/Feature/DealsTest.php', ['P', 'P', 'P'], 5, 190],
                'lead_scorer' => ['a lead is scored', 'tests/Unit/LeadScorerTest.php', ['P', 'P', 'P'], 1, 12],
                'pipeline_forecast' => ['the forecast weights deals by stage', 'tests/Unit/PipelineTest.php', ['R', 'P', 'P'], 3, 15],
                'follow_up' => ['a follow-up mail goes out after three days', 'tests/Feature/FollowUpTest.php', [null, null, 'P'], 2, 480],
                'lead_mail' => ['the lead assigned mail renders', 'tests/Feature/MailTest.php', ['R', 'R', 'R'], 0, 95],
                'deal_policy' => ['only the owner of a deal may change it', 'tests/Feature/DealsTest.php', [null, 'P', 'P'], 2, 120],
            ],
        ],
        'demo-finance' => [
            'name' => 'Demo Finance',
            'group' => 'Domain service',
            'surfaces' => [
                ['route', 'GET invoices', 'GET /invoices', 'routes/web.php', 15, ['T', 'T', 'T'], null, ['invoices_index']],
                ['route', 'POST invoices/{invoice}/send', 'POST /invoices/{invoice}/send', 'routes/web.php', 22, ['M', 'W', 'T'], null, ['invoice_send']],
                ['route', 'GET reports/cash-flow', 'GET /reports/cash-flow', 'routes/web.php', 30, ['T', 'T', 'T'], null, ['cash_flow']],
                ['component', 'budgets.editor', 'budgets.editor', 'resources/views/livewire/budgets/editor.blade.php', 1, ['M', 'M', 'M'], null, []],
                ['action', 'budgets.editor@save', 'budgets.editor@save', 'resources/views/livewire/budgets/editor.blade.php', 61, ['M', 'M', 'M'], null, []],
                ['service', 'App\\Finance\\Runway::months', 'Runway::months', 'app/Finance/Runway.php', 18, ['T', 'T', 'T'], 'killed', ['runway']],
                ['service', 'App\\Finance\\CsvImport::rows', 'CsvImport::rows', 'app/Finance/CsvImport.php', 40, ['W', 'T', 'T'], 'killed', ['csv_import']],
                ['command', 'App\\Console\\Commands\\PostRecurring::handle', 'PostRecurring::handle', 'app/Console/Commands/PostRecurring.php', 24, ['M', 'T', 'T'], 'killed', ['recurring']],
                ['model', 'App\\Models\\Invoice::total', 'Invoice::total', 'app/Models/Invoice.php', 52, ['T', 'T', 'T'], 'killed', ['invoices_index', 'invoice_send']],
            ],
            'tests' => [
                'invoices_index' => ['the invoices page lists open invoices', 'tests/Feature/InvoicesTest.php', ['P', 'P', 'P'], 5, 230],
                'invoice_send' => ['an invoice can be sent to its client', 'tests/Feature/InvoicesTest.php', [null, 'F', 'P'], 4, 410],
                'cash_flow' => ['the cash flow report adds up', 'tests/Feature/ReportsTest.php', ['P', 'P', 'P'], 7, 520],
                'runway' => ['runway is cash over monthly burn', 'tests/Unit/RunwayTest.php', ['P', 'P', 'P'], 3, 8],
                'csv_import' => ['a bank CSV becomes transactions', 'tests/Unit/CsvImportTest.php', ['R', 'P', 'P'], 9, 44],
                'recurring' => ['recurring bills post on their day', 'tests/Feature/RecurringTest.php', [null, 'P', 'P'], 3, 300],
            ],
        ],
        'demo-website' => [
            'name' => 'Demo Website',
            'group' => 'Website',
            'surfaces' => [
                ['route', 'GET /', 'GET /', 'routes/web.php', 9, ['T', 'T', 'T'], null, ['home']],
                ['route', 'GET lets-talk', 'GET /lets-talk', 'routes/web.php', 12, ['T', 'T', 'T'], null, ['lets_talk']],
                ['route', 'POST lets-talk', 'POST /lets-talk', 'routes/web.php', 13, ['W', 'T', 'T'], null, ['enquiry']],
                ['controller', 'App\\Http\\Controllers\\SubmissionController::store', 'SubmissionController::store', 'app/Http/Controllers/SubmissionController.php', 25, ['W', 'T', 'T'], 'killed', ['enquiry']],
                ['service', 'App\\Services\\TurnstileVerifier::verify', 'TurnstileVerifier::verify', 'app/Services/TurnstileVerifier.php', 17, ['M', 'M', 'T'], 'killed', ['turnstile']],
                ['service', 'App\\Services\\LeadNotifier::notify', 'LeadNotifier::notify', 'app/Services/LeadNotifier.php', 22, ['M', 'M', 'M'], null, []],
                ['middleware', 'App\\Http\\Middleware\\SecurityHeaders::handle', 'SecurityHeaders::handle', 'app/Http/Middleware/SecurityHeaders.php', 14, ['T', 'T', 'T'], 'killed', ['home']],
            ],
            'tests' => [
                'home' => ['the home page answers', 'tests/Feature/PagesTest.php', ['P', 'P', 'P'], 2, 80],
                'lets_talk' => ['the lets-talk form shows every field', 'tests/Feature/PagesTest.php', ['P', 'P', 'P'], 6, 95],
                'enquiry' => ['an enquiry is stored and acknowledged', 'tests/Feature/EnquiryTest.php', ['R', 'P', 'P'], 5, 310],
                'turnstile' => ['a submission without a Turnstile token is refused', 'tests/Feature/EnquiryTest.php', [null, null, 'P'], 2, 140],
            ],
        ],
    ];

    /** Commit subjects, oldest scan first. */
    private const COMMITS = ['Initial import of the demo suite', 'Cover the board and the policies', 'Add the pipeline report'];

    /**
     * @return int apps made
     */
    public function seed(): int
    {
        foreach (self::APPS as $key => $fixture) {
            $app = FleetApp::create([
                'key' => $key,
                'host' => $key.'.demo.test',
                'name' => $fixture['name'],
                'group' => $fixture['group'],
                'repo' => 'example/'.$key,
                'local_path' => null,
                'is_enabled' => true,
                'is_demo' => true,
                'synced_at' => now(),
            ]);

            $this->scans($app, $fixture);
        }

        $this->build(FleetApp::query()->where('key', 'demo-sales')->firstOrFail());

        return count(self::APPS);
    }

    /**
     * The three scans, a day apart, each judged against the one before.
     *
     * @param  array{surfaces: list<array>, tests: array<string, array>}  $fixture
     */
    private function scans(FleetApp $app, array $fixture): void
    {
        $surfaces = [];
        $previous = null;
        $last = count(self::COMMITS) - 1;

        foreach (self::COMMITS as $n => $subject) {
            $started = Carbon::now()->subDays($last - $n)->setTime(1, 0)->addMinutes(crc32($app->key) % 40);
            $scan = Scan::create([
                'fleet_app_id' => $app->id,
                'kind' => ScanKind::Main,
                'previous_scan_id' => $previous?->id,
                'status' => ScanStatus::Completed,
                'ref' => 'main',
                'commit_sha' => sha1($app->key.$n),
                'commit_subject' => $subject,
                'framework' => 'phpunit',
                'testkit_version' => $n === 0 ? null : '1.4.0',
                'test_database' => 'tests_scan_'.str_replace('-', '_', $app->key),
                'started_at' => $started,
                'finished_at' => $started->copy()->addMinutes(6)->addSeconds(17),
                'created_at' => $started,
                'updated_at' => $started,
                'output' => "Demo data — no suite ran.\n\nTests: ".count($fixture['tests']).' · this output is made up by tests:demo.',
            ]);

            $tests = $this->tests($scan, $fixture['tests'], $n);
            $counts = ['tested' => 0, 'weak' => 0, 'missing' => 0, 'new-untested' => 0, 'lost' => 0, 'gained' => 0, 'killed' => 0, 'survived' => 0];

            foreach ($fixture['surfaces'] as $i => [$kind, $identifier, $label, $file, $line, $history, $mutation, $evidence]) {
                $status = self::status($history[$n]);

                if ($status === null) {
                    continue;
                }

                // A mutant is only tried on the latest scan's tested methods;
                // the one that survives is what turned the method weak.
                $mutated = $n === $last && $mutation !== null ? MutationResult::from($mutation) : null;
                $reason = match (true) {
                    $mutated === MutationResult::Survived => VerdictReason::MutantSurvived,
                    $status === SurfaceStatus::Weak => VerdictReason::NoProvingTest,
                    default => null,
                };
                $before = $n === 0 ? null : self::status($history[$n - 1]);
                $change = match (true) {
                    $n === 0 => null,
                    $before === null => $status === SurfaceStatus::Tested ? null : SurfaceChange::NewUntested,
                    $before === SurfaceStatus::Tested && $status !== SurfaceStatus::Tested => SurfaceChange::Lost,
                    $before !== SurfaceStatus::Tested && $status === SurfaceStatus::Tested => SurfaceChange::Gained,
                    default => null,
                };

                $surface = $surfaces[$i] ??= new Surface(['fleet_app_id' => $app->id, 'kind' => SurfaceKind::from($kind), 'identifier' => $identifier]);
                $surface->fill([
                    'label' => $label,
                    'file' => $file,
                    'start_line' => $line,
                    'end_line' => $line + 12,
                    'body_hash' => sha1($identifier.$n),
                    'status' => $status,
                    'reason' => $reason,
                    'last_scan_id' => $scan->id,
                ]);

                if ($mutated !== null) {
                    $surface->mutation = $mutated->value;
                    $surface->mutation_hash = sha1($identifier.$n);
                    $surface->mutation_checked_at = $scan->finished_at;
                }

                $surface->save();

                $proving = array_values(array_filter($evidence, fn (string $test) => isset($tests[$test])));

                DB::table('scan_surfaces')->insert([
                    'scan_id' => $scan->id,
                    'surface_id' => $surface->id,
                    'status' => $status->value,
                    'reason' => $reason?->value,
                    'change' => $change?->value,
                    'mutation' => $mutated?->value,
                    'tests_count' => count($proving),
                    'evidence' => json_encode(array_map(fn (string $test) => $tests[$test], $proving)),
                ]);

                $counts[$status->value]++;

                if ($change !== null) {
                    $counts[$change->value]++;
                }

                if ($mutated !== null) {
                    $counts[$mutated->value]++;
                }
            }

            $total = $counts['tested'] + $counts['weak'] + $counts['missing'];
            $outcomes = collect($tests)->countBy('outcome');

            $scan->update([
                'tests_total' => count($tests),
                'tests_passed' => $outcomes['passed'] ?? 0,
                'tests_failed' => $outcomes['failed'] ?? 0,
                'tests_risky' => $outcomes['risky'] ?? 0,
                'assertions' => collect($fixture['tests'])->filter(fn (array $t, string $k) => isset($tests[$k]))->sum(fn (array $t) => $t[3]),
                'surfaces_total' => $total,
                'surfaces_tested' => $counts['tested'],
                'surfaces_weak' => $counts['weak'],
                'surfaces_missing' => $counts['missing'],
                'surfaces_new_untested' => $counts['new-untested'],
                'surfaces_lost' => $counts['lost'],
                'surfaces_gained' => $counts['gained'],
                'mutants_killed' => $counts['killed'],
                'mutants_survived' => $counts['survived'],
                'score' => $total === 0 ? null : round($counts['tested'] / $total * 100, 2),
                'notes' => ['Demo data made by tests:demo — no suite ran. Clear it with php artisan tests:demo --wipe.'],
            ]);

            $previous = $scan;
        }
    }

    /**
     * The scan's test rows, and each test as the evidence names it.
     *
     * @param  array<string, array{0: string, 1: string, 2: list<?string>, 3: int, 4: int}>  $fixture
     * @return array<string, array{id: string, name: string, file: string, outcome: string}>
     */
    private function tests(Scan $scan, array $fixture, int $n): array
    {
        $tests = [];

        foreach ($fixture as $key => [$name, $file, $outcomes, $assertions, $ms]) {
            $outcome = match ($outcomes[$n]) {
                'P' => 'passed',
                'F' => 'failed',
                'R' => 'risky',
                default => null,
            };

            if ($outcome === null) {
                continue;
            }

            $id = self::testId($file, $name);

            DB::table('scan_tests')->insert([
                'scan_id' => $scan->id,
                'test_id' => $id,
                'file' => $file,
                'name' => $name,
                'outcome' => $outcome,
                'assertions' => $outcome === 'risky' ? 0 : $assertions,
                'duration_ms' => $ms,
                'surfaces_count' => 1,
                'message' => $outcome === 'failed' ? "Failed asserting that two strings are equal.\n--- Expected\n+++ Actual\n-'Assigned to Riley'\n+'Unassigned'" : null,
            ]);

            $tests[$key] = ['id' => $id, 'name' => $name, 'file' => $file, 'outcome' => $outcome];
        }

        return $tests;
    }

    /**
     * The id a PHPUnit run reports for a test: its class and its method.
     */
    public static function testId(string $file, string $name): string
    {
        $class = str_replace(['tests/', '/', '.php'], ['Tests\\', '\\', ''], $file);

        return $class.'::test_'.str_replace([' ', '\'', '-'], ['_', '', '_'], strtolower($name));
    }

    private static function status(?string $code): ?SurfaceStatus
    {
        return match ($code) {
            'T' => SurfaceStatus::Tested,
            'W' => SurfaceStatus::Weak,
            'M' => SurfaceStatus::Missing,
            default => null,
        };
    }

    /**
     * A builder PR waiting for review: two of the sales app's gaps proven by
     * the tests an agent wrote, the third left out with its reason.
     */
    private function build(FleetApp $app): void
    {
        $scan = $app->latestCompletedScan()->firstOrFail();
        $targets = $app->surfaces()->whereIn('label', ['deals.convert@win', 'GET /reports/pipeline', 'LeadAssigned::content'])->orderBy('id')->get()->keyBy('label');
        $started = $scan->finished_at->copy()->addHours(2);

        Build::create([
            'fleet_app_id' => $app->id,
            'kind' => BuildKind::Tests,
            'scan_id' => $scan->id,
            'status' => BuildStatus::Opened,
            'base_ref' => 'main',
            'base_sha' => $scan->commit_sha,
            'branch' => 'tests/demo-close-gaps',
            'commit_sha' => sha1('demo-build'),
            'targets' => $targets->pluck('id')->all(),
            'verification' => ['targets' => [
                $targets['deals.convert@win']->id => ['label' => 'deals.convert@win', 'proven' => true, 'status' => 'tested', 'mutation' => 'killed', 'tests' => ['winning a deal marks it won and records the date']],
                $targets['GET /reports/pipeline']->id => ['label' => 'GET /reports/pipeline', 'proven' => true, 'status' => 'tested', 'mutation' => null, 'tests' => ['the pipeline report groups deals by stage']],
                $targets['LeadAssigned::content']->id => ['label' => 'LeadAssigned::content', 'proven' => false, 'status' => 'weak', 'mutation' => null, 'tests' => []],
            ]],
            'changed_files' => ['tests/Feature/DealsTest.php', 'tests/Feature/ReportsTest.php'],
            'agent_report' => [
                'skipped' => [['target' => $targets['LeadAssigned::content']->id, 'reason' => 'The mail reads the rep from a relation the factory does not set; left for a person to decide which is right.']],
                'suspected_bugs' => [],
            ],
            'targets_proven' => 2,
            'tests_added' => 2,
            'cost_usd' => 0.84,
            'pr_url' => 'https://github.com/example/demo-sales/pull/42',
            'pr_number' => 42,
            'notes' => ['Demo data made by tests:demo — no agent ran and no pull request exists.'],
            'log' => "Demo data — no agent ran.\n",
            'started_at' => $started,
            'finished_at' => $started->copy()->addMinutes(14),
            'created_at' => $started,
            'updated_at' => $started->copy()->addMinutes(14),
        ]);
    }
}
