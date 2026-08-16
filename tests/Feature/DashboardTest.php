<?php

use App\Actions\TransitionUploadStatus;
use App\Enums\MediaRootKind;
use App\Enums\UploadStatus;
use App\Http\Controllers\DashboardController;
use App\Models\Upload;
use App\Models\User;
use App\Support\Media\ConfiguredDiskRegistry;
use App\Support\Media\Contracts\MediaFilesystem;
use App\Support\Media\DiskMarker;
use App\Support\Media\NativeMediaFilesystem;
use App\Support\Media\OperationalDashboardPresenter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

function configureDashboardDisks(array $disks): void
{
    config()->set('media', [
        'disks' => $disks,
        'default_reserve_gib' => '0',
        'require_mountpoint' => false,
    ]);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
}

/** @param array<string, mixed> $attributes */
function dashboardUploadAtStatus(
    User $owner,
    UploadStatus $status,
    array $attributes = [],
    string $failureDetail = 'The media file could not be processed.',
): Upload {
    $upload = Upload::factory()->for($owner)->create($attributes);
    $transition = new TransitionUploadStatus;

    return match ($status) {
        UploadStatus::Pending => $upload,
        UploadStatus::Uploading => $transition->asSystem($upload, UploadStatus::Uploading),
        UploadStatus::Paused => $transition->asSystem(
            $transition->asSystem($upload, UploadStatus::Uploading),
            UploadStatus::Paused,
        ),
        UploadStatus::Processing => $transition->asSystem(
            $transition->asSystem($upload, UploadStatus::Uploading),
            UploadStatus::Processing,
        ),
        UploadStatus::Completed => $transition->asSystem(
            $transition->asSystem(
                $transition->asSystem($upload, UploadStatus::Uploading),
                UploadStatus::Processing,
            ),
            UploadStatus::Completed,
        ),
        UploadStatus::Failed => $transition->failAsSystem(
            $transition->asSystem($upload, UploadStatus::Uploading),
            'media_processing_failed',
            $failureDetail,
        ),
        UploadStatus::Cancelled => $transition->asSystem($upload, UploadStatus::Cancelled),
        UploadStatus::Expired => $transition->asSystem($upload, UploadStatus::Expired),
    };
}

beforeEach(function () {
    Carbon::setTestNow('2026-08-11 10:00:00');
    $this->filesystem = new Filesystem;
    $this->dashboardDiskRoot = storage_path('framework/testing/dashboard-'.bin2hex(random_bytes(6)));
    $this->dashboardSeriesRoot = $this->dashboardDiskRoot.'-series';
    $this->dashboardArchiveRoot = $this->dashboardDiskRoot.'-archive';
    $this->dashboardArchiveSeriesRoot = $this->dashboardDiskRoot.'-archive-series';
});

afterEach(function () {
    Carbon::setTestNow();
    $this->filesystem->deleteDirectory($this->dashboardDiskRoot);
    $this->filesystem->deleteDirectory($this->dashboardSeriesRoot);
    $this->filesystem->deleteDirectory($this->dashboardArchiveRoot);
    $this->filesystem->deleteDirectory($this->dashboardArchiveSeriesRoot);
    app()->forgetInstance(ConfiguredDiskRegistry::class);
});

it('redirects the root to the unchanged named dashboard route', function () {
    $this->get('/')->assertRedirect('/dashboard');

    expect(route('dashboard', absolute: false))->toBe('/dashboard')
        ->and(Route::getRoutes()->getByName('dashboard')?->getActionName())
        ->toBe(DashboardController::class);
});

it('redirects guests from private pages to login', function (string $uri) {
    $this->get($uri)->assertRedirect(route('login'));
})->with([
    'dashboard' => '/dashboard',
    'movie upload' => '/movies/upload',
    'profile settings' => '/settings/profile',
    'password settings' => '/settings/password',
]);

it('renders the dashboard contract with disk health initially deferred', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('uploadOverview.scope', 'personal')
            ->where('uploadOverview.counts', [
                'active' => 0,
                'paused' => 0,
                'processing' => 0,
                'failed' => 0,
                'expiring' => 0,
            ])
            ->has('uploadOverview.generated_at')
            ->has('uploadOverview.expiry_warning_cutoff')
            ->has('uploadOverview.warnings.failed', 0)
            ->has('uploadOverview.warnings.expiring', 0)
            ->missing('diskOverview')
            ->reloadOnly('uploadOverview', fn (Assert $reload) => $reload
                ->has('uploadOverview')
                ->missing('diskOverview')));
});

