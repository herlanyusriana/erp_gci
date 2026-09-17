<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $user,
                'roles' => $user?->roles()->pluck('name')->all() ?? [],
                'permissions' => $user?->permissions() ?? [],
            ],
            'appSettings' => $this->appSettings(),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }


    /**
     * Config-master backed application settings shared to the frontend.
     *
     * @return array<string, mixed>
     */
    private function appSettings(): array
    {
        if (class_exists(\App\Models\ConfigMaster::class)) {
            return [
                'companyName' => \App\Models\ConfigMaster::getValue('SYSTEM', 'company_name', 'PT Geum Cheon Indo'),
                'applicationName' => \App\Models\ConfigMaster::getValue('SYSTEM', 'application_name', 'Geum Cheon ERP'),
                'timezone' => \App\Models\ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta'),
                'dateFormat' => \App\Models\ConfigMaster::getValue('SYSTEM', 'date_format', 'DD/MM/YYYY'),
                'currency' => \App\Models\ConfigMaster::getValue('SYSTEM', 'currency', 'IDR'),
            ];
        }

        return [];
    }
}
