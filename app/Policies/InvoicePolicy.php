<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\PaymentOperation;
use App\Models\User;
use App\Services\BillmanagerMigration\AccountAccess;

class InvoicePolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admin.invoices.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        return $this->adminPermission($user, 'admin.invoices.view') || AccountAccess::canRead($user, $invoice);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('admin.invoices.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Invoice $invoice): bool
    {
        return $this->adminPermission($user, 'admin.invoices.update') || $invoice->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Invoice $model): bool
    {
        return !$model->transactions()->whereNotNull('original_allocation')->exists() && !InvoicePaidProcessing::whereKey($model->id)->exists() && !PaymentOperation::where('invoice_id', $model->id)->exists() && $user->hasPermission('admin.invoices.delete');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('admin.invoices.deleteAny');
    }
}
