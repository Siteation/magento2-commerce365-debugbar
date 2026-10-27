<?php
/**
 * Autoloader for the unit tests on a developer machine.
 *
 * Borrows a shop's vendor/ for Magento, PHPUnit and the Commerce365 modules, which are not
 * on a public mirror, and points at a debugbar checkout for Siteation\DebugBar:
 *
 *     C365_HOST=~/Developer/your-shop C365_DEBUGBAR=~/Developer/magento2-debugbar \
 *         $C365_HOST/vendor/bin/phpunit --bootstrap dev/bootstrap.php
 *
 * Both default to the paths above.
 */
declare(strict_types=1);

$home = (string) getenv('HOME');
$host = getenv('C365_HOST') ?: $home . '/Developer/your-shop';
$debugbar = getenv('C365_DEBUGBAR') ?: $home . '/Developer/magento2-debugbar';

// phpcs:ignore Magento2.Security.IncludeFile -- the host's autoloader, at a path the developer names
$composer = require $host . '/vendor/autoload.php';
// Prepended: once a shop has the add-on and the debugbar installed, its vendor/ copies would
// otherwise answer first, and the tests would run against those.
$composer->addPsr4('Siteation\\DebugBar\\', $debugbar . '/', true);
$composer->addPsr4('Siteation\\Commerce365DebugBar\\', dirname(__DIR__) . '/', true);
