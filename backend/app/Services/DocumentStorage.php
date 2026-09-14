<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class DocumentStorage
{
    public const PREFIXES = ['berkasclient', 'berkaslegalisasis', 'berkasnotaris', 'berkasppat', 'berkaswarmerkings', 'chat_attachments', 'scanned-documents', 'suratnotaris', 'suratppats', 'tandaterima'];

    public static function key(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', public_path()), '/').'/';
        if (str_starts_with($path, $root)) {
            $path = substr($path, strlen($root));
        }
        if (str_contains($path, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $path) || !in_array(explode('/', $path)[0], self::PREFIXES, true)) {
            throw new \InvalidArgumentException('Path dokumen tidak valid.');
        }
        return $path;
    }

    public static function disk()
    {
        return Storage::disk(config('filesystems.documents_disk', 'documents_local'));
    }

    public static function exists(string $path): bool
    {
        return self::disk()->exists(self::key($path));
    }

    public static function upload($file, string $directory, string $name): void
    {
        $key = self::key($directory.'/'.$name);
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            if (!self::disk()->put($key, $stream)) {
                throw new \RuntimeException('Gagal menyimpan dokumen.');
            }
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
    }

    public static function delete(string $path): void
    {
        if (!self::disk()->delete(self::key($path))) {
            throw new \RuntimeException('Gagal menghapus dokumen.');
        }
    }

    public static function copy(string $source, string $target): bool
    {
        self::disk()->getDriver()->copy(self::key($source), self::key($target), ['visibility' => 'private']);
        return true;
    }

    public static function response(string $path, ?string $name = null, bool $download = false)
    {
        $key = self::key($path);
        abort_unless(self::exists($key), 404);
        return self::disk()->response($key, $name ?: basename($key), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'], $download ? 'attachment' : 'inline');
    }

    public static function temporaryFile(string $path): string
    {
        $stream = self::disk()->readStream(self::key($path));
        if (!is_resource($stream)) throw new \RuntimeException('Gagal membaca dokumen.');
        $temp = tempnam(sys_get_temp_dir(), 'simanis-document-');
        $output = fopen($temp, 'wb');
        try {
            if (stream_copy_to_stream($stream, $output) === false) throw new \RuntimeException('Gagal menyalin dokumen.');
        } finally {
            fclose($stream);
            fclose($output);
        }
        register_shutdown_function(static function () use ($temp) { if (is_file($temp)) unlink($temp); });
        return $temp;
    }
}
