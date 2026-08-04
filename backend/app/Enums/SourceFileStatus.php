<?php

namespace App\Enums;

/**
 * Lifecycle of an uploaded file (blueprint §J — validation guardrail + audit log).
 */
enum SourceFileStatus: string
{
    /** Stored, not yet checked. */
    case Pending = 'pending';

    /** Failed the fingerprint check for the chosen type — nothing was imported. */
    case Rejected = 'rejected';

    /**
     * Passed the fingerprint check but not yet imported. Until M3 adds the parsers,
     * every accepted upload rests here: verified as the right file, nothing written.
     */
    case Validated = 'validated';

    /** Passed validation, import in progress. */
    case Importing = 'importing';

    /** Rows written successfully. */
    case Imported = 'imported';

    /** Blew up mid-import; see the error payload. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Rejected => 'Rejected',
            self::Validated => 'Validated — awaiting import',
            self::Importing => 'Importing',
            self::Imported => 'Imported',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Imported, self::Failed], true);
    }
}
