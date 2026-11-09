<?php
/**
 * OfferAttachmentStorage
 *
 * Files the merchant uploads as offer attachments (GOKINT-1099). They live in
 * download/inpostvonhalsky, next to PrestaShop's own product attachments: outside
 * the module (survives reinstall/upgrade zips) and denied to web access. Stored
 * under a random name; the original name is kept in inpost_offer_attachment.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class OfferAttachmentStorage
{
    private const DIRECTORY = 'inpostvonhalsky';

    public static function directory(): string
    {
        return _PS_DOWNLOAD_DIR_ . self::DIRECTORY . '/';
    }

    /**
     * Create the directory with its web-access guards. Never throws.
     */
    public static function ensureDirectory(): bool
    {
        $dir = self::directory();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
            // Same permissions as download/ itself: the directory may be created
            // by a console/cron user other than the web server's
            @chmod($dir, fileperms(_PS_DOWNLOAD_DIR_) & 0777);
        }

        if (!is_file($dir . '.htaccess')) {
            @file_put_contents($dir . '.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
        }
        if (!is_file($dir . 'index.php')) {
            @file_put_contents($dir . 'index.php', "<?php\nheader('Location: ../');\nexit;\n");
        }

        return is_writable($dir);
    }

    /**
     * Move an uploaded file into storage
     *
     * @return string Stored file name (relative to the directory)
     * @throws \RuntimeException
     */
    public function store(UploadedFile $file, string $extension): string
    {
        if (!self::ensureDirectory()) {
            throw new \RuntimeException('The attachments directory is not writable: ' . self::directory());
        }

        $name = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
        $file->move(self::directory(), $name);

        return $name;
    }

    public function path(string $localFile): string
    {
        return self::directory() . basename($localFile);
    }

    public function delete(?string $localFile): void
    {
        if ($localFile === null || $localFile === '') {
            return;
        }

        $path = $this->path($localFile);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Remove the whole directory (module uninstall)
     */
    public static function removeDirectory(): void
    {
        $dir = self::directory();
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($dir . $entry)) {
                @unlink($dir . $entry);
            }
        }
        @rmdir($dir);
    }
}
