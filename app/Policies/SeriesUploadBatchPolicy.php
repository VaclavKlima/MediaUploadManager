<?php

namespace App\Policies;

use App\Models\SeriesUploadBatch;
use App\Models\User;

class SeriesUploadBatchPolicy
{
    public function view(User $user, SeriesUploadBatch $batch): bool
    {
        return $user->isAdministrator() || $batch->user_id === $user->getKey();
    }

    public function update(User $user, SeriesUploadBatch $batch): bool
    {
        return $this->view($user, $batch);
    }
}
