<?php

declare(strict_types=1);

namespace App\Services\Acl\Api;

/**
 * Declaration-only stand-in for Pelican's AdminAcl permission constants.
 *
 * Values are those of Pelican v1.0.0-beta38 (app/Services/Acl/Api/AdminAcl.php):
 * NONE = 0, READ = 1, WRITE = 2. The fixture parent uses AdminAcl::NONE as the
 * default for `$permission`; the plugin requests use READ/WRITE.
 */
final class AdminAcl
{
    public const NONE = 0;

    public const READ = 1;

    public const WRITE = 2;
}
