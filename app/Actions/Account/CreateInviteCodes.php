<?php

namespace App\Actions\Account;

use App\Models\InviteCode;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates invite codes for staff: a batch of generated single-use codes, or one custom (often shared) code.
 */
class CreateInviteCodes
{
    public const MAX_COUNT = 50;

    public const MIN_CUSTOM_LENGTH = 4;

    private const GENERATION_ATTEMPTS = 10;

    /**
     * @return Collection<int, InviteCode>
     *
     * @throws ValidationException
     */
    public function __invoke(
        int $count,
        ?string $note,
        ?CarbonInterface $expiresAt,
        ?string $email,
        ?User $by,
        ?string $code = null,
        ?int $maxUses = 1,
    ): Collection {
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw ValidationException::withMessages(['count' => 'Create between 1 and '.self::MAX_COUNT.' codes at a time.']);
        }

        $custom = filled($code) ? InviteCode::normalise($code) : null;

        if ($custom !== null) {
            if ($count !== 1) {
                throw ValidationException::withMessages(['code' => 'A custom code can only be created on its own.']);
            }

            if (strlen($custom) < self::MIN_CUSTOM_LENGTH) {
                throw ValidationException::withMessages(['code' => 'A code needs at least '.self::MIN_CUSTOM_LENGTH.' letters or digits.']);
            }
        }

        $email = filled($email) ? mb_strtolower(trim($email)) : null;

        return DB::transaction(function () use ($count, $note, $expiresAt, $email, $by, $custom, $maxUses): Collection {
            $created = collect();

            for ($i = 0; $i < $count; $i++) {
                $created->push($custom !== null
                    ? $this->createCustom($custom, $note, $expiresAt, $email, $by, $maxUses)
                    : $this->createGenerated($note, $expiresAt, $email, $by, $maxUses));
            }

            return $created;
        });
    }

    private function createCustom(string $code, ?string $note, ?CarbonInterface $expiresAt, ?string $email, ?User $by, ?int $maxUses): InviteCode
    {
        try {
            return $this->store($code, $note, $expiresAt, $email, $by, $maxUses);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'That code is already in use.']);
        }
    }

    private function createGenerated(?string $note, ?CarbonInterface $expiresAt, ?string $email, ?User $by, ?int $maxUses): InviteCode
    {
        for ($attempt = 1; $attempt < self::GENERATION_ATTEMPTS; $attempt++) {
            try {
                return $this->store(InviteCode::generateCode(), $note, $expiresAt, $email, $by, $maxUses);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        return $this->store(InviteCode::generateCode(), $note, $expiresAt, $email, $by, $maxUses);
    }

    private function store(string $code, ?string $note, ?CarbonInterface $expiresAt, ?string $email, ?User $by, ?int $maxUses): InviteCode
    {
        // A savepoint keeps a unique clash from poisoning the surrounding transaction on PostgreSQL.
        return DB::transaction(function () use ($code, $note, $expiresAt, $email, $by, $maxUses): InviteCode {
            $invite = new InviteCode([
                'code' => $code,
                'note' => filled($note) ? $note : null,
                'max_uses' => $maxUses,
                'expires_at' => $expiresAt,
                'email' => $email,
            ]);
            $invite->forceFill(['used_count' => 0, 'created_by' => $by?->id])->save();

            return $invite;
        });
    }
}
