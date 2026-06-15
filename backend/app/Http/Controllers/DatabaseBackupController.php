<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Http\Request;
use RuntimeException;

class DatabaseBackupController extends Controller
{
    public function __construct(private DatabaseBackupService $databaseBackupService)
    {
    }

    private function isAdminOrSuper(?User $user): bool
    {
        $level = strtoupper(trim((string) optional($user)->level_user));

        return in_array($level, ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
    }

    private function rejectUnauthorized(Request $request)
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
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh mengelola backup database.',
                'data' => [],
            ], 403);
        }

        return null;
    }

    public function index(Request $request)
    {
        if ($response = $this->rejectUnauthorized($request)) {
            return $response;
        }

        return response()->json([
            'status' => true,
            'message' => 'Get Database Backup Summary Successfully',
            'data' => $this->databaseBackupService->summary(),
        ], 200);
    }

    public function store(Request $request)
    {
        if ($response = $this->rejectUnauthorized($request)) {
            return $response;
        }

        try {
            $result = $this->databaseBackupService->runMonthlyBackup((bool) $request->boolean('force', false));

            return response()->json([
                'status' => true,
                'message' => $result['message'],
                'data' => $result,
            ], 200);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
                'data' => [],
            ], 422);
        }
    }

    public function prune(Request $request)
    {
        if ($response = $this->rejectUnauthorized($request)) {
            return $response;
        }

        $result = $this->databaseBackupService->pruneExpiredBackups();

        return response()->json([
            'status' => true,
            'message' => $result['deleted_count'] > 0
                ? 'Backup lama berhasil dihapus.'
                : 'Tidak ada backup yang melewati masa retensi.',
            'data' => $result,
        ], 200);
    }

    public function download(Request $request, string $fileName)
    {
        if ($response = $this->rejectUnauthorized($request)) {
            return $response;
        }

        try {
            $path = $this->databaseBackupService->downloadPath($fileName);

            return response()->download($path, basename($path), [
                'Content-Type' => 'application/sql; charset=utf-8',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
                'data' => [],
            ], 404);
        }
    }
}
