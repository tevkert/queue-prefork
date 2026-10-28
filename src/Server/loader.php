<?php

// The template's own class loader: only this package's dependency-free classes. The template never
// loads the application's Composer autoloader; forked children load it through their entry script.

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tevkert\\QueuePrefork\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = dirname(__DIR__).'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($file)) {
        require $file;
    }
});
