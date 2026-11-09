<?php
/**
 * AttributeDictionary Entity
 *
 * Dictionary options for InPost attributes.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\AttributeDictionaryRepository")
 * @ORM\Table(
 *     name="inpost_attribute_dictionary",
 *     indexes={
 *         @ORM\Index(name="IDX_ATTRIBUTE_ID", columns={"attribute_id"}),
 *         @ORM\Index(name="IDX_VALUE_CODE", columns={"value_code"})
 *     }
 * )
 */
class AttributeDictionary
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="dictionary_id", options={"unsigned"=true})
     */
    private ?int $dictionaryId = null;

    /**
     * @ORM\ManyToOne(targetEntity="Attribute")
     * @ORM\JoinColumn(name="attribute_id", referencedColumnName="attribute_id", nullable=false)
     */
    private Attribute $attribute;

    /**
     * @ORM\Column(type="string", length=128, nullable=false, name="value_code")
     */
    private string $valueCode;

    /**
     * @ORM\Column(type="string", length=255, nullable=false, name="value_label")
     */
    private string $valueLabel;

    /**
     * InPost option.active - inactive options are not offered for new values
     * @ORM\Column(type="boolean", nullable=false, name="active", options={"unsigned"=true, "default"=true})
     */
    private bool $active = true;

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

    public function getDictionaryId(): ?int
    {
        return $this->dictionaryId;
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

    public function getValueCode(): string
    {
        return $this->valueCode;
    }

    public function setValueCode(string $valueCode): self
    {
        $this->valueCode = $valueCode;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getValueLabel(): string
    {
        return $this->valueLabel;
    }

    public function setValueLabel(string $valueLabel): self
    {
        $this->valueLabel = $valueLabel;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;
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
