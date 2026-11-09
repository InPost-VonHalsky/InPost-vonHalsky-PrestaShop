<?php
/**
 * OfferAttachmentRepository
 *
 * Document attachments of offers (GOKINT-1099), table inpost_offer_attachment.
 *
 * Raw SQL, like the offer command bookkeeping in OfferService and
 * PendingCommandSyncJob: the rows are written from admin AJAX, cron and CLI
 * alike, and a Doctrine flush must not clobber them.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Repository;

use Db;

class OfferAttachmentRepository
{
    /** Waiting for the offer to exist in InPost (or for a transport retry) */
    public const STATUS_PENDING = 'PENDING';
    /** Sent, async command not finished yet */
    public const STATUS_UPLOADING = 'UPLOADING';
    public const STATUS_UPLOADED = 'UPLOADED';
    /** Rejected by InPost or out of retries; the merchant can retry or remove it */
    public const STATUS_ERROR = 'ERROR';
    /** Removed by the merchant, the delete in InPost failed and is retried */
    public const STATUS_DELETING = 'DELETING';

    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_PS_ATTACHMENT = 'ps_attachment';

    private const TABLE = 'inpost_offer_attachment';

    private Db $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * @param array<string,mixed> $data Column => value
     * @return int New attachment_id
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $data += ['status' => self::STATUS_PENDING, 'retry_count' => 0];
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $this->db->insert(self::TABLE, $this->escape($data), true);

        return (int) $this->db->Insert_ID();
    }

    /**
     * @param array<string,mixed> $fields Column => value (null allowed)
     */
    public function update(int $attachmentId, array $fields): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update(self::TABLE, $this->escape($fields), 'attachment_id = ' . $attachmentId, 0, true);
    }

    public function delete(int $attachmentId): void
    {
        $this->db->delete(self::TABLE, 'attachment_id = ' . $attachmentId);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $attachmentId): ?array
    {
        $row = $this->db->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE attachment_id = ' . $attachmentId
        );

        return $row ?: null;
    }

    /**
     * Attachments of an offer, in upload order
     *
     * @return array<int,array<string,mixed>>
     */
    public function findByOffer(int $offerId): array
    {
        return $this->db->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE offer_id = ' . $offerId . '
            ORDER BY attachment_id ASC'
        ) ?: [];
    }

    /**
     * InPost replaces an attachment uploaded under a name the offer already has,
     * so names must be unique per offer
     */
    public function fileNameExists(int $offerId, string $fileName): bool
    {
        return (bool) $this->db->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE offer_id = ' . $offerId . "
              AND LOWER(file_name) = '" . pSQL(mb_strtolower($fileName)) . "'"
        );
    }

    /**
     * Attachments in the given statuses, with the offer state needed to decide
     * whether they can be sent (oldest first, so a backlog drains fairly)
     *
     * @param string[] $statuses
     * @param bool $offerReady Only offers that exist in InPost and whose create command went through
     * @return array<int,array<string,mixed>> Rows plus offer_inpost_id, offer_command_id,
     *                                        offer_command_status, offer_status
     */
    public function findByStatuses(array $statuses, int $limit, ?int $offerId = null, bool $offerReady = false): array
    {
        $in = implode(',', array_map(static function (string $status): string {
            return "'" . pSQL($status) . "'";
        }, $statuses));

        return $this->db->executeS(
            'SELECT a.*, o.inpost_offer_id AS offer_inpost_id, o.inpost_command_id AS offer_command_id,
                    o.command_status AS offer_command_status, o.status AS offer_status
            FROM `' . _DB_PREFIX_ . self::TABLE . '` a
            INNER JOIN `' . _DB_PREFIX_ . 'inpost_offer` o ON o.offer_id = a.offer_id
            WHERE a.status IN (' . $in . ')'
            . ($offerId !== null ? ' AND a.offer_id = ' . $offerId : '')
            . ($offerReady
                ? " AND o.inpost_offer_id IS NOT NULL
                    AND (o.inpost_command_id IS NULL OR o.command_status = 'SUCCESS')
                    AND o.status <> 'ERROR_NOT_CREATED'"
                : '') . '
            ORDER BY a.updated_at ASC, a.attachment_id ASC
            LIMIT ' . max(1, $limit)
        ) ?: [];
    }

    /**
     * Sent attachments whose offer now has another InPost ID (the create command
     * failed and the offer was created again) go back to the queue
     *
     * @return int Number of attachments queued again
     */
    public function requeueForChangedOffers(): int
    {
        $this->db->execute(
            'UPDATE `' . _DB_PREFIX_ . self::TABLE . '` a
            INNER JOIN `' . _DB_PREFIX_ . 'inpost_offer` o ON o.offer_id = a.offer_id
            SET a.status = \'' . self::STATUS_PENDING . '\', a.inpost_attachment_id = NULL, a.command_id = NULL,
                a.retry_count = 0, a.error = NULL, a.updated_at = NOW()
            WHERE a.status IN (\'' . self::STATUS_UPLOADING . '\', \'' . self::STATUS_UPLOADED . '\')
              AND a.inpost_offer_id IS NOT NULL
              AND NOT (o.inpost_offer_id <=> a.inpost_offer_id)'
        );

        return (int) $this->db->Affected_Rows();
    }

    /**
     * InPost attachment IDs already assigned to other rows of an offer
     *
     * @return string[]
     */
    public function knownInpostIds(int $offerId, int $exceptAttachmentId): array
    {
        $rows = $this->db->executeS(
            'SELECT inpost_attachment_id FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE offer_id = ' . $offerId . '
              AND attachment_id <> ' . $exceptAttachmentId . '
              AND inpost_attachment_id IS NOT NULL'
        ) ?: [];

        return array_column($rows, 'inpost_attachment_id');
    }

    /**
     * Stored upload files of an offer (to remove them with the offer)
     *
     * @return string[]
     */
    public function localFilesByOffer(int $offerId): array
    {
        $rows = $this->db->executeS(
            'SELECT local_file FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE offer_id = ' . $offerId . ' AND local_file IS NOT NULL'
        ) ?: [];

        return array_column($rows, 'local_file');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function escape(array $data): array
    {
        foreach ($data as $column => $value) {
            $data[$column] = $value === null ? null : pSQL((string) $value);
        }

        return $data;
    }
}
