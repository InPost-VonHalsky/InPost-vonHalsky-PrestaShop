-- InPost von Halsky PrestaShop Module - Complete Database Schema
-- Version: 1.1.2
-- Description: Consolidated schema with all tables (17 tables)
-- All upgrade scripts merged into this single install file
-- IMPORTANT: Tables ordered by foreign key dependencies

-- =============================================================================
-- OAUTH2 AUTHENTICATION TABLE (no dependencies)
-- =============================================================================

-- InPost PKCE Parameters for OAuth2 authentication
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_pkce_params` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `state` VARCHAR(255) NOT NULL COMMENT 'OAuth State Parameter',
  `code_verifier` VARCHAR(255) NOT NULL COMMENT 'PKCE Code Verifier',
  `expires_at` INT(10) UNSIGNED NOT NULL COMMENT 'Expiration Timestamp',
  `created_at` DATETIME NOT NULL COMMENT 'Created At',
  `return_url` VARCHAR(512) DEFAULT NULL COMMENT 'Return URL after OAuth callback',
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNQ_STATE` (`state`),
  INDEX `IDX_EXPIRES_AT` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost OAuth2 PKCE Parameters';

-- =============================================================================
-- OFFER TABLE (Core - no dependencies, referenced by children)
-- =============================================================================

-- Main offers table
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_offer` (
  `offer_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `inpost_offer_id` VARCHAR(64) DEFAULT NULL COMMENT 'InPost Offer ID',
  `inpost_command_id` VARCHAR(255) DEFAULT NULL COMMENT 'InPost create-offer command UUID',
  `command_status` VARCHAR(20) DEFAULT NULL COMMENT 'Async command status: PENDING|SUCCESS|FAILURE (NULL = no active command)',
  `command_retry_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Automatic create re-attempts after FAILURE',
  `command_error` TEXT DEFAULT NULL COMMENT 'Last API failure reason(s) from the offer command status (why the offer was not created)',
  `organization_id` VARCHAR(64) NOT NULL COMMENT 'InPost Organization ID',
  `prestashop_offer_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'PrestaShop Product ID',
  `prestashop_product_attribute_id` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'PrestaShop combination ID (0 = the whole product)',
  `shop_id` INT(10) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'PrestaShop Shop ID',
  `status` VARCHAR(20) NOT NULL DEFAULT 'DRAFT' COMMENT 'Status (DRAFT, PENDING, PUBLISHED, REJECTED, CLOSED, SOLDOUT, ERROR_NOT_CREATED)',
  `is_draft` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Draft status: 0=published, 1=draft',
    `shipping_time` VARCHAR(255) DEFAULT NULL COMMENT 'Shipping Time',
  `affiliation_product_url` TEXT DEFAULT NULL COMMENT 'Affiliation Product URL',
  `post_sale_return_policy` TEXT DEFAULT NULL COMMENT 'Post Sale Return Policy',
  `post_sale_complaint_policy` TEXT DEFAULT NULL COMMENT 'Post Sale Complaint Policy',
  `language` VARCHAR(5) DEFAULT NULL COMMENT 'Language',
  `inpost_category_id` VARCHAR(255) DEFAULT NULL COMMENT 'InPost Category UUID',
  `inpost_validation` TEXT DEFAULT NULL COMMENT 'InPost Validation Errors JSON',
  `inpost_validation_status` VARCHAR(50) DEFAULT NULL COMMENT 'Computed Validation Status (errors, passed, pending)',
  `require_api_sync` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag: Offer data needs API sync',
  `last_data_sync_at` DATETIME DEFAULT NULL COMMENT 'Last data sync timestamp',
  `last_api_sync_at` DATETIME DEFAULT NULL COMMENT 'Last API sync timestamp',
  `synced_at` DATETIME DEFAULT NULL COMMENT 'Last Successful Sync Timestamp',
  `created_at` DATETIME NOT NULL COMMENT 'Created At',
  `updated_at` DATETIME NOT NULL COMMENT 'Updated At',
  PRIMARY KEY (`offer_id`),
  UNIQUE KEY `UNQ_INPOST_OFFER_ID` (`inpost_offer_id`),
  INDEX `IDX_INPOST_OFFER_ID` (`inpost_offer_id`),
  INDEX `IDX_ORGANIZATION_ID` (`organization_id`),
  INDEX `IDX_PRESTASHOP_OFFER_ID` (`prestashop_offer_id`),
  INDEX `IDX_PS_PRODUCT_COMBINATION` (`prestashop_offer_id`, `prestashop_product_attribute_id`, `shop_id`),
  INDEX `IDX_SHOP_ID` (`shop_id`),
  INDEX `IDX_STATUS` (`status`),
  INDEX `IDX_IS_DRAFT` (`is_draft`),
  INDEX `IDX_COMMAND_STATUS` (`command_status`),
    INDEX `IDX_INPOST_CATEGORY_ID` (`inpost_category_id`),
  INDEX `IDX_VALIDATION_STATUS` (`inpost_validation_status`),
  INDEX `IDX_REQUIRE_API_SYNC` (`require_api_sync`),
  INDEX `IDX_CREATED_AT` (`created_at`),
  INDEX `IDX_UPDATED_AT` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Offers';

