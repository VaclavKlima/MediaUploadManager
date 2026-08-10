<?php

it('boots composer scripts in the testing environment in github quality jobs', function () {
    $projectRoot = dirname(__DIR__, 2);
    $testsWorkflow = file_get_contents($projectRoot.'/.github/workflows/tests.yml');
    $containersWorkflow = file_get_contents($projectRoot.'/.github/workflows/containers.yml');
    $composer = json_decode(
        file_get_contents($projectRoot.'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($testsWorkflow)
        ->toContain("    env:\n      APP_ENV: testing\n      DB_CONNECTION: mysql")
        ->and($containersWorkflow)
        ->toContain("  release-quality:\n")
        ->toContain("    env:\n      APP_ENV: testing\n      DB_CONNECTION: mysql")
        ->and($composer['scripts']['ci:check'])
        ->toContain('@php artisan wayfinder:generate --with-form --no-interaction');
});
