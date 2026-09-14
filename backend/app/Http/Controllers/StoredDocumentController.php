<?php

namespace App\Http\Controllers;

use App\Services\DocumentStorage;

class StoredDocumentController extends Controller
{
    // Preserve existing public asset URLs while keeping the bucket private.
    public function show(string $prefix, string $path)
    {
        try {
            $key = DocumentStorage::key($prefix.'/'.$path);
        } catch (\InvalidArgumentException $exception) {
            abort(404);
        }
        return DocumentStorage::response($key);
    }
}
