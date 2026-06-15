<?php

namespace App\Http\Controllers;

use App\Models\ReportSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReportSettingController extends Controller
{
    private function isAdminOrSuper(?User $user): bool
    {
        $level = strtoupper(trim((string) optional($user)->level_user));

        return in_array($level, ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
    }

    private function rejectUnauthorized(Request $request, string $message)
    {
        /** @var User|null $authUser */
        $authUser = $request->user();
        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
                'data' => [],
            ], 401);
        }

        if (!$this->isAdminOrSuper($authUser)) {
            return response()->json([
                'status' => false,
                'message' => $message,
                'data' => [],
            ], 403);
        }

        return null;
    }

    public function show(Request $request)
    {
        if ($response = $this->rejectUnauthorized($request, 'Akses ditolak. Hanya Admin/Super Admin yang boleh melihat pengaturan laporan.')) {
            return $response;
        }

        $settings = ReportSetting::current();

        return response()->json([
            'status' => true,
            'message' => 'Get Report Settings Successfully',
            'data' => $settings->toReportProfile(),
        ], 200);
    }

    public function update(Request $request)
    {
        if ($response = $this->rejectUnauthorized($request, 'Akses ditolak. Hanya Admin/Super Admin yang boleh mengubah pengaturan laporan.')) {
            return $response;
        }

        $validated = $request->validate([
            'header_label' => ['required', 'string'],
            'office_name' => ['required', 'string'],
            'office_address' => ['required', 'string'],
            'office_email' => ['nullable', 'email'],
            'office_phone' => ['nullable', 'string'],
            'office_city' => ['required', 'string'],
            'signatory_title' => ['required', 'string'],
            'signatory_name' => ['required', 'string'],
            'invoice_bank_account_1' => ['nullable', 'string'],
            'invoice_bank_account_2' => ['nullable', 'string'],
            'invoice_bank_account_3' => ['nullable', 'string'],
        ]);

        $settings = ReportSetting::current();
        $settings->fill($validated);
        $settings->save();

        return response()->json([
            'status' => true,
            'message' => 'Pengaturan laporan berhasil diperbarui.',
            'data' => $settings->toReportProfile(),
        ], 200);
    }

    public function uploadLogo(Request $request)
    {
        if ($response = $this->rejectUnauthorized($request, 'Akses ditolak. Hanya Admin/Super Admin yang boleh mengubah logo laporan.')) {
            return $response;
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $settings = ReportSetting::current();
        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $fileName = Str::uuid()->toString().'.'.$extension;
        $relativeDirectory = 'report-settings';
        $targetDirectory = public_path($relativeDirectory);

        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }

        $file->move($targetDirectory, $fileName);

        $oldPath = trim((string) $settings->logo_path);
        $settings->update([
            'logo_path' => $relativeDirectory.'/'.$fileName,
        ]);

        if ($oldPath !== '') {
            $oldFile = public_path($oldPath);
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Logo laporan berhasil diperbarui.',
            'data' => $settings->toReportProfile(),
        ], 200);
    }
}
