<?php

namespace App\Policies;

use App\Models\SeriesEpisode;
use App\Models\User;

class SeriesEpisodePolicy
{
    public function view(User $user, SeriesEpisode $episode): bool
    {
        return $user->disabled_at === null;
    }

    public function replace(User $user, SeriesEpisode $episode): bool
    {
        $current = $episode->currentMediaFile()->with('sourceUpload')->first();

        return $current !== null && ($user->isAdministrator() || $current->sourceUpload?->user_id === $user->getKey());
    }
}
