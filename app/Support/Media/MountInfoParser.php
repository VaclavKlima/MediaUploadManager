<?php

namespace App\Support\Media;

final class MountInfoParser
{
    /**
     * @return list<string>
     */
    public function mountPoints(string $mountInfo): array
    {
        $mountPoints = [];
        $lines = preg_split('/\R/', $mountInfo) ?: [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $separatorPosition = strpos($line, ' - ');

            if ($separatorPosition === false) {
                continue;
            }

            $fields = explode(' ', substr($line, 0, $separatorPosition));

            if (count($fields) < 6) {
                continue;
            }

            $mountPoint = $this->decodeField($fields[4]);

            if ($mountPoint === null || ! str_starts_with($mountPoint, '/')) {
                continue;
            }

            $mountPoints[] = $mountPoint === '/' ? '/' : rtrim($mountPoint, '/');
        }

        return array_values(array_unique($mountPoints));
    }

    private function decodeField(string $field): ?string
    {
        $escapes = [
            '\\011' => "\t",
            '\\012' => "\n",
            '\\040' => ' ',
            '\\134' => '\\',
        ];

        if (str_contains(str_replace(array_keys($escapes), '', $field), '\\')) {
            return null;
        }

        return strtr($field, $escapes);
    }
}
