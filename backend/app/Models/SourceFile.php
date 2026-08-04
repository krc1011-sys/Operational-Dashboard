<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Marketplace;
use App\Enums\SourceFileStatus;
use App\Enums\UploadType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An uploaded file and what became of it (§J).
 */
class SourceFile extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'upload_type' => UploadType::class,
            'marketplace' => Marketplace::class,
            'channel' => Channel::class,
            'status' => SourceFileStatus::class,
            'summary' => 'array',
            'warnings' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeImported(Builder $query): Builder
    {
        return $query->where('status', SourceFileStatus::Imported->value);
    }

    public function scopeOfType(Builder $query, UploadType $type): Builder
    {
        return $query->where('upload_type', $type->value);
    }

    /** When was this type last imported successfully? Drives the §J freshness nudge. */
    public static function lastImportedAt(UploadType $type): ?Carbon
    {
        $value = static::query()->ofType($type)->imported()->max('imported_at');

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * Types that are overdue against their expected cadence, for the dashboard banner:
     * "DFS not uploaded in 9 days - upload the latest" (§J).
     *
     * @return array<int, array{type: UploadType, last: ?Carbon, days: ?int, cadence: int}>
     */
    public static function overdueTypes(): array
    {
        $overdue = [];

        foreach (UploadType::cases() as $type) {
            $cadence = $type->expectedCadenceDays();

            if ($cadence === null) {
                continue; // event-driven, deliberately not nudged
            }

            $last = static::lastImportedAt($type);
            // Carbon 3 returns a float here; whole days are what the banner reports.
            $days = $last === null ? null : (int) $last->startOfDay()->diffInDays(Carbon::today());

            if ($last === null || $days > $cadence) {
                $overdue[] = ['type' => $type, 'last' => $last, 'days' => $days, 'cadence' => $cadence];
            }
        }

        return $overdue;
    }
}
