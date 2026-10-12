<?php

declare(strict_types=1);

use Faker\Factory as FakerFactory;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Test\UnitTestConfig;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__, 2).'/config/bootstrap.php')) {
    require dirname(__DIR__, 2).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
}

// Ensure OIDC_MOCK_ENABLED is set
putenv('OIDC_MOCK_ENABLED='.($_ENV['OIDC_MOCK_ENABLED'] ?? true));

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

Locale::setDefault('en');

// https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#non-kernel-tests
$faker = FakerFactory::create('fr_BE');
$faker->seed(123456);
UnitTestConfig::configure(
    instantiator: (Instantiator::withoutConstructor())
        ->allowExtra()
        ->alwaysForce(),
    faker: $faker,
);

// https://github.com/symfony/symfony/issues/53812#issuecomment-1962740145
set_exception_handler([new ErrorHandler(), 'handleException']);