-- =============================================================================
-- CATEGORY MAPPING TABLE (self-referencing, no external dependencies)
-- =============================================================================

-- InPost category tree (synced from API)
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_category_mapping` (
  `entity_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `inpost_category_id` VARCHAR(255) NOT NULL COMMENT 'InPost API Category ID',
  `inpost_parent_id` VARCHAR(255) DEFAULT NULL COMMENT 'Parent Category ID',
  `name` VARCHAR(255) NOT NULL COMMENT 'Category Name',
  `description` TEXT DEFAULT NULL COMMENT 'Category Description',
  `parent_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'Parent Entity ID',
  `level` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Category Level',
  `is_leaf` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Is Leaf Category',
  `is_active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Is Active',
  `sort_order` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sort Order',
  `language` VARCHAR(5) NOT NULL DEFAULT 'pl' COMMENT 'Language Code',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Created At',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Updated At',
  `synced_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Last Sync with API',
  PRIMARY KEY (`entity_id`),
  UNIQUE KEY `UNQ_CATEGORY_LANG` (`inpost_category_id`, `language`),
  INDEX `IDX_INPOST_PARENT_ID` (`inpost_parent_id`),
  INDEX `IDX_PARENT_ID` (`parent_id`),
  INDEX `IDX_LEVEL` (`level`),
  INDEX `IDX_IS_LEAF` (`is_leaf`),
  INDEX `IDX_IS_ACTIVE` (`is_active`),
  INDEX `IDX_LANGUAGE` (`language`),
  INDEX `IDX_ACTIVE_LEVEL` (`is_active`, `level`),
  INDEX `IDX_ACTIVE_LEAF` (`is_active`, `is_leaf`),
  CONSTRAINT `FK_INPOST_CATEGORY_MAPPING_PARENT_ID`
    FOREIGN KEY (`parent_id`)
    REFERENCES `PREFIX_inpost_category_mapping` (`entity_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Category Tree (Auto-synced)';

-- =============================================================================
-- ATTRIBUTE TABLE (no dependencies, referenced by offer_attributes)
-- =============================================================================

