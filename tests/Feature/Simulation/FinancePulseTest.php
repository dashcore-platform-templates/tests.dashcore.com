<?php

namespace Tests\Feature\Simulation;

use App\Simulation\Pulse\FinancePulse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinancePulseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->decimal('amount', 12, 2);
            $table->date('occurred_on');
            $table->timestamps();
        });
        Schema::create('recurring_income_patterns', function (Blueprint $table) {
            $table->id();
            $table->decimal('average_amount', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->boolean('excluded_from_forecast')->default(false);
        });
    }

    public function test_it_reads_the_books(): void
    {
        $now = Carbon::parse('2026-10-20 15:00:00');

        DB::table('transactions')->insert([
            ['type' => 'income', 'amount' => 5000, 'occurred_on' => '2026-10-02', 'created_at' => '2026-10-02 09:00:00'],
            ['type' => 'expense', 'amount' => 1200.5, 'occurred_on' => '2026-10-15', 'created_at' => '2026-10-20 10:00:00'],
            ['type' => 'expense', 'amount' => 6000, 'occurred_on' => '2026-10-01', 'created_at' => '2026-10-20 14:00:00'],
            ['type' => 'income', 'amount' => 9999, 'occurred_on' => '2026-09-30', 'created_at' => '2026-09-30 09:00:00'],
        ]);
        DB::table('recurring_income_patterns')->insert([
            ['average_amount' => 2500, 'is_active' => true, 'excluded_from_forecast' => false],
            ['average_amount' => 800, 'is_active' => false, 'excluded_from_forecast' => false],
            ['average_amount' => 400, 'is_active' => true, 'excluded_from_forecast' => true],
        ]);

        $metrics = collect((new FinancePulse)->read(DB::connection(), $now, Carbon::parse('2026-10-20 12:00:00')))->keyBy('label');

        $this->assertSame(['4', '+1 since switched on'], [$metrics['Transactions']['value'], $metrics['Transactions']['hint']]);
        $this->assertSame('$5,000', $metrics['Income this month']['value']);
        $this->assertSame('$7,201', $metrics['Expenses this month']['value']);
        $this->assertSame(['−$2,201', 'spending more than earning'], [$metrics['Net this month']['value'], $metrics['Net this month']['hint']]);
        $this->assertSame(['$2,500', '1 active payer'], [$metrics['Recurring income']['value'], $metrics['Recurring income']['hint']]);
    }
}
