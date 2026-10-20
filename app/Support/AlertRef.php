<?php

namespace App\Support;

/**
 * An opaque, URL-path-safe reference to one app alert group, carried in a push's tap link so the app can mark the
 * alert read before its delivery row (and so its id) exists. The same groups as AppAlert::keyFor(): with a product,
 * `p.{rule id, 0 for none}.{product id}`; without one, `b.{batch key}`. A legacy row with neither is a group of its
 * own that cannot be named before it is written, so it has no reference.
 */
final class AlertRef
{
    public const int MAX_LENGTH = 40;

    /** Mirrored by the app's AlertRefFormat::PATTERN. */
    public const string PATTERN = '/^(?:p\.(0|[1-9][0-9]{0,17})\.([1-9][0-9]{0,17})|b\.([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}))$/D';

    public static function forGroup(?int $alertRuleId, ?int $ingestedProductId, ?string $batchKey): ?string
    {
        $ref = match (true) {
            $ingestedProductId !== null => 'p.'.($alertRuleId ?? 0).'.'.$ingestedProductId,
            $batchKey !== null => 'b.'.$batchKey,
            default => null,
        };

        return $ref !== null && self::parse($ref) !== null ? $ref : null;
    }

    /**
     * @return array{alert_rule_id: ?int, ingested_product_id: ?int, batch_key: ?string}|null null for anything malformed
     */
    public static function parse(string $ref): ?array
    {
        if (strlen($ref) > self::MAX_LENGTH || preg_match(self::PATTERN, $ref, $matches) !== 1) {
            return null;
        }

        if (($matches[3] ?? '') !== '') {
            return ['alert_rule_id' => null, 'ingested_product_id' => null, 'batch_key' => $matches[3]];
        }

        $ruleId = (int) $matches[1];

        return ['alert_rule_id' => $ruleId === 0 ? null : $ruleId, 'ingested_product_id' => (int) $matches[2], 'batch_key' => null];
    }
}
