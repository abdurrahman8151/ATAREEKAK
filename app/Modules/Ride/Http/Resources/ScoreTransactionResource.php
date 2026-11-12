<?php

namespace App\Modules\Ride\Http\Resources;

use App\Enums\ScoreAction;
use App\Models\Booking;
use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Score Transaction Resource
 *
 * Formats a single ScoreTransaction for the API consumer.
 *
 * Reference models (Ride / Booking) must be pre-loaded by the controller
 * via setRelation('referenceModel', ...) to avoid N+1 queries.
 * If the relation is absent the resource still returns id + type.
 *
 * Expected response shape:
 * {
 *   "id": 42,
 *   "event": {
 *     "code":    "ride_completed",
 *     "label":   "Ride completed",
 *     "summary": "+10 pts — Ride completed"
 *   },
 *   "points": {
 *     "value":   10,
 *     "display": "+10"
 *   },
 *   "score": {
 *     "before": 80,
 *     "after":  90,
 *     "delta":  10
 *   },
 *   "reason": "Ride completed successfully — all parties rewarded",
 *   "high_cancel_rate_applied": false,
 *   "reference": {
 *     "type": "Ride",
 *     "id":   7,
 *     "origin": "Amman, Jordan",
 *     "destination": "Zarqa, Jordan",
 *     "departure_time": "2026-08-17T09:00:00+03:00",
 *     "status": "completed"
 *   },
 *   "occurred_at": "2026-08-17T09:35:00+00:00"
 * }
 */
final class ScoreTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $action = ScoreAction::from($this->action);
        $sign = $this->points >= 0 ? '+' : '';   // negative already carries its own '−'

        return [
            // ── What happened ─────────────────────────────────────────────
            'id' => $this->id,
            'event' => [
                'code' => $this->action,
                'label' => $action->label(),
                'summary' => "{$sign}{$this->points} pts — {$action->label()}",
            ],

            // ── Points ────────────────────────────────────────────────────
            'points' => [
                'value' => $this->points,
                'display' => "{$sign}{$this->points}",   // e.g. "+10" or "−5"
            ],

            // ── Score snapshot ────────────────────────────────────────────
            'score' => [
                'before' => $this->previous_score,
                'after' => $this->new_score,
                'delta' => $this->new_score - $this->previous_score,
            ],

            // ── Narrative ─────────────────────────────────────────────────
            'reason' => $this->reason,
            'high_cancel_rate_applied' => (bool) $this->high_cancel_rate_applied,

            // ── Context (ride / booking) ──────────────────────────────────
            'reference' => $this->buildReference(),

            // ── Timestamp ─────────────────────────────────────────────────
            'occurred_at' => $this->created_at->toIso8601String(),
        ];
    }

    // =========================================================================
    // REFERENCE BUILDER
    // =========================================================================

    private function buildReference(): ?array
    {
        if (! $this->reference_type || ! $this->reference_id) {
            return null;
        }

        $type = class_basename($this->reference_type);
        $base = [
            'type' => $type,
            'id' => $this->reference_id,
        ];

        // Model was not pre-loaded → return id + type only (safe fallback)
        if (! $this->resource->relationLoaded('referenceModel')) {
            return $base;
        }

        $model = $this->resource->getRelation('referenceModel');

        if (! $model) {
            return $base;  // row was deleted — still return the id for traceability
        }

        return match ($type) {
            'Ride' => $this->formatRide($base, $model),
            'Booking' => $this->formatBooking($base, $model),
            default => $base,
        };
    }

    /**
     * Fields returned for a Ride reference.
     *
     * Stage 0.6 item 2: this read `$ride->origin` / `$ride->destination`, which DO NOT EXIST on the
     * Ride model (it stores `pickup_address` / `destination_address`). With
     * `preventAccessingMissingAttributes` armed - the app arms it outside production - the read threw
     * and the whole endpoint answered 500, but only for a score row that actually referenced a ride.
     * The JSON keys stay `origin` / `destination` (the documented shape); only the attribute names
     * were wrong.
     */
    private function formatRide(array $base, Ride $ride): array
    {
        return array_merge($base, [
            'origin' => $ride->pickup_address,
            'destination' => $ride->destination_address,
            'departure_time' => $ride->departure_time?->toIso8601String(),
            'status' => $ride->status,
        ]);
    }

    /**
     * Fields returned for a Booking reference (passenger-side events).
     * The nested `ride` block is populated only when the booking's ride
     * relation was eager-loaded by the controller.
     */
    private function formatBooking(array $base, Booking $booking): array
    {
        $result = array_merge($base, [
            'seats' => $booking->seats,
            'status' => $booking->status,
            'payment_method' => $booking->payment_method ?? null,
        ]);

        // Ride is eager-loaded in ScoreController::hydrateReferences()
        if ($booking->relationLoaded('ride') && $booking->ride) {
            $result['ride'] = [
                'id' => $booking->ride->id,
                // Same wrong attribute names as formatRide() above (stage 0.6 item 2).
                'origin' => $booking->ride->pickup_address,
                'destination' => $booking->ride->destination_address,
                'departure_time' => $booking->ride->departure_time?->toIso8601String(),
                'status' => $booking->ride->status,
            ];
        }

        return $result;
    }
}
