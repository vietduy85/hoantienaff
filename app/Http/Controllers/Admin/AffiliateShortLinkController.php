<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LinkRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AffiliateShortLinkController extends Controller
{
    public function index(Request $request): View
    {
        $user = auth()->user();
        $pinnedLinks = LinkRequest::forUser($user)->pinned()->latest('pinned_at')->limit(5)->get();
        $recentLinks = LinkRequest::forUser($user)->latest()->limit(5)->get();

        return view('admin.affiliate-short-link.index', compact('pinnedLinks', 'recentLinks'));
    }
}
