<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
 * Works in both layouts without editing:
 *  - standard:     project/public/index.php   (application one level up)
 *  - shared host:  public_html/index.php      (application in ../core, next to public_html)
 */
$basePath = is_file(__DIR__.'/../core/vendor/autoload.php') ? dirname(__DIR__).'/core' : dirname(__DIR__);

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $basePath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $basePath.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $basePath.'/bootstrap/app.php';

// On a shared host this folder (public_html) is the public directory: build assets,
// published Livewire scripts and public files are read from and written here.
if (realpath(__DIR__) !== realpath($basePath.'/public')) {
    $app->usePublicPath(__DIR__);
}

$app->handleRequest(Request::capture());
