<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FinanceService;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function index(): View
    {
        return view('admin.finance.index', FinanceService::dashboard());
    }
}
