<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Http\Controllers;

use Dashcore\Bridge\Uploads\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The upload contract, and the probe that tests it.
 *
 * `show` says what the app has declared and what PHP will allow. `probe`
 * receives a real file, the way a person's upload arrives — multipart,
 * through the web server, PHP's limits and the framework — and says
 * whether all of it got here intact. Nothing is kept: the file lives in
 * PHP's temporary directory for the length of the request and is gone
 * when it ends.
 *
 * The probe's answer names the layer that stopped a file when one did. A
 * refusal before PHP never reaches this controller, and the caller reads
 * that from the response it got back instead.
 */
class UploadsController
{
    public function show(): JsonResponse
    {
        return response()->json([
            'declared_max_bytes' => UploadLimits::declared(),
            'php' => UploadLimits::php(),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function probe(Request $request): JsonResponse
    {
        $declared = UploadLimits::declared();

        if ($declared === null) {
            return $this->refuse(409, 'UPLOAD_LIMIT_UNDECLARED', 'This app has not declared its largest upload; set BRIDGE_UPLOAD_MAX.');
        }

        if ($declared === 0) {
            return $this->refuse(409, 'UPLOADS_NOT_ACCEPTED', 'This app declares that it takes no uploads.');
        }

        $bytes = filter_var($request->query('bytes'), FILTER_VALIDATE_INT);
        $sha256 = (string) $request->query('sha256', '');

        if ($bytes === false || $bytes < 1 || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            return $this->refuse(422, 'UPLOAD_PROBE_MALFORMED', 'Send the file\'s size as `bytes` and its SHA-256 as `sha256`.');
        }

        // The probe tests the promise, not the server's ceiling: a file
        // larger than the app says it takes is not one it owes anyone.
        if ($bytes > $declared) {
            return $this->refuse(422, 'UPLOAD_PROBE_OVER_DECLARED', "The probe is larger than the {$declared} bytes this app declares.");
        }

        $file = $request->file('file');

        // PHP drops the whole body, files and all, when it is over
        // post_max_size and nothing upstream refused it first.
        if (! $file instanceof UploadedFile) {
            return $this->failed('body_dropped', 'The request arrived with no file in it; PHP discarded the body (post_max_size).');
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->failed(...$this->explain($file->getError()));
        }

        // The size and hash were signed in the query string; matching them is
        // what ties the file that arrived to the request that was signed.
        $received = (int) $file->getSize();
        $matches = $received === $bytes && hash_equals($sha256, (string) hash_file('sha256', $file->getRealPath()));

        if (! $matches) {
            return $this->failed('altered', "The file arrived as {$received} bytes, not the {$bytes} sent, or with different contents.", $received);
        }

        return response()->json([
            'received' => true,
            'bytes' => $received,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function explain(int $error): array
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE => ['upload_max_filesize', 'PHP refused the file: it is larger than upload_max_filesize ('.ini_get('upload_max_filesize').').'],
            UPLOAD_ERR_FORM_SIZE => ['form_size', 'The file is larger than the form\'s MAX_FILE_SIZE.'],
            UPLOAD_ERR_PARTIAL => ['partial', 'Only part of the file arrived.'],
            UPLOAD_ERR_NO_TMP_DIR => ['no_tmp_dir', 'PHP has no temporary directory to receive uploads into.'],
            UPLOAD_ERR_CANT_WRITE => ['cant_write', 'PHP could not write the upload to disk.'],
            UPLOAD_ERR_EXTENSION => ['extension', 'A PHP extension stopped the upload.'],
            default => ['unknown', "PHP reported upload error {$error}."],
        };
    }

    protected function failed(string $layer, string $detail, int $bytes = 0): JsonResponse
    {
        return response()->json([
            'received' => false,
            'bytes' => $bytes,
            'stopped_by' => $layer,
            'detail' => $detail,
        ], 422, ['Cache-Control' => 'no-store']);
    }

    protected function refuse(int $status, string $error, string $detail): JsonResponse
    {
        return response()->json(['error' => $error, 'detail' => $detail], $status, ['Cache-Control' => 'no-store']);
    }
}
