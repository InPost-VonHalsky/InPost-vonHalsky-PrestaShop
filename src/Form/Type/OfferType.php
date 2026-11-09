<?php

declare(strict_types=1);

namespace Inpost\VonHalsky\Form\Type;

use Inpost\VonHalsky\Entity\Offer;
use Inpost\VonHalsky\Entity\OfferAttributes;
use Inpost\VonHalsky\Entity\OfferPrice;
use Inpost\VonHalsky\Service\Mapping\AttributeSource;
use Inpost\VonHalsky\Service\Offer\ProductAttributeValueResolver;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Inpost\VonHalsky\Validator\Constraints\ValidEan;
use Inpost\VonHalsky\Validator\Constraints\EanOrAlternativeIdentification;
// Custom form types removed - using standard Symfony types instead

/**
 * Offer Form Type - Restructured with Tab Organization
 *
 * Tab 1: Offer Details (Store, Organization, Validation, Category, Status)
 * Tab 2: Product Details (Product info, dimensions, price/stock display)
 * Tab 3: Policies & Shipping (Shipping time, return/complaint policies)
 * Tab 4: GPSR and Manufacturer Details (GPSR compliance, manufacturer info)
 * Tab 5: Events (Offer event history - display only)
 *
 * @package Inpost\VonHalsky\Form\Type
 */