-- Global InPost attributes (synced from API)
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_attribute` (
  `attribute_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Attribute ID',
  `inpost_attribute_id` VARCHAR(36) NOT NULL COMMENT 'InPost API Attribute ID (UUID)',
  `name` VARCHAR(255) NOT NULL COMMENT 'Attribute Name',
  `type` VARCHAR(64) DEFAULT NULL COMMENT 'Attribute Type',
  `lang` VARCHAR(10) DEFAULT NULL COMMENT 'Language code',
  `expected_value` VARCHAR(64) DEFAULT NULL COMMENT 'Expected value type',
  `description` TEXT DEFAULT NULL COMMENT 'Attribute description',
  `required` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Is Required',
  `is_active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Is Active',
  `synced_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Last Sync with API',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Created At',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Updated At',
  PRIMARY KEY (`attribute_id`),
  UNIQUE KEY `UNQ_ATTRIBUTE_ID_LANG` (`inpost_attribute_id`, `lang`),
  INDEX `IDX_INPOST_ATTRIBUTE_ID` (`inpost_attribute_id`),
  INDEX `IDX_TYPE` (`type`),
  INDEX `IDX_LANG` (`lang`),
  INDEX `IDX_IS_ACTIVE` (`is_active`),
  INDEX `IDX_SYNCED_AT` (`synced_at`),
  INDEX `IDX_ACTIVE_LANG` (`is_active`, `lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Global InPost Attributes (Auto-synced)';

-- =============================================================================
-- OFFER CHILD TABLES (depend on offer and/or attribute)
-- =============================================================================

-- Offer product details
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_offer_product` (
  `offer_product_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `offer_id` INT(10) UNSIGNED NOT NULL COMMENT 'Offer ID',
  `product_id` INT(10) UNSIGNED NOT NULL COMMENT 'PrestaShop Product ID',
  `name` VARCHAR(255) NOT NULL COMMENT 'Product Name',
  `description` TEXT DEFAULT NULL COMMENT 'Product Description',
  `brand` VARCHAR(128) DEFAULT NULL COMMENT 'Brand',
  `model` VARCHAR(128) DEFAULT NULL COMMENT 'Model',
  `sku` VARCHAR(64) NOT NULL COMMENT 'SKU',
  `ean` VARCHAR(13) DEFAULT NULL COMMENT 'EAN-8/UPC-A/EAN-13 (optional when brand + MPN provided)',
  `super_model` VARCHAR(100) DEFAULT NULL COMMENT 'Super Model',
  `manufacturer_product_number` VARCHAR(100) DEFAULT NULL COMMENT 'Manufacturer Product Number',
  `dimension_width` INT DEFAULT NULL COMMENT 'Dimension Width in mm',
  `dimension_height` INT DEFAULT NULL COMMENT 'Dimension Height in mm',
  `dimension_length` INT DEFAULT NULL COMMENT 'Dimension Length in mm',
  `dimension_weight` INT DEFAULT NULL COMMENT 'Dimension Weight in grams',
  `category_id` VARCHAR(64) DEFAULT NULL COMMENT 'Category ID',
  `inpost_category_id` VARCHAR(255) DEFAULT NULL COMMENT 'Selected InPost Category ID for this offer',
  `category_mapping_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'Category Mapping ID (FK to inpost_category_mapping)',
  `created_at` DATETIME NOT NULL COMMENT 'Created At',
  `updated_at` DATETIME NOT NULL COMMENT 'Updated At',
  PRIMARY KEY (`offer_product_id`),
  INDEX `IDX_OFFER_ID` (`offer_id`),
  INDEX `IDX_PRODUCT_ID` (`product_id`),
  INDEX `IDX_EAN` (`ean`),
  CONSTRAINT `FK_INPOST_OFFER_PRODUCT_OFFER_ID`
    FOREIGN KEY (`offer_id`)
    REFERENCES `PREFIX_inpost_offer` (`offer_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Offer Products';

-- Offer attributes (depends on offer AND attribute)
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_offer_attributes` (
  `offer_attribute_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Offer Attribute ID',
  `category_mapping_id` VARCHAR(255) NULL COMMENT 'Category Mapping ID',
  `inpost_attribute_id` VARCHAR(36) NULL COMMENT 'InPost Attribute ID',
  `offer_id` INT(10) UNSIGNED NOT NULL COMMENT 'Offer ID',
  `attribute_id` INT(10) UNSIGNED NOT NULL COMMENT 'Attribute ID',
  `value` TEXT NOT NULL COMMENT 'Attribute value(s) as JSON array',
  `value_source` VARCHAR(16) NOT NULL DEFAULT 'manual' COMMENT 'Where the value came from: ps (mapped PrestaShop feature/attribute), hint, manual',
  `created_at` DATETIME NOT NULL COMMENT 'Created At',
  `updated_at` DATETIME NOT NULL COMMENT 'Updated At',
  PRIMARY KEY (`offer_attribute_id`),
  INDEX `IDX_OFFER_ID` (`offer_id`),
  INDEX `IDX_ATTRIBUTE_ID` (`attribute_id`),
  INDEX `IDX_CATEGORY_MAPPING_ID` (`category_mapping_id`),
  INDEX `IDX_INPOST_ATTRIBUTE_ID` (`inpost_attribute_id`),
  UNIQUE INDEX `UNQ_OFFER_CATEGORY_ATTRIBUTE` (`offer_id`, `category_mapping_id`, `inpost_attribute_id`),
  CONSTRAINT `FK_INPOST_OFFER_ATTR_OFFER_ID`
    FOREIGN KEY (`offer_id`)
    REFERENCES `PREFIX_inpost_offer` (`offer_id`)
    ON DELETE CASCADE,
  CONSTRAINT `FK_INPOST_OFFER_ATTR_ATTRIBUTE_ID`
    FOREIGN KEY (`attribute_id`)
    REFERENCES `PREFIX_inpost_attribute` (`attribute_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Offer Attributes';

-- Offer stock information
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_offer_stock` (
  `offer_stock_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `offer_id` INT(10) UNSIGNED NOT NULL COMMENT 'Offer ID',
  `quantity` INT(11) NOT NULL DEFAULT 0 COMMENT 'Stock Quantity',
  `unit` VARCHAR(50) DEFAULT NULL COMMENT 'Stock Unit',
  `availability` VARCHAR(20) NOT NULL DEFAULT 'available' COMMENT 'Availability Status',
  `created_at` DATETIME NOT NULL COMMENT 'Created At',
  `updated_at` DATETIME NOT NULL COMMENT 'Updated At',
  PRIMARY KEY (`offer_stock_id`),
  INDEX `IDX_OFFER_ID` (`offer_id`),
  CONSTRAINT `FK_INPOST_OFFER_STOCK_OFFER_ID`
    FOREIGN KEY (`offer_id`)
    REFERENCES `PREFIX_inpost_offer` (`offer_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Offer Stock';

-- Offer price information
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_offer_price` (
  `offer_price_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `offer_id` INT(10) UNSIGNED NOT NULL COMMENT 'Offer ID',
  `price` DECIMAL(12,4) NOT NULL COMMENT 'Price Amount',
  `currency` VARCHAR(3) NOT NULL DEFAULT 'PLN' COMMENT 'Price Currency',
  `tax_rate` DECIMAL(5,2) DEFAULT NULL COMMENT 'Tax Rate',
  `price_mode` VARCHAR(16) NOT NULL DEFAULT 'auto' COMMENT 'auto = from the shop, manual = typed in',
  `manual_price` DECIMAL(12,4) DEFAULT NULL COMMENT 'Manual gross price',
  `shop_price` DECIMAL(12,4) DEFAULT NULL COMMENT 'Last gross price of the shop',
  `created_at` DATETIME NOT NULL COMMENT 'Created At',
  `updated_at` DATETIME NOT NULL COMMENT 'Updated At',
  PRIMARY KEY (`offer_price_id`),
  INDEX `IDX_OFFER_ID` (`offer_id`),
  CONSTRAINT `FK_INPOST_OFFER_PRICE_OFFER_ID`
    FOREIGN KEY (`offer_id`)
    REFERENCES `PREFIX_inpost_offer` (`offer_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Offer Price';

-- Offer manufacturer information
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_offer_manufacturer` (
  `offer_manufacturer_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `offer_id` INT(10) UNSIGNED NOT NULL COMMENT 'Offer ID',
  `name` VARCHAR(128) DEFAULT NULL COMMENT 'Manufacturer Name',
  `address` VARCHAR(255) DEFAULT NULL COMMENT 'Full Address (legacy)',
  `website` VARCHAR(255) DEFAULT NULL COMMENT 'Website',
  `address_street` VARCHAR(255) DEFAULT NULL COMMENT 'Street Address',
  `address_building_number` VARCHAR(50) DEFAULT NULL COMMENT 'Building Number',
  `address_apartment_number` VARCHAR(50) DEFAULT NULL COMMENT 'Apartment Number',
  `address_city` VARCHAR(255) DEFAULT NULL COMMENT 'City',
  `address_postal_code` VARCHAR(20) DEFAULT NULL COMMENT 'Postal Code',
  `address_country_code` CHAR(2) DEFAULT NULL COMMENT 'Country Code',
  `email` VARCHAR(255) DEFAULT NULL COMMENT 'Email',
  `phone` VARCHAR(50) DEFAULT NULL COMMENT 'Phone',
  `created_at` DATETIME NOT NULL COMMENT 'Created At',
  `updated_at` DATETIME NOT NULL COMMENT 'Updated At',
  PRIMARY KEY (`offer_manufacturer_id`),
  INDEX `IDX_OFFER_ID` (`offer_id`),
  CONSTRAINT `FK_INPOST_OFFER_MANUFACTURER_OFFER_ID`
    FOREIGN KEY (`offer_id`)
    REFERENCES `PREFIX_inpost_offer` (`offer_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Offer Manufacturer';

-- Offer GPSR compliance data
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_offer_gpsr` (
  `offer_gpsr_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'GPSR Entity ID',
  `offer_id` INT(10) UNSIGNED NOT NULL COMMENT 'Offer ID (FK to inpost_offer)',
  `batch_number` VARCHAR(500) DEFAULT NULL COMMENT 'GPSR Batch Number',
  `ce_marking` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'GPSR CE Marking (boolean)',
  `safety_information` TEXT DEFAULT NULL COMMENT 'GPSR Safety Information',
  `does_not_require_info` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'GPSR Does Not Require Info (boolean)',
  `created_at` DATETIME NOT NULL COMMENT 'Created At',
  `updated_at` DATETIME NOT NULL COMMENT 'Updated At',
  PRIMARY KEY (`offer_gpsr_id`),
  UNIQUE KEY `UNQ_OFFER_ID` (`offer_id`),
  CONSTRAINT `FK_INPOST_OFFER_GPSR_OFFER_ID`
    FOREIGN KEY (`offer_id`)
    REFERENCES `PREFIX_inpost_offer` (`offer_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Offer GPSR Compliance Data';

-- Offer manuals/documents
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_offer_manual` (
  `entity_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `offer_id` INT(10) UNSIGNED NOT NULL COMMENT 'Offer ID',
  `title` VARCHAR(255) DEFAULT NULL COMMENT 'Manual Title',
  `url` TEXT DEFAULT NULL COMMENT 'Manual URL',
  PRIMARY KEY (`entity_id`),
  INDEX `IDX_OFFER_ID` (`offer_id`),
  CONSTRAINT `FK_INPOST_OFFER_MANUAL_OFFER_ID`
    FOREIGN KEY (`offer_id`)
    REFERENCES `PREFIX_inpost_offer` (`offer_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Offer Manuals';

-- Document attachments of offers (GOKINT-1099): manuals, energy labels, safety
-- information... uploaded to InPost via /offers/{id}/attachments
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_offer_attachment` (
  `attachment_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `offer_id` INT(10) UNSIGNED NOT NULL COMMENT 'Offer ID',
  `attachment_type` VARCHAR(40) NOT NULL COMMENT 'InPost AttachmentType (MANUAL, ENERGY_LABEL...)',
  `source` VARCHAR(16) NOT NULL COMMENT 'upload | ps_attachment',
  `id_ps_attachment` INT(10) UNSIGNED DEFAULT NULL COMMENT 'PrestaShop attachment (source ps_attachment)',
  `local_file` VARCHAR(255) DEFAULT NULL COMMENT 'File name in download/inpostvonhalsky (source upload)',
  `file_name` VARCHAR(255) NOT NULL COMMENT 'File name shown in InPost',
  `mime` VARCHAR(128) NOT NULL COMMENT 'MIME type',
  `file_size` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Size in bytes',
  `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING | UPLOADING | UPLOADED | ERROR | DELETING',
  `inpost_offer_id` VARCHAR(64) DEFAULT NULL COMMENT 'InPost offer the file was sent to',
  `inpost_attachment_id` VARCHAR(64) DEFAULT NULL COMMENT 'InPost attachment ID',
  `command_id` VARCHAR(64) DEFAULT NULL COMMENT 'Async upload command ID',
  `error` TEXT DEFAULT NULL COMMENT 'Last error',
  `retry_count` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Failed transport attempts',
  `created_at` DATETIME NOT NULL COMMENT 'Created At',
  `updated_at` DATETIME NOT NULL COMMENT 'Updated At',
  PRIMARY KEY (`attachment_id`),
  INDEX `IDX_ATTACHMENT_OFFER_ID` (`offer_id`),
  INDEX `IDX_ATTACHMENT_STATUS` (`status`),
  CONSTRAINT `FK_INPOST_OFFER_ATTACHMENT_OFFER_ID`
    FOREIGN KEY (`offer_id`)
    REFERENCES `PREFIX_inpost_offer` (`offer_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Offer Document Attachments';

-- =============================================================================
-- EVENT TABLE (no foreign keys)
-- =============================================================================

-- Offer events
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_offer_event` (
  `entity_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `inpost_offer_event_id` VARCHAR(36) DEFAULT NULL COMMENT 'InPost API Event ID (UUID)',
  `offer_event_type` VARCHAR(32) NOT NULL COMMENT 'Event Type',
  `occured_at` DATETIME NOT NULL COMMENT 'Event Occurred At',
  `inpost_offer_id` VARCHAR(255) NOT NULL COMMENT 'InPost API Offer ID',
  `prestashop_offer_id` VARCHAR(255) DEFAULT NULL COMMENT 'PrestaShop Offer ID',
  PRIMARY KEY (`entity_id`),
  UNIQUE KEY `UQ_INPOST_OFFER_EVENT_ID` (`inpost_offer_event_id`),
  INDEX `IDX_PRESTASHOP_OFFER_ID` (`prestashop_offer_id`),
  INDEX `IDX_INPOST_OFFER_ID` (`inpost_offer_id`),
  INDEX `IDX_OFFER_EVENT_TYPE` (`offer_event_type`),
  INDEX `IDX_OCCURRED_AT` (`occured_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Offer Events';

-- =============================================================================
-- CATEGORY CHILD TABLES (depend on category_mapping and/or attribute)
-- =============================================================================

-- PrestaShop to InPost category links (manual mappings)
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_category_link` (
  `link_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Link ID',
  `mapping_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK to inpost_category_mapping',
  `prestashop_category_id` INT(10) UNSIGNED NOT NULL COMMENT 'PrestaShop Category ID',
  `shop_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'PrestaShop Shop ID',
  PRIMARY KEY (`link_id`),
  UNIQUE KEY `UNQ_MAPPING_CATEGORY_SHOP` (`mapping_id`, `prestashop_category_id`, `shop_id`),
  INDEX `IDX_SHOP_ID` (`shop_id`),
  INDEX `IDX_MAPPING_ID` (`mapping_id`),
  INDEX `IDX_PRESTASHOP_CATEGORY_ID` (`prestashop_category_id`),
  CONSTRAINT `FK_INPOST_CATLINK_MAPPING`
    FOREIGN KEY (`mapping_id`)
    REFERENCES `PREFIX_inpost_category_mapping` (`entity_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PrestaShop to InPost Category Links (Manual Mappings)';

-- Category-Attribute relationships (with category-specific metadata)
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_category_attribute` (
  `entity_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `inpost_category_mapping_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK to category_mapping',
  `inpost_attribute_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK to inpost_attribute',
  `prestashop_attribute_code` VARCHAR(255) DEFAULT NULL COMMENT 'Deprecated (1.1.41): mapped attribute group id - see ps_source_type/ps_source_id',
  `ps_source_type` VARCHAR(16) DEFAULT NULL COMMENT 'Value source in PrestaShop: feature or attribute_group',
  `ps_source_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'id_feature or id_attribute_group',
  `required` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Is Attribute Required for This Category (derived from API expectedValue)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Created At',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Updated At',
  PRIMARY KEY (`entity_id`),
  UNIQUE KEY `UNQ_CATEGORY_ATTRIBUTE` (`inpost_category_mapping_id`, `inpost_attribute_id`),
  INDEX `IDX_CATEGORY_ID` (`inpost_category_mapping_id`),
  INDEX `IDX_ATTRIBUTE_ID` (`inpost_attribute_id`),
  INDEX `IDX_REQUIRED` (`required`),
  CONSTRAINT `FK_INPOST_CATATTR_CATEGORY`
    FOREIGN KEY (`inpost_category_mapping_id`)
    REFERENCES `PREFIX_inpost_category_mapping` (`entity_id`)
    ON DELETE CASCADE,
  CONSTRAINT `FK_INPOST_CATATTR_ATTRIBUTE`
    FOREIGN KEY (`inpost_attribute_id`)
    REFERENCES `PREFIX_inpost_attribute` (`attribute_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Category-Attribute Mappings';

-- Attribute dictionary options (synced from API)
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_attribute_dictionary` (
  `dictionary_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Dictionary ID',
  `attribute_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK to inpost_attribute',
  `value_code` VARCHAR(128) NOT NULL COMMENT 'Value Code',
  `value_label` VARCHAR(255) NOT NULL COMMENT 'Value Label',
  `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Option still selectable in InPost',
  `created_at` DATETIME NOT NULL COMMENT 'Created At',
  `updated_at` DATETIME NOT NULL COMMENT 'Updated At',
  PRIMARY KEY (`dictionary_id`),
  INDEX `IDX_ATTRIBUTE_ID` (`attribute_id`),
  INDEX `IDX_VALUE_CODE` (`value_code`),
  CONSTRAINT `FK_INPOST_ATTRDICT_ATTRIBUTE`
    FOREIGN KEY (`attribute_id`)
    REFERENCES `PREFIX_inpost_attribute` (`attribute_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Attribute Dictionary Options';

-- PrestaShop value -> InPost dictionary option (per InPost attribute, shared by all categories)
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_attribute_value_map` (
  `value_map_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `attribute_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK to inpost_attribute',
  `ps_source_type` VARCHAR(16) NOT NULL COMMENT 'feature or attribute_group',
  `ps_value_id` INT(10) UNSIGNED NOT NULL COMMENT 'id_feature_value or id_attribute',
  `dictionary_value_code` VARCHAR(128) NOT NULL COMMENT 'InPost dictionary option code',
  `created_at` DATETIME NOT NULL COMMENT 'Created At',
  `updated_at` DATETIME NOT NULL COMMENT 'Updated At',
  PRIMARY KEY (`value_map_id`),
  UNIQUE KEY `UNQ_ATTRIBUTE_PS_VALUE` (`attribute_id`, `ps_source_type`, `ps_value_id`),
  CONSTRAINT `FK_INPOST_VALUEMAP_ATTRIBUTE`
    FOREIGN KEY (`attribute_id`)
    REFERENCES `PREFIX_inpost_attribute` (`attribute_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PrestaShop value to InPost dictionary option mapping';

-- =============================================================================
-- ORGANIZATION TABLE (no dependencies)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `PREFIX_inpost_organization` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `organization_id` VARCHAR(255) NOT NULL COMMENT 'InPost Organization ID',
  `shop_id` INT(10) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'PrestaShop Shop ID',
  `name` VARCHAR(255) NOT NULL COMMENT 'Organization Name',
  `country` VARCHAR(50) DEFAULT NULL COMMENT 'Country Code',
  `currency` VARCHAR(3) DEFAULT NULL COMMENT 'Currency Code',
  `type` VARCHAR(100) DEFAULT NULL COMMENT 'Organization Type',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Created At',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Updated At',
  `cached_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Last Cache Refresh Time',
  `is_active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Is Active',
  `last_offer_event_id` VARCHAR(64) DEFAULT NULL COMMENT 'Last synced offer event ID (cursor)',
  `last_offer_event_sync_at` DATETIME DEFAULT NULL COMMENT 'Last offer event sync timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNQ_ORGANIZATION_ID` (`organization_id`),
  INDEX `IDX_SHOP_ID` (`shop_id`),
  INDEX `IDX_NAME` (`name`),
  INDEX `IDX_IS_ACTIVE` (`is_active`),
  INDEX `IDX_CACHED_AT` (`cached_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Organization Metadata';

-- =============================================================================
-- ORDER TABLE (no dependencies)
-- =============================================================================

-- InPost orders synced from API
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_order` (
  `id_inpost_order` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Entity ID',
  `id_order` INT(10) UNSIGNED DEFAULT NULL COMMENT 'PrestaShop Order ID',
  `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'PrestaShop Shop ID',
  `inpost_order_id` VARCHAR(64) NOT NULL COMMENT 'InPost Order ID (UUID)',
  `status` VARCHAR(50) NOT NULL DEFAULT 'new' COMMENT 'Order Status',
  `point_name` VARCHAR(255) DEFAULT NULL COMMENT 'Pickup Point Name',
  `point_address` TEXT DEFAULT NULL COMMENT 'Pickup Point Address',
  `sender_point_name` VARCHAR(255) DEFAULT NULL COMMENT 'Sender Point Name',
  `receiver_email` VARCHAR(255) DEFAULT NULL COMMENT 'Receiver Email',
  `receiver_phone` VARCHAR(50) DEFAULT NULL COMMENT 'Receiver Phone',
  `shipment_data` TEXT DEFAULT NULL COMMENT 'Full Shipment Data JSON',
  `total_items_count` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total Items Count',
  `total` DECIMAL(20,6) NOT NULL DEFAULT 0.000000 COMMENT 'Order Total Amount',
  `date_add` DATETIME NOT NULL COMMENT 'Created At',
  `date_upd` DATETIME NOT NULL COMMENT 'Updated At',
  PRIMARY KEY (`id_inpost_order`),
  UNIQUE KEY `UNQ_INPOST_ORDER_ID` (`inpost_order_id`),
  INDEX `IDX_ID_ORDER` (`id_order`),
  INDEX `IDX_ID_SHOP` (`id_shop`),
  INDEX `IDX_STATUS` (`status`),
  INDEX `IDX_DATE_ADD` (`date_add`),
  INDEX `IDX_DATE_UPD` (`date_upd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Orders';

-- GOKINT-927: Product hint (productization) cache
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_product_hint_cache` (
  `hint_cache_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `cache_key` VARCHAR(64) NOT NULL COMMENT 'sha256 of type|query|language',
  `query_type` VARCHAR(16) NOT NULL COMMENT 'ean | name',
  `query_value` VARCHAR(255) NOT NULL COMMENT 'Normalized query value',
  `language` VARCHAR(5) NOT NULL DEFAULT 'pl' COMMENT 'Language code',
  `payload` LONGTEXT NULL COMMENT 'JSON array of normalized hints, NULL = negative cache',
  `is_negative` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '1 = cached miss',
  `expires_at` DATETIME NOT NULL COMMENT 'Cache expiry timestamp',
  `created_at` DATETIME NOT NULL COMMENT 'Created At',
  PRIMARY KEY (`hint_cache_id`),
  UNIQUE KEY `UNQ_HINT_CACHE_KEY` (`cache_key`),
  INDEX `IDX_HINT_EXPIRES_AT` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost Product Hint Cache';

-- Status panel: journal of the module's warnings and errors (30-day retention)
CREATE TABLE IF NOT EXISTS `PREFIX_inpost_log_journal` (
  `journal_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `level` VARCHAR(10) NOT NULL COMMENT 'warning | error | critical | alert | emergency',
  `fingerprint` CHAR(40) NOT NULL COMMENT 'sha1 of level + message without ids, groups repeats',
  `message` VARCHAR(255) NOT NULL,
  `context` TEXT NULL COMMENT 'JSON context, secrets masked, truncated',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`journal_id`),
  INDEX `IDX_LOG_JOURNAL_CREATED_AT` (`created_at`),
  INDEX `IDX_LOG_JOURNAL_FINGERPRINT` (`fingerprint`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='InPost status panel error journal';
