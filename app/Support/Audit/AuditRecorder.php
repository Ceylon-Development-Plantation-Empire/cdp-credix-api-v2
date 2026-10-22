<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Traits\ActivityLogTrait;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Writes the old -> new values of a created, updated or deleted record to the audit trail,
 * through the senior's ActivityLogTrait (who, when, IP, user agent and URL are added there).
 *
 * SECURITY: the trait also copies the payload into the application log file, so personal data is
 * masked here first: hidden attributes (password, hashes, encrypted numbers) are never included,
 * and identity / contact fields are reduced to their last characters.
 */
final class AuditRecorder
{
    use ActivityLogTrait;

    /**
     * Fields shown masked (last 4 characters only) in audit payloads.
     */
    private const MASKED = [
        'phone', 'phone_primary', 'phone_secondary', 'whatsapp_number', 'employer_phone',
        'business_phone', 'email', 'employer_email', 'business_email',
    ];

    /**
     * Encrypted columns (their stored value is ciphertext): recorded as changed, never shown.
     */
    private const ENCRYPTED = ['id_number', 'account_number'];

    /**
     * Record one model event.
     *
     * @param  'created'|'updated'|'deleted'  $event
     * @param  list<string>  $ignored  Columns whose change alone is not worth an entry (caches, timestamps).
     */
    public function record(Model $model, string $event, array $ignored = []): void
    {
        $timestamps = ['created_at', 'updated_at', 'deleted_at'];
        $skip = array_merge($ignored, $model->getHidden(), $timestamps);

        [$old, $new] = match ($event) {
            'created' => [null, $this->clean($model->getAttributes(), $skip)],
            'deleted' => [$this->clean($model->getRawOriginal(), $skip), null],
            // Hidden columns (password, hashes) are reported as changed, never with their value.
            default => $this->changes($model, array_merge($ignored, $timestamps), $model->getHidden()),
        };

        if ($event === 'updated' && $new === []) {
            return;
        }

        $alias = Relation::getMorphAlias($model::class);
        // Read the raw row: not every model has a public_id / slug / code column (strict mode refuses missing ones).
        $attributes = $model->getAttributes();
        $label = $attributes['public_id'] ?? $attributes['slug'] ?? $attributes['code'] ?? $model->getKey();

        $this->logActivity(strtoupper(match ($event) {
            'created' => 'create',
            'deleted' => 'delete',
            default => 'update',
        }), class_basename($model), "{$event} {$alias} {$label}", array_filter([
            'subject' => ['type' => $alias, 'id' => $model->getKey()],
            'old' => $old,
            'new' => $new,
        ], fn ($value) => $value !== null));
    }

    /**
     * The changed fields of an update, before and after.
     *
     * @param  list<string>  $skip  Columns not reported at all.
     * @param  list<string>  $hidden  Columns reported as "[hidden]" (their values never leave the model).
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function changes(Model $model, array $skip, array $hidden): array
    {
        $changed = array_diff(array_keys($model->getChanges()), $skip);
        $old = [];
        $new = [];

        foreach ($changed as $key) {
            if (in_array($key, $hidden, true)) {
                $old[$key] = '[hidden]';
                $new[$key] = '[hidden]';

                continue;
            }

            $old[$key] = $this->display($key, $model->getRawOriginal($key));
            $new[$key] = $this->display($key, $model->getAttributes()[$key] ?? null);
        }

        return [$old, $new];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $skip
     * @return array<string, mixed>
     */
    private function clean(array $attributes, array $skip): array
    {
        $clean = [];

        foreach (array_diff_key($attributes, array_flip($skip)) as $key => $value) {
            $clean[$key] = $this->display($key, $value);
        }

        return $clean;
    }

    /**
     * A JSON-safe, masked value.
     */
    private function display(string $key, mixed $value): mixed
    {
        $value = match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            default => $value,
        };

        // Encrypted columns: the raw value is ciphertext; record only that it changed.
        if (in_array($key, self::ENCRYPTED, true) && $value !== null) {
            return '[encrypted]';
        }

        if (in_array($key, self::MASKED, true) && is_string($value) && $value !== '') {
            return str_repeat('*', max(0, mb_strlen($value) - 4)).mb_substr($value, -4);
        }

        return $value;
    }
}
