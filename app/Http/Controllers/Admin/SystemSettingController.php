<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSystemSettingsRequest;
use App\Models\SystemLog;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

class SystemSettingController extends Controller
{
    private const KEYS = ['system_name', 'institution_name', 'institution_address', 'system_email'];

    public function index(): JsonResponse
    {
        return response()->json([
            ...array_fill_keys(self::KEYS, null),
            ...SystemSetting::cached()->only(self::KEYS)->all(),
        ]);
    }

    public function update(UpdateSystemSettingsRequest $request): JsonResponse
    {
        foreach ($request->validated() as $key => $value) {
            SystemSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        SystemLog::record('Settings Changed', 'Updated system settings');

        SystemSetting::forgetCache();

        return response()->json([
            ...array_fill_keys(self::KEYS, null),
            ...SystemSetting::cached()->only(self::KEYS)->all(),
        ]);
    }
}
