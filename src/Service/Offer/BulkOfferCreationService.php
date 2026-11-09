<?php
/**
 * BulkOfferCreationService
 *
 * Creates DRAFT offers from PrestaShop products in bulk (GOKINT-930). Re-validates
 * every product server-side (the confirmation screen is display-only), one
 * transaction per product so a single failure never loses the rest, and clears
 * the identity map at the end of each chunk. Nothing is sent to InPost here.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Service\Offer;

use Doctrine\ORM\EntityManagerInterface;
use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Helper\Config;
use Inpost\VonHalsky\Repository\OrganizationRepository;
use Inpost\VonHalsky\Service\Business\OfferBuilderService;
use Inpost\VonHalsky\Service\Business\OfferValidatorService;
use Inpost\VonHalsky\Service\Util\LoggerService;

class BulkOfferCreationService
{
    public const RESULT_CREATED = 'created';
    public const RESULT_SKIPPED = 'skipped';
    public const RESULT_ERROR = 'error';

    private OfferBuilderService $offerBuilder;
    private ProductPreValidationService $preValidation;
    private OfferValidatorService $offerValidator;
    private HintProductizationService $hintProductization;
    private OrganizationRepository $organizationRepository;
    private Config $config;
    private EntityManagerInterface $entityManager;
    private LoggerService $logger;

    public function __construct(
        OfferBuilderService $offerBuilder,
        ProductPreValidationService $preValidation,
        OfferValidatorService $offerValidator,
        HintProductizationService $hintProductization,
        OrganizationRepository $organizationRepository,
        Config $config,
        EntityManagerInterface $entityManager,
        LoggerService $logger
    ) {
        $this->offerBuilder = $offerBuilder;
        $this->preValidation = $preValidation;
        $this->offerValidator = $offerValidator;
        $this->hintProductization = $hintProductization;
        $this->organizationRepository = $organizationRepository;
        $this->config = $config;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    /**
     * Create DRAFT offers for a chunk of products / combinations.
     *
     * @param array<int,array{0:int,1:int}> $items [id_product, id_product_attribute (0 = product without combinations)]
     * @param int $shopId
     * @return array<int,array<string,mixed>>
     */
    public function createBatch(array $items, int $shopId): array
    {
        $unique = [];
        foreach ($items as [$idProduct, $idProductAttribute]) {
            if ((int) $idProduct > 0) {
                $unique[(int) $idProduct . ':' . max(0, (int) $idProductAttribute)] = [(int) $idProduct, max(0, (int) $idProductAttribute)];
            }
        }
        if (empty($unique)) {
            return [];
        }

        $organizationId = $this->config->getOrganizationId($shopId);
        $organization = $organizationId ? $this->organizationRepository->findByOrganizationId($organizationId) : null;

        // Server-side re-validation (display results are never trusted). A product
        // with combinations is only listed per combination.
        $validation = [];
        $productIds = array_values(array_unique(array_column($unique, 0)));
        foreach ($this->preValidation->validateBatch($productIds, $shopId) as $row) {
            $idProduct = (int) $row['id_product'];
            if (empty($row['combinations'])) {
                $validation[$idProduct . ':0'] = $row['status'];
                continue;
            }
            foreach ($row['combinations'] as $combination) {
                $validation[$idProduct . ':' . (int) $combination['id_product_attribute']] = $row['status'] === ProductPreValidationService::STATUS_BLOCKED
                    ? ProductPreValidationService::STATUS_BLOCKED
                    : $combination['status'];
            }
        }

        $results = [];
        foreach ($unique as $key => [$id, $idProductAttribute]) {
            $item = ['id_product' => $id, 'id_product_attribute' => $idProductAttribute];

            if (($validation[$key] ?? ProductPreValidationService::STATUS_BLOCKED) === ProductPreValidationService::STATUS_BLOCKED) {
                $results[] = $item + ['status' => self::RESULT_SKIPPED, 'offer_id' => null, 'message' => 'Blocked by pre-validation'];
                continue;
            }

            if ($organization === null) {
                $results[] = $item + ['status' => self::RESULT_ERROR, 'offer_id' => null, 'message' => 'Organization not configured'];
                continue;
            }

            $this->entityManager->getConnection()->beginTransaction();
            try {
                $offer = $this->offerBuilder->buildFromProduct($id, null, $idProductAttribute);
                $offer->setShopId($shopId);
                $offer->setOrganization($organization);
                $offer->setOrganizationId($organizationId);
                $offer->setStatus(Offer::STATUS_DRAFT);
                $offer->setRequireApiSync(false);
                $offer->setUpdatedAt(new \DateTime());

                // GOKINT-927: fill product fields + category attributes from the
                // first InPost hint matched by EAN (no-op when productization off).
                $this->hintProductization->applyHintToOffer($offer);

                // Compute validation status (advisory; does not block draft creation).
                try {
                    $result = $this->offerValidator->validate($offer, $this->config->getDefaultLanguageCode());
                    $offer->setInpostValidationStatus($this->offerValidator->getValidationStatus($result));
                } catch (\Throwable $e) {
                    // Validation is best-effort here; never fail creation on it.
                    $this->logger->warning('Bulk validation status failed', ['id_product' => $id, 'id_product_attribute' => $idProductAttribute, 'error' => $e->getMessage()]);
                }

                $this->entityManager->persist($offer);
                $this->entityManager->flush();
                $this->entityManager->getConnection()->commit();

                $results[] = $item + ['status' => self::RESULT_CREATED, 'offer_id' => $offer->getOfferId(), 'message' => null];
            } catch (\Throwable $e) {
                $this->entityManager->getConnection()->rollBack();
                $this->logger->error('Bulk offer creation failed', [
                    'id_product' => $id,
                    'id_product_attribute' => $idProductAttribute,
                    'error' => $e->getMessage(),
                ]);
                $results[] = $item + ['status' => self::RESULT_ERROR, 'offer_id' => null, 'message' => $e->getMessage()];
            }
        }

        // Keep the identity map from growing across a 100+ product run.
        $this->entityManager->clear();

        return $results;
    }
}
