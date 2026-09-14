<?php

namespace Tests\Feature;

use App\Services\DocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentStorageTest extends TestCase
{
    public function test_all_document_prefixes_support_upload_read_copy_and_delete(): void
    {
        config(['filesystems.documents_disk' => 'documents']);
        Storage::fake('documents');
        foreach (DocumentStorage::PREFIXES as $prefix) {
            $file = UploadedFile::fake()->createWithContent('sample.pdf', '%PDF-test');
            DocumentStorage::upload($file, $prefix, 'sample.pdf');
            $key = $prefix.'/sample.pdf';
            $this->assertTrue(DocumentStorage::exists(public_path($key)));
            $this->assertSame('%PDF-test', file_get_contents(DocumentStorage::temporaryFile($key)));
            $this->assertTrue(DocumentStorage::copy($key, $prefix.'/copy.pdf'));
            DocumentStorage::delete($key);
            $this->assertFalse(DocumentStorage::exists($key));
            $this->assertTrue(DocumentStorage::exists($prefix.'/copy.pdf'));
        }
    }

    public function test_invalid_paths_cannot_escape_document_prefixes(): void
    {
        foreach (['../.env', 'berkasclient/../../.env', '/etc/passwd', 'foto/test.jpg', "berkasclient/a\0.pdf"] as $path) {
            try {
                DocumentStorage::key($path);
                $this->fail('Accepted invalid path: '.$path);
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_legacy_preview_url_streams_from_selected_storage(): void
    {
        $this->withoutMiddleware();
        config(['filesystems.documents_disk' => 'documents']);
        Storage::fake('documents')->put('berkasnotaris/sample.pdf', '%PDF-preview');
        $response = $this->get('/berkasnotaris/sample.pdf');
        $response->assertOk();
        $this->assertSame('%PDF-preview', $response->streamedContent());
        $this->get('/berkasnotaris/missing.pdf')->assertNotFound();
    }
}