it('uses lifecycle states for every aggregate and includes the exact expiry boundary', function () {
    $user = User::factory()->create();
    $boundary = now()->addHours(24);

    $pendingAtBoundary = dashboardUploadAtStatus($user, UploadStatus::Pending, [
        'original_filename' => 'boundary.mkv',
        'expires_at' => $boundary,
    ]);
    $uploadingOverdue = dashboardUploadAtStatus($user, UploadStatus::Uploading, [
        'original_filename' => 'overdue.mkv',
        'expires_at' => now()->subHour(),
    ]);
    $paused = dashboardUploadAtStatus($user, UploadStatus::Paused, [
        'original_filename' => 'paused.mkv',
        'expires_at' => now()->addHours(12),
    ]);
    dashboardUploadAtStatus($user, UploadStatus::Processing, [
        'expires_at' => now()->addHour(),
    ]);
    dashboardUploadAtStatus($user, UploadStatus::Failed, [
        'expires_at' => now()->addHour(),
    ]);
    dashboardUploadAtStatus($user, UploadStatus::Pending, [
        'expires_at' => null,
    ]);
    dashboardUploadAtStatus($user, UploadStatus::Completed, [
        'expires_at' => now()->addHour(),
    ]);
    dashboardUploadAtStatus($user, UploadStatus::Expired, [
        'expires_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('uploadOverview.generated_at', now()->toIso8601String())
            ->where('uploadOverview.expiry_warning_cutoff', $boundary->toIso8601String())
            ->where('uploadOverview.counts', [
                'active' => 3,
                'paused' => 1,
                'processing' => 1,
                'failed' => 1,
                'expiring' => 3,
            ])
            ->where('uploadOverview.warnings.expiring.0.uuid', $uploadingOverdue->uuid)
            ->where('uploadOverview.warnings.expiring.1.uuid', $paused->uuid)
            ->where('uploadOverview.warnings.expiring.2.uuid', $pendingAtBoundary->uuid));
});

it('keeps personal upload figures and warnings isolated from every other owner', function () {
    $user = User::factory()->create(['name' => 'Visible Owner']);
    $other = User::factory()->create([
        'name' => 'Hidden Owner',
        'email' => 'hidden-owner@example.test',
    ]);
    $visible = dashboardUploadAtStatus(
        $user,
        UploadStatus::Failed,
        ['original_filename' => 'visible-failure.mkv'],
        'A safe visible failure.',
    );
    dashboardUploadAtStatus(
        $other,
        UploadStatus::Failed,
        ['original_filename' => 'hidden-failure.mkv'],
        'A hidden failure.',
    );
    dashboardUploadAtStatus($other, UploadStatus::Pending, [
        'original_filename' => 'hidden-deadline.mkv',
        'expires_at' => now()->addHour(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('uploadOverview.counts.failed', 1)
        ->where('uploadOverview.counts.expiring', 0)
        ->where('uploadOverview.warnings.failed.0.uuid', $visible->uuid)
        ->where('uploadOverview.warnings.failed.0.can_open_recovery', true)
        ->missing('uploadOverview.warnings.failed.0.owner_name'));

    expect($response->getContent())
        ->not->toContain('hidden-failure.mkv')
        ->not->toContain('hidden-deadline.mkv')
        ->not->toContain('A hidden failure.')
        ->not->toContain('Hidden Owner')
        ->not->toContain('hidden-owner@example.test');
});

it('gives administrators global owner attribution without cross-user recovery controls', function () {
    $administrator = User::factory()->administrator()->create(['name' => 'Admin Owner']);
    $other = User::factory()->create([
        'name' => 'Movie Owner',
        'email' => 'movie-owner@example.test',
    ]);
    $administratorUpload = dashboardUploadAtStatus(
        $administrator,
        UploadStatus::Failed,
        ['original_filename' => 'admin-failure.mkv'],
    );
    $otherUpload = dashboardUploadAtStatus(
        $other,
        UploadStatus::Failed,
        ['original_filename' => 'owner-failure.mkv'],
    );
    $otherExpiry = dashboardUploadAtStatus($other, UploadStatus::Paused, [
        'original_filename' => 'owner-expiry.mkv',
        'expires_at' => now()->addHour(),
    ]);

    $response = $this->actingAs($administrator)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('uploadOverview.scope', 'installation')
        ->where('uploadOverview.counts.failed', 2)
        ->where('uploadOverview.counts.paused', 1)
        ->where('uploadOverview.counts.expiring', 1)
        ->where('uploadOverview.warnings.failed.0.uuid', $otherUpload->uuid)
        ->where('uploadOverview.warnings.failed.0.owner_name', 'Movie Owner')
        ->where('uploadOverview.warnings.failed.0.can_open_recovery', false)
        ->where('uploadOverview.warnings.failed.1.uuid', $administratorUpload->uuid)
        ->where('uploadOverview.warnings.failed.1.owner_name', 'Admin Owner')
        ->where('uploadOverview.warnings.failed.1.can_open_recovery', true)
        ->where('uploadOverview.warnings.expiring.0.uuid', $otherExpiry->uuid)
        ->where('uploadOverview.warnings.expiring.0.owner_name', 'Movie Owner')
        ->where('uploadOverview.warnings.expiring.0.can_open_recovery', false));

    expect($response->getContent())
        ->not->toContain('movie-owner@example.test')
        ->not->toContain($otherUpload->staging_relative_path)
        ->not->toContain($otherUpload->fingerprint_first_sha256)
        ->not->toContain((string) $otherUpload->token_hash);
});

it('bounds and orders warnings while replacing unsafe failure detail', function () {
    $user = User::factory()->create();
    $failedUploads = [];
    $expiringUploads = [];

    foreach (range(0, 5) as $index) {
        $failedUploads[] = dashboardUploadAtStatus(
            $user,
            UploadStatus::Failed,
            ['original_filename' => "failure-{$index}.mkv"],
            $index === 0
                ? 'Probe exposed /private/media/movie.mkv token=secret-value '.str_repeat('a', 64)
                : "Safe failure {$index}.",
        );
        Upload::query()->whereKey($failedUploads[$index])->update([
            'failed_at' => now()->subMinutes($index),
        ]);

        $expiringUploads[] = dashboardUploadAtStatus($user, UploadStatus::Pending, [
            'original_filename' => "expiry-{$index}.mkv",
            'expires_at' => $index === 0
                ? now()->subHour()
                : now()->addHours($index),
            'declared_size' => 1_000,
            'confirmed_offset' => $index * 100,
        ]);
    }

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('uploadOverview.counts.failed', 6)
        ->where('uploadOverview.counts.expiring', 6)
        ->has('uploadOverview.warnings.failed', 5)
        ->has('uploadOverview.warnings.expiring', 5)
        ->where('uploadOverview.warnings.failed.0.uuid', $failedUploads[0]->uuid)
        ->where('uploadOverview.warnings.failed.4.uuid', $failedUploads[4]->uuid)
        ->where('uploadOverview.warnings.failed.0.failure_detail', 'The upload failed during processing.')
        ->where('uploadOverview.warnings.expiring.0.uuid', $expiringUploads[0]->uuid)
        ->where('uploadOverview.warnings.expiring.4.uuid', $expiringUploads[4]->uuid)
        ->where('uploadOverview.warnings.expiring.4.progress_percentage', 40));

    expect($response->getContent())
        ->not->toContain('failure-5.mkv')
        ->not->toContain('expiry-5.mkv')
        ->not->toContain('/private/media/movie.mkv')
        ->not->toContain('secret-value')
        ->not->toContain(str_repeat('a', 64));
});

it('groups four same-partition roots into one safe volume with two logical disks', function () {
    foreach ([
        $this->dashboardDiskRoot,
        $this->dashboardSeriesRoot,
        $this->dashboardArchiveRoot,
        $this->dashboardArchiveSeriesRoot,
    ] as $root) {
        $this->filesystem->makeDirectory($root.'/.media-upload-manager/incoming', 0750, true);
    }

    file_put_contents(
        $this->dashboardDiskRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies'),
    );
    file_put_contents(
        $this->dashboardSeriesRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('movies', MediaRootKind::Series),
    );
    file_put_contents(
        $this->dashboardArchiveRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('archive'),
    );
    file_put_contents(
        $this->dashboardArchiveSeriesRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('archive', MediaRootKind::Series),
    );
    configureDashboardDisks([
        [
            'id' => 'movies',
            'label' => 'Movies',
            'movies_path' => $this->dashboardDiskRoot,
            'series_path' => $this->dashboardSeriesRoot,
            'reserve_gib' => '0',
        ],
        [
            'id' => 'archive',
            'label' => 'Archive',
            'movies_path' => $this->dashboardArchiveRoot,
            'series_path' => $this->dashboardArchiveSeriesRoot,
            'reserve_gib' => '0',
        ],
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('diskOverview')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('diskOverview.status', 'available')
                ->where('diskOverview.message', null)
                ->has('diskOverview.checked_at')
                ->has('diskOverview.volumes', 1)
                ->where('diskOverview.volumes.0.id', 'storage-volume-1')
                ->where('diskOverview.volumes.0.label', 'Storage volume 1')
                ->where('diskOverview.volumes.0.health', 'healthy')
                ->where('diskOverview.volumes.0.eligible', true)
                ->has('diskOverview.volumes.0.total_bytes')
                ->has('diskOverview.volumes.0.free_bytes')
                ->has('diskOverview.volumes.0.disks', 2)
                ->where('diskOverview.volumes.0.disks.0.id', 'movies')
                ->where('diskOverview.volumes.0.disks.0.label', 'Movies')
                ->where('diskOverview.volumes.0.disks.0.safety_reserve_bytes', 0)
                ->has('diskOverview.volumes.0.disks.0.usable_bytes')
                ->has('diskOverview.volumes.0.disks.0.roots', 2)
                ->where('diskOverview.volumes.0.disks.0.roots.0.kind', 'movies')
                ->where('diskOverview.volumes.0.disks.0.roots.1.kind', 'series')
                ->where('diskOverview.volumes.0.disks.1.id', 'archive')
                ->has('diskOverview.volumes.0.disks.1.roots', 2)));

    expect(json_encode(app(OperationalDashboardPresenter::class)->diskOverview()))
        ->not->toContain($this->dashboardDiskRoot)
        ->not->toContain($this->dashboardSeriesRoot)
        ->not->toContain($this->dashboardArchiveRoot)
        ->not->toContain($this->dashboardArchiveSeriesRoot)
        ->not->toContain('device');
});

it('keeps distinct filesystem devices in separate dashboard volumes', function () {
    foreach ([11 => $this->dashboardDiskRoot, 22 => $this->dashboardSeriesRoot] as $device => $root) {
        $this->filesystem->makeDirectory($root.'/.media-upload-manager/incoming', 0750, true);
        file_put_contents(
            $root.'/.media-upload-manager/disk.json',
            DiskMarker::encode($device === 11 ? 'primary' : 'archive'),
        );
    }

    $mediaFilesystem = new class([$this->dashboardDiskRoot => 11, $this->dashboardSeriesRoot => 22]) extends NativeMediaFilesystem
    {
        /** @param array<string, int> $devices */
        public function __construct(private readonly array $devices) {}

        public function deviceId(string $path): ?int
        {
            return $this->devices[$path] ?? parent::deviceId($path);
        }

        public function capacity(string $path): ?array
        {
            return $path === array_key_first($this->devices)
                ? ['total' => 1_000, 'free' => 700]
                : ['total' => 2_000, 'free' => 1_500];
        }
    };
    $this->instance(MediaFilesystem::class, $mediaFilesystem);
    configureDashboardDisks([
        ['id' => 'primary', 'label' => 'Primary', 'path' => $this->dashboardDiskRoot, 'reserve_gib' => '0'],
        ['id' => 'archive', 'label' => 'Archive', 'path' => $this->dashboardSeriesRoot, 'reserve_gib' => '0'],
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('diskOverview.volumes', 2)
                ->where('diskOverview.volumes.0.total_bytes', 1_000)
                ->where('diskOverview.volumes.0.disks.0.id', 'primary')
                ->where('diskOverview.volumes.1.total_bytes', 2_000)
                ->where('diskOverview.volumes.1.disks.0.id', 'archive')));
});

it('keeps an unidentified unhealthy root with its one identified disk sibling', function () {
    $this->filesystem->makeDirectory(
        $this->dashboardDiskRoot.'/.media-upload-manager/incoming',
        0750,
        true,
    );
    file_put_contents(
        $this->dashboardDiskRoot.'/.media-upload-manager/disk.json',
        DiskMarker::encode('media'),
    );
    configureDashboardDisks([[
        'id' => 'media',
        'label' => 'Media',
        'movies_path' => $this->dashboardDiskRoot,
        'series_path' => $this->dashboardSeriesRoot,
        'reserve_gib' => '0',
    ]]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('diskOverview.volumes', 1)
                ->where('diskOverview.volumes.0.health', 'unhealthy')
                ->where('diskOverview.volumes.0.eligible', false)
                ->has('diskOverview.volumes.0.disks', 1)
                ->has('diskOverview.volumes.0.disks.0.roots', 2)
                ->where('diskOverview.volumes.0.disks.0.roots.1.kind', 'series')
                ->where('diskOverview.volumes.0.disks.0.roots.1.health', 'unhealthy')
                ->where('diskOverview.volumes.0.disks.0.roots.1.reasons.0.code', 'root_missing')));

    expect(json_encode(app(OperationalDashboardPresenter::class)->diskOverview()))
        ->not->toContain($this->dashboardDiskRoot, $this->dashboardSeriesRoot, 'device');
});

