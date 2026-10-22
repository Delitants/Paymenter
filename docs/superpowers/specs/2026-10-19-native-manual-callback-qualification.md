# Session-bound native manual-payment qualification design

Status: written amendment and offline implementation plan approved; generic offline probe implemented and reviewed. Product financial integration and native execution remain separate review stages. The inner callback replaces the proposed outer epAfter journal in this design; existing economic, provider and handover gates remain.

## Purpose and evidence

Prove that the original native manual payment business effect and an owned QA journal can commit or roll back together, before admitting a production source receipt bridge. Use no customer records or public/provider payment, and keep BILLmanager billing authoritative. Static inspection binds the installed payment-module constructor's parent `payment` plus child `setpaid` to aPaymentStatus with requested status4, shared flow name `payment.status`, and the existing ProcessOne routine. That routine calls money::UpdatePaymentStatus between explicit cache commits. The inner routine invokes installed event::PaymentStatusChanged(old_status,new_status,payment). These are static facts for the hash-pinned installed vendor build, not native runtime acceptance.

The callback supplies neither Session nor Cache. The public SDK provides InternalCall(const Session&,func,params). Its parameterless Authenticate() returns maximum lvInternal authority, and the function-only InternalCall overloads also run as lvInternal. None is a caller-context getter. The proposal therefore uses the supplied-session overload and explicit scoped state. It does not silently hook or invoke payment.setpaid.one.

## Recommended architecture

Add a test-only manual dispatcher to the existing guarded QA harness. After validating its exact authority/run/case/actor/nonce, cache and immutable fresh fixture registry, it holds an exception-safe C++ RAII scope across one synchronous `InternalCall(ses,"payment.setpaid",fixed_params)` invocation. The original native group handler performs the business operation. The dispatcher never directly changes the payment or subaccount. A native error remains an error; retain the original XML/error evidence and do not invent duplicate success or provider ACKs.

Register one version-pinned PaymentStatusChanged callback. Its first operation checks payment-ID membership using the already admitted in-memory owned registry; outside-registry callbacks return without SQL, files or changed native behavior. For a registered test ID, require the active exact case context, one-use intent, current worker identity, supplied cache object and original supplied actor. A missing, stale, nested, foreign-worker or mismatched owned context refuses the test path before a QA write. Never carry a Session/Cache pointer into another thread, silently recreate context, call Authenticate(), use an unbound InternalCall overload, or switch to .one.

The RAII scope survives the native handler's initial cache commit without using a custom Job. It is destroyed when the synchronous call returns or throws, preventing stale thread-local context from leaking into a later request. Consume the callback's one-use attempt state before a phase's journal operation/intentional exception. Retain real source effects if a test fails; an absent QA row or surviving credit is a failed observation, not permission to replay the payment.

Inside that guarded callback, observe the actual owned native business delta and use the same supplied cache for the owned QA journal. Test before/after-journal exception phases. No Begin/Commit/Rollback, new cache/connection, custom Job, precommit wait, source symbol override, SQL balance repair or standalone synthetic Fire of the vendor callback store is permitted. A future native result must establish the actual worker/cache/actor path; synchronous API declarations alone do not prove it.

## Notification and provider isolation

The inspected AccountNotify constructor selects `id FROM user WHERE account=<account> AND enabled='on' AND (remoteid IS NULL OR remotelevel=1)`. Send returns without calling Notify::Send when its recipient vector is empty. Restricted notices bypass ordinary opt-outs, but they do not create recipients in this constructor. This establishes a static AccountNotify isolation condition: every associated fixture user must be disabled at the user-row level. A disabled client label, unverified/fake email or ordinary unsubscribes is insufficient.

Before any financial admission, a fresh source-only verifier must prove that the exact owned account has no enabled users satisfying the native query, and that this remains true after each case. All associated users must belong to the registry, with no customer/staff linkage. Disable only those owned users through a separately qualified native operation. Do not change global templates, gateways, schedulers, existing queues or subscriptions. Verify owned notification/queue absence source-side without exporting notification bodies or private recipients.

This static AccountNotify result does not qualify direct EmailNotify, forced registration/recovery/verification, invoice or other creation paths. The exact supported fresh fixture-creation operation and its notification controls remain prerequisites. No financial fixture can be created until that contract and safe provider isolation are concrete. New/retained fixture payments must have no BillOrder, invoice, recurring/stored method, service, expense or external-provider linkage beyond the exact approved native manual method. No provider invocation or refund is an acceptable test/cleanup mechanism.

## Fresh operation and fixtures

Prepare a new private registry for a disabled owned account, its USD subaccount and four `0.0100` unpaid native manual payments. Bind account/subaccount/currency/payment/method/actor/module/nonce and preimages. No old QA namespace, retired table/control pair, zero-price fixture or retired test resources is reusable authority. Derive IDs from supported native creation replies and independently verify exact ownership/link absence; do not guess IDs or insert raw accounting rows. The amount and accounting audit limits remain the original approved manual-plan limits.

Native test execution requires a concrete fresh build/install/startup/recovery/fixture packet, independent reader and explicit approval. Retain original failures, exact consumed slots and expiry. No automatic retries or third recovery pair. All paid audit records remain held unless a separately qualified native reversal is admitted; never delete a paid payment or repair balances directly.

## Callback lifetime and recovery

The installed Store exposes Add/Fire, not removal. GNU UNIQUE value/guard symbols identify a candidate shared instance but do not prove actual cross-DSO resolution. Bind the exact SDK/library/compiler/module identities, inspect the new candidate and qualify real registration in the approved operation. The callback remains guarded/revoked until the experimental manager process exits. The reserved recovery must prove the old process is gone and the normal process has no experimental module or handlers before claiming unloading/cleanup. Do not destroy a lambda target while its callback remains stored, or rely on DropEvent to remove this different callback mechanism.

## Acceptance and interpretation

Repeat F1-F5 for the new build; prior framework acceptance is historical only. Execute M1-M5 once in their admitted order with independent original payment/subaccount/QA observations: one exact credit/journal; pre-journal exception rollback; post-journal exception rollback; native duplicate behavior; lost-result reconciliation without another credit. A surviving credit after an injected exception fails atomicity. Scope/actor/worker/cache/context failures must not be relabelled business successes. Native decoder and collector must preserve exact error/duplicate behavior.

Report precisely that this tests the original handler through the supplied-session SDK invocation. Do not promote it to direct external group invocation, every provider callback, source bridge deployment, opening inclusion or financial handover. If the native path uses a foreign worker/cache/actor or the scoped callback cannot unwind safely, stop and retain an explicit blocker; no fallback hook, new transaction or weakened requirement.

## Alternatives and next stage

The old outer epAfter journal remains unsuitable for acceptance because the native routine commits internally. A direct child-action hook would require its own supported request/lifetime contract and explicit design; none is substituted here. The recommended dispatcher gives the test a lexical lifetime and an explicit actor-preserving API entry, while leaving the original business handler intact.

Approval of this written amendment permits preparing the detailed offline implementation plan for the generic probe and its refusal tests. It does not approve product implementation, a source build/write, installation/load/restart, a client/payment fixture, source financial test, provider action, release or handover. Implementation and the concrete native operation remain the subsequent reviewed stages.
