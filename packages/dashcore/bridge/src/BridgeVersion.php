<?php

namespace Dashcore\Bridge;

use Composer\InstalledVersions;
use Throwable;

class BridgeVersion
{
    /**
     * The installed dashcore/bridge version as Composer knows it — a tag
     * like "v0.5.0" for VCS installs, a branch alias for path installs,
     * or "unknown" when Composer runtime metadata is unavailable.
     */
    public static function version(): string
    {
        try {
            if (InstalledVersions::isInstalled('dashcore/bridge')) {
                return InstalledVersions::getPrettyVersion('dashcore/bridge') ?? 'unknown';
            }
        } catch (Throwable) {
            // Composer runtime API missing (non-Composer autoload) — fall through.
        }

        return 'unknown';
    }

    /**
     * The installed dashcore/ui version, or null when the app does not use
     * the fleet shell. Read through Composer rather than by referencing the
     * package, so the bridge takes no dependency on it.
     */
    public static function ui(): ?string
    {
        try {
            if (InstalledVersions::isInstalled('dashcore/ui')) {
                return InstalledVersions::getPrettyVersion('dashcore/ui');
            }
        } catch (Throwable) {
            // Composer runtime API missing — treat as not installed.
        }

        return null;
    }
}
