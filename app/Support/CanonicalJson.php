<?php

namespace App\Support;

final class CanonicalJson
{
    public static function equivalent(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) {
            return $left === $right;
        }

        if (array_is_list($left) !== array_is_list($right) || count($left) !== count($right)) {
            return false;
        }

        if (array_is_list($left)) {
            foreach ($left as $index => $value) {
                if (! self::equivalent($value, $right[$index])) {
                    return false;
                }
            }

            return true;
        }

        $leftKeys = array_keys($left);
        $rightKeys = array_keys($right);
        sort($leftKeys);
        sort($rightKeys);

        if ($leftKeys !== $rightKeys) {
            return false;
        }

        foreach ($leftKeys as $key) {
            if (! self::equivalent($left[$key], $right[$key])) {
                return false;
            }
        }

        return true;
    }
}
