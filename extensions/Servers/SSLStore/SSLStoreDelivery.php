<?php

namespace Paymenter\Extensions\Servers\SSLStore;

use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SSLStoreDelivery
{
    public function renewal(Service $service): Response
    {
        Gate::authorize('update', $service);
        try {
            [, $api] = (new SSLStoreOrders)->confirmedOrder($service, allowSuspended: true);
            abort_unless($api->renewalsEnabled(), 409);
        } catch (RuntimeException) {
            abort(409, __('Certificate renewal is unavailable.'));
        }

        return $this->renewalPage($service);
    }

    public function previewRenewal(Request $request, Service $service): Response
    {
        Gate::authorize('update', $service);
        $csr = $request->input('csr');
        $email = $request->input('approval_email', '') ?? '';
        abort_unless(is_string($csr) && is_string($email) && strlen($csr) <= 16384 && strlen($email) <= 254, 422);
        try {
            $preview = (new SSLStoreRenewals)->preview($service, $csr, $email);
        } catch (RuntimeException) {
            abort(422, __('SSLStore could not verify the renewal request. Check the certificate names and approval email.'));
        }

        return $this->renewalPage($service, $preview);
    }

    public function confirmRenewal(Request $request, Service $service): RedirectResponse
    {
        Gate::authorize('update', $service);
        $token = $request->input('quote');
        abort_unless(is_string($token) && $request->boolean('accepted'), 422, __('Accept the subscriber agreement to continue.'));
        try {
            $invoice = (new SSLStoreRenewals)->request($service, $token, accepted: true);
        } catch (RuntimeException) {
            abort(409, __('The certificate renewal quote changed. Return to the service and review your renewal.'));
        }

        return redirect()->route('invoices.show', $invoice)->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function renewalPage(Service $service, ?array $preview = null): Response
    {
        return response()->view('services.sslstore-renewal', ['service' => $service, 'preview' => $preview],
            headers: ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function enroll(Service $service): RedirectResponse
    {
        Gate::authorize('update', $service);
        try {
            $link = (new SSLStoreOrders)->open($service);
        } catch (RuntimeException) {
            abort(409, __('Certificate enrollment is unavailable.'));
        }

        return redirect()->away($link)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function download(Service $service): StreamedResponse
    {
        Gate::authorize('update', $service);
        try {
            $archive = (new SSLStoreOrders)->download($service);
        } catch (RuntimeException) {
            abort(409, __('The certificate is unavailable for download.'));
        }

        return response()->streamDownload(function () use ($archive) {
            echo $archive;
        }, 'certificate-' . $service->id . '.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store']);
    }
}
