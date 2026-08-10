<?php

namespace App\Support\Pulse;

use Laravel\Pulse\Storage\DatabaseStorage as PulseDatabaseStorage;

final class DatabaseStorage extends PulseDatabaseStorage
{
    protected function requiresManualKeyHash(): bool
    {
        return in_array($this->connection()->getDriverName(), ['mariadb', 'mysql', 'sqlite'], true);
    }
}
