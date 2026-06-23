<?php

namespace App\Http\Controllers\Debug;

use App\Http\Controllers\Controller;
use App\Services\AffiliateWorkerClient;
use Illuminate\View\View;

class PlaywrightController extends Controller
{
    public function __construct(
        private AffiliateWorkerClient $worker,
    ) {}

    public function index(): View
    {
        $online = $this->worker->ping();
        $playwrightResult = null;

        if ($online) {
            $playwrightResult = $this->worker->testPlaywright();
        }

        return view('debug.playwright', compact('online', 'playwrightResult'));
    }
}
