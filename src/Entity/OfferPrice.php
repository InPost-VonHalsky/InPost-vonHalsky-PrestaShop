<?php
/**
 * OfferPrice Entity
 *
 * Pricing information for an InPost offer.
 *
 * price is what is sent to InPost. In the automatic mode it follows the shop
 * (the configured customer group price, the % rule and rounding), in the manual
 * mode it is the amount typed in by the merchant (GOKINT-1098).
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\OfferPriceRepository")
 * @ORM\Table(
 *     name="inpost_offer_price",
 *     indexes={
 *         @ORM\Index(name="IDX_OFFER_ID", columns={"offer_id"})
 *     }
 * )
 */
class OfferPrice
{
    public const MODE_AUTO = 'auto';
    public const MODE_MANUAL = 'manual';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="offer_price_id", options={"unsigned"=true})
     */
    private ?int $offerPriceId = null;

    /**
     * @ORM\OneToOne(targetEntity="Offer", inversedBy="price")
     * @ORM\JoinColumn(name="offer_id", referencedColumnName="offer_id", nullable=false)
     */
    private Offer $offer;

    /**
     * @ORM\Column(type="decimal", precision=12, scale=4, nullable=false)
     */
    private string $price = '0.00';

    /**
     * @ORM\Column(type="string", length=3, nullable=false)
     */
    private string $currency = 'PLN';

    /**
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=true, name="tax_rate")
     */
    private ?string $taxRate = null;

    /**
     * @ORM\Column(type="string", length=16, nullable=false, name="price_mode", options={"default"="auto"})
     */
    private string $priceMode = self::MODE_AUTO;

    /**
     * Gross price typed in by the merchant (manual mode)
     *
     * @ORM\Column(type="decimal", precision=12, scale=4, nullable=true, name="manual_price")
     */
    private ?string $manualPrice = null;

    /**
     * Last gross price of the shop (no customer group, no % rule) - shown next to the InPost price
     *
     * @ORM\Column(type="decimal", precision=12, scale=4, nullable=true, name="shop_price")
     */
    private ?string $shopPrice = null;

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

    public function getOfferPriceId(): ?int
    {
        return $this->offerPriceId;
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

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getTaxRate(): ?string
    {
        return $this->taxRate;
    }

    public function setTaxRate(?string $taxRate): self
    {
        $this->taxRate = $taxRate;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getPriceMode(): string
    {
        return $this->priceMode;
    }

    public function setPriceMode(?string $priceMode): self
    {
        $this->priceMode = $priceMode === self::MODE_MANUAL ? self::MODE_MANUAL : self::MODE_AUTO;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function isManual(): bool
    {
        return $this->priceMode === self::MODE_MANUAL;
    }

    public function getManualPrice(): ?string
    {
        return $this->manualPrice;
    }

    public function setManualPrice(?string $manualPrice): self
    {
        $this->manualPrice = $manualPrice;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getShopPrice(): ?string
    {
        return $this->shopPrice;
    }

    public function setShopPrice(?string $shopPrice): self
    {
        $this->shopPrice = $shopPrice;
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
