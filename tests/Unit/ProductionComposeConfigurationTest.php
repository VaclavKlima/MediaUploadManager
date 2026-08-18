<?php

use Symfony\Component\Yaml\Yaml;

it('embeds release identity and enables sanitized Pulse exception context', function () {
    $projectRoot = dirname(__DIR__, 2);
    $dockerfile = file_get_contents($projectRoot.'/deploy/production/Dockerfile');
    $compose = file_get_contents($projectRoot.'/deploy/production/compose.yml');
    $environmentExample = file_get_contents($projectRoot.'/deploy/production/.env.production.example');
    $containersWorkflow = file_get_contents($projectRoot.'/.github/workflows/containers.yml');

    expect($dockerfile)
        ->toContain('ARG APP_RELEASE=development', 'ENV APP_RELEASE=${APP_RELEASE}')
        ->and($compose)->toContain('PULSE_EXCEPTION_CONTEXT_ENABLED: ${PULSE_EXCEPTION_CONTEXT_ENABLED:-true}')
        ->and($environmentExample)->toContain('PULSE_EXCEPTION_CONTEXT_ENABLED=true')
        ->and($containersWorkflow)
        ->toContain('APP_RELEASE=${{ github.sha }}')
        ->toContain('APP_RELEASE=${{ github.event_name == \'workflow_dispatch\' && inputs.release || github.ref_name }}');
});

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

it('documents the one-time guarded dynamic-range backfill after deployment health checks', function () {
    $runbook = file_get_contents(dirname(__DIR__, 2).'/docs/production-deployment.md');

    expect($runbook)
        ->toContain('exec app php artisan media:metadata:backfill-dynamic-range')
        ->toContain('one-time post-deploy backfill')
        ->toContain('do not roll back the release')
        ->toContain('affected cards simply omit HDR');
});

it('keeps Movie mounts compatible and provides an all-service opt-in Series override', function () {
    $projectRoot = dirname(__DIR__, 2);
    $baseCompose = file_get_contents($projectRoot.'/deploy/production/compose.yml');
    $seriesCompose = Yaml::parseFile($projectRoot.'/deploy/production/compose.series.yml');
    $seriesServices = $seriesCompose['services'];
    $containersWorkflow = file_get_contents($projectRoot.'/.github/workflows/containers.yml');
    $runbook = file_get_contents($projectRoot.'/docs/production-deployment.md');

    expect($baseCompose)
        ->toContain('MEDIA_DISK_NAS_A_MOVIES_PATH:-${MEDIA_DISK_NAS_A_PATH')
        ->not->toContain('MEDIA_DISK_NAS_A_SERIES_PATH')
        ->and(array_keys($seriesServices))->toBe(['migrate', 'app', 'worker', 'scheduler', 'tusd']);

    foreach (['migrate', 'app', 'worker', 'scheduler'] as $serviceName) {
        expect($seriesServices[$serviceName]['environment'])
            ->toHaveKeys([
                'MEDIA_DISK_NAS_A_SERIES_PATH',
                'MEDIA_DISK_NAS_A_SERIES_DEFAULT_CATEGORY',
                'MEDIA_DISK_NAS_B_SERIES_PATH',
                'MEDIA_DISK_NAS_B_SERIES_DEFAULT_CATEGORY',
                'MEDIA_DISK_NAS_C_SERIES_PATH',
                'MEDIA_DISK_NAS_C_SERIES_DEFAULT_CATEGORY',
            ]);
    }

    foreach (array_keys($seriesServices) as $serviceName) {
        expect($seriesServices[$serviceName]['volumes'])->toHaveCount(3);
    }

    expect($containersWorkflow)
        ->toContain('-f deploy/production/compose.series.yml config --quiet')
        ->toContain('media:disks:initialize nas_a --kind=series')
        ->toContain('media:disks:check --kind=series --json')
        ->and($runbook)
        ->toContain('include `deploy/production/compose.series.yml` in every validation')
        ->toContain('matching version-1 Movie marker is upgraded atomically');

    $environmentExample = file_get_contents($projectRoot.'/deploy/production/.env.production.example');

    expect($seriesServices['app']['environment']['MEDIA_DISK_NAS_A_SERIES_DEFAULT_CATEGORY'])
        ->toBe('${MEDIA_DISK_NAS_A_SERIES_DEFAULT_CATEGORY:-}')
        ->and($environmentExample)->toContain('MEDIA_DISK_NAS_A_SERIES_DEFAULT_CATEGORY=tv');
});
