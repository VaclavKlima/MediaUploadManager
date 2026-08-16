<?php

namespace App\Policies;

use App\Models\Series;
use App\Models\User;

class SeriesPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->disabled_at === null;
    }

    public function view(User $user, Series $series): bool
    {
        return $user->disabled_at === null;
    }

    public function create(User $user): bool
    {
        return $user->disabled_at === null;
    }

    public function update(User $user, Series $series): bool
    {
        return $user->isAdministrator();
    }

    public function delete(User $user, Series $series): bool
    {
        return false;
    }
}
