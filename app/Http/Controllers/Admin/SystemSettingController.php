<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSystemSettingsRequest;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

class SystemSettingController extends Controller
{
    private const KEYS = ['system_name', 'institution_name', 'institution_address', 'system_email'];

    public function index(): JsonResponse
    {
        $settings = SystemSetting::whereIn('key', self::KEYS)->pluck('value', 'key');

        return response()->json([
            ...array_fill_keys(self::KEYS, null),
            ...$settings->all(),
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request): JsonResponse
    {
        foreach ($request->validated() as $key => $value) {
            SystemSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $settings = SystemSetting::whereIn('key', self::KEYS)->pluck('value', 'key');

        return response()->json([
            ...array_fill_keys(self::KEYS, null),
            ...$settings->all(),
        ]);
    }
}
