<?php

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

$dotenv = new Dotenv();
$dotenv->usePutenv()->loadEnv(__DIR__.'/../.env', overrideExistingVars: true);

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
