<?php
/**
 * Attribute Entity
 *
 * Global InPost attributes (synced from API).
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\AttributeRepository")
 * @ORM\Table(
 *     name="inpost_attribute",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="UNQ_ATTRIBUTE_ID_LANG", columns={"inpost_attribute_id", "lang"})
 *     },
 *     indexes={
 *         @ORM\Index(name="IDX_INPOST_ATTRIBUTE_ID", columns={"inpost_attribute_id"}),
 *         @ORM\Index(name="IDX_TYPE", columns={"type"}),
 *         @ORM\Index(name="IDX_LANG", columns={"lang"}),
 *         @ORM\Index(name="IDX_IS_ACTIVE", columns={"is_active"}),
 *         @ORM\Index(name="IDX_SYNCED_AT", columns={"synced_at"})
 *     }
 * )
 */
class Attribute
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="attribute_id", options={"unsigned"=true})
     */
    private ?int $attributeId = null;

    /**
     * @ORM\Column(type="string", length=36, nullable=false, name="inpost_attribute_id")
     */
    private string $inpostAttributeId;

    /**
     * @ORM\Column(type="string", length=255, nullable=false, name="name")
     */
    private string $name;

    /**
     * @ORM\Column(type="string", length=64, nullable=true, name="type")
     */
    private ?string $type = null;

    /**
     * @ORM\Column(type="string", length=10, nullable=true, name="lang")
     */
    private ?string $lang = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=true, name="expected_value")
     */
    private ?string $expectedValue = null;

    /**
     * @ORM\Column(type="text", nullable=true, name="description")
     */
    private ?string $description = null;

    /**
     * @ORM\Column(type="boolean", nullable=false, name="required", options={"default"=false})
     */
    private bool $required = false;

    /**
     * @ORM\Column(type="boolean", nullable=false, name="is_active", options={"default"=true})
     */
    private bool $isActive = true;

    /**
     * @ORM\Column(type="datetime", nullable=true, name="synced_at")
     */
    private ?\DateTime $syncedAt = null;

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

    public function getAttributeId(): ?int
    {
        return $this->attributeId;
    }

    public function getInpostAttributeId(): string
    {
        return $this->inpostAttributeId;
    }

    public function setInpostAttributeId(string $inpostAttributeId): self
    {
        $this->inpostAttributeId = $inpostAttributeId;
        $this->updatedAt = new \DateTime();
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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getLang(): ?string
    {
        return $this->lang;
    }

    public function setLang(?string $lang): self
    {
        $this->lang = $lang;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getExpectedValue(): ?string
    {
        return $this->expectedValue;
    }

    public function setExpectedValue(?string $expectedValue): self
    {
        $this->expectedValue = $expectedValue;
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        $this->updatedAt = new \DateTime();
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

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }
}
