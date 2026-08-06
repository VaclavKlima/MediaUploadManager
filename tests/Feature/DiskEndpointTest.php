<?php

use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\DiskMarker;
use Illuminate\Filesystem\Filesystem;

function configureDiskEndpoint(array $disks, bool $requireMountpoint = false): void
{
    config()->set('media', [
        'disks' => $disks,
        'default_reserve_gib' => '0',
        'require_mountpoint' => $requireMountpoint,
    ]);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
}

beforeEach(function () {
    $this->filesystem = new Filesystem;
    $this->root = storage_path('framework/testing/endpoint-'.bin2hex(random_bytes(6)));
    $this->filesystem->makeDirectory($this->root.'/.media-upload-manager/incoming', 0750, true);
    file_put_contents($this->root.'/.media-upload-manager/disk.json', DiskMarker::encode('movies'));
});

afterEach(function () {
    $this->filesystem->deleteDirectory($this->root);
});

it('requires authentication to list disks', function () {
    configureDiskEndpoint([
        ['id' => 'movies', 'label' => 'Movies', 'path' => $this->root, 'reserve_gib' => '0'],
    ]);

    $this->get(route('disks.index'))->assertRedirect(route('login'));
});

it('returns safe disk health and capacity data to authenticated users', function () {
    configureDiskEndpoint([
        ['id' => 'movies', 'label' => 'Movies', 'path' => $this->root, 'reserve_gib' => '0'],
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('disks.index'))
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', 'movies')
        ->assertJsonPath('data.0.label', 'Movies')
        ->assertJsonPath('data.0.health', 'healthy')
        ->assertJsonPath('data.0.eligible', true)
        ->assertJsonPath('data.0.safety_reserve_bytes', 0)
        ->assertJsonPath('data.0.reasons', [])
        ->assertJsonStructure(['data' => [[
            'id',
            'label',
            'health',
            'eligible',
            'total_bytes',
            'free_bytes',
            'safety_reserve_bytes',
            'usable_bytes',
            'reasons',
        ]]]);

    expect($response->getContent())->not->toContain($this->root);
});

it('returns a generic safe 503 for invalid local configuration', function () {
    configureDiskEndpoint([
        ['id' => 'Movies', 'label' => 'Movies', 'path' => $this->root, 'reserve_gib' => '0'],
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('disks.index'))
        ->assertServiceUnavailable()
        ->assertExactJson(['message' => 'Media disk configuration is unavailable.']);

    expect($response->getContent())->not->toContain($this->root)
        ->not->toContain('Movies must');
});

it('enforces credential onboarding before evaluating disk configuration', function () {
    configureDiskEndpoint([
        ['id' => 'Movies', 'label' => 'Movies', 'path' => $this->root, 'reserve_gib' => '0'],
    ]);
    $user = User::factory()->credentialChangeRequired()->create();

    $this->actingAs($user)
        ->get(route('disks.index'))
        ->assertRedirect(route('onboarding.edit'));
});

it('terminates disabled sessions before evaluating disk configuration', function () {
    configureDiskEndpoint([
        ['id' => 'Movies', 'label' => 'Movies', 'path' => $this->root, 'reserve_gib' => '0'],
    ]);
    $user = User::factory()->disabled()->create();

    $this->actingAs($user)
        ->get(route('disks.index'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
