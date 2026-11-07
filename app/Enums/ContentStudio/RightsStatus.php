<?php

namespace App\Enums\ContentStudio;

/**
 * Same vocabulary as skill `tiktok-growth-studio` (references/storage-and-report.md).
 */
enum RightsStatus: string
{
    case Owned = 'owned';
    case Licensed = 'licensed';
    case PermissionGranted = 'permission_granted';
    case ReferenceOnly = 'reference_only';
    case RightsUnverified = 'rights_unverified';
    case NotPermitted = 'not_permitted';
    case Expired = 'expired';

    /**
     * Only these may appear in a published/scheduled post. Everything else may
     * be kept for research but never goes into the publish path.
     */
    public function allowsPublishing(): bool
    {
        return in_array($this, [self::Owned, self::Licensed, self::PermissionGranted], true);
    }
}
