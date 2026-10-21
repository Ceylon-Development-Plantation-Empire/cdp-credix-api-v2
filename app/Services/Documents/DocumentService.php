<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentTarget;
use App\Enums\LoanStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Collateral;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Loan;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploads, review and download of documents.
 *
 * Fixes for the old system's file handling:
 * - files go to the private disk, never under public/, so there is no URL to guess;
 * - the stored name is random and the extension comes from the detected content type,
 *   never from the client's file name;
 * - the file type is checked from its content (finfo), so a script renamed to .pdf is refused;
 * - downloads go through an authorised endpoint by document id, never by a client-supplied path.
 */
class DocumentService
{
    use ActivityLogTrait;

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
     * @param  array{issued_on?: string|null, expires_on?: string|null, remarks?: string|null}  $meta
     */
    public function upload(Model $owner, DocumentType $type, UploadedFile $file, array $meta, User $user): Document
    {
        if (! $type->is_active) {
            throw new BusinessRuleException('DOCUMENT_TYPE_INACTIVE', 'This document type is no longer accepted.', 422);
        }

        if ($type->target !== $this->targetFor($owner)) {
            throw new BusinessRuleException('DOCUMENT_TYPE_MISMATCH', "A {$type->name} cannot be attached here.", 422);
        }

        $this->assertNotFrozen($owner);

        $contentType = $file->getMimeType();
        $extension = $this->extensionFor($type, $contentType);

        if ($extension === null) {
            throw new BusinessRuleException('DOCUMENT_FORMAT_NOT_ALLOWED', 'This file format is not accepted for '.$type->name.'. Allowed: '.$type->allowed_mimes.'.', 422);
        }

        if ($file->getSize() > $type->max_size_kb * 1024) {
            throw new BusinessRuleException('DOCUMENT_TOO_LARGE', "The file is larger than {$type->max_size_kb} KB.", 422);
        }

        $directory = 'documents/'.now()->format('Y/m');
        $name = Str::ulid().'.'.$extension;
        $path = Storage::disk(self::DISK)->putFileAs($directory, $file, $name);

        try {
            $document = Document::create([
                'documentable_type' => $owner->getMorphClass(),
                'documentable_id' => $owner->getKey(),
                'document_type_id' => $type->id,
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
                'path' => $path,
                'mime_type' => $contentType,
                'size_bytes' => $file->getSize(),
                'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
                'issued_on' => $meta['issued_on'] ?? null,
                'expires_on' => $meta['expires_on'] ?? null,
                'remarks' => $meta['remarks'] ?? null,
                'uploaded_by' => $user->id,
            ]);
        } catch (\Throwable $th) {
            // Never leave an orphan file without a database row pointing at it.
            Storage::disk(self::DISK)->delete($path);

            throw $th;
        }

        $this->logActivity('UPLOAD', 'Document', "Uploaded {$type->name} ({$document->public_id})");

        return $document;
    }

    /**
     * A document is verified by someone other than the person who uploaded it.
     */
    public function verify(Document $document, User $user): Document
    {
        $this->assertPending($document);

        if ($document->uploaded_by === $user->id) {
            throw new BusinessRuleException('SELF_VERIFICATION_NOT_ALLOWED', 'A document must be verified by someone other than the person who uploaded it.', 403);
        }

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
