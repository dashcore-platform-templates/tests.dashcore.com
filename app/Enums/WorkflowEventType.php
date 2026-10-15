<?php

namespace App\Enums;

/**
 * Everything that happens to a workflow, as one line in its log: its runs,
 * the alerts they set off, what people changed, and the machinery under it.
 */
enum WorkflowEventType: string
{
    case RunPassed = 'run_passed';
    case RunFailed = 'run_failed';
    case RunStopped = 'run_stopped';
    case AlertSent = 'alert_sent';
    case RecoverySent = 'recovery_sent';
    case AlertNotSent = 'alert_not_sent';
    case Created = 'created';
    case Updated = 'updated';
    case Imported = 'imported';
    case Deleted = 'deleted';
    case SwitchedOn = 'switched_on';
    case SwitchedOff = 'switched_off';
    case RunRequested = 'run_requested';
    case CouldNotRun = 'could_not_run';
    case ScheduleResumed = 'schedule_resumed';

    /** The filters the log offers, each with the types it shows. */
    public const GROUPS = [
        'problems' => 'Problems',
        'runs' => 'Runs',
        'alerts' => 'Alerts',
        'changes' => 'Changes',
        'system' => 'Schedule & browser',
    ];

    public function label(): string
    {
        return match ($this) {
            self::RunPassed => 'Passed',
            self::RunFailed => 'Failed',
            self::RunStopped => 'Cut off',
            self::AlertSent => 'Alert sent',
            self::RecoverySent => 'Recovery sent',
            self::AlertNotSent => 'Alert not sent',
            self::Created => 'Created',
            self::Updated => 'Changed',
            self::Imported => 'Imported',
            self::Deleted => 'Deleted',
            self::SwitchedOn => 'Switched on',
            self::SwitchedOff => 'Switched off',
            self::RunRequested => 'Run asked for',
            self::CouldNotRun => 'Could not run',
            self::ScheduleResumed => 'Schedule was down',
        };
    }

    /** 'good', 'bad', 'warn' or 'info' — the colour of its dot. */
    public function tone(): string
    {
        return match ($this) {
            self::RunPassed, self::RecoverySent => 'good',
            self::RunFailed, self::AlertSent, self::CouldNotRun => 'bad',
            self::RunStopped, self::AlertNotSent, self::ScheduleResumed => 'warn',
            default => 'info',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::RunPassed, self::RunFailed, self::RunStopped, self::RunRequested => 'runs',
            self::AlertSent, self::RecoverySent, self::AlertNotSent => 'alerts',
            self::CouldNotRun, self::ScheduleResumed => 'system',
            default => 'changes',
        };
    }

    /**
     * @return list<self>
     */
    public static function inGroup(string $group): array
    {
        return array_values(array_filter(self::cases(), fn (self $type) => $group === 'problems'
            ? in_array($type->tone(), ['bad', 'warn'], true)
            : $type->group() === $group));
    }
}
