<?php

/**
 * Hybrid Laravel Bootstrap Helper
 * Allows legacy PHP files in Job Request PHU to access Laravel features
 * (Eloquent Models, DB facade, Session, Blade, Config, Helpers, etc.)
 */

if (!defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
$bootstrapAppPath = dirname(__DIR__) . '/bootstrap/app.php';

if (file_exists($autoloadPath) && file_exists($bootstrapAppPath)) {
    require_once $autoloadPath;
    
    /** @var \Illuminate\Foundation\Application $app */
    $app = require_once $bootstrapAppPath;

    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    
    $kernel->bootstrap();

}
