<?php

namespace App\Http\Controllers;

use App\Models\BillmanagerAttachment;

class BillmanagerAttachmentController extends Controller
{
    public function download(BillmanagerAttachment $legacyAttachment)
    {
        $path = realpath($legacyAttachment->local_path);
        $root = realpath(storage_path('app/tickets/legacy'));
        abort_unless($path && $root && str_starts_with($path, $root . '/') && is_file($path), 404);

        return response()->download($path, $legacyAttachment->filename, ['X-Content-Type-Options' => 'nosniff']);
    }
}
