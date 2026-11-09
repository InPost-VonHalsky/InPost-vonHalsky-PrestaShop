<?php
/**
 * InPost Order Entity
 *
 * Doctrine Entity for ps_inpost_order table
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\InpostOrderRepository")
 * @ORM\Table(name="ps_inpost_order", indexes={
 *     @ORM\Index(name="id_order", columns={"id_order"}),
 *     @ORM\Index(name="inpost_order_id", columns={"inpost_order_id"}),
 *     @ORM\Index(name="status", columns={"status"}),
 *     @ORM\Index(name="id_shop", columns={"id_shop"})
 * })
 */
class InpostOrder
{
    /**
     * @var int
     *
     * @ORM\Id
     * @ORM\Column(name="id_inpost_order", type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $idInpostOrder;

    /**
     * @var int
     *
     * @ORM\Column(name="id_order", type="integer")
     */
    private $idOrder;

    /**
     * @var int|null
     *
     * @ORM\Column(name="id_shop", type="integer", nullable=true)
     */
    private $idShop;

    /**
     * @var string|null
     *
     * @ORM\Column(name="inpost_order_id", type="string", length=255, nullable=true)
     */
    private $inpostOrderId;

    /**
     * @var string
     *
     * @ORM\Column(name="status", type="string", length=32, options={"default":"pending"})
     */
    private $status;

    /**
     * @var string|null
     *
     * @ORM\Column(name="point_name", type="string", length=255, nullable=true)
     */
    private $pointName;

    /**
     * @var string|null
     *
     * @ORM\Column(name="point_address", type="text", nullable=true)
     */
    private $pointAddress;

    /**
     * @var string|null
     *
     * @ORM\Column(name="sender_point_name", type="string", length=255, nullable=true)
     */
    private $senderPointName;

    /**
     * @var string|null
     *
     * @ORM\Column(name="receiver_email", type="string", length=128, nullable=true)
     */
    private $receiverEmail;

    /**
     * @var string|null
     *
     * @ORM\Column(name="receiver_phone", type="string", length=32, nullable=true)
     */
    private $receiverPhone;

    /**
     * @var string|null
     *
     * @ORM\Column(name="shipment_data", type="text", nullable=true)
     */
    private $shipmentData;

    /**
     * @var int
     *
     * @ORM\Column(name="total_items_count", type="integer", options={"default":0})
     */
    private $totalItemsCount = 0;

    /**
     * @var string
     *
     * @ORM\Column(name="total", type="decimal", precision=20, scale=6, options={"default":0})
     */
    private $total = '0.000000';

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="date_add", type="datetime")
     */
    private $dateAdd;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="date_upd", type="datetime")
     */
    private $dateUpd;

    public function __construct()
    {
        $this->status = 'pending';
        $this->dateAdd = new \DateTime();
        $this->dateUpd = new \DateTime();
    }

    /**
     * @return int
     */
    public function getIdInpostOrder(): int
    {
        return $this->idInpostOrder;
    }

    /**
     * @return int
     */
    public function getIdOrder(): int
    {
        return $this->idOrder;
    }

    /**
     * @param int $idOrder
     *
     * @return self
     */
    public function setIdOrder(int $idOrder): self
    {
        $this->idOrder = $idOrder;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getIdShop(): ?int
    {
        return $this->idShop;
    }

    /**
     * @param int|null $idShop
     *
     * @return self
     */
    public function setIdShop(?int $idShop): self
    {
        $this->idShop = $idShop;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getInpostOrderId(): ?string
    {
        return $this->inpostOrderId;
    }

    /**
     * @param string|null $inpostOrderId
     *
     * @return self
     */
    public function setInpostOrderId(?string $inpostOrderId): self
    {
        $this->inpostOrderId = $inpostOrderId;

        return $this;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status
     *
     * @return self
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPointName(): ?string
    {
        return $this->pointName;
    }

    /**
     * @param string|null $pointName
     *
     * @return self
     */
    public function setPointName(?string $pointName): self
    {
        $this->pointName = $pointName;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getPointAddress(): ?string
    {
        return $this->pointAddress;
    }

    /**
     * @param string|null $pointAddress
     *
     * @return self
     */
    public function setPointAddress(?string $pointAddress): self
    {
        $this->pointAddress = $pointAddress;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getSenderPointName(): ?string
    {
        return $this->senderPointName;
    }

    /**
     * @param string|null $senderPointName
     *
     * @return self
     */
    public function setSenderPointName(?string $senderPointName): self
    {
        $this->senderPointName = $senderPointName;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getReceiverEmail(): ?string
    {
        return $this->receiverEmail;
    }

    /**
     * @param string|null $receiverEmail
     *
     * @return self
     */
    public function setReceiverEmail(?string $receiverEmail): self
    {
        $this->receiverEmail = $receiverEmail;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getReceiverPhone(): ?string
    {
        return $this->receiverPhone;
    }

    /**
     * @param string|null $receiverPhone
     *
     * @return self
     */
    public function setReceiverPhone(?string $receiverPhone): self
    {
        $this->receiverPhone = $receiverPhone;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getShipmentData(): ?string
    {
        return $this->shipmentData;
    }

    /**
     * @param string|null $shipmentData
     *
     * @return self
     */
    public function setShipmentData(?string $shipmentData): self
    {
        $this->shipmentData = $shipmentData;

        return $this;
    }

    /**
     * @return int
     */
    public function getTotalItemsCount(): int
    {
        return $this->totalItemsCount;
    }

    /**
     * @param int $totalItemsCount
     *
     * @return self
     */
    public function setTotalItemsCount(int $totalItemsCount): self
    {
        $this->totalItemsCount = $totalItemsCount;

        return $this;
    }

    /**
     * @return string
     */
    public function getTotal(): string
    {
        return $this->total;
    }

    /**
     * @param string|float $total
     *
     * @return self
     */
    public function setTotal($total): self
    {
        $this->total = (string) $total;

        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getDateAdd(): \DateTime
    {
        return $this->dateAdd;
    }

    /**
     * @param \DateTime $dateAdd
     *
     * @return self
     */
    public function setDateAdd(\DateTime $dateAdd): self
    {
        $this->dateAdd = $dateAdd;

        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getDateUpd(): \DateTime
    {
        return $this->dateUpd;
    }

    /**
     * @param \DateTime $dateUpd
     *
     * @return self
     */
    public function setDateUpd(\DateTime $dateUpd): self
    {
        $this->dateUpd = $dateUpd;

        return $this;
    }
}
