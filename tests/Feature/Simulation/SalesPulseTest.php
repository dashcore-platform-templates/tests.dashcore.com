<?php

namespace Tests\Feature\Simulation;

use App\Simulation\Pulse\SalesPulse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesPulseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The slice of the sales app's schema the pulse reads, built inside the
     * test's transaction (Postgres DDL rolls back with it).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('stage');
            $table->decimal('amount', 12, 2);
            $table->timestamp('closed_at')->nullable();
        });
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('occurred_at');
        });
        Schema::create('lead_tasks', function (Blueprint $table) {
            $table->id();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function test_it_reads_the_sales_apps_vital_signs(): void
    {
        $now = Carbon::parse('2026-10-02 21:00:00');

        DB::table('leads')->insert([
            ['created_at' => '2026-09-01 10:00:00'],
            ['created_at' => '2026-10-02 08:00:00'],
            ['created_at' => '2026-10-02 20:30:00'],
        ]);
        DB::table('deals')->insert([
            ['stage' => 'qualified', 'amount' => 1000, 'closed_at' => null],
            ['stage' => 'proposal', 'amount' => 2500, 'closed_at' => null],
            ['stage' => 'won', 'amount' => 4000, 'closed_at' => '2026-09-29 12:00:00'],
            ['stage' => 'won', 'amount' => 9999, 'closed_at' => '2026-09-20 12:00:00'],
            ['stage' => 'lost', 'amount' => 700, 'closed_at' => '2026-10-01 12:00:00'],
        ]);
        DB::table('lead_activities')->insert([
            ['lead_id' => 1, 'user_id' => 5, 'occurred_at' => '2026-10-02 09:00:00'],
            ['lead_id' => 1, 'user_id' => 5, 'occurred_at' => '2026-10-02 10:00:00'],
            ['lead_id' => 2, 'user_id' => 6, 'occurred_at' => '2026-10-02 11:00:00'],
            ['lead_id' => 3, 'user_id' => null, 'occurred_at' => '2026-10-02 11:00:00'],
            ['lead_id' => 3, 'user_id' => 6, 'occurred_at' => '2026-10-01 11:00:00'],
        ]);
        DB::table('lead_tasks')->insert([
            ['due_at' => '2026-10-01 09:00:00', 'completed_at' => null],
            ['due_at' => '2026-10-05 09:00:00', 'completed_at' => null],
            ['due_at' => '2026-09-01 09:00:00', 'completed_at' => '2026-09-01 10:00:00'],
        ]);

        $metrics = collect((new SalesPulse)->read(DB::connection(), $now, null))->keyBy('label');

        $this->assertSame(['3', '+2 today'], [$metrics['Leads']['value'], $metrics['Leads']['hint']]);
        $this->assertSame('2', $metrics['Touched today']['value']);
        $this->assertSame(['$3,500', '2 open deals'], [$metrics['Open pipeline']['value'], $metrics['Open pipeline']['hint']]);
        $this->assertSame(['$4,000', '1 won · 1 lost'], [$metrics['Won this week']['value'], $metrics['Won this week']['hint']]);
        $this->assertSame(['2', '1 overdue'], [$metrics['Follow-ups']['value'], $metrics['Follow-ups']['hint']]);

        $since = collect((new SalesPulse)->read(DB::connection(), $now, Carbon::parse('2026-10-02 20:00:00')))->keyBy('label');
        $this->assertSame('+1 since switched on', $since['Leads']['hint']);
    }
}
