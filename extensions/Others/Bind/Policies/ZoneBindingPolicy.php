<?php

namespace Paymenter\Extensions\Others\Bind\Policies;

use App\Models\User;
use App\Policies\BasePolicy;
use Paymenter\Extensions\Others\Bind\BindAccess;
use Paymenter\Extensions\Others\Bind\Models\ZoneBinding;

final class ZoneBindingPolicy extends BasePolicy
{
    public function viewAny(User $u): bool
    {
        return $this->adminPermission($u, 'admin.bind.view');
    }

    public function create(User $u): bool
    {
        return $this->adminPermission($u, 'admin.bind.approve');
    }

    public function view(User $u, ZoneBinding $b): bool
    {
        try {
            BindAccess::assertAllowed($u, $b, 'inspect');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function update(User $u, ZoneBinding $b): bool
    {
        try {
            BindAccess::assertAllowed($u, $b, 'replace_records');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function delete(User $u, ZoneBinding $b): bool
    {
        return false;
    }
}
