<?php
/**
 * Offer Entity
 *
 * Represents an InPost offer synchronized with PrestaShop products.
 * Main entity with relationships to product details, stock, price, and manufacturer.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\OfferRepository")
 * @ORM\Table(
 *     name="inpost_offer",
 *     indexes={
 *         @ORM\Index(name="IDX_INPOST_OFFER_ID", columns={"inpost_offer_id"}),
 *         @ORM\Index(name="IDX_ORGANIZATION_ID", columns={"organization_id"}),
 *         @ORM\Index(name="IDX_PRESTASHOP_OFFER_ID", columns={"prestashop_offer_id"}),
 *         @ORM\Index(name="IDX_PS_PRODUCT_COMBINATION", columns={"prestashop_offer_id", "prestashop_product_attribute_id", "shop_id"}),
 *         @ORM\Index(name="IDX_SHOP_ID", columns={"shop_id"}),
 *         @ORM\Index(name="IDX_STATUS", columns={"status"}),
 *         @ORM\Index(name="IDX_IS_DRAFT", columns={"is_draft"}),
 *         @ORM\Index(name="IDX_COMMAND_STATUS", columns={"command_status"}),
 *         @ORM\Index(name="IDX_REQUIRE_API_SYNC", columns={"require_api_sync"}),
 *         @ORM\Index(name="IDX_INPOST_CATEGORY_ID", columns={"inpost_category_id"}),
 *         @ORM\Index(name="IDX_VALIDATION_STATUS", columns={"inpost_validation_status"}),
 *         @ORM\Index(name="IDX_CREATED_AT", columns={"created_at"}),
 *         @ORM\Index(name="IDX_UPDATED_AT", columns={"updated_at"})
 *     },
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="UNQ_INPOST_OFFER_ID", columns={"inpost_offer_id"})
 *     }
 * )
 */
class Offer
{
    // API Status values from InPost
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_PUBLISHED = 'PUBLISHED';
    public const STATUS_CLOSED = 'CLOSED';
    public const STATUS_SOLDOUT = 'SOLDOUT';
    // Local terminal status: async create command failed past the retry limit.
    public const STATUS_ERROR_NOT_CREATED = 'ERROR_NOT_CREATED';

