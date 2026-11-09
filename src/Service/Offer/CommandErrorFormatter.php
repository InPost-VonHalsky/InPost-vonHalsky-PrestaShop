<?php
/**
 * CommandErrorFormatter
 *
 * Normalizes and formats the optional `errors` returned by the Offer command
 * status endpoint (getOffersCommandsByIdV1 -> OfferCommandStatusDetails). The
 * InPost API started returning these details (field name, element id, message)
 * for FAILURE commands in API 1.5.11, so they can be logged and surfaced to
 * explain why an offer was not created.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

final class CommandErrorFormatter
{
    /**
     * Normalize command errors into plain arrays.
     *
     * Accepts the SDK model (OfferCommandStatusDetails with
     * getErrors(): CommandError[]) or an already-decoded array.
     *
     * @param mixed $data
     * @return array<int, array{field: ?string, element_id: ?string, message: string}>
     */
    public static function toArray($data): array
    {
        $out = [];
        foreach (self::extractRawErrors($data) as $err) {
            $field = null;
            $elementId = null;
            $message = '';

            if (is_object($err)) {
                if (method_exists($err, 'getFieldName')) {
                    $field = $err->getFieldName();
                }
                if (method_exists($err, 'getElementId')) {
                    $elementId = $err->getElementId();
                }
                if (method_exists($err, 'getMessage')) {
                    $message = (string) $err->getMessage();
                }
            } elseif (is_array($err)) {
                $field = $err['fieldName'] ?? $err['field_name'] ?? $err['field'] ?? null;
                $elementId = $err['elementId'] ?? $err['element_id'] ?? null;
                $message = (string) ($err['message'] ?? '');
            }

            if ($message === '' && $field === null && $elementId === null) {
                continue;
            }

            $out[] = [
                'field' => $field !== null ? (string) $field : null,
                'element_id' => $elementId !== null ? (string) $elementId : null,
                'message' => $message,
            ];
        }

        return $out;
    }

    /**
     * Build a single human-readable line from normalized errors.
     *
     * @param array<int, array{field: ?string, element_id: ?string, message: string}> $errors
     */
    public static function format(array $errors): string
    {
        $parts = [];
        foreach ($errors as $error) {
            $label = $error['field'] ?? $error['element_id'] ?? null;
            $message = $error['message'] ?? '';
            $parts[] = ($label !== null && $label !== '')
                ? sprintf('%s: %s', $label, $message)
                : $message;
        }

        return implode('; ', array_filter($parts, static fn ($part) => $part !== ''));
    }

    /**
     * @param mixed $data
     * @return array<int, mixed>
     */
    private static function extractRawErrors($data): array
    {
        if (is_object($data) && method_exists($data, 'getErrors')) {
            $errors = $data->getErrors();
        } elseif (is_array($data)) {
            $errors = $data['errors'] ?? null;
        } else {
            $errors = null;
        }

        if ($errors === null) {
            return [];
        }
        if (is_array($errors)) {
            return array_values($errors);
        }
        if (is_iterable($errors)) {
            $collected = [];
            foreach ($errors as $error) {
                $collected[] = $error;
            }
            return $collected;
        }

        return [];
    }
}
