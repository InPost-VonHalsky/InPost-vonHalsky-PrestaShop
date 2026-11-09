<?php
/**
 * OfferManual Entity
 *
 * Product manuals/documents for an InPost offer.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\OfferManualRepository")
 * @ORM\Table(
 *     name="inpost_offer_manual",
 *     indexes={
 *         @ORM\Index(name="IDX_OFFER_ID", columns={"offer_id"})
 *     }
 * )
 */
class OfferManual
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="entity_id", options={"unsigned"=true})
     */
    private ?int $offerManualId = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $title = null;

    /**
     * @ORM\ManyToOne(targetEntity="Offer", inversedBy="manuals")
     * @ORM\JoinColumn(name="offer_id", referencedColumnName="offer_id", nullable=false)
     */
    private Offer $offer;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $url = null;

    public function getOfferManualId(): ?int
    {
        return $this->offerManualId;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
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

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }
}
