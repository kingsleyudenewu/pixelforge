<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Position in the lifecycle. Snapshots may only move a generation to a higher rank,
     * which is what makes late or duplicated webhook deliveries harmless.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Processing => 1,
            self::Succeeded, self::Failed => 2,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return [self::Queued, self::Processing];
    }
}
