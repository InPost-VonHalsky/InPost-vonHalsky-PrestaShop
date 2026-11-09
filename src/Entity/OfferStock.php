<?php
/**
 * OfferStock Entity
 *
 * Stock information for an InPost offer.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\OfferStockRepository")
 * @ORM\Table(
 *     name="inpost_offer_stock",
 *     indexes={
 *         @ORM\Index(name="IDX_OFFER_ID", columns={"offer_id"})
 *     }
 * )
 */
class OfferStock
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="offer_stock_id", options={"unsigned"=true})
     */
    private ?int $offerStockId = null;

    /**
     * @ORM\OneToOne(targetEntity="Offer", inversedBy="stock")
     * @ORM\JoinColumn(name="offer_id", referencedColumnName="offer_id", nullable=false)
     */
    private Offer $offer;

    /**
     * @ORM\Column(type="integer", nullable=false)
     */
    private int $quantity = 0;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private ?string $unit = null;

    /**
     * @ORM\Column(type="string", length=20, nullable=false)
     */
    private string $availability = 'available';

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

    public function getOfferStockId(): ?int
    {
        return $this->offerStockId;
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

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): self
    {
        $this->unit = $unit;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getAvailability(): string
    {
        return $this->availability;
    }

    public function setAvailability(string $availability): self
    {
        $this->availability = $availability;
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
}
