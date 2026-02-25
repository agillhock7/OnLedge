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
        $any = $conditions['any'] ?? null;

        if (is_array($all)) {
            foreach ($all as $condition) {
                if (!is_array($condition) || !self::matchCondition($receipt, $condition)) {
                    return false;
                }
            }
            return true;
        }

        if (is_array($any)) {
            foreach ($any as $condition) {
                if (is_array($condition) && self::matchCondition($receipt, $condition)) {
                    return true;
                }
            }
            return false;
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
        $actualText = strtolower(trim((string) $actual));
        $targetText = strtolower(trim((string) $value));

        return match ($operator) {
            'equals' => self::matchesEquals($actual, $value),
            'not_equals' => !self::matchesEquals($actual, $value),
            'contains' => self::matchesContains($actual, $value),
            'not_contains' => !self::matchesContains($actual, $value),
            'starts_with' => $targetText !== '' && str_starts_with($actualText, $targetText),
            'ends_with' => $targetText !== '' && str_ends_with($actualText, $targetText),
            'gt' => (float) $actual > (float) $value,
            'gte' => (float) $actual >= (float) $value,
            'lt' => (float) $actual < (float) $value,
            'lte' => (float) $actual <= (float) $value,
            'in' => self::matchesIn($actual, $value),
            default => false,
        };
    }

    private static function matchesEquals(mixed $actual, mixed $target): bool
    {
        if (is_array($actual)) {
            $normalizedActual = array_map(static fn (mixed $item): string => strtolower(trim((string) $item)), $actual);
            if (is_array($target)) {
                $normalizedTarget = array_map(static fn (mixed $item): string => strtolower(trim((string) $item)), $target);
                sort($normalizedActual);
                sort($normalizedTarget);
                return $normalizedActual === $normalizedTarget;
            }

            return in_array(strtolower(trim((string) $target)), $normalizedActual, true);
        }

        return strtolower(trim((string) $actual)) === strtolower(trim((string) $target));
    }

    private static function matchesContains(mixed $actual, mixed $target): bool
    {
        if (is_array($actual)) {
            $needle = strtolower(trim((string) $target));
            if ($needle === '') {
                return false;
            }

            foreach ($actual as $item) {
                if (strtolower(trim((string) $item)) === $needle) {
                    return true;
                }
            }

            return false;
        }

        $haystack = strtolower((string) $actual);
        $needle = strtolower(trim((string) $target));
        return $needle !== '' && str_contains($haystack, $needle);
    }

    private static function matchesIn(mixed $actual, mixed $target): bool
    {
        if (!is_array($target)) {
            return false;
        }

        $set = array_map(static fn (mixed $item): string => strtolower(trim((string) $item)), $target);
        if ($set === []) {
            return false;
        }

        if (is_array($actual)) {
            foreach ($actual as $item) {
                if (in_array(strtolower(trim((string) $item)), $set, true)) {
                    return true;
                }
            }

            return false;
        }

        return in_array(strtolower(trim((string) $actual)), $set, true);
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
