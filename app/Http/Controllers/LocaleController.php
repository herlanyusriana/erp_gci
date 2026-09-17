<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(['id', 'en', 'ko'])],
        ]);
        $locale = $validated['locale'];
        $request->session()->put('locale', $locale);
        $request->session()->keep(['errors', 'success', 'error']);
        app()->setLocale($locale);
        $response = $request->expectsJson()
            ? response()->json(['locale' => $locale])
            : back();

        return $response->withCookie(cookie('locale', $locale, 525600, null, null, null, true, false, 'lax'));
    }
}
