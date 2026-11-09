<?php
/**
 * CategoryLink Entity
 *
 * Links PrestaShop categories to InPost categories (manual mapping).
 * Maps to inpost_category_link table.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\CategoryLinkRepository")
 * @ORM\Table(
 *     name="inpost_category_link",
 *     indexes={
 *         @ORM\Index(name="IDX_SHOP_ID", columns={"shop_id"}),
 *         @ORM\Index(name="IDX_MAPPING_ID", columns={"mapping_id"}),
 *         @ORM\Index(name="IDX_PRESTASHOP_CATEGORY_ID", columns={"prestashop_category_id"})
 *     },
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="UNQ_MAPPING_CATEGORY_SHOP", columns={"mapping_id", "prestashop_category_id", "shop_id"})
 *     }
 * )
 */
class CategoryLink
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="link_id", options={"unsigned"=true})
     */
    private ?int $linkId = null;

    /**
     * @ORM\Column(type="integer", nullable=false, name="mapping_id", options={"unsigned"=true})
     */
    private int $mappingId;

    /**
     * @ORM\Column(type="integer", nullable=false, name="prestashop_category_id", options={"unsigned"=true})
     */
    private int $prestashopCategoryId;

    /**
     * @ORM\Column(type="integer", nullable=true, name="shop_id", options={"unsigned"=true})
     */
    private ?int $shopId = null;

    public function __construct()
    {
    }

    public function getLinkId(): ?int
    {
        return $this->linkId;
    }

    public function getMappingId(): int
    {
        return $this->mappingId;
    }

    public function setMappingId(int $mappingId): self
    {
        $this->mappingId = $mappingId;
        return $this;
    }

    public function getPrestashopCategoryId(): int
    {
        return $this->prestashopCategoryId;
    }

    public function setPrestashopCategoryId(int $prestashopCategoryId): self
    {
        $this->prestashopCategoryId = $prestashopCategoryId;
        return $this;
    }

    public function getShopId(): ?int
    {
        return $this->shopId;
    }

    public function setShopId(?int $shopId): self
    {
        $this->shopId = $shopId;
        return $this;
    }
}
