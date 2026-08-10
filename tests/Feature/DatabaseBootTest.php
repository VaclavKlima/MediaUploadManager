<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('boots against the configured database with the application schema', function () {
    $connection = DB::connection();
    $driver = $connection->getDriverName();
    $database = $connection->getDatabaseName();

    expect($driver)->toBeIn(['mysql', 'sqlite'])
        ->and($driver === 'mysql' ? $database : ':memory:')
        ->toBe($driver === 'mysql' ? 'media_upload_manager_testing' : ':memory:')
        ->and(DB::select('select 1 as result')[0]->result)->toBe(1)
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasColumns('library_findings', ['path_key', 'relative_path']))->toBeTrue()
        ->and(User::query()->count())->toBe(0);
});
