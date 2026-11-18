<?php

namespace Lookout\Services;

use Carbon\CarbonImmutable;
use NightOwl\Support\RollupTiers;

/**
 * A time window of Lookout (UTC) with the rollup tier that covers it and the chart bucket width:
 * up to 48 h the minute tier, up to 90 days the hourly tier, beyond that the daily tier; about 120 buckets at most.
 */
final class Window
{
    public const PERIODS = ['1h' => 3600, '24h' => 86400, '7d' => 604800, '14d' => 1209600, '30d' => 2592000];

    /** Longest custom from/to range: the daily tier's retention. */
    public const MAX_SECONDS = 400 * 86400;

    /** Raw (per-event) tables keep about 14 days; queries over raw rows are clamped to this. */
    public const RAW_SECONDS = 14 * 86400;

    private const MAX_BUCKETS = 120;

    private const STEPS = [60, 300, 900, 1800, 3600, 10800, 21600, 43200, 86400, 172800, 604800];

    public readonly string $tier;

    public readonly int $bucketSeconds;

    public function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $to, public readonly ?string $period = null)
    {
        $seconds = $this->seconds();
        $this->tier = match (true) {
            $seconds <= 48 * 3600 => 'minute',
            $seconds <= 90 * 86400 => 'hourly',
            default => 'daily',
        };
        $minimum = ['minute' => 60, 'hourly' => 3600, 'daily' => 86400][$this->tier];
        $wanted = (int) ceil($seconds / self::MAX_BUCKETS);
        $width = $minimum;
        foreach (self::STEPS as $step) {
            $width = $step;
            if ($step >= $minimum && $step >= $wanted) {
                break;
            }
        }
        $this->bucketSeconds = max($minimum, $width);
    }

    public static function period(string $period): self
    {
        $to = CarbonImmutable::now('UTC');

        return new self($to->subSeconds(self::PERIODS[$period]), $to, $period);
    }

    public function seconds(): int
    {
        return max(60, $this->to->getTimestamp() - $this->from->getTimestamp());
    }

    public function table(string $baseRollupTable): string
    {
        return $this->tier === 'minute' ? $baseRollupTable : RollupTiers::table($baseRollupTable, $this->tier);
    }

    /**
     * SQL that floors bucket_start (a UTC timestamp) to this window's bucket width. The width is an int we computed.
     */
    public function bucketExpression(string $column = 'bucket_start'): string
    {
        $w = $this->bucketSeconds;

        return "to_timestamp(floor(extract(epoch from {$column}) / {$w}) * {$w}) at time zone 'UTC'";
    }

    /**
     * The same window clamped to what the raw tables still hold.
     */
    public function raw(): self
    {
        $floor = $this->to->subSeconds(self::RAW_SECONDS);

        return $this->from->lt($floor) ? new self($floor, $this->to, $this->period) : $this;
    }

    /**
     * The start floored to the bucket width, so the first bucket is whole: series and totals read from here.
     */
    public function fromSql(): string
    {
        return gmdate('Y-m-d H:i:s', intdiv($this->from->getTimestamp(), $this->bucketSeconds) * $this->bucketSeconds);
    }

    public function toSql(): string
    {
        return $this->to->format('Y-m-d H:i:s');
    }

    /**
     * Every bucket start in the window, so the series has no gaps: whole buckets from the floored start, the last
     * one (the current, still filling) included, e.g. 61 minute buckets for 1h.
     *
     * @return list<string>
     */
    public function bucketStarts(): array
    {
        $w = $this->bucketSeconds;
        $starts = [];
        for ($t = intdiv($this->from->getTimestamp(), $w) * $w; $t < $this->to->getTimestamp(); $t += $w) {
            $starts[] = gmdate('Y-m-d\TH:i:s\Z', $t);
        }

        return $starts;
    }

    /**
     * @return array{period: ?string, from: string, to: string, tier: string, bucket_seconds: int}
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'from' => $this->from->format('Y-m-d\TH:i:s\Z'),
            'to' => $this->to->format('Y-m-d\TH:i:s\Z'),
            'tier' => $this->tier,
            'bucket_seconds' => $this->bucketSeconds,
        ];
    }
}
