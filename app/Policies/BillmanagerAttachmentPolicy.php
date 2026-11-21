<?php

namespace App\Policies;

use App\Models\BillmanagerAttachment;
use App\Models\User;

class BillmanagerAttachmentPolicy
{
    public function view(User $user, BillmanagerAttachment $attachment): bool
    {
        return $user->hasPermission('admin.tickets.view');
    }
}
