<?php
/**
 * OfferProduct Entity
 *
 * Product details for an InPost offer.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\OfferProductRepository")
 * @ORM\Table(
 *     name="inpost_offer_product",
 *     indexes={
 *         @ORM\Index(name="IDX_OFFER_ID", columns={"offer_id"}),
 *         @ORM\Index(name="IDX_PRODUCT_ID", columns={"product_id"})
 *     }
 * )
 */
class OfferProduct
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="offer_product_id", options={"unsigned"=true})
     */
    private ?int $offerProductId = null;

    /**
     * @ORM\OneToOne(targetEntity="Offer", inversedBy="product")
     * @ORM\JoinColumn(name="offer_id", referencedColumnName="offer_id", nullable=false)
     */
    private Offer $offer;

    /**
     * @ORM\Column(type="integer", nullable=false, name="product_id", options={"unsigned"=true})
     */
    private int $productId;

    /**
     * @ORM\Column(type="string", length=255, nullable=false)
     */
    private ?string $name = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * @ORM\Column(type="string", length=128, nullable=true)
     */
    private ?string $brand = null;

    /**
     * @ORM\Column(type="string", length=128, nullable=true)
     */
    private ?string $model = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=false)
     */
    private ?string $sku = null;

    /**
     * @ORM\Column(type="string", length=13, nullable=true)
     */
    private ?string $ean = null;

    /**
     * @ORM\Column(type="string", length=100, nullable=true, name="super_model")
     */
    private ?string $superModel = null;

    /**
     * @ORM\Column(type="string", length=100, nullable=true, name="manufacturer_product_number")
     */
    private ?string $manufacturerProductNumber = null;

    /**
     * @ORM\Column(type="integer", nullable=true, name="dimension_width")
     */
    private ?int $dimensionWidth = null;

    /**
     * @ORM\Column(type="integer", nullable=true, name="dimension_height")
     */
    private ?int $dimensionHeight = null;

    /**
     * @ORM\Column(type="integer", nullable=true, name="dimension_length")
     */
    private ?int $dimensionLength = null;

    /**
     * @ORM\Column(type="integer", nullable=true, name="dimension_weight")
     */
    private ?int $dimensionWeight = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=true, name="category_id")
     */
    private ?string $categoryId = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, name="inpost_category_id")
     */
    private ?string $inpostCategoryId = null;

    /**
     * @ORM\Column(type="integer", nullable=true, name="category_mapping_id", options={"unsigned"=true})
     */
    private ?int $categoryMappingId = null;

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

    public function getOfferProductId(): ?int
    {
        return $this->offerProductId;
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

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function setProductId(int $productId): self
    {
        $this->productId = $productId;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): self
    {
        $this->brand = $brand;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): self
    {
        $this->model = $model;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(?string $sku): self
    {
        $this->sku = $sku;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getEan(): ?string
    {
        return $this->ean;
    }

    public function setEan(?string $ean): self
    {
        $this->ean = $ean;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getCategoryId(): ?string
    {
        return $this->categoryId;
    }

    public function setCategoryId(?string $categoryId): self
    {
        $this->categoryId = $categoryId;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getInpostCategoryId(): ?string
    {
        return $this->inpostCategoryId;
    }

    public function setInpostCategoryId(?string $inpostCategoryId): self
    {
        $this->inpostCategoryId = $inpostCategoryId;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getCategoryMappingId(): ?int
    {
        return $this->categoryMappingId;
    }

    public function setCategoryMappingId(?int $categoryMappingId): self
    {
        $this->categoryMappingId = $categoryMappingId;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getSuperModel(): ?string
    {
        return $this->superModel;
    }

    public function setSuperModel(?string $superModel): self
    {
        $this->superModel = $superModel;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getManufacturerProductNumber(): ?string
    {
        return $this->manufacturerProductNumber;
    }

    public function setManufacturerProductNumber(?string $manufacturerProductNumber): self
    {
        $this->manufacturerProductNumber = $manufacturerProductNumber;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getDimensionWidth(): ?int
    {
        return $this->dimensionWidth;
    }

    public function setDimensionWidth(?int $dimensionWidth): self
    {
        $this->dimensionWidth = $dimensionWidth;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getDimensionHeight(): ?int
    {
        return $this->dimensionHeight;
    }

    public function setDimensionHeight(?int $dimensionHeight): self
    {
        $this->dimensionHeight = $dimensionHeight;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getDimensionLength(): ?int
    {
        return $this->dimensionLength;
    }

    public function setDimensionLength(?int $dimensionLength): self
    {
        $this->dimensionLength = $dimensionLength;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getDimensionWeight(): ?int
    {
        return $this->dimensionWeight;
    }

    public function setDimensionWeight(?int $dimensionWeight): self
    {
        $this->dimensionWeight = $dimensionWeight;
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
