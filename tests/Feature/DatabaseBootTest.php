<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('boots against an empty sqlite database', function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(DB::select('select 1 as result')[0]->result)->toBe(1)
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(User::query()->count())->toBe(0);
});
