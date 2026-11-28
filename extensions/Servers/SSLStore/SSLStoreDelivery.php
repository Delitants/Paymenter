<?php

namespace Paymenter\Extensions\Servers\SSLStore;

use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SSLStoreDelivery
{
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
