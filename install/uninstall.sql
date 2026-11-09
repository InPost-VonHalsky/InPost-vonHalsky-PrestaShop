-- InPost von Halsky PrestaShop Module - Uninstall Script
-- Version: 1.1.1
-- Description: Drops all InPost von Halsky database tables (17 tables)

-- Drop tables in reverse dependency order (child tables first)

-- =============================================================================
-- OFFER TABLES (Drop child tables first due to foreign keys)
-- =============================================================================
DROP TABLE IF EXISTS `PREFIX_inpost_offer_event`;
DROP TABLE IF EXISTS `PREFIX_inpost_offer_attachment`;
DROP TABLE IF EXISTS `PREFIX_inpost_offer_manual`;
DROP TABLE IF EXISTS `PREFIX_inpost_offer_gpsr`;
DROP TABLE IF EXISTS `PREFIX_inpost_offer_manufacturer`;
DROP TABLE IF EXISTS `PREFIX_inpost_offer_price`;
DROP TABLE IF EXISTS `PREFIX_inpost_offer_stock`;
DROP TABLE IF EXISTS `PREFIX_inpost_offer_attributes`;
DROP TABLE IF EXISTS `PREFIX_inpost_offer_product`;
DROP TABLE IF EXISTS `PREFIX_inpost_offer`;

-- =============================================================================
-- ATTRIBUTE TABLES (Drop child tables first due to foreign keys)
-- =============================================================================
DROP TABLE IF EXISTS `PREFIX_inpost_attribute_value_map`;
DROP TABLE IF EXISTS `PREFIX_inpost_attribute_dictionary`;
DROP TABLE IF EXISTS `PREFIX_inpost_category_attribute`;
DROP TABLE IF EXISTS `PREFIX_inpost_attribute`;

-- =============================================================================
-- CATEGORY TABLES (Drop child tables first due to foreign keys)
-- =============================================================================
DROP TABLE IF EXISTS `PREFIX_inpost_category_link`;
DROP TABLE IF EXISTS `PREFIX_inpost_category_mapping`;

-- =============================================================================
-- ORDER TABLE
-- =============================================================================
DROP TABLE IF EXISTS `PREFIX_inpost_order`;

-- =============================================================================
-- ORGANIZATION TABLE
-- =============================================================================
DROP TABLE IF EXISTS `PREFIX_inpost_organization`;

-- =============================================================================
-- OAUTH2 AUTHENTICATION TABLE
-- =============================================================================
DROP TABLE IF EXISTS `PREFIX_inpost_pkce_params`;

-- Status panel error journal
DROP TABLE IF EXISTS `PREFIX_inpost_log_journal`;

-- =============================================================================
-- ADMIN TABS CLEANUP
-- =============================================================================
-- Delete tab translations first (FK dependency)
DELETE FROM `PREFIX_tab_lang` WHERE `id_tab` IN (
    SELECT `id_tab` FROM `PREFIX_tab` WHERE `module` = 'inpostvonhalsky'
);

-- Delete all module tabs (catches orphans from old versions)
DELETE FROM `PREFIX_tab` WHERE `module` = 'inpostvonhalsky';

-- =============================================================================
-- CONFIGURATION CLEANUP
-- =============================================================================
-- Remove any configuration entries (if added in future versions)
DELETE FROM `PREFIX_configuration` WHERE `name` LIKE 'INPOSTSA_%';
