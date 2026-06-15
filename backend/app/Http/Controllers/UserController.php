<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private function isAdminOrSuper(?User $user): bool
    {
        $level = strtoupper(trim((string) optional($user)->level_user));
        return in_array($level, ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
    }

    private function isSameUser(User $left, User $right): bool
    {
        return (string) $left->id === (string) $right->id
            || (string) $left->id_user === (string) $right->id_user;
    }

    private function buildUsername(string $namaLengkap): string
    {
        $parts = preg_split('/\s+/', trim($namaLengkap));
        $username = strtolower((string) ($parts[0] ?? 'user'));
        return $username !== '' ? $username : 'user';
    }

    private function nextUserCode(): string
    {
        $lastRow = User::query()->orderByDesc('id_user')->first();
        $next = $lastRow ? ((int) $lastRow->id_user + 1) : 1;
        return str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function reportoriumDependencySummary(string $idUser): array
    {
        return [
            'Buku Akta' => DB::table('buku_notaris')->where('id_user', $idUser)->count(),
            'Buku PPAT' => DB::table('buku_ppats')->where('id_user', $idUser)->count(),
            'Buku Legalisasi' => DB::table('buku_legalisasis')->where('id_user', $idUser)->count(),
            'Buku Waarmerking' => DB::table('buku_warmerkings')->where('id_user', $idUser)->count(),
            'Surat Notaris' => DB::table('buku_surat_notaris')->where('pengirim', $idUser)->count(),
            'Surat PPAT' => DB::table('buku_surat_ppats')->where('pengirim', $idUser)->count(),
            'Tanda Terima' => DB::table('tanda_terima')->where('pembuat', $idUser)->count(),
        ];
    }

    public function SaveAccount(Request $request)
    {
        /** @var User|null $authUser */
        $authUser = $request->user();
        if (!$authUser) {
            return response([
                'status' => false,
                'message' => 'Unauthorized.',
                'data' => [],
            ], 401);
        }

        $targetIdUser = trim((string) $request->post('id_user'));
        $isCreate = $targetIdUser === '';

        if ($isCreate) {
            if (!$this->isAdminOrSuper($authUser)) {
                return response([
                    'status' => false,
                    'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh menambah user.',
                    'data' => [],
                ], 403);
            }

            $validated = $request->validate([
                'email' => ['required', 'email', 'unique:users,email'],
                'level_user' => ['required', 'string'],
                'nama_lengkap' => ['required', 'string'],
                'phone' => ['required', 'string'],
                'password' => ['required', 'string', 'confirmed', 'min:8'],
            ]);

            $data = [
                'id_user' => $this->nextUserCode(),
                'email' => (string) $validated['email'],
                'username' => $this->buildUsername((string) $validated['nama_lengkap']),
                'level_user' => (string) $validated['level_user'],
                'nama_lengkap' => (string) $validated['nama_lengkap'],
                'phone' => (string) $validated['phone'],
                'password' => Hash::make((string) $validated['password']),
            ];

            User::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Save New Account Successfully',
                'data' => [],
            ], 200);
        }

        $targetUser = User::query()->where('id_user', $targetIdUser)->first();
        if (!$targetUser) {
            return response([
                'status' => false,
                'message' => 'User tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($targetUser->id)],
            'level_user' => ['required', 'string'],
            'nama_lengkap' => ['required', 'string'],
            'phone' => ['required', 'string'],
            'password' => ['nullable', 'string', 'confirmed', 'min:8'],
        ]);

        $data = [
            'email' => (string) $validated['email'],
            'username' => $this->buildUsername((string) $validated['nama_lengkap']),
            'level_user' => (string) $validated['level_user'],
            'nama_lengkap' => (string) $validated['nama_lengkap'],
            'phone' => (string) $validated['phone'],
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make((string) $validated['password']);
        }

        $targetUser->update($data);

        return response()->json([
            'status' => true,
            'message' => 'Update Account Successfully',
            'data' => [],
        ], 200);
    }

    public function DeleteAccount(Request $request)
    {
        /** @var User|null $authUser */
        $authUser = $request->user();
        if (!$authUser) {
            return response([
                'status' => false,
                'message' => 'Unauthorized.',
                'data' => [],
            ], 401);
        }

        if (!$this->isAdminOrSuper($authUser)) {
            return response([
                'status' => false,
                'message' => 'Akses ditolak. Hanya Admin/Super Admin yang boleh menghapus user.',
                'data' => [],
            ], 403);
        }

        $validated = $request->validate([
            'id_user' => ['required', 'string'],
        ]);

        $targetUser = User::query()->where('id_user', (string) $validated['id_user'])->first();
        if (!$targetUser) {
            return response([
                'status' => false,
                'message' => 'User tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        if ($this->isSameUser($authUser, $targetUser)) {
            return response([
                'status' => false,
                'message' => 'User aktif tidak bisa dihapus.',
                'data' => [],
            ], 422);
        }

        $dependencySummary = $this->reportoriumDependencySummary((string) $targetUser->id_user);
        $activeDependencies = collect($dependencySummary)
            ->filter(fn ($count) => (int) $count > 0)
            ->map(fn ($count, $label) => $label.' ('.$count.')')
            ->values()
            ->all();

        if (!empty($activeDependencies)) {
            return response([
                'status' => false,
                'message' => 'User tidak bisa dihapus karena masih terikat pekerjaan reportorium.',
                'data' => [
                    'dependencies' => $activeDependencies,
                ],
            ], 422);
        }

        $oldPhoto = trim((string) $targetUser->foto);
        $targetUser->tokens()->delete();
        $targetUser->delete();

        if ($oldPhoto !== '') {
            $oldPath = public_path('foto' . DIRECTORY_SEPARATOR . $oldPhoto);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        return response([
            'status' => true,
            'message' => 'Delete Account Successfully',
            'data' => [],
        ], 200);
    }

    public function DataUser()
    {
        $data = User::query()
            ->orderBy('nama_lengkap')
            ->get()
            ->toArray();

        $response = [
            'status' => true,
            'message' => 'Get All Account Successfully',
            'data' => $data,
        ];

        return response($response, 200);
    }

    public function UpdatePassword(Request $request)
    {
        $cek = $this->CekPassWord($request->post('last_password'));
        if (!$cek) {
            $response = [
                'status' => false,
                'message' => "The New Password isn't match with old password.",
                'data' => [],
            ];
            $code = 422;
        } else {
            $request->validate([
                'last_password' => ['required'],
                'new_password' => ['required'],
                'password_confirmation' => ['same:new_password'],
            ]);

            User::find(auth()->user()->id)->update(['password' => Hash::make($request->new_password)]);
            $response = [
                'status' => true,
                'message' => 'Update New Password Successfully.',
                'data' => [],
            ];
            $code = 200;
        }
        return response($response, $code);
    }

    public function CekPassWord($value)
    {
        return Hash::check($value, auth()->user()->password);
    }

    public function UploadFoto(Request $request)
    {
        /** @var User|null $authUser */
        $authUser = $request->user();
        if (!$authUser) {
            return response([
                'status' => false,
                'message' => 'Unauthorized.',
                'data' => [],
            ], 401);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'id_user' => ['nullable', 'string'],
        ]);

        $requestedIdUser = trim((string) ($validated['id_user'] ?? ''));
        $targetIdUser = $requestedIdUser !== '' ? $requestedIdUser : (string) $authUser->id_user;
        $targetUser = User::query()->where('id_user', $targetIdUser)->first();

        if (!$targetUser) {
            return response([
                'status' => false,
                'message' => 'User tidak ditemukan.',
                'data' => [],
            ], 404);
        }

        $file = $request->file('file');
        $hashName = $file->hashName();
        $targetDirectory = public_path('foto');
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }
        $file->move($targetDirectory, $hashName);

        $oldPhoto = trim((string) $targetUser->foto);
        $targetUser->update(['foto' => $hashName]);

        if ($oldPhoto !== '' && $oldPhoto !== $hashName) {
            $oldPath = $targetDirectory . DIRECTORY_SEPARATOR . $oldPhoto;
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $data = [
            'id_user' => (string) $targetUser->id_user,
            'foto' => $hashName,
        ];

        $response = [
            'status' => true,
            'message' => 'Upload New Profile Image Successfully',
            'data' => $data,
        ];
        return response($response, 200);
    }
}
