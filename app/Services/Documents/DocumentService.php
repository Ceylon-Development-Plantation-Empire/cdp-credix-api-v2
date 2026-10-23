<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Support\BusinessDate;
use App\Enums\DocumentStatus;
use App\Enums\DocumentTarget;
use App\Enums\LoanStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Collateral;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Loan;
use App\Models\Setting;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use App\Traits\FileUploadTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploads, review and download of documents.
 *
 * Every file is stored by the senior's FileUploadTrait (private disk, uploads/<module>/<name>),
 * never with a hand-written Storage call. Around it, this service enforces what the trait leaves
 * to its caller:
 * - SECURITY: the file type is checked from its content (finfo), so a script renamed to .pdf is
 *   refused; and the client's extension must match that content type, because the trait names
 *   the stored file with the client's extension;
 * - SECURITY: the stored name is a random ULID (the trait's "prefix"), never the client's name;
 * - the size limit and allowed formats of the chosen document type;
 * - files live on the private disk, never under public/: there is no URL to guess; downloads go
 *   through an authorised endpoint by document id, never by a client-supplied path.
 */
class DocumentService
{
    use ActivityLogTrait, FileUploadTrait;

    public const DISK = 'private';

    /**
     * Extension (as configured on a document type) => content types we accept for it.
     */
    protected const CONTENT_TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    /**
     * Store an uploaded file for a customer, loan or collateral item and record it (pending review).
     *
     * @param  Request  $request  The upload request (the senior's trait reads the file from it).
     * @param  string  $field  The form field holding the file.
     * @param  array{issued_on?: string|null, expires_on?: string|null, remarks?: string|null}  $meta
     *
     * @throws BusinessRuleException DOCUMENT_* when the file or its type is not acceptable.
     */
    public function upload(Model $owner, DocumentType $type, Request $request, string $field, array $meta, User $user): Document
    {
        /** @var UploadedFile $file */
        $file = $request->file($field);

        if (! $type->is_active) {
            throw new BusinessRuleException('DOCUMENT_TYPE_INACTIVE', 'This document type is no longer accepted.', 422);
        }

        if ($type->target !== $this->targetFor($owner)) {
            throw new BusinessRuleException('DOCUMENT_TYPE_MISMATCH', "A {$type->name} cannot be attached here.", 422);
        }

        $this->assertNotFrozen($owner);

        $contentType = $this->assertFileAcceptable($type, $file);

        // The checksum is taken from the upload itself, before it is stored.
        $checksum = hash_file('sha256', $file->getRealPath());
        $size = $file->getSize();
        $originalName = Str::limit(basename($file->getClientOriginalName()), 200, '');

        // Stored by the senior's FileUploadTrait on the private disk as uploads/documents/YYYY/MM/<ULID>.<ext>.
        $path = $this->handleFileUpload($request, $field, null, 'documents/'.BusinessDate::today()->format('Y/m'), (string) Str::ulid());

        if ($path === null) {
            throw new BusinessRuleException('DOCUMENT_FILE_MISSING', 'No file was received.', 422);
        }

        try {
            $document = Document::create([
                'documentable_type' => $owner->getMorphClass(),
                'documentable_id' => $owner->getKey(),
                'document_type_id' => $type->id,
                'original_name' => $originalName,
                'path' => $path,
                'mime_type' => $contentType,
                'size_bytes' => $size,
                'checksum_sha256' => $checksum,
                'issued_on' => $meta['issued_on'] ?? null,
                'expires_on' => $meta['expires_on'] ?? null,
                'remarks' => $meta['remarks'] ?? null,
                'uploaded_by' => $user->id,
            ]);
        } catch (\Throwable $th) {
            // Never leave an orphan file without a database row pointing at it (the trait's delete).
            $this->deleteFile($path);

            throw $th;
        }

        $this->logActivity('UPLOAD', 'Document', "Uploaded {$type->name} ({$document->public_id})");

        return $document;
    }

    /**
     * Change a document that has not been verified: its details, its type, or the file itself.
     * A new file or a new type is a new thing to check, so the document goes back to pending.
     *
     * @param  array{document_type_slug?: string, issued_on?: string|null, expires_on?: string|null, remarks?: string|null}  $data
     */
    public function update(Document $document, array $data, ?UploadedFile $file, Request $request, User $user): Document
    {
        if ($document->status === DocumentStatus::Verified) {
            throw new BusinessRuleException('DOCUMENT_VERIFIED_LOCKED', 'A verified document cannot be changed.', 409);
        }

        $owner = $document->documentable;
        $this->assertNotFrozen($owner);

        $type = isset($data['document_type_slug']) ? DocumentType::findBySlug($data['document_type_slug']) : $document->documentType;
        $typeChanged = $type->id !== $document->document_type_id;

        if ($typeChanged) {
            if (! $type->is_active) {
                throw new BusinessRuleException('DOCUMENT_TYPE_INACTIVE', 'This document type is no longer accepted.', 422);
            }

            if ($type->target !== $this->targetFor($owner)) {
                throw new BusinessRuleException('DOCUMENT_TYPE_MISMATCH', "A {$type->name} cannot be attached here.", 422);
            }
        }

        $changes = [];

        if ($file !== null) {
            $contentType = $this->assertFileAcceptable($type, $file);
            $checksum = hash_file('sha256', $file->getRealPath());
            $size = $file->getSize();
            $originalName = Str::limit(basename($file->getClientOriginalName()), 200, '');

            $path = $this->handleFileUpload($request, 'file', null, 'documents/'.BusinessDate::today()->format('Y/m'), (string) Str::ulid());

            if ($path === null) {
                throw new BusinessRuleException('DOCUMENT_FILE_MISSING', 'No file was received.', 422);
            }

            // The replaced file stays on disk for audit, like a deleted document's file.
            $changes += ['original_name' => $originalName, 'path' => $path, 'mime_type' => $contentType, 'size_bytes' => $size, 'checksum_sha256' => $checksum];
        } elseif ($typeChanged) {
            $this->assertFileAcceptable($type, null, $document->mime_type, $document->size_bytes);
        }

        foreach (['issued_on', 'expires_on', 'remarks'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        if ($typeChanged) {
            $changes['document_type_id'] = $type->id;
        }

        if ($file !== null || $typeChanged) {
            $changes += ['status' => DocumentStatus::Pending, 'verified_by' => null, 'verified_at' => null, 'rejection_reason' => null];
        }

        $document->forceFill($changes)->save();

        $this->logActivity('UPDATE', 'Document', "Updated document {$document->public_id}".($file !== null ? ' (file replaced)' : ''));

        return $document;
    }

    /**
     * The real content type, checked against the document type's formats and size limit.
     * Without a file, the stored file's content type and size are checked (a type change).
     *
     * @throws BusinessRuleException DOCUMENT_* when it is not acceptable.
     */
    protected function assertFileAcceptable(DocumentType $type, ?UploadedFile $file, ?string $storedContentType = null, ?int $storedSize = null): string
    {
        $contentType = $file ? $file->getMimeType() : $storedContentType;
        $size = $file ? $file->getSize() : $storedSize;

        if ($this->extensionFor($type, $contentType) === null) {
            throw new BusinessRuleException('DOCUMENT_FORMAT_NOT_ALLOWED', 'This file format is not accepted for '.$type->name.'. Allowed: '.$type->allowed_mimes.'.', 422);
        }

        // SECURITY: the trait keeps the client's extension, so it must agree with the real content
        // (a PDF named invoice.php is refused instead of being stored as .php).
        if ($file) {
            $clientExtension = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($contentType, self::CONTENT_TYPES[$clientExtension] ?? [], true)) {
                throw new BusinessRuleException('DOCUMENT_FORMAT_NOT_ALLOWED', 'The file extension does not match its content. Allowed: '.$type->allowed_mimes.'.', 422);
            }
        }

        if ($size > $type->max_size_kb * 1024) {
            throw new BusinessRuleException('DOCUMENT_TOO_LARGE', "The file is larger than {$type->max_size_kb} KB.", 422);
        }

        return $contentType;
    }

    /**
     * Verify a document (the Document Verify permission decides who may).
     */
    public function verify(Document $document, User $user): Document
    {
        $this->assertPending($document);

        $document->forceFill([
            'status' => DocumentStatus::Verified,
            'verified_by' => $user->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ])->save();

        $this->logActivity('VERIFY', 'Document', "Verified document {$document->public_id}");

        return $document;
    }

    public function reject(Document $document, User $user, string $reason): Document
    {
        $this->assertPending($document);

        $document->forceFill([
            'status' => DocumentStatus::Rejected,
            'verified_by' => $user->id,
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        $this->logActivity('REJECT', 'Document', "Rejected document {$document->public_id}", ['reason' => $reason]);

        return $document;
    }

    /**
     * Pending or rejected documents can be removed (soft delete; the file is kept for audit).
     * A verified document is evidence and stays.
     */
    public function delete(Document $document): void
    {
        if ($document->status === DocumentStatus::Verified) {
            throw new BusinessRuleException('DOCUMENT_VERIFIED_LOCKED', 'A verified document cannot be deleted.');
        }

        $this->assertNotFrozen($document->documentable);

        $document->delete();

        $this->logActivity('DELETE', 'Document', "Deleted document {$document->public_id}");
    }

    /**
     * Stream the file. The download name is rebuilt from safe parts so the client's original
     * file name can never inject anything into the response headers.
     */
    public function download(Document $document): StreamedResponse
    {
        $extension = pathinfo($document->path, PATHINFO_EXTENSION);
        $name = Str::slug($document->documentType->name).'-'.$document->public_id.'.'.$extension;

        $this->logActivity('DOWNLOAD', 'Document', "Downloaded document {$document->public_id}");

        // DECISION: the senior's FileUploadTrait stores and deletes but has no download method, so
        // reading the stored file back is the one direct use of the private disk (same disk as the trait).
        return Storage::disk(self::DISK)->download($document->path, $name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    protected function targetFor(Model $owner): DocumentTarget
    {
        return match (true) {
            $owner instanceof Customer => DocumentTarget::Person,
            $owner instanceof Loan => DocumentTarget::Loan,
            $owner instanceof Collateral => DocumentTarget::Collateral,
            default => throw new BusinessRuleException('DOCUMENT_OWNER_INVALID', 'Documents cannot be attached to this record.', 422),
        };
    }

    /**
     * Rule L-8: the documents of a loan (and of its collateral) are the evidence it was granted on,
     * so they are frozen once the loan is disbursed or closed.
     */
    protected function assertNotFrozen(Model $owner): void
    {
        $loan = match (true) {
            $owner instanceof Loan => $owner,
            $owner instanceof Collateral => $owner->loan,
            default => null,
        };

        if ($loan && ($loan->status === LoanStatus::Active || $loan->status->isClosed())) {
            throw new BusinessRuleException('LOAN_DOCUMENTS_FROZEN', 'The documents of a disbursed or closed loan cannot change.', 409);
        }
    }

    /**
     * The extension to store the file under, or null when its real content type is not allowed.
     */
    protected function extensionFor(DocumentType $type, ?string $contentType): ?string
    {
        foreach (explode(',', $type->allowed_mimes) as $extension) {
            $extension = trim($extension);

            if (in_array($contentType, self::CONTENT_TYPES[$extension] ?? [], true)) {
                return $extension === 'jpeg' ? 'jpg' : $extension;
            }
        }

        return null;
    }

    protected function assertPending(Document $document): void
    {
        if ($document->status !== DocumentStatus::Pending) {
            throw new BusinessRuleException('DOCUMENT_ALREADY_REVIEWED', 'This document has already been reviewed.');
        }
    }
}
