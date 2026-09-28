<?php

namespace App\Models;

class BillmanagerRecord extends Model
{
    protected $table = 'billmanager_records';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected $casts = ['payload' => 'encrypted:array'];

    public static function tablesVisibleTo(User $user): array
    {
        $groups = [
            'admin.tickets.view' => ['tickets', 'ticket_messages', 'ticket_notes', 'ticket_history', 'ticket_attachments', 'ticket_authors'],
            'admin.users.view' => ['users', 'accounts', 'profiles'],
            'admin.invoices.view' => ['payments', 'invoices', 'invoiceitems', 'subaccounts', 'expenses', 'expense_changes', 'expense_payments', 'invoice_history', 'invoiceitem_history', 'payment_history', 'payment_refunds', 'invoiceitem_expenses', 'invoiceitem_payments'],
            'admin.services.view' => ['items', 'addons', 'itemparams', 'pricelists', 'prices', 'pricelistprices', 'fixedprices', 'fixedpricesprice', 'itemtypes', 'discounts', 'discountprices'],
        ];
        $tables = [];
        foreach ($groups as $permission => $names) {
            if ($user->hasPermission($permission)) {
                $tables = array_merge($tables, $names);
            }
        }

        return $tables;
    }
}
