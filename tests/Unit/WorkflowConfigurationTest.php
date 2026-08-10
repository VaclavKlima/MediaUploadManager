<?php

it('boots composer scripts in the testing environment in github quality jobs', function () {
    $projectRoot = dirname(__DIR__, 2);
    $testsWorkflow = file_get_contents($projectRoot.'/.github/workflows/tests.yml');
    $containersWorkflow = file_get_contents($projectRoot.'/.github/workflows/containers.yml');

    expect($testsWorkflow)
        ->toContain("    env:\n      APP_ENV: testing\n      DB_CONNECTION: mysql")
        ->and($containersWorkflow)
        ->toContain("  release-quality:\n")
        ->toContain("    env:\n      APP_ENV: testing\n      DB_CONNECTION: mysql");
});
