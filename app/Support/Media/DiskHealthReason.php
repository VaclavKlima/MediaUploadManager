<?php

namespace App\Support\Media;

enum DiskHealthReason: string
{
    case RootMissing = 'root_missing';
    case UnsafeRoot = 'unsafe_root';
    case MountInfoUnavailable = 'mount_info_unavailable';
    case NotExactMountPoint = 'not_exact_mountpoint';
    case MarkerMissing = 'marker_missing';
    case MarkerInvalid = 'marker_invalid';
    case MarkerMismatch = 'marker_mismatch';
    case IncomingMissing = 'incoming_missing';
    case IncomingUnreadable = 'incoming_unreadable';
    case IncomingUnwritable = 'incoming_unwritable';
    case ProbeFailed = 'probe_failed';
    case CapacityUnavailable = 'capacity_unavailable';
    case BelowSafetyReserve = 'below_safety_reserve';
    case CheckFailed = 'check_failed';

    public function message(): string
    {
        return match ($this) {
            self::RootMissing => 'The configured disk root is unavailable.',
            self::UnsafeRoot => 'The configured disk root is unsafe.',
            self::MountInfoUnavailable => 'Mount information is unavailable.',
            self::NotExactMountPoint => 'The disk root is not an exact mount point.',
            self::MarkerMissing => 'The disk has not been initialized.',
            self::MarkerInvalid => 'The disk marker is malformed.',
            self::MarkerMismatch => 'The disk marker does not match this disk.',
            self::IncomingMissing => 'The private incoming directory is unavailable.',
            self::IncomingUnreadable => 'The private incoming directory is not readable.',
            self::IncomingUnwritable => 'The private incoming directory is not writable.',
            self::ProbeFailed => 'The filesystem write and rename probe failed.',
            self::CapacityUnavailable => 'Filesystem capacity is unavailable.',
            self::BelowSafetyReserve => 'Free space is below the safety reserve.',
            self::CheckFailed => 'The disk health check could not be completed.',
        };
    }
}
