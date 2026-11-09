<?php
/**
 * OfferManufacturer Entity
 *
 * Manufacturer information for an InPost offer.
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

declare(strict_types=1);

namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\OfferManufacturerRepository")
 * @ORM\Table(
 *     name="inpost_offer_manufacturer",
 *     indexes={
 *         @ORM\Index(name="IDX_OFFER_ID", columns={"offer_id"})
 *     }
 * )
 */
class OfferManufacturer
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="offer_manufacturer_id", options={"unsigned"=true})
     */
    private ?int $offerManufacturerId = null;

    /**
     * @ORM\OneToOne(targetEntity="Offer", inversedBy="manufacturer")
     * @ORM\JoinColumn(name="offer_id", referencedColumnName="offer_id", nullable=false)
     */
    private Offer $offer;

    /**
     * @ORM\Column(type="string", length=128, nullable=true)
     */
    private ?string $name = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $address = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $website = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, name="address_street")
     */
    private ?string $addressStreet = null;

    /**
     * @ORM\Column(type="string", length=50, nullable=true, name="address_building_number")
     */
    private ?string $addressBuildingNumber = null;

    /**
     * @ORM\Column(type="string", length=50, nullable=true, name="address_apartment_number")
     */
    private ?string $addressApartmentNumber = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, name="address_city")
     */
    private ?string $addressCity = null;

    /**
     * @ORM\Column(type="string", length=20, nullable=true, name="address_postal_code")
     */
    private ?string $addressPostalCode = null;

    /**
     * @ORM\Column(type="string", length=2, nullable=true, name="address_country_code")
     */
    private ?string $addressCountryCode = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $email = null;

    /**
     * @ORM\Column(type="string", length=50, nullable=true)
     */
    private ?string $phone = null;

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

    public function getOfferManufacturerId(): ?int
    {
        return $this->offerManufacturerId;
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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getAddressStreet(): ?string
    {
        return $this->addressStreet;
    }

    public function setAddressStreet(?string $addressStreet): self
    {
        $this->addressStreet = $addressStreet;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getAddressBuildingNumber(): ?string
    {
        return $this->addressBuildingNumber;
    }

    public function setAddressBuildingNumber(?string $addressBuildingNumber): self
    {
        $this->addressBuildingNumber = $addressBuildingNumber;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getAddressApartmentNumber(): ?string
    {
        return $this->addressApartmentNumber;
    }

    public function setAddressApartmentNumber(?string $addressApartmentNumber): self
    {
        $this->addressApartmentNumber = $addressApartmentNumber;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getAddressCity(): ?string
    {
        return $this->addressCity;
    }

    public function setAddressCity(?string $addressCity): self
    {
        $this->addressCity = $addressCity;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getAddressPostalCode(): ?string
    {
        return $this->addressPostalCode;
    }

    public function setAddressPostalCode(?string $addressPostalCode): self
    {
        $this->addressPostalCode = $addressPostalCode;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getAddressCountryCode(): ?string
    {
        return $this->addressCountryCode;
    }

    public function setAddressCountryCode(?string $addressCountryCode): self
    {
        $this->addressCountryCode = $addressCountryCode;
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
