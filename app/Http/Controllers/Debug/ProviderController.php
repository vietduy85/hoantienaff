<?php

namespace App\Http\Controllers\Debug;

use App\Http\Controllers\Controller;
use App\Services\ProviderFactory;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProviderController extends Controller
{
    public function __construct(
        private ProviderFactory $providerFactory,
    ) {}

    public function index(): View
    {
        return view('debug.provider', [
            'result' => null,
            'url' => null,
        ]);
    }

    public function test(Request $request): View
    {
        $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $url = $request->input('url');

        $platform = $this->providerFactory->detectPlatform($url);
        $className = null;
        $result = null;
        $error = null;

        try {
            $provider = $this->providerFactory->getProvider($url);
            $className = get_class($provider);
            $classShortName = class_basename($provider);
            $result = $provider->createLink($url);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        return view('debug.provider', compact('url', 'platform', 'className', 'classShortName', 'result', 'error'));
    }
}
