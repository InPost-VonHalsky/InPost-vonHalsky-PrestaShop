<?php
/**
 * CategoryMapping Entity
 *
 * InPost category tree (auto-synced from API).
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
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\CategoryMappingRepository")
 * @ORM\Table(
 *     name="inpost_category_mapping",
 *     indexes={
 *         @ORM\Index(name="IDX_INPOST_CATEGORY_ID", columns={"inpost_category_id"}),
 *         @ORM\Index(name="IDX_INPOST_PARENT_ID", columns={"inpost_parent_id"}),
 *         @ORM\Index(name="IDX_PARENT_ID", columns={"parent_id"}),
 *         @ORM\Index(name="IDX_LEVEL", columns={"level"}),
 *         @ORM\Index(name="IDX_IS_LEAF", columns={"is_leaf"}),
 *         @ORM\Index(name="IDX_IS_ACTIVE", columns={"is_active"}),
 *         @ORM\Index(name="IDX_LANGUAGE", columns={"language"})
 *     }
 * )
 */
class CategoryMapping
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="entity_id", options={"unsigned"=true})
     */
    private ?int $entityId = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=false, name="inpost_category_id")
     */
    private string $inpostCategoryId;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, name="inpost_parent_id")
     */
    private ?string $inpostParentId = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=false, name="name")
     */
    private string $name;

    /**
     * @ORM\Column(type="text", nullable=true, name="description")
     */
    private ?string $description = null;

    /**
     * @ORM\Column(type="integer", nullable=true, name="parent_id", options={"unsigned"=true})
     */
    private ?int $parentId = null;

    /**
     * @ORM\Column(type="integer", nullable=false, name="level", options={"unsigned"=true, "default"=0})
     */
    private int $level = 0;

    /**
     * @ORM\Column(type="boolean", nullable=false, name="is_leaf", options={"default"=false})
     */
    private bool $isLeaf = false;

    /**
     * @ORM\Column(type="boolean", nullable=false, name="is_active", options={"default"=true})
     */
    private bool $isActive = true;

    /**
     * @ORM\Column(type="integer", nullable=false, name="sort_order", options={"unsigned"=true, "default"=0})
     */
    private int $sortOrder = 0;

    /**
     * @ORM\Column(type="string", length=5, nullable=false, name="language", options={"default"="pl"})
     */
    private string $language = 'pl';

    /**
     * @ORM\Column(type="datetime", nullable=false, name="created_at")
     */
    private \DateTime $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=false, name="updated_at")
     */
    private \DateTime $updatedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true, name="synced_at")
     */
    private ?\DateTime $syncedAt = null;

    /**
     * @ORM\OneToMany(targetEntity="CategoryAttribute", mappedBy="categoryMapping", cascade={"persist", "remove"})
     */
    private Collection $attributes;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->attributes = new ArrayCollection();
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function getInpostCategoryId(): string
    {
        return $this->inpostCategoryId;
    }

    public function setInpostCategoryId(string $inpostCategoryId): self
    {
        $this->inpostCategoryId = $inpostCategoryId;
        return $this;
    }

    public function getInpostParentId(): ?string
    {
        return $this->inpostParentId;
    }

    public function setInpostParentId(?string $inpostParentId): self
    {
        $this->inpostParentId = $inpostParentId;
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

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function setParentId(?int $parentId): self
    {
        $this->parentId = $parentId;
        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): self
    {
        $this->level = $level;
        return $this;
    }

    public function getIsLeaf(): bool
    {
        return $this->isLeaf;
    }

    public function setIsLeaf(bool $isLeaf): self
    {
        $this->isLeaf = $isLeaf;
        return $this;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): self
    {
        $this->language = $language;
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

    public function getSyncedAt(): ?\DateTime
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(?\DateTime $syncedAt): self
    {
        $this->syncedAt = $syncedAt;
        return $this;
    }

    /**
     * @return Collection|CategoryAttribute[]
     */
    public function getAttributes(): Collection
    {
        return $this->attributes;
    }

    public function addAttribute(CategoryAttribute $attribute): self
    {
        if (!$this->attributes->contains($attribute)) {
            $this->attributes[] = $attribute;
            $attribute->setCategoryMapping($this);
        }
        return $this;
    }

    public function removeAttribute(CategoryAttribute $attribute): self
    {
        if ($this->attributes->removeElement($attribute)) {
            if ($attribute->getCategoryMapping() === $this) {
                $attribute->setCategoryMapping(null);
            }
        }
        return $this;
    }
}
