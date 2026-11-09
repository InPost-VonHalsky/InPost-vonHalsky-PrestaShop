<?php
/**
 * CategoryAttribute Entity
 *
 * Required/optional attributes for InPost categories.
 * Links CategoryMapping to Attribute with category-specific metadata.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\CategoryAttributeRepository")
 * @ORM\Table(
 *     name="inpost_category_attribute",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="UNQ_CATEGORY_ATTRIBUTE", columns={"inpost_category_mapping_id", "inpost_attribute_id"})
 *     },
 *     indexes={
 *         @ORM\Index(name="IDX_CATEGORY_ID", columns={"inpost_category_mapping_id"}),
 *         @ORM\Index(name="IDX_ATTRIBUTE_ID", columns={"inpost_attribute_id"}),
 *         @ORM\Index(name="IDX_REQUIRED", columns={"required"})
 *     }
 * )
 */
class CategoryAttribute
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="entity_id", options={"unsigned"=true})
     */
    private ?int $entityId = null;

    /**
     * @ORM\ManyToOne(targetEntity="CategoryMapping", inversedBy="attributes")
     * @ORM\JoinColumn(name="inpost_category_mapping_id", referencedColumnName="entity_id", nullable=false, onDelete="CASCADE")
     */
    private CategoryMapping $categoryMapping;

    /**
     * @ORM\ManyToOne(targetEntity="Attribute")
     * @ORM\JoinColumn(name="inpost_attribute_id", referencedColumnName="attribute_id", nullable=false, onDelete="CASCADE")
     */
    private Attribute $attribute;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, name="prestashop_attribute_code")
     */
    private ?string $prestashopAttributeCode = null;

    /**
     * PrestaShop value source: feature or attribute_group (see AttributeSource)
     * @ORM\Column(type="string", length=16, nullable=true, name="ps_source_type")
     */
    private ?string $psSourceType = null;

    /**
     * id_feature or id_attribute_group
     * @ORM\Column(type="integer", nullable=true, name="ps_source_id", options={"unsigned"=true})
     */
    private ?int $psSourceId = null;

    /**
     * Derived from API expectedValue field (ONE, AT_LEAST_ONE = true)
     *
     * @ORM\Column(type="boolean", nullable=false, name="required", options={"unsigned"=true, "default"=false})
     */
    private bool $required = false;

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

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function getCategoryMapping(): CategoryMapping
    {
        return $this->categoryMapping;
    }

    public function setCategoryMapping(CategoryMapping $categoryMapping): self
    {
        $this->categoryMapping = $categoryMapping;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getAttribute(): Attribute
    {
        return $this->attribute;
    }

    public function setAttribute(Attribute $attribute): self
    {
        $this->attribute = $attribute;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getPrestashopAttributeCode(): ?string
    {
        return $this->prestashopAttributeCode;
    }

    public function setPrestashopAttributeCode(?string $prestashopAttributeCode): self
    {
        $this->prestashopAttributeCode = $prestashopAttributeCode;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getPsSourceType(): ?string
    {
        return $this->psSourceType;
    }

    public function getPsSourceId(): ?int
    {
        return $this->psSourceId;
    }

    public function setPsSource(?string $type, ?int $id): self
    {
        $this->psSourceType = $type;
        $this->psSourceId = $id;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): self
    {
        $this->required = $required;
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
