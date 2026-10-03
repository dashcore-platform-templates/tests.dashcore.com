<?php

namespace Tests\Feature\Simulation;

use App\Simulation\Pulse\TablePulse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TablePulseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_rows_and_what_arrived_from_config_alone(): void
    {
        Schema::create('time_off_requests', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
        });
        DB::table('time_off_requests')->insert([
            ['status' => 'pending', 'created_at' => '2026-10-20 13:00:00'],
            ['status' => 'pending', 'created_at' => '2026-10-01 09:00:00'],
            ['status' => 'approved', 'created_at' => '2026-10-20 14:00:00'],
        ]);
        DB::table('holidays')->insert([['id' => 1], ['id' => 2]]);

        $pulse = new TablePulse([
            ['label' => 'Time off requests', 'table' => 'time_off_requests'],
            ['label' => 'Waiting', 'table' => 'time_off_requests', 'where' => ['status' => 'pending']],
            ['label' => 'Holidays', 'table' => 'holidays'],
            ['label' => 'Shifts', 'table' => 'shifts'],
        ]);

        $metrics = $pulse->read(DB::connection(), Carbon::parse('2026-10-20 15:00:00'), Carbon::parse('2026-10-20 12:00:00'));

        $this->assertSame([
            ['label' => 'Time off requests', 'value' => '3', 'hint' => '+2 since switched on'],
            ['label' => 'Waiting', 'value' => '2', 'hint' => '+1 since switched on'],
            ['label' => 'Holidays', 'value' => '2', 'hint' => null],
            ['label' => 'Shifts', 'value' => '—', 'hint' => 'no shifts table'],
        ], $metrics);
    }
}
