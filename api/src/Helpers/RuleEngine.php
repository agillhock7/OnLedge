<?php

declare(strict_types=1);

namespace App\Helpers;

final class RuleEngine
{
    /**
     * @param array<string, mixed> $receipt
     * @param array<int, array<string, mixed>> $rules
     * @return array{updated: array<string, mixed>, explanation: array<int, array<string, mixed>>}
     */
    public static function apply(array $receipt, array $rules): array
    {
        $updated = [
            'category' => $receipt['category'] ?? null,
            'tags' => self::normalizeTags($receipt['tags'] ?? []),
            'notes' => $receipt['notes'] ?? null,
        ];

        $explanation = [];

        foreach ($rules as $rule) {
            $conditions = self::decodeJsonField($rule['conditions'] ?? []);
            $actions = self::decodeJsonField($rule['actions'] ?? []);

            $matched = self::matches($receipt, $conditions);
            if (!$matched) {
                continue;
            }

            if (isset($actions['set']) && is_array($actions['set'])) {
                foreach ($actions['set'] as $field => $value) {
                    if (in_array($field, ['category', 'notes'], true)) {
                        $updated[$field] = is_string($value) ? $value : $updated[$field];
                    }
                    if ($field === 'tags') {
                        $updated['tags'] = self::normalizeTags($value);
                    }
                }
            }

            if (isset($actions['append_tags']) && is_array($actions['append_tags'])) {
                $updated['tags'] = array_values(array_unique([
                    ...$updated['tags'],
                    ...self::normalizeTags($actions['append_tags']),
                ]));
            }

            $explanation[] = [
                'rule_id' => (int) ($rule['id'] ?? 0),
                'rule_name' => (string) ($rule['name'] ?? 'Unnamed Rule'),
                'matched' => true,
                'conditions' => $conditions,
                'actions_applied' => $actions,
            ];
        }

        return ['updated' => $updated, 'explanation' => $explanation];
    }

    private static function decodeJsonField(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private static function matches(array $receipt, array $conditions): bool
    {
        $all = $conditions['all'] ?? null;

        if (is_array($all)) {
            foreach ($all as $condition) {
                if (!is_array($condition) || !self::matchCondition($receipt, $condition)) {
                    return false;
                }
            }
            return true;
        }

        return self::matchCondition($receipt, $conditions);
    }

    private static function matchCondition(array $receipt, array $condition): bool
    {
        $field = (string) ($condition['field'] ?? '');
        $operator = strtolower((string) ($condition['operator'] ?? 'equals'));
        $value = $condition['value'] ?? null;

        if ($field === '' || !array_key_exists($field, $receipt)) {
            return false;
        }

        $actual = $receipt[$field];

        return match ($operator) {
            'equals' => (string) $actual === (string) $value,
            'contains' => str_contains(strtolower((string) $actual), strtolower((string) $value)),
            'gt' => (float) $actual > (float) $value,
            'gte' => (float) $actual >= (float) $value,
            'lt' => (float) $actual < (float) $value,
            'lte' => (float) $actual <= (float) $value,
            'in' => is_array($value) && in_array((string) $actual, array_map('strval', $value), true),
            default => false,
        };
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }

        if (!is_array($tags)) {
            return [];
        }

        $normalized = [];
        foreach ($tags as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }

            $tagText = trim((string) $tag);
            if ($tagText !== '') {
                $normalized[] = $tagText;
            }
        }

        return array_values(array_unique($normalized));
    }
}
