<?php

namespace App\Http\Controllers;

use App\Actions\Auth\Logout;
use Illuminate\Http\RedirectResponse;

class LogoutController extends Controller
{
    public function __invoke(Logout $logout): RedirectResponse
    {
        $logout->execute();

        return redirect('/');
    }
}
