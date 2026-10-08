<?php

namespace App\Services\Accounts;

use App\Models\AccountDowngradeReceipt;
use App\Models\AccountMovement;
use App\Models\AccountReversalReservation;
use App\Models\AccountWallet;
use App\Models\Invoice;
use App\Models\InvoicePaidProcessing;
use App\Models\PaymentOperation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DepositLifecycle
{
    public function creditPaidInvoice(Invoice $invoice): ?AccountMovement
    {
        $result = DB::transaction(function () use ($invoice) {
            $write = function () use ($invoice) {
                $current = AccountPaymentLocks::currentInvoices([$invoice->id])->firstWhere('id', $invoice->id);
                // This lookup only chooses the adapter. Protected native cash writes
                // serialize the owner and reject any concurrently created wallet.
                if (!AccountWallet::where('user_id', $current->user_id)->where('currency_code', $current->currency_code)->exists()) {
                    return null;
                }
                $receipt = InvoicePaidProcessing::whereKey($current->id)->lockForUpdate()->firstOrFail();
                $context = AccountWriteContext::paidDeposit($receipt);

                return $this->postIncome($context, 'native-deposit:' . $current->id);
            };
            if (AccountPaymentLocks::active()) {
                return $write();
            }

            return (new AccountPaymentLocks)->duringIncomingEvidence([$invoice->id], fn () => $write());
        }, 3);
        if ($result instanceof IncomingPostingRequired) {
            throw $result;
        }

        return $result;
    }

    public function creditCompletedDowngrade(ServiceUpgrade $upgrade): ?AccountMovement
    {
        $result = DB::transaction(function () use ($upgrade) {
            $snapshot = ServiceUpgrade::findOrFail($upgrade->id);
            $write = function () use ($snapshot) {
                if (!AccountPaymentLocks::coversUpgrade($snapshot->id)) {
                    throw new RuntimeException('Downgrade verification requires the original locked upgrade.');
                }
                $receipt = AccountDowngradeReceipt::where('service_upgrade_id', $snapshot->id)->lockForUpdate()->first();
                if (!$receipt) {
                    $service = $snapshot->service;
                    if (AccountWallet::where('user_id', $service->user_id)->where('currency_code', $service->currency_code)->exists()) {
                        throw new RuntimeException('A managed downgrade requires its completed original receipt.');
                    }

                    return null;
                }
                if (!AccountWallet::where('user_id', $receipt->user_id)->where('currency_code', $receipt->currency_code)->exists()) {
                    return null;
                }
                $context = AccountWriteContext::completedDowngrade($snapshot);

                return $this->postIncome($context, 'native-downgrade:' . $snapshot->id);
            };
            if (AccountPaymentLocks::active()) {
                AccountPaymentLocks::currentInvoices(array_filter([$snapshot->invoice_id]), $snapshot->service_id);

                return $write();
            }

            return (new AccountPaymentLocks)->duringIncomingEvidence(array_filter([$snapshot->invoice_id]), fn () => $write(), [], [$snapshot->service_id], [$snapshot->id]);
        }, 3);
        if ($result instanceof IncomingPostingRequired) {
            throw $result;
        }

        return $result;
    }

    public function reserveRefund(PaymentOperation $operation): ?AccountReversalReservation
    {
        return DepositReversals::reserve($operation);
    }

    public function finalizeRefund(PaymentOperation $operation): void
    {
        if (!AccountPaymentLocks::active()) {
            if (DB::transactionLevel() !== 0) {
                // Existing unmanaged native outcomes have no account principal.
                // Managed sources still reject an incomplete dependency frame.
                if (DepositReversalReceipt::facts($operation) === null) {
                    return;
                }
                throw new RuntimeException('Public native retry must enter its complete dependency frame from the top level.');
            }
            $actor = Auth::user();
            if (!$actor) {
                throw new RuntimeException('An authenticated reconciliation administrator is required.');
            }
            $sourceOwners = AccountMovement::where('kind', 'deposit')->where('reference_id', $operation->invoice_id)->pluck('user_id')->all();
            DB::transaction(function () use ($operation, $actor, $sourceOwners) {
                (new AccountPaymentLocks)->during([$operation->invoice_id], function () use ($operation, $actor, $sourceOwners) {
                    AccountPaymentLocks::lockFinancialUsers($actor, $sourceOwners);
                    $stored = PaymentOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
                    if ($stored->request_fingerprint !== $operation->request_fingerprint) {
                        throw new RuntimeException('Native recovery original request identity changed.');
                    }
                    $this->finalizeRefund($stored);
                }, [$operation->gateway_id]);
            }, 3);

            return;
        }
        $proof = DepositReversalReceipt::facts($operation);
        if (!$proof) {
            return;
        }
        DepositReversals::authorize($operation, true);
        if (!in_array($operation->state, ['succeeded', 'failed'], true)) {
            return;
        }
        DepositReversalReceipt::assertTerminal($operation);
        try {
            DB::transaction(function () use ($operation, $proof) {
                if ($operation->state === 'failed' || $proof['principal'] === '0.0000') {
                    DepositReversals::releaseOrFeeOnly($operation);
                } else {
                    $context = AccountWriteContext::depositOperation($operation);
                    $key = 'native-deposit-operation:' . $operation->id;
                    (new WalletLedger)->post($context, $context->delta(), $context->action, $key, $context->fingerprint($key));
                    DepositReversals::clearIssue($operation);
                }
            });
        } catch (RuntimeException|DomainException $exception) {
            // Preserve the actual terminal provider/admin receipt; the savepoint
            // rolled back principal effects before evidence is marked incomplete.
            DepositReversals::markIssue($operation);
        }
    }

    public function transitionManualDeposit(PaymentOperation $operation): void
    {
        $proof = DepositReversalReceipt::facts($operation, true);
        if (!$proof) {
            return;
        }
        DepositReversals::authorize($operation, true);
        $context = AccountWriteContext::depositOperation($operation);
        $key = 'native-deposit-operation:' . $operation->id;
        (new WalletLedger)->post($context, $context->delta(), $context->action, $key, $context->fingerprint($key));
    }

    private function postIncome(AccountWriteContext $context, string $key): AccountMovement|IncomingPostingRequired
    {
        $context->assertReceiptFactsCurrent();
        User::whereKey($context->ownerId)->lockForUpdate()->firstOrFail();
        AccountWallet::where('user_id', $context->ownerId)->where('currency_code', $context->currency)->lockForUpdate()->firstOrFail();
        try {
            return (new WalletLedger)->post($context, $context->delta(), $context->action, $key, $context->fingerprint($key));
        } catch (RuntimeException|DomainException $exception) {
            // The ledger owns its savepoint. Append verified evidence after its
            // rollback and let the native payment transaction commit that evidence.
            return new IncomingPostingRequired(NativePostingIssue::record($context), $exception);
        }
    }
}
