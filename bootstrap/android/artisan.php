<?php

define('ARTISAN_START', microtime(true));

if (! isset($argv)) {
    global $argv;
    $argv = $_SERVER['argv'] ?? ['artisan'];
}

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\StreamOutput;

// ✅ Redirect output to php://output so ub_write captures it
$stdout = fopen('php://output', 'w');
$output = new StreamOutput($stdout);

$kernel = $app->make(Kernel::class);

// NativePHP install-time bootstrap: run the post-extraction command batch under a
// single PHP embed cycle. native_run_artisan_command() does php_embed_init +
// php_embed_shutdown per call, and repeated TSRM startup crashes on some low-end
// devices (e.g. Galaxy A32). Collapse the four commands into one interpreter boot.
if (($argv[1] ?? null) === 'native:bootstrap') {
    $commands = [
        ['optimize:clear', []],
        ['storage:unlink', []],
        ['storage:link', []],
        ['migrate', ['--force' => true]],
    ];

    $status = 0;
    foreach ($commands as [$name, $params]) {
        try {
            $kernel->call($name, $params, $output);
        } catch (\Throwable $e) {
            fwrite($stdout, "\n[native:bootstrap] {$name} failed: {$e->getMessage()}\n");
            $status = 1;
        }
    }

    $kernel->terminate(new ArgvInput, $status);
    exit($status);
}

$status = $kernel->handle(
    new ArgvInput,
    $output
);

$kernel->terminate(new ArgvInput, $status);

exit($status);
