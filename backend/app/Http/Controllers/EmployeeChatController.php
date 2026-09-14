<?php

namespace App\Http\Controllers;

use App\Models\EmployeeChat;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class EmployeeChatController extends ApiController
{
    public function contacts(Request $request)
    {
        $currentUserId = (int) $request->user()->id;

        $contacts = User::query()
            ->select(['id', 'id_user', 'nama_lengkap', 'level_user', 'foto'])
            ->where('id', '!=', $currentUserId)
            ->orderBy('nama_lengkap')
            ->get()
            ->map(fn (User $user) => $this->mapContact($user))
            ->values()
            ->all();

        return $this->successResponse($contacts, 'Berhasil memuat daftar karyawan.');
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'scope' => 'nullable|string|in:direct,global',
            'receiver_id' => 'nullable|integer|exists:users,id',
            'limit' => 'nullable|integer|min:20|max:300',
            'mark_read' => 'nullable|boolean',
        ]);

        $scope = (string) ($validated['scope'] ?? 'direct');
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 120;
        $markRead = isset($validated['mark_read']) ? (bool) $validated['mark_read'] : true;

        $currentUserId = (int) $request->user()->id;

        if ($scope === 'global') {
            $messageModels = EmployeeChat::query()
                ->with([
                    'sender:id,id_user,nama_lengkap,level_user,foto',
                    'receiver:id,id_user,nama_lengkap,level_user,foto',
                ])
                ->whereNull('receiver_id')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();

            if ($markRead) {
                User::query()
                    ->where('id', $currentUserId)
                    ->update(['last_global_chat_read_at' => now()]);
            }

            $messages = $messageModels
                ->map(fn (EmployeeChat $chat) => $this->mapMessage($chat))
                ->all();

            return $this->successResponse([
                'scope' => 'global',
                'receiver_id' => null,
                'current_user_id' => $currentUserId,
                'messages' => $messages,
            ], 'Berhasil memuat chat umum.');
        }

        $receiverId = isset($validated['receiver_id']) ? (int) $validated['receiver_id'] : 0;
        if ($receiverId <= 0) {
            return $this->errorResponse([], 'receiver_id wajib diisi untuk chat direct.', 422);
        }

        if ($receiverId === $currentUserId) {
            return $this->errorResponse([], 'Tidak bisa membuka chat dengan diri sendiri.', 422);
        }

        $messageModels = EmployeeChat::query()
            ->with([
                'sender:id,id_user,nama_lengkap,level_user,foto',
                'receiver:id,id_user,nama_lengkap,level_user,foto',
            ])
            ->where(function ($query) use ($currentUserId, $receiverId) {
                $query->where('sender_id', $currentUserId)->where('receiver_id', $receiverId);
            })
            ->orWhere(function ($query) use ($currentUserId, $receiverId) {
                $query->where('sender_id', $receiverId)->where('receiver_id', $currentUserId);
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        if ($markRead) {
            EmployeeChat::query()
                ->where('sender_id', $receiverId)
                ->where('receiver_id', $currentUserId)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        $messages = $messageModels
            ->map(fn (EmployeeChat $chat) => $this->mapMessage($chat))
            ->all();

        return $this->successResponse([
            'scope' => 'direct',
            'receiver_id' => $receiverId,
            'current_user_id' => $currentUserId,
            'messages' => $messages,
        ], 'Berhasil memuat percakapan.');
    }

    public function unreadSummary(Request $request)
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $currentUserId = (int) $currentUser->id;

        $unreadRows = EmployeeChat::query()
            ->selectRaw('sender_id, COUNT(*) as unread_count, MAX(created_at) as latest_at')
            ->where('receiver_id', $currentUserId)
            ->whereNull('read_at')
            ->groupBy('sender_id')
            ->orderByDesc('latest_at')
            ->get();

        $senderIds = $unreadRows->pluck('sender_id')->map(fn ($value) => (int) $value)->all();
        $senders = User::query()
            ->select(['id', 'id_user', 'nama_lengkap', 'level_user', 'foto'])
            ->whereIn('id', $senderIds)
            ->get()
            ->keyBy('id');

        $latestMessageBySender = EmployeeChat::query()
            ->with([
                'sender:id,id_user,nama_lengkap,level_user,foto',
                'receiver:id,id_user,nama_lengkap,level_user,foto',
            ])
            ->where('receiver_id', $currentUserId)
            ->whereNull('read_at')
            ->whereIn('sender_id', $senderIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('sender_id')
            ->map(static fn (Collection $group) => $group->first());

        $conversations = $unreadRows->map(function ($row) use ($senders, $latestMessageBySender) {
            $senderId = (int) $row->sender_id;
            /** @var User|null $sender */
            $sender = $senders->get($senderId);
            /** @var EmployeeChat|null $latestMessage */
            $latestMessage = $latestMessageBySender->get($senderId);

            return [
                'sender_id' => $senderId,
                'unread_count' => (int) $row->unread_count,
                'latest_at' => $latestMessage ? optional($latestMessage->created_at)->toISOString() : null,
                'latest_message' => $latestMessage ? [
                    'message' => $latestMessage->message,
                    'attachment_name' => $latestMessage->attachment_name,
                    'has_attachment' => !empty($latestMessage->attachment_path),
                ] : null,
                'sender' => $sender ? $this->mapContact($sender) : null,
            ];
        })->values()->all();

        $globalUnreadQuery = EmployeeChat::query()
            ->with([
                'sender:id,id_user,nama_lengkap,level_user,foto',
                'receiver:id,id_user,nama_lengkap,level_user,foto',
            ])
            ->whereNull('receiver_id')
            ->where('sender_id', '!=', $currentUserId);

        if (!empty($currentUser->last_global_chat_read_at)) {
            $globalUnreadQuery->where('created_at', '>', $currentUser->last_global_chat_read_at);
        }

        $globalUnreadCount = (int) (clone $globalUnreadQuery)->count();
        $globalLatestMessage = (clone $globalUnreadQuery)->orderByDesc('id')->first();

        $directUnreadTotal = (int) $unreadRows->sum('unread_count');
        $totalUnread = $directUnreadTotal + $globalUnreadCount;

        return $this->successResponse([
            'total_unread' => $totalUnread,
            'direct_unread_total' => $directUnreadTotal,
            'global_unread_count' => $globalUnreadCount,
            'global_latest_message' => $globalLatestMessage ? $this->mapMessage($globalLatestMessage) : null,
            'conversations' => $conversations,
        ], 'Berhasil memuat ringkasan chat belum dibaca.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'scope' => 'nullable|string|in:direct,global',
            'receiver_id' => 'nullable|integer|exists:users,id',
            'message' => 'nullable|string|max:5000',
            'file' => 'nullable|file|max:153600',
        ]);

        $scope = (string) ($validated['scope'] ?? 'direct');
        $currentUserId = (int) $request->user()->id;

        $receiverId = null;
        if ($scope === 'direct') {
            $receiverId = isset($validated['receiver_id']) ? (int) $validated['receiver_id'] : 0;
            if ($receiverId <= 0) {
                return $this->errorResponse([], 'receiver_id wajib diisi untuk chat direct.', 422);
            }
            if ($receiverId === $currentUserId) {
                return $this->errorResponse([], 'Tidak bisa mengirim pesan ke diri sendiri.', 422);
            }
        }

        $attachmentPayload = null;
        if ($request->hasFile('file')) {
            /** @var UploadedFile $uploadedFile */
            $uploadedFile = $request->file('file');
            $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'csv', 'zip', 'rar', '7z', 'exe'];
            $extension = strtolower((string) $uploadedFile->getClientOriginalExtension());
            if (!in_array($extension, $allowedExtensions, true)) {
                return $this->errorResponse([
                    'file' => ['The file must be a file of type: ' . implode(', ', $allowedExtensions) . '.'],
                ], 'File tidak didukung.', 422);
            }
            $attachmentPayload = $this->storeAttachment($uploadedFile);
        }

        $message = trim((string) ($validated['message'] ?? ''));
        if ($message === '' && !$attachmentPayload) {
            return $this->errorResponse([], 'Pesan tidak boleh kosong.', 422);
        }
        if ($message === '' && $attachmentPayload) {
            $message = 'Mengirim lampiran.';
        }

        $chat = EmployeeChat::create([
            'sender_id' => $currentUserId,
            'receiver_id' => $scope === 'direct' ? $receiverId : null,
            'message' => $message,
            'attachment_path' => $attachmentPayload['path'] ?? null,
            'attachment_name' => $attachmentPayload['name'] ?? null,
            'attachment_mime' => $attachmentPayload['mime'] ?? null,
            'attachment_size' => $attachmentPayload['size'] ?? null,
        ]);

        $chat->load([
            'sender:id,id_user,nama_lengkap,level_user,foto',
            'receiver:id,id_user,nama_lengkap,level_user,foto',
        ]);

        $chatPayload = $this->mapMessage($chat);

        return $this->successResponse($chatPayload, 'Pesan berhasil dikirim.', 201);
    }

    private function mapContact(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'id_user' => $user->id_user,
            'nama_lengkap' => $user->nama_lengkap,
            'level_user' => $user->level_user,
            'foto' => $user->foto,
        ];
    }

    private function mapMessage(EmployeeChat $chat): array
    {
        return [
            'id' => (int) $chat->id,
            'scope' => empty($chat->receiver_id) ? 'global' : 'direct',
            'sender_id' => (int) $chat->sender_id,
            'receiver_id' => $chat->receiver_id !== null ? (int) $chat->receiver_id : null,
            'message' => $chat->message,
            'is_read' => !empty($chat->read_at),
            'read_at' => optional($chat->read_at)->toISOString(),
            'created_at' => optional($chat->created_at)->toISOString(),
            'updated_at' => optional($chat->updated_at)->toISOString(),
            'sender' => $chat->sender ? $this->mapContact($chat->sender) : null,
            'receiver' => $chat->receiver ? $this->mapContact($chat->receiver) : null,
            'attachment' => $chat->attachment_path
                ? [
                    'path' => $chat->attachment_path,
                    'name' => $chat->attachment_name,
                    'mime' => $chat->attachment_mime,
                    'size' => (int) ($chat->attachment_size ?? 0),
                    'url' => url($chat->attachment_path),
                    'is_image' => strpos(strtolower((string) $chat->attachment_mime), 'image/') === 0,
                ]
                : null,
        ];
    }

    private function storeAttachment(UploadedFile $file): array
    {

        $originalName = (string) $file->getClientOriginalName();
        $mimeType = (string) $file->getClientMimeType();
        $size = 0;
        try {
            $size = (int) ($file->getSize() ?? 0);
        } catch (\Throwable $exception) {
            $size = 0;
        }

        $safeExtension = strtolower((string) $file->getClientOriginalExtension());
        $storedName = uniqid('chat_', true) . ($safeExtension ? '.' . $safeExtension : '');
        \App\Services\DocumentStorage::upload($file, 'chat_attachments', $storedName);


        return [
            'path' => 'chat_attachments/' . $storedName,
            'name' => $originalName,
            'mime' => $mimeType,
            'size' => $size,
        ];
    }
}
