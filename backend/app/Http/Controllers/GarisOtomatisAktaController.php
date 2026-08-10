<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GarisOtomatisAktaController extends Controller
{
    public function process(Request $request)
    {
        $request->validate([
            'document' => ['required', 'file', 'max:25600'],
            'outside_shift' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'zoom' => ['nullable', 'numeric', 'min:1', 'max:4'],
        ]);

        $file = $request->file('document');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!$file || !in_array($extension, ['doc', 'docx'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'File harus berformat .doc atau .docx.',
            ], 422);
        }

        $baseUrl = rtrim((string) env('GARIS_AKTA_BASE_URL', 'http://garis-akta:8788'), '/');
        $timeout = max(30, (int) ceil(((int) env('GARIS_AKTA_TIMEOUT_MS', 300000)) / 1000));
        $resource = fopen($file->getRealPath(), 'r');

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(10)
                ->attach('document', $resource, $file->getClientOriginalName())
                ->post($baseUrl . '/process', [
                    'outside_shift' => (string) $request->input('outside_shift', 4),
                    'zoom' => (string) $request->input('zoom', 2),
                ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Service Garis Otomatis Akta belum bisa dihubungi: ' . $exception->getMessage(),
            ], 502);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }

        if (!$response->successful()) {
            $payload = $response->json();
            $message = data_get($payload, 'detail') ?: data_get($payload, 'message') ?: 'Garis Otomatis Akta gagal memproses dokumen.';

            return response()->json([
                'status' => false,
                'message' => $message,
            ], $response->status());
        }

        $stem = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'akta';
        $downloadName = Str::slug($stem) . '-garis-otomatis.pdf';

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type', 'application/pdf'),
            'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
            'X-Garis-Segments' => $response->header('X-Garis-Segments', ''),
        ]);
    }
}
