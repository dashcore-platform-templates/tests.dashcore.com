<?php

namespace App\Enums;

/**
 * The kinds of thing in an app that a test suite owes coverage to.
 *
 * Routes, components and actions are proven by what a test hit at runtime;
 * the rest are classes whose methods are proven by the lines a test executed.
 */
enum SurfaceKind: string
{
    case Route = 'route';
    case Component = 'component';
    case Action = 'action';
    case Controller = 'controller';
    case Job = 'job';
    case Command = 'command';
    case Listener = 'listener';
    case Mail = 'mail';
    case Notification = 'notification';
    case Policy = 'policy';
    case Middleware = 'middleware';
    case Rule = 'rule';
    case Model = 'model';
    case Service = 'service';
    case McpTool = 'mcp_tool';
    case Code = 'code';

    public function label(): string
    {
        return match ($this) {
            self::Route => 'Routes',
            self::Component => 'Livewire components',
            self::Action => 'Livewire actions',
            self::Controller => 'Controllers',
            self::Job => 'Jobs',
            self::Command => 'Commands',
            self::Listener => 'Listeners & observers',
            self::Mail => 'Mail',
            self::Notification => 'Notifications',
            self::Policy => 'Policies',
            self::Middleware => 'Middleware',
            self::Rule => 'Validation rules',
            self::Model => 'Models',
            self::Service => 'Services & actions',
            self::McpTool => 'MCP tools',
            self::Code => 'Other code',
        };
    }

    /**
     * Whether this kind is proven by executed lines rather than by a runtime event.
     */
    public function isCode(): bool
    {
        return ! in_array($this, [self::Route, self::Component, self::Action], true);
    }
}
