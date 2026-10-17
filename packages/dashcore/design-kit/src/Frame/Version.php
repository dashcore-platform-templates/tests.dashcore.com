<?php

declare(strict_types=1);

namespace Dashcore\DesignKit\Frame;

use Composer\InstalledVersions;
use Throwable;

/**
 * The installed release of this package, as the consuming app sees it.
 *
 * Rendered onto the shell as data-dc-ui, the attribute dashcore/ui used, so
 * any page says which release drew it and anything already reading it keeps
 * working. A style change is not finished until every app is on it, and this
 * is how anyone finds out whether it is.
 */
class Version
{
    public const PACKAGE = 'dashcore/design-kit';

    public static function installed(): ?string
    {
        try {
            if (! InstalledVersions::isInstalled(self::PACKAGE)) {
                return null;
            }

            return InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (Throwable) {
            return null;
        }
    }
}