    public const VALID_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_REJECTED,
        self::STATUS_PUBLISHED,
        self::STATUS_CLOSED,
        self::STATUS_SOLDOUT,
        self::STATUS_ERROR_NOT_CREATED,
    ];

    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="offer_id", options={"unsigned"=true})
     */
    private ?int $offerId = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=true, name="inpost_offer_id")
     */
    private ?string $inpostOfferId = null;

    /**
     * InPost create-offer command UUID (async creation).
     * @ORM\Column(type="string", length=255, nullable=true, name="inpost_command_id")
     */
    private ?string $inpostCommandId = null;

    /**
     * Async command status: PENDING|SUCCESS|FAILURE (NULL = no active command).
     * @ORM\Column(type="string", length=20, nullable=true, name="command_status")
     */
    private ?string $commandStatus = null;

    /**
     * Automatic create re-attempts after a FAILURE command status.
     * @ORM\Column(type="integer", nullable=false, name="command_retry_count", options={"unsigned"=true, "default"=0})
     */
    private int $commandRetryCount = 0;

    /**
     * Last API failure reason(s) from the offer command status (why the offer
     * was not created). Populated on FAILURE, cleared on SUCCESS.
     * @ORM\Column(type="text", nullable=true, name="command_error")
     */
    private ?string $commandError = null;

    /**
     * Organization ID string (UUID from InPost API)
     * Stored directly as a string column for simpler persistence
     * @ORM\Column(type="string", length=64, nullable=false, name="organization_id")
     */
    private ?string $organizationId = null;

    /**
     * @ORM\Column(type="integer", nullable=true, name="prestashop_offer_id", options={"unsigned"=true})
     */
    private ?int $prestashopOfferId = null;

    /**
     * PrestaShop combination the offer sells; 0 = the whole product (all combinations)
     * @ORM\Column(type="integer", nullable=false, name="prestashop_product_attribute_id", options={"unsigned"=true, "default"=0})
     */
    private int $prestashopProductAttributeId = 0;

    /**
     * @ORM\Column(type="integer", nullable=false, name="shop_id", options={"unsigned"=true, "default"=1})
     */
    private int $shopId = 1;

    /**
     * @ORM\Column(type="string", length=20, nullable=false, options={"default"="DRAFT"})
     */
    private string $status = 'DRAFT';

    /**
     * @ORM\Column(type="integer", nullable=false, name="is_draft", options={"unsigned"=true, "default"=1})
     */
    private int $isDraft = 1;

        /**
     * @ORM\Column(type="string", length=255, nullable=true, name="shipping_time")
     */
    private ?string $shippingTime = null;

    /**
     * @ORM\Column(type="text", nullable=true, name="affiliation_product_url")
     */
    private ?string $affiliationProductUrl = null;

    /**
     * @ORM\Column(type="text", nullable=true, name="post_sale_return_policy")
     */
    private ?string $postSaleReturnPolicy = null;

    /**
     * @ORM\Column(type="text", nullable=true, name="post_sale_complaint_policy")
     */
    private ?string $postSaleComplaintPolicy = null;

    /**
     * @ORM\Column(type="string", length=5, nullable=true)
     */
    private ?string $language = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, name="inpost_category_id")
     */
    private ?string $inpostCategoryId = null;

    /**
     * @ORM\Column(type="datetime", nullable=true, name="synced_at")
     */
    private ?\DateTime $syncedAt = null;

    /**
     * @ORM\Column(type="string", length=50, nullable=true, name="inpost_validation_status")
     */
    private ?string $inpostValidationStatus = null;

    /**
     * @ORM\Column(type="text", nullable=true, name="inpost_validation")
     */
    private ?string $inpostValidation = null;

    /**
     * @ORM\Column(type="boolean", nullable=false, name="require_api_sync", options={"default"=false})
     */
    private bool $requireApiSync = false;

    /**
     * @ORM\Column(type="datetime", nullable=true, name="last_data_sync_at")
     */
    private ?\DateTime $lastDataSyncAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=true, name="last_api_sync_at")
     */
    private ?\DateTime $lastApiSyncAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=false, name="created_at")
     */
    private \DateTime $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=false, name="updated_at")
     */
    private \DateTime $updatedAt;

    /**
     * @ORM\OneToOne(targetEntity="OfferProduct", mappedBy="offer", cascade={"persist", "remove"})
     */
    private ?OfferProduct $product = null;

    /**
     * @ORM\OneToOne(targetEntity="OfferStock", mappedBy="offer", cascade={"persist", "remove"})
     */
    private ?OfferStock $stock = null;

    /**
     * @ORM\OneToOne(targetEntity="OfferPrice", mappedBy="offer", cascade={"persist", "remove"})
     */
    private ?OfferPrice $price = null;

    /**
     * @ORM\OneToOne(targetEntity="OfferManufacturer", mappedBy="offer", cascade={"persist", "remove"})
     */
    private ?OfferManufacturer $manufacturer = null;

    /**
     * @ORM\OneToOne(targetEntity="OfferGpsr", mappedBy="offer", cascade={"persist", "remove"}, fetch="EAGER")
     */
    private ?OfferGpsr $gpsr = null;

    /**
     * @ORM\OneToMany(targetEntity="OfferManual", mappedBy="offer", cascade={"persist", "remove"})
     */
    private Collection $manuals;

    /**
     * @ORM\OneToMany(targetEntity="OfferAttributes", mappedBy="offer", cascade={"persist", "remove"})
     */
    private Collection $attributes;

    /**
     * Organization entity (not mapped via Doctrine, loaded manually when needed)
     * We store organizationId as a simple string to avoid Doctrine's non-PK reference issues
     */
    private ?Organization $organization = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->manuals = new ArrayCollection();
        $this->attributes = new ArrayCollection();
    }

    // Getters and Setters

    public function getOfferId(): ?int
    {
        return $this->offerId;
    }

    /**
     * The offer was sent to InPost (it exists there, or its create command is pending).
     * Deleting it only removes it from the module - InPost has no offer deletion.
     */
    public function existsOnInpost(): bool
    {
        return !empty($this->getInpostOfferId()) || !empty($this->getInpostCommandId());
    }

    /**
     * Alias for getOfferId() for compatibility
     */
    public function getId(): ?int
    {
        return $this->offerId;
    }

    public function getInpostOfferId(): ?string
    {
        return $this->inpostOfferId;
    }

    public function setInpostOfferId(?string $inpostOfferId): self
    {
        $this->inpostOfferId = $inpostOfferId;

        // Auto-upgrade from DRAFT when inpost_offer_id is set. Note: this only
        // fires when setting a non-null id; clearing it to null (as the pending
        // command poller does on FAILURE) intentionally does not change status.
        if ($inpostOfferId !== null && $this->status === self::STATUS_DRAFT) {
            $this->status = self::STATUS_PENDING;
        }

        return $this;
    }

    public function getInpostCommandId(): ?string
    {
        return $this->inpostCommandId;
    }

    public function setInpostCommandId(?string $inpostCommandId): self
    {
        $this->inpostCommandId = $inpostCommandId;
        return $this;
    }

    public function getCommandStatus(): ?string
    {
        return $this->commandStatus;
    }

    public function setCommandStatus(?string $commandStatus): self
    {
        $this->commandStatus = $commandStatus;
        return $this;
    }

    public function getCommandRetryCount(): int
    {
        return $this->commandRetryCount;
    }

    public function setCommandRetryCount(int $commandRetryCount): self
    {
        $this->commandRetryCount = $commandRetryCount;
        return $this;
    }

    public function getCommandError(): ?string
    {
        return $this->commandError;
    }

    public function setCommandError(?string $commandError): self
    {
        $this->commandError = $commandError;
        return $this;
    }

    public function getOrganizationId(): ?string
    {
        // Derive from Organization entity when available (preferred)
        if ($this->organization !== null) {
            return $this->organization->getOrganizationId();
        }
        return $this->organizationId;
    }

    public function setOrganizationId(?string $organizationId): self
    {
        $this->organizationId = $organizationId;
        return $this;
    }

    public function getPrestashopOfferId(): ?int
    {
        return $this->prestashopOfferId;
    }

    public function setPrestashopOfferId(?int $prestashopOfferId): self
    {
        $this->prestashopOfferId = $prestashopOfferId;
        return $this;
    }

    public function getPrestashopProductAttributeId(): int
    {
        return $this->prestashopProductAttributeId;
    }

    public function setPrestashopProductAttributeId(int $prestashopProductAttributeId): self
    {
        $this->prestashopProductAttributeId = max(0, $prestashopProductAttributeId);
        return $this;
    }

    /**
     * Offer of one combination (not the whole product)
     */
    public function isCombinationOffer(): bool
    {
        return $this->prestashopProductAttributeId > 0;
    }

    public function getShopId(): int
    {
        return $this->shopId;
    }

    public function setShopId(int $shopId): self
    {
        $this->shopId = $shopId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getIsDraft(): int
    {
        return $this->isDraft;
    }

    public function setIsDraft(?int $isDraft): self
    {
        // If already submitted to InPost, isDraft must be 0
        if ($this->inpostOfferId !== null) {
            $this->isDraft = 0;
            return $this;
        }

        // Default to 1 (draft) if null for new offers
        $this->isDraft = $isDraft ?? 1;

        // Auto-set status to DRAFT when is_draft = 1
        if ($this->isDraft === 1) {
            $this->status = self::STATUS_DRAFT;
        }

        $this->updatedAt = new \DateTime();
        return $this;
    }

    


    public function getShippingTime(): ?string
    {
        return $this->shippingTime;
    }

    public function setShippingTime(?string $shippingTime): self
    {
        $this->shippingTime = $shippingTime;
        return $this;
    }

    public function getAffiliationProductUrl(): ?string
    {
        return $this->affiliationProductUrl;
    }

    public function setAffiliationProductUrl(?string $affiliationProductUrl): self
    {
        $this->affiliationProductUrl = $affiliationProductUrl;
        return $this;
    }

    public function getPostSaleReturnPolicy(): ?string
    {
        return $this->postSaleReturnPolicy;
    }

    public function setPostSaleReturnPolicy(?string $postSaleReturnPolicy): self
    {
        $this->postSaleReturnPolicy = $postSaleReturnPolicy;
        return $this;
    }

    public function getPostSaleComplaintPolicy(): ?string
    {
        return $this->postSaleComplaintPolicy;
    }

    public function setPostSaleComplaintPolicy(?string $postSaleComplaintPolicy): self
    {
        $this->postSaleComplaintPolicy = $postSaleComplaintPolicy;
        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): self
    {
        $this->language = $language;
        return $this;
    }

    public function getInpostCategoryId(): ?string
    {
        return $this->inpostCategoryId;
    }

    public function setInpostCategoryId(?string $inpostCategoryId): self
    {
        $this->inpostCategoryId = $inpostCategoryId;
        return $this;
    }

    public function getSyncedAt(): ?\DateTime
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(?\DateTime $syncedAt): self
    {
        $this->syncedAt = $syncedAt;
        return $this;
    }

    public function getInpostValidationStatus(): ?string
    {
        return $this->inpostValidationStatus;
    }

    public function setInpostValidationStatus(?string $inpostValidationStatus): self
    {
        $this->inpostValidationStatus = $inpostValidationStatus;
        return $this;
    }

    public function getInpostValidation(): ?string
    {
        return $this->inpostValidation;
    }

    public function setInpostValidation(?string $inpostValidation): self
    {
        $this->inpostValidation = $inpostValidation;
        return $this;
    }

    public function getValidationErrors(): array
    {
        if (!$this->inpostValidation) {
            return [];
        }

        try {
            return json_decode($this->inpostValidation, true) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function setValidationErrors(array $errors): self
    {
        $this->inpostValidation = json_encode($errors);
        return $this;
    }

    public function hasValidationErrors(): bool
    {
        return !empty($this->getValidationErrors());
    }

    public function getValidationStatus(): string
    {
        if (empty($this->inpostOfferId)) {
            return 'pending';
        }
        return $this->hasValidationErrors() ? 'errors' : 'passed';
    }

    public function getRequireApiSync(): bool
    {
        return $this->requireApiSync;
    }

    public function setRequireApiSync(bool $requireApiSync): self
    {
        $this->requireApiSync = $requireApiSync;
        return $this;
    }

    public function getLastDataSyncAt(): ?\DateTime
    {
        return $this->lastDataSyncAt;
    }

    public function setLastDataSyncAt(?\DateTime $lastDataSyncAt): self
    {
        $this->lastDataSyncAt = $lastDataSyncAt;
        return $this;
    }

    public function getLastApiSyncAt(): ?\DateTime
    {
        return $this->lastApiSyncAt;
    }

    public function setLastApiSyncAt(?\DateTime $lastApiSyncAt): self
    {
        $this->lastApiSyncAt = $lastApiSyncAt;
        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    // Relationship methods

    public function getProduct(): ?OfferProduct
    {
        return $this->product;
    }

    public function setProduct(?OfferProduct $product): self
    {
        $this->product = $product;
        if ($product !== null) {
            $product->setOffer($this);
        }
        return $this;
    }

    public function getStock(): ?OfferStock
    {
        return $this->stock;
    }

    public function setStock(?OfferStock $stock): self
    {
        $this->stock = $stock;
        if ($stock !== null) {
            $stock->setOffer($this);
        }
        return $this;
    }

    public function getPrice(): ?OfferPrice
    {
        return $this->price;
    }

    public function setPrice(?OfferPrice $price): self
    {
        $this->price = $price;
        if ($price !== null) {
            $price->setOffer($this);
        }
        return $this;
    }

    public function getManufacturer(): ?OfferManufacturer
    {
        return $this->manufacturer;
    }

    public function setManufacturer(?OfferManufacturer $manufacturer): self
    {
        $this->manufacturer = $manufacturer;
        if ($manufacturer !== null) {
            $manufacturer->setOffer($this);
        }
        return $this;
    }

    public function getGpsr(): ?OfferGpsr
    {
        return $this->gpsr;
    }

    public function setGpsr(?OfferGpsr $gpsr): self
    {
        $this->gpsr = $gpsr;
        if ($gpsr !== null) {
            $gpsr->setOffer($this);
        }
        return $this;
    }

    /**
     * @return Collection|OfferManual[]
     */
    public function getManuals(): Collection
    {
        return $this->manuals;
    }

    public function addManual(OfferManual $manual): self
    {
        if (!$this->manuals->contains($manual)) {
            $this->manuals[] = $manual;
            $manual->setOffer($this);
        }
        return $this;
    }

    public function removeManual(OfferManual $manual): self
    {
        if ($this->manuals->removeElement($manual)) {
            if ($manual->getOffer() === $this) {
                $manual->setOffer(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection|OfferAttributes[]
     */
    public function getAttributes(): Collection
    {
        return $this->attributes;
    }

    public function addAttribute(OfferAttributes $attribute): self
    {
        if (!$this->attributes->contains($attribute)) {
            $this->attributes[] = $attribute;
            $attribute->setOffer($this);
        }
        return $this;
    }

    public function removeAttribute(OfferAttributes $attribute): self
    {
        $this->attributes->removeElement($attribute);
        return $this;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): self
    {
        $this->organization = $organization;
        return $this;
    }

    /**
     * Disable the offer
     */
    public function disable(): void
    {
        $this->status = 'disabled';
        $this->updatedAt = new \DateTime();
    }
}
