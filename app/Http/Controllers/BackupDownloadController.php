<?php

namespace App\Http\Controllers;

use App\Services\Backup\BackupStore;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** A database backup downloaded — by a super admin only (BackupStore::mayUse()). */
class BackupDownloadController extends Controller
{
    public function __invoke(BackupStore $store, string $name): BinaryFileResponse
    {
        abort_unless(BackupStore::mayUse(), 403);
        abort_unless(BackupStore::isBackupName($name) && is_file($path = $store->path($name)), 404);

        return response()->download($path, $name);
    }
}
