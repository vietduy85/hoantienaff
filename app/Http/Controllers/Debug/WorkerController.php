<?php

namespace App\Http\Controllers\Debug;

use App\Http\Controllers\Controller;
use App\Services\AffiliateWorkerClient;
use Illuminate\View\View;

class WorkerController extends Controller
{
    public function __construct(
        private AffiliateWorkerClient $worker,
    ) {}

    public function index(): View
    {
        $health = $this->worker->health();
        $online = $this->worker->ping();

        return view('debug.worker', compact('health', 'online'));
    }
}
