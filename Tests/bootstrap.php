<?php

declare(strict_types=1);

$mauticVendor = dirname(__DIR__, 3).'/vendor/autoload.php';
$pluginVendor = dirname(__DIR__).'/vendor/autoload.php';

if (file_exists($mauticVendor)) {
    include_once $mauticVendor;
} elseif (file_exists($pluginVendor)) {
    include_once $pluginVendor;
} else {
    throw new RuntimeException('Composer autoload not found.');
}
