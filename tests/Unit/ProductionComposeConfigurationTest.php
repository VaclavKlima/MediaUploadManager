<?php

use Symfony\Component\Yaml\Yaml;

it('assigns the supplemental media group only to media services while preserving the primary identity', function () {
    $projectRoot = dirname(__DIR__, 2);
    $compose = Yaml::parseFile($projectRoot.'/deploy/production/compose.yml');
    $services = $compose['services'];
    $mediaServices = ['app', 'migrate', 'scheduler', 'tusd', 'worker'];
    $servicesWithSupplementalGroups = collect($services)
        ->filter(fn (array $service): bool => array_key_exists('group_add', $service))
        ->keys()
        ->sort()
        ->values()
        ->all();

    expect($servicesWithSupplementalGroups)->toBe($mediaServices);

    foreach ($mediaServices as $serviceName) {
        expect($services[$serviceName]['group_add'])->toBe(['${MEDIA_GID:-${APP_GID:-1000}}'])
            ->and($services[$serviceName]['user'])->toBe('${APP_UID:-1000}:${APP_GID:-1000}');
    }

    $environmentExample = file_get_contents($projectRoot.'/deploy/production/.env.production.example');
    $containersWorkflow = file_get_contents($projectRoot.'/.github/workflows/containers.yml');

    expect($environmentExample)->toContain('MEDIA_GID=${APP_GID}')
        ->and($containersWorkflow)->toContain('MEDIA_GID: 1999')
        ->and($containersWorkflow)->toContain('exec -T "$service" id -G');
});
