<?php
/**
 * Organization Entity
 *
 * InPost organization metadata for multi-org support.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\OrganizationRepository")
 * @ORM\Table(
 *     name="inpost_organization",
 *     indexes={
 *         @ORM\Index(name="IDX_SHOP_ID", columns={"shop_id"})
 *     },
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="UNQ_ORGANIZATION_ID", columns={"organization_id"})
 *     }
 * )
 */
class Organization
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="id", options={"unsigned"=true})
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=false, unique=true, name="organization_id")
     */
    private string $organizationId;

    /**
     * @ORM\Column(type="integer", nullable=false, name="shop_id", options={"unsigned"=true, "default"=1})
     */
    private int $shopId = 1;

    /**
     * @ORM\Column(type="string", length=255, nullable=false)
     */
    private string $name;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private ?string $country = null;

    /**
     * @ORM\Column(type="string", length=3, nullable=true)
     */
    private ?string $currency = null;

    /**
     * @ORM\Column(type="datetime", nullable=false, name="created_at")
     */
    private \DateTime $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=false, name="updated_at")
     */
    private \DateTime $updatedAt;

    /**
     * Last synced offer event ID (cursor for incremental sync)
     *
     * @ORM\Column(type="string", length=64, nullable=true, name="last_offer_event_id")
     */
    private ?string $lastOfferEventId = null;

    /**
     * Timestamp of last offer event sync
     *
     * @ORM\Column(type="datetime", nullable=true, name="last_offer_event_sync_at")
     */
    private ?\DateTime $lastOfferEventSyncAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganizationId(): string
    {
        return $this->organizationId;
    }

    public function setOrganizationId(string $organizationId): self
    {
        $this->organizationId = $organizationId;
        return $this;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency;
        $this->updatedAt = new \DateTime();
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

    public function getLastOfferEventId(): ?string
    {
        return $this->lastOfferEventId;
    }

    public function setLastOfferEventId(?string $lastOfferEventId): self
    {
        $this->lastOfferEventId = $lastOfferEventId;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getLastOfferEventSyncAt(): ?\DateTime
    {
        return $this->lastOfferEventSyncAt;
    }

    public function setLastOfferEventSyncAt(?\DateTime $lastOfferEventSyncAt): self
    {
        $this->lastOfferEventSyncAt = $lastOfferEventSyncAt;
        $this->updatedAt = new \DateTime();
        return $this;
    }
}
