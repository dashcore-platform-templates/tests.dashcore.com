<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Http\Controllers;

use Dashcore\Bridge\Uploads\DirectUpload;
use Dashcore\Bridge\Uploads\UploadLimits;
use Dashcore\Bridge\Uploads\UploadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

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
 *
 * An app whose files go straight to storage (`bridge.uploads.direct`) is
 * tested on that route instead: `direct` signs a URL for a throwaway
 * object, the caller PUTs the file to it as a browser would, and
 * `directCheck` reads back what landed and deletes it. Both take their
 * values from the query or a JSON body, which the signature covers either
 * way; the bridge client's post() sends a body.
 *
 * `stored` tests the other half of the promise: a small probe file is
 * written to the disk uploads are kept on, the way an upload is, and its
 * plain URL handed back for the caller to fetch unsigned. `storedForget`
 * deletes it. A stranger reading those bytes means uploads are public.
 */
class UploadsController
{
    /** Where probe files land on the direct-upload disk, apart from anything real. */
    private const PROBES = 'bridge-upload-probes';

    public function show(): JsonResponse
    {
        return response()->json([
            'declared_max_bytes' => UploadLimits::declared(),
            'php' => UploadLimits::php(),
            'direct' => DirectUpload::describe(),
            'stored_on' => UploadStorage::describe(),
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
     * Sign a PUT for one probe file on the direct-upload disk. Probes are
     * kept under their own prefix, and any left behind by a caller that
     * never came back are swept here.
     */
    public function direct(Request $request): JsonResponse
    {
        if (($refusal = $this->refuseDirect()) !== null) {
            return $refusal;
        }

        $bytes = filter_var($request->input('bytes'), FILTER_VALIDATE_INT);

        if ($bytes === false || $bytes < 1) {
            return $this->refuse(422, 'UPLOAD_PROBE_MALFORMED', 'Send the size of the file you will upload as `bytes`.');
        }

        if ($bytes > (int) UploadLimits::declared()) {
            return $this->refuse(422, 'UPLOAD_PROBE_OVER_DECLARED', 'The probe is larger than the '.UploadLimits::declared().' bytes this app declares.');
        }

        $disk = (string) DirectUpload::disk();
        $this->sweep($disk);
        $id = (string) Str::uuid();

        return response()->json(
            ['id' => $id, 'bytes' => $bytes] + DirectUpload::target($disk, self::PROBES.'/'.$id, 'application/octet-stream', 30),
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * What landed for a probe signed by `direct`: there, the size sent, the
     * contents sent. Deleted whatever the answer.
     */
    public function directCheck(Request $request, string $id): JsonResponse
    {
        if (($refusal = $this->refuseDirect()) !== null) {
            return $refusal;
        }

        $bytes = filter_var($request->input('bytes'), FILTER_VALIDATE_INT);
        $sha256 = (string) $request->input('sha256', '');

        if (! Str::isUuid($id) || $bytes === false || $bytes < 1 || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            return $this->refuse(422, 'UPLOAD_PROBE_MALFORMED', 'Check a probe by its id, with the file\'s size as `bytes` and its SHA-256 as `sha256`.');
        }

        $storage = Storage::disk((string) DirectUpload::disk());
        $path = self::PROBES.'/'.$id;

        if (! $storage->exists($path)) {
            return $this->failed('not_stored', 'Nothing arrived in storage for this probe; the upload to the signed URL did not complete.');
        }

        try {
            $received = (int) $storage->size($path);
            $matches = $received === $bytes && hash_equals($sha256, $this->hash($storage->readStream($path)));
        } finally {
            $storage->delete($path);
        }

        if (! $matches) {
            return $this->failed('altered', "The file landed as {$received} bytes, not the {$bytes} sent, or with different contents.", $received);
        }

        return response()->json([
            'received' => true,
            'bytes' => $received,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Store a small probe file as uploads are stored and say where its
     * plain URL points. The caller fetches that URL with no credentials,
     * then calls `storedForget`; anything left behind is swept.
     */
    public function stored(): JsonResponse
    {
        if (($refusal = $this->refuseUploads()) !== null) {
            return $refusal;
        }

        $disk = UploadStorage::disk();
        $this->sweep($disk);
        $id = (string) Str::uuid();
        $path = self::PROBES.'/'.$id;
        $contents = random_bytes(1024);

        try {
            Storage::disk($disk)->put($path, $contents);
        } catch (Throwable $e) {
            return $this->refuse(503, 'UPLOAD_STORAGE_UNWRITABLE', "The [{$disk}] disk refused the probe file: ".$e->getMessage());
        }

        return response()->json([
            'id' => $id,
            'sha256' => hash('sha256', $contents),
            'url' => UploadStorage::url($path),
        ] + UploadStorage::describe(), 200, ['Cache-Control' => 'no-store']);
    }

    public function storedForget(string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return $this->refuse(422, 'UPLOAD_PROBE_MALFORMED', 'Forget a probe by the id `stored` gave it.');
        }

        Storage::disk(UploadStorage::disk())->delete(self::PROBES.'/'.$id);

        return response()->json(['deleted' => true], 200, ['Cache-Control' => 'no-store']);
    }

    protected function refuseUploads(): ?JsonResponse
    {
        $declared = UploadLimits::declared();

        if ($declared === null) {
            return $this->refuse(409, 'UPLOAD_LIMIT_UNDECLARED', 'This app has not declared its largest upload; set BRIDGE_UPLOAD_MAX.');
        }

        if ($declared === 0) {
            return $this->refuse(409, 'UPLOADS_NOT_ACCEPTED', 'This app declares that it takes no uploads.');
        }

        return null;
    }

    protected function refuseDirect(): ?JsonResponse
    {
        if (($refusal = $this->refuseUploads()) !== null) {
            return $refusal;
        }

        $disk = DirectUpload::disk();

        if ($disk === null) {
            return $this->refuse(409, 'UPLOADS_NOT_DIRECT', 'This app\'s uploads come through the app; test them with uploads/probe.');
        }

        if (! DirectUpload::signs($disk)) {
            return $this->refuse(409, 'DIRECT_DISK_CANNOT_SIGN', "The [{$disk}] disk cannot sign upload URLs: it needs the s3 driver, or 'serve' => true on a local disk.");
        }

        return null;
    }

    /**
     * Probes older than an hour belong to a caller that never checked them.
     */
    protected function sweep(string $disk): void
    {
        try {
            $storage = Storage::disk($disk);
            $cutoff = now()->subHour()->getTimestamp();

            foreach ($storage->files(self::PROBES) as $path) {
                if ($storage->lastModified($path) < $cutoff) {
                    $storage->delete($path);
                }
            }
        } catch (Throwable) {
            // A sweep that cannot run is no reason to refuse the probe.
        }
    }

    /**
     * @param  resource|null  $stream
     */
    protected function hash($stream): string
    {
        if (! is_resource($stream)) {
            return '';
        }

        $context = hash_init('sha256');

        try {
            hash_update_stream($context, $stream);
        } finally {
            fclose($stream);
        }

        return hash_final($context);
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