class OfferType extends AbstractType
{
    /**
     * Build offer form with tab structure
     *
     * @param FormBuilderInterface $builder Form builder
     * @param array $options Form options
     * @return void
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // =================================================================
        // TAB 1: OFFER DETAILS
        // =================================================================

        // Organization Display (readonly)
        $builder->add('organization_display', TextType::class, [
            'label' => 'Organization',
            'required' => false,
            'mapped' => false,
            'data' => $options['organization_name'] ?? '',
            'attr' => ['readonly' => true],
            'help' => 'Organization is set from module configuration',
            'block_prefix' => 'tab_offer_details'
        ]);

        // Organization ID (hidden)
        // Disabled: never taken from the request - controllers set it from module configuration
        $builder->add('organizationId', HiddenType::class, [
            'required' => true,
            'disabled' => true,
            'data' => $options['organization_id'] ?? ''
        ]);

        // InPost Category Selection (locked after creation)
        $offer = $options['data'] ?? null;
        $isExistingOffer = $offer instanceof Offer && $offer->getOfferId();

        if ($isExistingOffer) {
            // Hidden field for ALL existing offers - display handled in template
            $builder->add('inpost_category', HiddenType::class, [
                'property_path' => 'product.categoryMappingId',
            ]);
        } else {
            // Editable dropdown for create form only
            $builder->add('inpost_category', ChoiceType::class, [
                'label' => 'InPost Category',
                'required' => true,
                'property_path' => 'product.categoryMappingId',
                'choices' => $options['inpost_category_choices'] ?? [],
                'choice_value' => static function (?string $value): string {
                    return $value ?? '';
                },
                'placeholder' => 'Select InPost Category...',
                'attr' => [
                    'class' => 'form-control inpost-category-select',
                    'data-product-id' => $options['product_id'] ?? '',
                    'data-product-attribute-id' => $options['product_attribute_id'] ?? 0
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'InPost category is required'])
                ],
                'help' => 'Select category to load InPost attributes',
                'block_prefix' => 'tab_offer_details'
            ]);
        }

        // InPost Offer ID (read-only)
        $builder->add('inpost_offer_id', TextType::class, [
            'label' => 'InPost Offer ID',
            'required' => false,
            'property_path' => 'inpostOfferId',
            'disabled' => true,
            'attr' => [
                'readonly' => true,
                'placeholder' => 'Not yet submitted to InPost'
            ],
            'help' => 'Generated after submitting offer to InPost API',
            'block_prefix' => 'tab_offer_details'
        ]);

        // Draft Toggle - Disabled for new offers (no offer_id), enabled only for saved offers
        $isExistingOffer = !empty($options['data']) && $options['data']->getOfferId();
        $isDraftOptions = [
            'label' => 'Mark as Draft',
            'required' => true,
            'property_path' => 'isDraft',
            'choices' => [
                'Yes' => 1,
                'No' => 0
            ],
            'disabled' => !$isExistingOffer,
            'attr' => [
                'class' => 'js-draft-toggle'
            ],
            'help' => $isExistingOffer
                ? 'Set to "No" to enable sending to InPost API'
                : 'New offers are always created as drafts. Save first to change this setting.',
            'block_prefix' => 'tab_offer_details'
        ];
        // Only set data for new offers (existing offers use entity value)
        if (!$isExistingOffer) {
            $isDraftOptions['data'] = 1;
        }
        $builder->add('is_draft', ChoiceType::class, $isDraftOptions);

        // Publication Status (read-only)
        $builder->add('status', ChoiceType::class, [
            'label' => 'Publication Status',
            'required' => true,
            'choices' => [
                'Draft' => 'DRAFT',
                'Pending' => 'PENDING',
                'Rejected' => 'REJECTED',
                'Published' => 'PUBLISHED',
                'Closed' => 'CLOSED',
                'Sold Out' => 'SOLDOUT',
                'Not Created (Error)' => 'ERROR_NOT_CREATED'
            ],
            'disabled' => true,
            'help' => 'Controlled by InPost API. Set to DRAFT automatically when marked as draft.',
            'block_prefix' => 'tab_offer_details'
        ]);

        // Language removed - using default 'pl' from Config::getDefaultLanguageCode()

        // Product Selection (hidden fields for PrestaShop product mapping)
        $builder->add('product_search', TextType::class, [
            'label' => 'PrestaShop Product',
            'mapped' => false,
            'required' => false,
            'attr' => [
                'placeholder' => 'Search by name or SKU...',
                'class' => 'product-autocomplete'
            ],
            'help' => 'Start typing to search for a product',
            'block_prefix' => 'tab_offer_details'
        ]);

        $builder->add('prestashop_offer_id', HiddenType::class, [
            'required' => true,
            'constraints' => [
                new Assert\NotBlank(['message' => 'Product is required']),
                new Assert\Positive()
            ],
            'block_prefix' => 'tab_offer_details'
        ]);

        // =================================================================
        // TAB 2: PRODUCT DETAILS
        // =================================================================

        // Product Basic Info
        $builder->add('product_name', TextType::class, [
            'label' => 'Product Name',
            'required' => true,
            'property_path' => 'product.name',
            'attr' => ['maxlength' => 255],
            'constraints' => [
                new Assert\NotBlank(['message' => 'Product name is required']),
                new Assert\Length(['max' => 255])
            ],
            'block_prefix' => 'tab_product_details'
        ]);

        $builder->add('product_description', TextareaType::class, [
            'label' => 'Product Description',
            'required' => true,
            'property_path' => 'product.description',
            'attr' => ['rows' => 5, 'maxlength' => 5000],
            'constraints' => [
                new Assert\NotBlank(['message' => 'Product description is required']),
                new Assert\Length(['max' => 5000])
            ],
            'block_prefix' => 'tab_product_details'
        ]);

        $builder->add('product_sku', TextType::class, [
            'label' => 'SKU',
            'required' => true,
            'property_path' => 'product.sku',
            'attr' => ['maxlength' => 100],
            'constraints' => [
                new Assert\NotBlank(['message' => 'SKU is required']),
                new Assert\Length(['max' => 100])
            ],
            'block_prefix' => 'tab_product_details'
        ]);

        $builder->add('product_ean', TextType::class, [
            'label' => 'EAN',
            'required' => false,
            'property_path' => 'product.ean',
            'attr' => ['maxlength' => 13],
            'help' => 'EAN-8, UPC-A (12) or EAN-13. Optional if both Brand and Manufacturer Product Number are filled in.',
            'constraints' => [
                // Requiredness is enforced at class level by
                // EanOrAlternativeIdentification; format/checksum by ValidEan.
                new ValidEan()
            ],
            'block_prefix' => 'tab_product_details'
        ]);

        $builder->add('product_brand', TextType::class, [
            'label' => 'Brand',
            'required' => true,
            'property_path' => 'product.brand',
            'attr' => ['maxlength' => 100],
            'constraints' => [
                new Assert\NotBlank(['message' => 'Brand is required']),
                new Assert\Length(['max' => 100])
            ],
            'block_prefix' => 'tab_product_details'
        ]);

        $builder->add('product_model', TextType::class, [
            'label' => 'Model',
            'required' => true,
            'property_path' => 'product.model',
            'attr' => ['maxlength' => 128],
            'constraints' => [
                new Assert\NotBlank(['message' => 'Model is required']),
                new Assert\Length(['max' => 128])
            ],
            'block_prefix' => 'tab_product_details'
        ]);

        // Product Dimensions
        $builder->add('dimension_width', IntegerType::class, [
            'label' => 'Dimension Width (mm)',
            'required' => false,
            'property_path' => 'product.dimensionWidth',
            'attr' => ['min' => 0],
            'constraints' => [
                new Assert\PositiveOrZero()
            ],
            'block_prefix' => 'tab_product_details'
        ]);

        $builder->add('dimension_height', IntegerType::class, [
            'label' => 'Dimension Height (mm)',
            'required' => false,
            'property_path' => 'product.dimensionHeight',
            'attr' => ['min' => 0],
            'constraints' => [
                new Assert\PositiveOrZero()
            ],
            'block_prefix' => 'tab_product_details'
        ]);

        $builder->add('dimension_length', IntegerType::class, [
            'label' => 'Dimension Length (mm)',
            'required' => false,
            'property_path' => 'product.dimensionLength',
            'attr' => ['min' => 0],
            'constraints' => [
                new Assert\PositiveOrZero()
            ],
            'block_prefix' => 'tab_product_details'
        ]);

        $builder->add('dimension_weight', IntegerType::class, [
            'label' => 'Dimension Weight (g)',
            'required' => false,
            'property_path' => 'product.dimensionWeight',
            'attr' => ['min' => 0],
            'constraints' => [
                new Assert\PositiveOrZero()
            ],
            'block_prefix' => 'tab_product_details'
        ]);

        // Extended Product Info
        $builder->add('super_model', TextType::class, [
            'label' => 'Super Model',
            'required' => false,
            'property_path' => 'product.superModel',
            'attr' => ['maxlength' => 100],
            'block_prefix' => 'tab_product_details'
        ]);

        $builder->add('manufacturer_product_number', TextType::class, [
            'label' => 'Manufacturer Product Number',
            'required' => false,
            'property_path' => 'product.manufacturerProductNumber',
            'attr' => ['maxlength' => 100],
            'help' => 'Combined with Brand, substitutes for EAN when no EAN is available.',
            'block_prefix' => 'tab_product_details'
        ]);

        // InPost price (GOKINT-1098): from the shop (price settings of the module)
        // or typed in; a manual price must be above 0 (OfferController)
        $builder->add('price_mode', ChoiceType::class, [
            'label' => 'InPost price',
            'property_path' => 'price.priceMode',
            'choices' => [
                'Automatic - from the shop' => OfferPrice::MODE_AUTO,
                'Manual' => OfferPrice::MODE_MANUAL,
            ],
            'expanded' => true,
            // Not "required": the admin theme would star both options; no empty choice either
            'required' => false,
            'placeholder' => false,
            'block_prefix' => 'tab_product_details'
        ]);

        $builder->add('manual_price', NumberType::class, [
            'label' => 'Manual price (gross)',
            'required' => false,
            'property_path' => 'price.manualPrice',
            'input' => 'string',
            'scale' => 2,
            'invalid_message' => 'Enter the price as a number, e.g. 99.99.',
            'help' => 'Sent to InPost as it is - price changes in the shop and the price settings do not apply to it.',
            'block_prefix' => 'tab_product_details'
        ]);

        // =================================================================
        // TAB 3: POLICIES & SHIPPING
        // =================================================================

        // Shipping Time (NEW FIELD - days)
        $builder->add('shipping_time_days', IntegerType::class, [
            'label' => 'Shipping Time (days)',
            'required' => false,
            'property_path' => 'shippingTime',
            'attr' => ['min' => 0],
            'constraints' => [
                new Assert\PositiveOrZero()
            ],
            'help' => 'Days to ship. When empty: the "Delivery days" product field mapping, then the default from Offer Defaults.',
            'block_prefix' => 'tab_policies_shipping'
        ]);

        $builder->add('affiliation_product_url', UrlType::class, [
            'label' => 'Affiliation Product URL',
            'required' => false,
            'property_path' => 'affiliationProductUrl',
            'help' => 'External product URL',
            'block_prefix' => 'tab_policies_shipping'
        ]);

        // Policies
        $builder->add('return_policy', TextareaType::class, [
            'label' => 'Return Policy',
            'required' => false,
            'property_path' => 'postSaleReturnPolicy',
            'attr' => ['rows' => 3],
            'help' => 'Leave empty to use default from configuration',
            'block_prefix' => 'tab_policies_shipping'
        ]);

        $builder->add('complaint_policy', TextareaType::class, [
            'label' => 'Complaint Policy',
            'required' => false,
            'property_path' => 'postSaleComplaintPolicy',
            'attr' => ['rows' => 3],
            'help' => 'Leave empty to use default from configuration',
            'block_prefix' => 'tab_policies_shipping'
        ]);

        // =================================================================
        // TAB 4: GPSR AND MANUFACTURER DETAILS
        // =================================================================

        // GPSR Compliance - NOW using gpsr.* property paths for OfferGpsr entity
        $builder->add('gpsr_batch_number', TextType::class, [
            'label' => 'GPSR Batch Number',
            'required' => false,
            'property_path' => 'gpsr.batchNumber',
            'attr' => ['maxlength' => 500],
            'help' => 'Product batch number for GPSR compliance',
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        $builder->add('gpsr_ce_marking', CheckboxType::class, [
            'label' => 'GPSR CE Marking',
            'required' => false,
            'property_path' => 'gpsr.ceMarking',
            'help' => 'Check if product has CE marking',
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        $builder->add('gpsr_safety_information', TextareaType::class, [
            'label' => 'GPSR Safety Information',
            'required' => false,
            'property_path' => 'gpsr.safetyInformation',
            'attr' => ['rows' => 4],
            'help' => 'Product safety information for GPSR compliance',
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        $builder->add('gpsr_does_not_require_info', CheckboxType::class, [
            'label' => 'GPSR Does Not Require Info',
            'required' => false,
            'property_path' => 'gpsr.doesNotRequireInfo',
            'data' => true, // Default to checked
            'help' => 'Check if product does not require GPSR information. When unchecked, manufacturer information becomes required.',
            'attr' => [
                'class' => 'js-gpsr-toggle'
            ],
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        // Manufacturer Information
        $builder->add('manufacturer_name', TextType::class, [
            'label' => 'Manufacturer Name',
            'required' => false,
            'property_path' => 'manufacturer.name',
            'attr' => ['maxlength' => 128],
            'constraints' => [
                new Assert\Length(['max' => 128])
            ],
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        $builder->add('manufacturer_address', TextType::class, [
            'label' => 'Address',
            'required' => false,
            'property_path' => 'manufacturer.address',
            'attr' => ['maxlength' => 255],
            'constraints' => [
                new Assert\Length(['max' => 255])
            ],
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        $builder->add('manufacturer_email', TextType::class, [
            'label' => 'Email',
            'required' => false,
            'property_path' => 'manufacturer.email',
            'attr' => ['maxlength' => 255],
            'constraints' => [
                new Assert\Length(['max' => 255]),
                new Assert\Email(['message' => 'Please enter a valid email address'])
            ],
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        $builder->add('manufacturer_phone', TextType::class, [
            'label' => 'Phone',
            'required' => false,
            'property_path' => 'manufacturer.phone',
            'attr' => ['maxlength' => 50],
            'constraints' => [
                new Assert\Length(['max' => 50])
            ],
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        $builder->add('manufacturer_website', UrlType::class, [
            'label' => 'Website',
            'required' => false,
            'property_path' => 'manufacturer.website',
            'attr' => ['maxlength' => 255],
            'constraints' => [
                new Assert\Length(['max' => 255]),
                new Assert\Url()
            ],
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        // Manufacturer Address (GPSR Compliance)
        $builder->add('manufacturer_address_street', TextType::class, [
            'label' => 'Address Street',
            'required' => false,
            'property_path' => 'manufacturer.addressStreet',
            'attr' => ['maxlength' => 255],
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        $builder->add('manufacturer_address_building_number', TextType::class, [
            'label' => 'Address Building Number',
            'required' => false,
            'property_path' => 'manufacturer.addressBuildingNumber',
            'attr' => ['maxlength' => 50],
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        $builder->add('manufacturer_address_apartment_number', TextType::class, [
            'label' => 'Address Apartment Number',
            'required' => false,
            'property_path' => 'manufacturer.addressApartmentNumber',
            'attr' => ['maxlength' => 50],
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        $builder->add('manufacturer_address_city', TextType::class, [
            'label' => 'Address City',
            'required' => false,
            'property_path' => 'manufacturer.addressCity',
            'attr' => ['maxlength' => 255],
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        $builder->add('manufacturer_address_postal_code', TextType::class, [
            'label' => 'Address Postal Code',
            'required' => false,
            'property_path' => 'manufacturer.addressPostalCode',
            'attr' => ['maxlength' => 20],
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        $builder->add('manufacturer_address_country_code', ChoiceType::class, [
            'label' => 'Address Country Code',
            'required' => false,
            'property_path' => 'manufacturer.addressCountryCode',
            'choices' => [
                'Poland' => 'PL',
                'Germany' => 'DE',
                'United Kingdom' => 'GB',
                'France' => 'FR',
                'Czech Republic' => 'CZ',
                'Slovakia' => 'SK'
            ],
            'placeholder' => 'Select country...',
            'block_prefix' => 'tab_gpsr_manufacturer'
        ]);

        // =================================================================
        // TAB 5: INPOST ATTRIBUTES (dynamic per category)
        // =================================================================
        $attributesForm = $builder->create('inpost_attributes', FormType::class, [
            'mapped' => false,
            'label' => false,
            'required' => false,
        ]);

        foreach ($options['inpost_category_attributes'] as $attribute) {
            $fieldName = sprintf('attr_%d', $attribute['id']);
            $fieldType = TextType::class;
            $multiple = $attribute['type'] === 'DICTIONARY' && !empty($attribute['multiple']);
            $values = (array) ($options['inpost_attribute_values'][$attribute['code']] ?? []);
            $fieldOptions = [
                'label' => $attribute['label'],
                'required' => (bool) $attribute['required'],
                'attr' => [
                    'class' => 'form-control',
                    'data-inpost-attribute-id' => $attribute['code'],
                    'data-required' => $attribute['required'] ? '1' : '0',
                ],
                // A single field holding several stored values shows them comma-separated;
                // OfferController keeps the original values when the text is left unchanged
                'data' => $multiple ? array_values($values) : (count($values) > 1 ? implode(', ', $values) : ($values[0] ?? null)),
            ];
            $fieldOptions += $this->attributeHelp($attribute, $options['inpost_attribute_meta'][$attribute['code']] ?? null, $values);

            switch ($attribute['type']) {
                case 'DICTIONARY':
                    $fieldType = ChoiceType::class;
                    $choices = [];
                    foreach ($attribute['dictionary_values'] ?? [] as $option) {
                        $choices[$option['label']] = $option['value'];
                    }
                    // A stored value whose option InPost has since deactivated stays
                    // visible (marked) instead of silently disappearing from the select
                    foreach ($values as $value) {
                        if ($value !== '' && !in_array($value, $choices, true)) {
                            $choices[$value . ' (inactive)'] = $value;
                        }
                    }
                    $fieldOptions['choices'] = $choices;
                    if ($multiple) {
                        $fieldOptions['multiple'] = true;
                        $fieldOptions['attr']['class'] .= ' js-inpost-multiselect';
                    } else {
                        $fieldOptions['placeholder'] = '-- select --';
                    }
                    break;

                case 'NUMERIC_FLOAT':
                case 'NUMERIC':
                    // Plain text on purpose: never reformat the entered value (e.g. "10"
                    // must not become "10.00"). NumberType/IntegerType apply locale
                    // formatting/rounding and yield a numeric value, but InPost expects
                    // attribute values as plain strings and rejects the reformatted form.
                    $fieldType = TextType::class;
                    $fieldOptions['attr']['inputmode'] = ($attribute['type'] === 'NUMERIC_FLOAT') ? 'decimal' : 'numeric';
                    break;

                case 'TEXT_VALUE':
                default:
                    $fieldType = TextType::class;
                    break;
            }

            // Add HTML required attribute and NotBlank constraint for required fields
            if ($attribute['required']) {
                $fieldOptions['attr']['required'] = 'required';
                $fieldOptions['constraints'] = [
                    new Assert\NotBlank(['message' => 'This attribute is required.']),
                ];
            }

            $attributesForm->add($fieldName, $fieldType, $fieldOptions);
        }

        $builder->add($attributesForm);

        // =================================================================
        // TAB 6: EVENTS (display only)
        // =================================================================
        // Events are handled in the Twig template via AJAX, so no form fields here.
    }

    /**
     * Configure form options
     *
     * @param OptionsResolver $resolver Options resolver
     * @return void
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Offer::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'inpost_offer_form',
            'translation_domain' => 'Modules.Inpostvonhalsky.Admin',
            'inpost_category_choices' => [],
            'inpost_category_attributes' => [],
            'inpost_attribute_values' => [],
            // Per attribute UUID: ['source' => OfferAttributes::SOURCE_*|null, 'ps' => ProductAttributeValueResolver entry|null]
            'inpost_attribute_meta' => [],
            'product_id' => null,
            'product_attribute_id' => 0,
            'organization_name' => '',
            'organization_id' => '',
            'constraints' => [
                new EanOrAlternativeIdentification(),
            ],
        ]);
    }

    /**
     * Help text under an attribute field: where the value comes from, or why
     * PrestaShop could not provide it
     *
     * @param array<string,mixed> $attribute Category attribute definition
     * @param array<string,mixed>|null $meta Entry of the inpost_attribute_meta option
     * @param string[] $values Current field values
     * @return array<string,mixed> help / help_translation_parameters / help_attr options
     */
    private function attributeHelp(array $attribute, ?array $meta, array $values): array
    {
        $ps = $meta['ps'] ?? null;
        $source = $meta['source'] ?? null;

        if ($ps === null) {
            if ($source === OfferAttributes::SOURCE_HINT && !empty($values)) {
                return self::help('Value from the InPost product suggestion.');
            }
            if (!empty($attribute['required']) && empty($values)) {
                return self::help('No PrestaShop source set for this attribute - enter the value or set the source in Category mapping.', [], 'text-warning');
            }

            return [];
        }

        $kinds = [AttributeSource::FEATURE => 'feature', AttributeSource::PRODUCT_FIELD => 'product_field'];
        $kind = $kinds[$ps['source_type']] ?? 'attribute';
        $fromSource = [
            'feature' => 'From PrestaShop feature "%name%".',
            'product_field' => 'From PrestaShop product field "%name%".',
            'attribute' => 'From PrestaShop attribute "%name%".',
        ];
        $missingSource = [
            'feature' => 'The product has no value of feature "%name%" in PrestaShop - fill it in the product or enter it here.',
            'product_field' => 'The product field "%name%" is empty in PrestaShop - fill it in the product or enter it here.',
            'attribute' => 'The product has no value of attribute "%name%" in PrestaShop - enter it here.',
        ];
        $params = [
            '%name%' => (string) $ps['source_name'],
            '%value%' => implode(', ', $ps['raw_values'] ?? []),
        ];

        switch ($ps['status']) {
            case ProductAttributeValueResolver::STATUS_OK:
                $psValues = $ps['values'];
                sort($psValues);
                $current = $values;
                sort($current);
                if (empty($values) || $current === $psValues) {
                    return self::help($fromSource[$kind], $params, 'text-success');
                }

                return self::help('Entered manually - PrestaShop ("%name%") has: %value%.', $params);

            case ProductAttributeValueResolver::STATUS_MISSING:
                return self::help($missingSource[$kind], $params, 'text-warning');

            case ProductAttributeValueResolver::STATUS_UNMATCHED:
                return self::help('Value "%value%" from PrestaShop ("%name%") matches no InPost option - choose one here or map the values in Category mapping.', $params, 'text-warning');

            case ProductAttributeValueResolver::STATUS_INVALID:
                return self::help('Value "%value%" from PrestaShop ("%name%") is not valid for this attribute - enter it here.', $params, 'text-warning');

            case ProductAttributeValueResolver::STATUS_HAS_COMBINATIONS:
                return self::help('The product has several combinations and this offer covers all of them - enter the value of "%name%" here.', $params, 'text-info');
        }

        return [];
    }

    private static function help(string $message, array $params = [], string $class = 'text-muted'): array
    {
        return [
            'help' => $message,
            'help_translation_parameters' => $params,
            'help_attr' => ['class' => 'form-text ' . $class],
        ];
    }
}
