<?php
/**
 * OfferGpsr Entity
 *
 * GPSR (General Product Safety Regulation) compliance data for an InPost offer.
 * This entity stores EU GPSR compliance information separately from the main offer entity.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\OfferGpsrRepository")
 * @ORM\Table(
 *     name="inpost_offer_gpsr",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="UNQ_OFFER_ID", columns={"offer_id"})
 *     }
 * )
 */
class OfferGpsr
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="offer_gpsr_id", options={"unsigned"=true})
     */
    private ?int $offerGpsrId = null;

    /**
     * @ORM\OneToOne(targetEntity="Offer", inversedBy="gpsr")
     * @ORM\JoinColumn(name="offer_id", referencedColumnName="offer_id", nullable=false, onDelete="CASCADE")
     */
    private Offer $offer;

    /**
     * @ORM\Column(type="string", length=500, nullable=true, name="batch_number")
     */
    private ?string $batchNumber = null;

    /**
     * @ORM\Column(type="boolean", nullable=false, name="ce_marking", options={"default"=false})
     */
    private bool $ceMarking = false;

    /**
     * @ORM\Column(type="text", nullable=true, name="safety_information")
     */
    private ?string $safetyInformation = null;

    /**
     * @ORM\Column(type="boolean", nullable=false, name="does_not_require_info", options={"default"=false})
     */
    private bool $doesNotRequireInfo = false;

    /**
     * @ORM\Column(type="datetime", nullable=false, name="created_at")
     */
    private \DateTime $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=false, name="updated_at")
     */
    private \DateTime $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getOfferGpsrId(): ?int
    {
        return $this->offerGpsrId;
    }

    public function getOffer(): Offer
    {
        return $this->offer;
    }

    public function setOffer(Offer $offer): self
    {
        $this->offer = $offer;
        return $this;
    }

    public function getBatchNumber(): ?string
    {
        return $this->batchNumber;
    }

    public function setBatchNumber(?string $batchNumber): self
    {
        $this->batchNumber = $batchNumber;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getCeMarking(): bool
    {
        return $this->ceMarking;
    }

    public function setCeMarking(bool $ceMarking): self
    {
        $this->ceMarking = $ceMarking;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getSafetyInformation(): ?string
    {
        return $this->safetyInformation;
    }

    public function setSafetyInformation(?string $safetyInformation): self
    {
        $this->safetyInformation = $safetyInformation;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getDoesNotRequireInfo(): bool
    {
        return $this->doesNotRequireInfo;
    }

    public function setDoesNotRequireInfo(bool $doesNotRequireInfo): self
    {
        $this->doesNotRequireInfo = $doesNotRequireInfo;
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

    public function setUpdatedAt(\DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
