<?php
/**
 * OfferAttachmentValidator
 *
 * Checks a file before it becomes an offer attachment (GOKINT-1099), so the
 * merchant gets the reason right away instead of a rejected async command.
 * Limits found on the InPost stage API (2026-10-28):
 *   - text/plain etc. -> 415 UNSUPPORTED_ATTACHMENT (MIME detected from content);
 *   - 15 MB -> 413, 10 MB accepted;
 *   - images under 50x50 px or 1 kB -> command FAILURE.
 *
 * Messages are English source strings of the Modules.Inpostvonhalsky.Admin
 * domain, translated by the controller (with the returned params).
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use InpostApi\Model\AttachmentType;

class OfferAttachmentValidator
{
    public const MAX_SIZE = 10 * 1024 * 1024;

    private const IMAGE_MIN_SIZE = 1024;
    private const IMAGE_MIN_DIMENSION = 50;

    /** MIME => stored file extension */
    public const ALLOWED_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * Document types offered to the merchant, in the order of the select
     * (IMAGE / VIDEO / AUDIO are product media, sent with the offer itself)
     */
    public const TYPES = [
        AttachmentType::MANUAL,
        AttachmentType::ASSEMBLY_INSTRUCTION,
        AttachmentType::ENERGY_LABEL,
        AttachmentType::PRODUCT_LABEL,
        AttachmentType::CERTIFICATE,
        AttachmentType::SPECIFICATION_SHEET,
        AttachmentType::WARRANTY_CARD,
        AttachmentType::SIZE_CHART,
        AttachmentType::GENERAL_DOCUMENT,
        AttachmentType::OTHER,
    ];

    /**
     * MIME type detected from the file content
     */
    public function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) $finfo->file($path);
    }

    /**
     * @return array{0: string, 1: array<string,string|int>}|null [message, params] or null when valid
     */
    public function validateType(string $type): ?array
    {
        if (!in_array($type, self::TYPES, true)) {
            return ['Choose the attachment type.', []];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: array<string,string|int>}|null [message, params] or null when valid
     */
    public function validateFile(string $path, string $fileName): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['File %name% cannot be read.', ['%name%' => $fileName]];
        }

        $size = (int) filesize($path);
        if ($size === 0) {
            return ['File %name% is empty.', ['%name%' => $fileName]];
        }
        if ($size > self::MAX_SIZE) {
            return ['File %name% is too large (%size% MB). The limit is %max% MB.', [
                '%name%' => $fileName,
                '%size%' => number_format($size / 1048576, 1),
                '%max%' => (int) (self::MAX_SIZE / 1048576),
            ]];
        }

        $mime = $this->detectMime($path);
        if (!isset(self::ALLOWED_MIME[$mime])) {
            return ['File %name% is not a PDF, JPG or PNG file.', ['%name%' => $fileName]];
        }

        if (strpos($mime, 'image/') === 0) {
            $dimensions = @getimagesize($path);
            if ($size < self::IMAGE_MIN_SIZE
                || !$dimensions
                || $dimensions[0] < self::IMAGE_MIN_DIMENSION
                || $dimensions[1] < self::IMAGE_MIN_DIMENSION
            ) {
                return ['Image %name% is too small. InPost needs at least %px%x%px% px and 1 kB.', [
                    '%name%' => $fileName,
                    '%px%' => self::IMAGE_MIN_DIMENSION,
                ]];
            }
        }

        return null;
    }

    /**
     * File name safe to show in InPost: no path, no control characters, at most
     * 255 bytes, with the extension matching the content
     */
    public function normalizeFileName(string $fileName, string $mime): string
    {
        $name = basename(str_replace('\\', '/', $fileName));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));

        $extension = self::ALLOWED_MIME[$mime] ?? '';
        $base = pathinfo($name, PATHINFO_FILENAME);
        $current = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($current === 'jpeg') {
            $current = 'jpg';
        }
        if ($base === '') {
            $base = 'attachment';
        }
        if ($extension !== '' && $current !== $extension) {
            // e.g. a PrestaShop attachment named without its extension
            $base = $name !== '' ? $name : $base;
        }

        $base = mb_strcut($base, 0, 240, 'UTF-8');

        return $extension !== '' ? $base . '.' . $extension : $base;
    }
}
