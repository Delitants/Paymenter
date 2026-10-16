<?php

namespace App\Services\Accounts;

use App\Models\AccountMovement;
use App\Models\AccountReversalReservation;
use App\Models\AccountWallet;
use App\Models\Credit;
use App\Models\PaymentOperation;
use App\Models\User;
use App\Services\BillmanagerMigration\AccountAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class AccountStatement
{
    public function forReader(User $actor, User $owner, string $currency): array
    {
        return DB::transaction(function () use ($actor, $owner, $currency) {
            $ids = array_values(array_unique([$actor->id, $owner->id]));
            sort($ids, SORT_NUMERIC);
            $users = [];
            foreach ($ids as $id) {
                $users[$id] = User::whereKey($id)->lockForUpdate()->firstOrFail();
            }
            $actor = $users[$actor->id];
            $owner = $users[$owner->id];
            $actor->setRelation('role', $actor->role()->lockForUpdate()->first());
            if (Auth::check() && Auth::id() !== $actor->id) {
                throw new AuthorizationException('Account statement reader identity changed.');
            }
            $admin = $actor->hasPermission('admin.account_funding.view');
            if (!$admin && !AccountAccess::canRead($actor, new AccountWallet(['user_id' => $owner->id]), true)) {
                throw new AuthorizationException('This account statement is private.');
            }
            $wallet = AccountWallet::where('user_id', $owner->id)->where('currency_code', $currency)->lockForUpdate()->firstOrFail();
            $quote = (new WalletLedger)->quote($owner, $currency);
            $cash = Credit::where('user_id', $owner->id)->where('currency_code', $currency)->orderBy('id')->lockForUpdate()->get();
            $cashAmount = $cash->count() === 1 ? $cash->first()->amount : '0.00';
            $movements = $wallet->movements()->orderByDesc('id')->paginate(25);
            $movements->through(function (AccountMovement $movement) use ($admin) {
                $row = ['label' => match ($movement->kind) {
                    'invoice_funding' => 'Account payment','internal_reversal' => 'Account payment reversal','deposit' => 'Deposit','downgrade' => 'Plan downgrade credit','deposit_refund' => 'Deposit refund','manual_unsettle' => 'Manual deposit unsettlement','manual_restore' => 'Manual deposit restoration',default => 'Account movement'
                },
                    'delta' => $movement->delta, 'balance_before' => $movement->balance_before, 'balance_after' => $movement->balance_after, 'date' => $movement->created_at->utc()->format('Y-m-d H:i:s'),
                    'cash_portion' => $movement->kind === 'invoice_funding' ? ($movement->payload['cash'] ?? null) : null, 'borrowed_portion' => $movement->kind === 'invoice_funding' ? ($movement->payload['debt'] ?? null) : null];
                if ($admin) {
                    $row['audit'] = ['id' => $movement->id, 'actor_id' => $movement->actor_id, 'origin' => $movement->origin, 'reference_type' => $movement->reference_type, 'reference_id' => $movement->reference_id,
                        'linked_reversal_id' => $movement->linked_reversal_id, 'request_key' => $movement->request_key, 'reason' => $movement->payload['reason'] ?? null];
                }

                return $row;
            });
            $reservations = AccountReversalReservation::where('wallet_id', $wallet->id)->orderByDesc('id')->get()->map(function ($reservation) use ($admin) {
                $operation = PaymentOperation::findOrFail($reservation->payment_operation_id);
                $row = ['principal' => $reservation->principal, 'state' => $reservation->state, 'posting_required' => $reservation->posting_required,
                    'label' => $reservation->posting_required ? match ($operation->state) {
                        'succeeded' => 'Refund confirmed; account update pending','failed' => 'Refund declined; account update pending',default => 'Refund needs review'
                    } : match ($reservation->state) {
                        'reserved' => 'Refund awaiting confirmation','consumed' => 'Refund posted','released' => 'Refund reservation released',default => 'Refund needs review'
                    }];
                if ($admin) {
                    $row['audit'] = ['id' => $reservation->id, 'payment_operation_id' => $operation->id, 'original_transaction_id' => $operation->original_transaction_id, 'state' => $operation->state];
                }

                return $row;
            });

            return ['currency' => $currency, 'quote' => $quote, 'cash' => $cashAmount, 'movements' => $movements, 'reservations' => $reservations, 'administrative' => $admin, 'opening' => $admin ? $wallet->opening_evidence : null];
        }, 3);
    }
}
