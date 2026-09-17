<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale', $request->cookie('locale', 'id'));
        app()->setLocale(in_array($locale, ['id', 'en', 'ko'], true) ? $locale : 'id');

        return $next($request);
    }
}
