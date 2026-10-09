<?php

namespace App\Domain\Crm;

use App\Models\Customer;
use Illuminate\Validation\ValidationException;

class ClientIdGenerator
{
    /** New issue format: YYMMDD_C{n} e.g. 261009_C101 (underscore before C). */
    public const NEW_FORMAT_PATTERN = '/^\d{6}_C(\d+)$/';

    /** Legacy formats still stored in DB (read-only for sequence). */
    public const LEGACY_SEQUENCE_PATTERN = '/^\d{6}[-_]C(\d+)$/';

    public function generate(?\DateTimeInterface $issueDate = null): string
    {
        $date = $issueDate ?? now();
        $ymd = $date->format('ymd');
        $next = $this->nextGlobalSequenceNumber();

        return sprintf('%s_C%d', $ymd, $next);
    }

    public function validateNewFormat(string $clientId): void
    {
        if (!preg_match(self::NEW_FORMAT_PATTERN, $clientId)) {
            throw ValidationException::withMessages([
                'client_id' => ['Client ID must match YYMMDD_C{n} (example: 261009_C101).'],
            ]);
        }
    }

    public function assertUnique(string $clientId, ?int $ignoreCustomerId = null): void
    {
        $query = Customer::where('client_id', $clientId);
        if ($ignoreCustomerId) {
            $query->where('id', '!=', $ignoreCustomerId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'client_id' => ['This Client ID is already in use.'],
            ]);
        }
    }

    /** Highest C suffix across MSS-style IDs (legacy -C / _C and new _C). Chunked for large N. */
    public function nextGlobalSequenceNumber(): int
    {
        $max = 0;
        Customer::query()
            ->select(['id', 'client_id'])
            ->where(function ($q) {
                $q->where('client_id', 'like', '______\_C%')
                    ->orWhere('client_id', 'like', '______-C%');
            })
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$max) {
                foreach ($rows as $row) {
                    if (preg_match(self::LEGACY_SEQUENCE_PATTERN, (string) $row->client_id, $m)) {
                        $max = max($max, (int) $m[1]);
                    }
                }
            });

        return $max + 1;
    }
}
