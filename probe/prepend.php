<?php

/**
 * Loaded into a fleet app's test process with `-d auto_prepend_file=` so the
 * probe can ride along without a single file being written into that app.
 *
 * Only the autoloader is registered here: the extension implements PHPUnit
 * interfaces that do not exist until the app's own vendor autoloader has run,
 * so the class itself is loaded lazily when PHPUnit asks for it.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'DashcoreTestsProbe\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($file)) {
        require $file;
    }
});
