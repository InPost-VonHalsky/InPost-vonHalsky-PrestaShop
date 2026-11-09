<?php
/**
 * OfferAttributes Entity
 *
 * Product attributes for an InPost offer.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\OfferAttributesRepository")
 * @ORM\Table(
 *     name="inpost_offer_attributes",
 *     indexes={
 *         @ORM\Index(name="IDX_OFFER_ID", columns={"offer_id"}),
 *         @ORM\Index(name="IDX_ATTRIBUTE_ID", columns={"attribute_id"}),
 *         @ORM\Index(name="IDX_CATEGORY_MAPPING_ID", columns={"category_mapping_id"}),
 *         @ORM\Index(name="IDX_INPOST_ATTRIBUTE_ID", columns={"inpost_attribute_id"})
 *     },
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="UNQ_OFFER_CATEGORY_ATTRIBUTE", columns={"offer_id", "category_mapping_id", "inpost_attribute_id"})
 *     }
 * )
 */
class OfferAttributes
{
    /** Value read from the mapped PrestaShop feature / attribute group */
    public const SOURCE_PS = 'ps';
    /** Value from an InPost product hint */
    public const SOURCE_HINT = 'hint';
    /** Value entered or changed by the merchant */
    public const SOURCE_MANUAL = 'manual';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="offer_attribute_id", options={"unsigned"=true})
     */
    private ?int $offerAttributeId = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, name="category_mapping_id")
     */
    private ?string $categoryMappingId = null;

    /**
     * @ORM\Column(type="string", length=36, nullable=true, name="inpost_attribute_id")
     */
    private ?string $inpostAttributeId = null;

    /**
     * @ORM\ManyToOne(targetEntity="Offer", inversedBy="attributes")
     * @ORM\JoinColumn(name="offer_id", referencedColumnName="offer_id", nullable=false)
     */
    private Offer $offer;

    /**
     * @ORM\ManyToOne(targetEntity="Attribute")
     * @ORM\JoinColumn(name="attribute_id", referencedColumnName="attribute_id", nullable=false)
     */
    private Attribute $attribute;

    /**
     * Attribute value(s) stored as JSON array (e.g., ["val1"] or ["val1","val2"])
     *
     * @ORM\Column(type="text", nullable=false)
     */
    private string $value;

    /**
     * Where the value came from (SOURCE_*). Only SOURCE_PS values follow later
     * product changes.
     * @ORM\Column(type="string", length=16, nullable=false, name="value_source", options={"default"="manual"})
     */
    private string $valueSource = self::SOURCE_MANUAL;

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

    public function getOfferAttributeId(): ?int
    {
        return $this->offerAttributeId;
    }

    public function getOffer(): Offer
    {
        return $this->offer;
    }

    public function setOffer(?Offer $offer): self
    {
        $this->offer = $offer;
        return $this;
    }

    public function getAttribute(): Attribute
    {
        return $this->attribute;
    }

    public function setAttribute(Attribute $attribute): self
    {
        $this->attribute = $attribute;
        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getValueSource(): string
    {
        return $this->valueSource;
    }

    public function setValueSource(string $valueSource): self
    {
        $this->valueSource = $valueSource;
        return $this;
    }

    public function getCategoryMappingId(): ?string
    {
        return $this->categoryMappingId;
    }

    public function setCategoryMappingId(?string $categoryMappingId): self
    {
        $this->categoryMappingId = $categoryMappingId;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getInpostAttributeId(): ?string
    {
        return $this->inpostAttributeId;
    }

    public function setInpostAttributeId(?string $inpostAttributeId): self
    {
        $this->inpostAttributeId = $inpostAttributeId;
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
