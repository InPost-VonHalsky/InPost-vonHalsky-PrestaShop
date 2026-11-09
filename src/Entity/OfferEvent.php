<?php
/**
 * OfferEvent Entity
 *
 * Event log for offer operations (create, update, sync, errors).
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\OfferEventRepository")
 * @ORM\Table(
 *     name="inpost_offer_event",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="UQ_INPOST_OFFER_EVENT_ID", columns={"inpost_offer_event_id"})
 *     },
 *     indexes={
 *         @ORM\Index(name="IDX_PRESTASHOP_OFFER_ID", columns={"prestashop_offer_id"}),
 *         @ORM\Index(name="IDX_INPOST_OFFER_ID", columns={"inpost_offer_id"}),
 *         @ORM\Index(name="IDX_OFFER_EVENT_TYPE", columns={"offer_event_type"}),
 *         @ORM\Index(name="IDX_OCCURRED_AT", columns={"occured_at"})
 *     }
 * )
 */
class OfferEvent
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="entity_id", options={"unsigned"=true})
     */
    private ?int $eventId = null;

    /**
     * @ORM\Column(type="string", length=36, nullable=true, name="inpost_offer_event_id")
     */
    private ?string $inpostOfferEventId = null;

    /**
     * @ORM\Column(type="string", length=32, nullable=false, name="offer_event_type")
     */
    private string $eventType;

    /**
     * @ORM\Column(type="datetime", nullable=false, name="occured_at")
     */
    private \DateTime $occuredAt;

    /**
     * @ORM\Column(type="string", length=255, nullable=false, name="inpost_offer_id")
     */
    private string $inpostOfferId;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, name="prestashop_offer_id")
     */
    private ?string $prestashopOfferId = null;

    public function __construct()
    {
        $this->occuredAt = new \DateTime();
    }

    public function getEventId(): ?int
    {
        return $this->eventId;
    }

    public function getInpostOfferEventId(): ?string
    {
        return $this->inpostOfferEventId;
    }

    public function setInpostOfferEventId(?string $inpostOfferEventId): self
    {
        $this->inpostOfferEventId = $inpostOfferEventId;
        return $this;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): self
    {
        $this->eventType = $eventType;
        return $this;
    }

    public function getOccuredAt(): \DateTime
    {
        return $this->occuredAt;
    }

    public function setOccuredAt(\DateTime $occuredAt): self
    {
        $this->occuredAt = $occuredAt;
        return $this;
    }

    public function getInpostOfferId(): string
    {
        return $this->inpostOfferId;
    }

    public function setInpostOfferId(string $inpostOfferId): self
    {
        $this->inpostOfferId = $inpostOfferId;
        return $this;
    }

    public function getPrestashopOfferId(): ?string
    {
        return $this->prestashopOfferId;
    }

    public function setPrestashopOfferId(?string $prestashopOfferId): self
    {
        $this->prestashopOfferId = $prestashopOfferId;
        return $this;
    }

    // Convenience methods for backward compatibility
    public function getCreatedAt(): \DateTime
    {
        return $this->occuredAt;
    }
}