it('converts invalid disk configuration to a safe deferred unavailable state', function () {
    configureDashboardDisks([[
        'id' => 'Invalid ID',
        'label' => 'Movies',
        'path' => $this->dashboardDiskRoot,
        'reserve_gib' => '0',
    ]]);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('diskOverview')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('diskOverview.status', 'unavailable')
                ->where('diskOverview.message', 'Media disk configuration is unavailable.')
                ->where('diskOverview.volumes', [])));
});

it('uses Wayfinder, deferred rescue, and upload-only nonoverlapping polling in the Vue source', function () {
    $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));
    $notificationControl = file_get_contents(resource_path('js/components/UploadResultNotificationControl.vue'));

    expect($dashboard)
        ->toContain('Upload movie')
        ->toContain(':href="movieUpload()"')
        ->toContain(':href="operations().url"')
        ->toContain('<Deferred data="diskOverview">')
        ->toContain('<template #fallback>')
        ->toContain('<template #rescue="{ reloading }">')
        ->toContain("router.reload({ only: ['diskOverview'] })")
        ->toContain('v-for="volume in diskOverview.volumes"')
        ->toContain('v-for="disk in volume.disks"')
        ->toContain('v-for="root in disk.roots"')
        ->toContain('root.kind ===')
        ->toContain('usePoll(')
        ->toContain('15_000')
        ->toContain("only: ['uploadOverview']")
        ->toContain("mode: 'rest'")
        ->toContain('UploadResultNotificationControl')
        ->toContain('useUploadResultNotifications()')
        ->toContain(':state="uploadNotifications.state.value"')
        ->toContain('@enable="uploadNotifications.requestPermission"')
        ->toContain('@disable="uploadNotifications.disableNotifications"')
        ->toContain('@test="uploadNotifications.sendTestNotification"')
        ->toContain('animate-pulse')
        ->toContain('Owner action required')
        ->not->toContain('keepAlive')
        ->not->toContain('href="/')
        ->not->toContain('useHttp')
        ->not->toContain('type="file"');

    expect($notificationControl)
        ->toContain('Upload result notifications')
        ->toContain('Enable once for this browser')
        ->toContain('succeeds or needs attention')
        ->toContain('This setting stays saved in this browser')
        ->toContain('Enable notifications')
        ->toContain('Send test')
        ->toContain('Turn off')
        ->toContain('<details')
        ->toContain('Notification setup help')
        ->toContain('set Notifications to Allow')
        ->toContain('System Settings → Notifications')
        ->toContain('Settings → System → Notifications')
        ->toContain("desktop environment's Settings →")
        ->toContain('Focus is not silencing the browser')
        ->toContain('check Do not')
        ->toContain('Do Not Disturb');

    expect(file_get_contents(resource_path('js/types/dashboard.ts')))
        ->toContain('export interface UploadOverview')
        ->toContain('export interface DiskOverview');

    expect(file_get_contents(resource_path('js/components/AppSidebar.vue')))
        ->toContain("title: 'Overview'")
        ->toContain("title: 'Library'")
        ->toContain("title: 'Add media'")
        ->toContain("title: 'System'")
        ->toContain('v-for="group in navigationGroups"')
        ->toContain(':icon="group.icon"')
        ->toContain("title: 'Upload movie'")
        ->toContain('href: movieUpload()')
        ->toContain("title: 'Shows'")
        ->toContain("title: 'Upload show episodes'")
        ->toContain('href: seriesUpload()');

    expect(file_get_contents(resource_path('js/components/NavMain.vue')))
        ->toContain('title: string;')
        ->toContain('SidebarGroupContent')
        ->toContain('<component :is="icon"')
        ->toContain('before:bg-sidebar-border')
        ->toContain('group-data-[active=true]/nav-item:bg-sidebar-primary')
        ->not->toContain(':is="item.icon"')
        ->not->toContain('>Platform<');

    expect(file_get_contents(resource_path('js/components/AppLogoIcon.vue')))
        ->toContain('M24 33V16')
        ->and(file_get_contents(public_path('images/media-upload-manager-logo.svg')))
        ->toContain('Media Upload Manager')
        ->and(file_get_contents(base_path('README.md')))
        ->toContain('public/images/media-upload-manager-logo.svg')
        ->and(file_get_contents(resource_path('js/components/AppHeader.vue')))
        ->toContain('https://github.com/VaclavKlima/MediaUploadManager')
        ->not->toContain('laravel/vue-starter-kit');
});
