<?php
/**
 * InPost von Halsky Module for PrestaShop 8
 *
 * @author    SII Poland
 * @copyright 2025 SII Poland
 * @license   Commercial
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Autoload Composer dependencies
$autoloadFile = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadFile)) {
    require_once $autoloadFile;
}

/**
 * The module is also a payment module: orders imported from InPost are recorded
 * with an "InPost" payment (BLIK, card, ...) instead of a shop payment method the
 * merchant may have disabled. It has no paymentOptions hook, so it is never
 * offered in the shop checkout.
 */
class Inpostvonhalsky extends PaymentModule
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->name = 'inpostvonhalsky';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'InPost';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => '8.99.99'
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('InPost von Halsky');
        $this->description = $this->l('Integration module for InPost von Halsky platform.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall InPost von Halsky? All configuration will be lost.');
    }

    /**
     * Get module local path for service configuration
     *
     * Required by PrestaShop 8 to load config/services.yml
     *
     * @return string Module directory path
     */
    public function getLocalPath()
    {
        return $this->local_path;
    }

    /**
     * Get admin tabs for module
     *
     * @return array
     */
    public function getTabs(): array
    {
        return [
            [
                'class_name' => 'AdminInpostVonHalsky',
                'visible' => true,
                'parent_class_name' => 'SELL',
                'name' => [
                    'en' => 'InPost von Halsky',
                    'pl' => 'InPost von Halsky',
                ],
                'wording' => 'InPost von Halsky',
                'wording_domain' => 'Modules.Inpostvonhalsky.Admin',
                'icon' => 'local_shipping',
            ],
            [
                'class_name' => 'AdminInpostStatus',
                'visible' => true,
                'parent_class_name' => 'AdminInpostVonHalsky',
                'route_name' => 'inpost_status',
                'name' => [
                    'en' => 'Status',
                    'pl' => 'Status',
                ],
                'wording' => 'Status',
                'wording_domain' => 'Modules.Inpostvonhalsky.Admin',
            ],
            [
                'class_name' => 'AdminInpostOffers',
                'visible' => true,
                'parent_class_name' => 'AdminInpostVonHalsky',
                'route_name' => 'inpost_offers_list',
                'name' => [
                    'en' => 'Offers',
                    'pl' => 'Oferty',
                ],
                'wording' => 'Offers',
                'wording_domain' => 'Modules.Inpostvonhalsky.Admin',
            ],
            [
                'class_name' => 'AdminInpostOrders',
                'visible' => true,
                'parent_class_name' => 'AdminInpostVonHalsky',
                'route_name' => 'admin_inpost_orders_index',
                'name' => [
                    'en' => 'Orders',
                    'pl' => 'Zamówienia',
                ],
                'wording' => 'Orders',
                'wording_domain' => 'Modules.Inpostvonhalsky.Admin',
            ],
            [
                'class_name' => 'AdminInpostCategoryMapping',
                'visible' => true,
                'parent_class_name' => 'AdminInpostVonHalsky',
                'route_name' => 'inpost_category_mapping_list',
                'name' => [
                    'en' => 'Category Mapping',
                    'pl' => 'Mapowanie kategorii',
                ],
                'wording' => 'Category Mapping',
                'wording_domain' => 'Modules.Inpostvonhalsky.Admin',
            ],
            [
                // Direct shortcut to this module's configuration page so it is
                // reachable from the menu without going through Modules > Configure.
                // Backed by a small legacy controller that redirects to
                // AdminModules&configure=inpostvonhalsky.
                'class_name' => 'AdminInpostConfiguration',
                'visible' => true,
                'parent_class_name' => 'AdminInpostVonHalsky',
                'name' => [
                    'en' => 'Configuration',
                    'pl' => 'Konfiguracja',
                ],
                'wording' => 'Configuration',
                'wording_domain' => 'Modules.Inpostvonhalsky.Admin',
            ],
        ];
    }

    /**
     * Install module
     *
     * @return bool
     */
    public function install()
    {
        if (!parent::install() ||
            !$this->registerHook('displayHeader') ||
            !$this->registerHook('displayBackOfficeHeader') ||
            // Product hooks
            !$this->registerHook('actionProductUpdate') ||
            // Stock hooks
            !$this->registerHook('actionUpdateQuantity') ||
            // Combination hooks (offers per combination)
            !$this->registerHook('actionObjectCombinationUpdateAfter') ||
            !$this->registerHook('actionObjectCombinationDeleteAfter') ||
            // Specific prices / catalog price rules (InPost price, GOKINT-1098)
            !$this->registerHook('actionObjectSpecificPriceAddAfter') ||
            !$this->registerHook('actionObjectSpecificPriceUpdateAfter') ||
            !$this->registerHook('actionObjectSpecificPriceDeleteAfter') ||
            !$this->registerHook('actionObjectSpecificPriceRuleAddAfter') ||
            !$this->registerHook('actionObjectSpecificPriceRuleUpdateAfter') ||
            !$this->registerHook('actionObjectSpecificPriceRuleDeleteAfter') ||
            // Image hooks (propagate catalog image changes to InPost)
            !$this->registerHook('actionObjectImageAddAfter') ||
            !$this->registerHook('actionObjectImageUpdateAfter') ||
            !$this->registerHook('actionObjectImageDeleteAfter') ||
            // Email hooks
            !$this->registerHook('actionEmailSendBefore') ||
            // Product grid bulk action (GOKINT-930)
            !$this->registerHook('actionProductGridDefinitionModifier') ||
            // Cron job hook (for PrestaShop CronJobsModule integration)
            !$this->registerHook('actionCronJob')
        ) {
            return false;
        }

        // Create configuration values
        Configuration::updateValue('INPOSTSA_ORGANIZATION_ID', '');
        Configuration::updateValue('INPOSTSA_CLIENT_ID', '');
        Configuration::updateValue('INPOSTSA_CLIENT_SECRET', '');
        Configuration::updateValue('INPOSTSA_API_URL', 'https://stage-api.inpost-group.com/inpsa');
        Configuration::updateValue('INPOSTSA_AUTH_URL', 'https://stage-account.inpost-group.com');
        Configuration::updateValue('INPOSTSA_TEST_MODE', false);

        // Token storage (managed by ApiTokenManager)
        Configuration::updateValue('INPOSTSA_ACCESS_TOKEN', '');
        Configuration::updateValue('INPOSTSA_ACCESS_TOKEN_EXPIRES_AT', 0);
        Configuration::updateValue('INPOSTSA_REFRESH_TOKEN', '');

        // Cron job settings
        Configuration::updateValue('INPOSTSA_CRON_SYNC_CATEGORIES_ENABLED', true);
        Configuration::updateValue('INPOSTSA_CRON_SYNC_CATEGORIES_SCHEDULE', '0 2 * * *');
        Configuration::updateValue('INPOSTSA_CRON_SYNC_CATEGORIES_LIMIT', 100);
        // GOKINT-929: orphan category cleanup kill switch
        Configuration::updateValue('INPOSTSA_CATEGORY_ORPHAN_CLEANUP_ENABLED', true);

        Configuration::updateValue('INPOSTSA_CRON_SYNC_OFFERS_ENABLED', true);
        Configuration::updateValue('INPOSTSA_CRON_SYNC_OFFERS_SCHEDULE', '*/10 * * * *');
        Configuration::updateValue('INPOSTSA_CRON_SYNC_OFFERS_LIMIT', 100);

        // GOKINT-928: Pending create-command sync cron + retry settings
        Configuration::updateValue('INPOSTSA_CRON_SYNC_COMMANDS_ENABLED', true);
        Configuration::updateValue('INPOSTSA_CRON_SYNC_COMMANDS_SCHEDULE', '*/3 * * * *');
        Configuration::updateValue('INPOSTSA_CRON_SYNC_COMMANDS_LIMIT', 50);
        Configuration::updateValue('INPOSTSA_CREATE_COMMAND_MAX_RETRIES', 3);

        // Offer events sync cron settings
        Configuration::updateValue('INPOSTSA_OFFER_EVENTS_CRON_ENABLED', true);
        Configuration::updateValue('INPOSTSA_OFFER_EVENTS_CRON_EXPRESSION', '*/20 * * * *');
        Configuration::updateValue('INPOSTSA_OFFER_EVENTS_CRON_LIMIT', 100);

        // Order sync cron settings
        Configuration::updateValue('INPOSTSA_ORDERS_CRON_ENABLED', true);
        Configuration::updateValue('INPOSTSA_ORDERS_CRON_EXPRESSION', '*/10 * * * *');
        Configuration::updateValue('INPOSTSA_ORDERS_CRON_LIMIT', 30);
        Configuration::updateValue('INPOSTSA_ORDERS_SYNC_RANGE', 'continuous');
        Configuration::updateValue('INPOSTSA_ORDERS_SYNC_RANGE_DAYS', 30);

        // Order events sync cron settings
        Configuration::updateValue('INPOSTSA_ORDER_EVENTS_CRON_ENABLED', true);
        Configuration::updateValue('INPOSTSA_ORDER_EVENTS_CRON_EXPRESSION', '*/15 * * * *');
        Configuration::updateValue('INPOSTSA_ORDER_EVENTS_CRON_LIMIT', 100);
        Configuration::updateValue('INPOSTSA_ORDER_EVENTS_CREATE_ORDERS', 1);



        // Offer defaults
        Configuration::updateValue('INPOSTSA_OFFER_DEFAULT_RETURN_POLICY', '');
        Configuration::updateValue('INPOSTSA_OFFER_DEFAULT_COMPLAINT_POLICY', '');
        Configuration::updateValue('INPOSTSA_OFFER_DEFAULT_DELIVERY_DAYS', '');
        foreach (\Inpost\VonHalsky\Service\Offer\OfferFieldMapping::all() as $field => $definition) {
            Configuration::updateValue(\Inpost\VonHalsky\Service\Offer\OfferFieldMapping::configKey($field), $definition['default']);
        }

        // GOKINT-926: Customer email suppression for InPost orders.
        // Master switch defaults OFF so an upgrade never silently swallows mail.
        Configuration::updateValue('INPOSTSA_DISABLE_CUSTOMER_EMAILS', false);
        Configuration::updateValue('INPOSTSA_EMAIL_TYPES_TO_DISABLE', 'order_confirmation,order_status_update,shipment,credit_slip');
        Configuration::updateValue('INPOSTSA_LOG_BLOCKED_EMAILS', true);

        // GOKINT-927: Productization from /hints
        Configuration::updateValue('INPOSTSA_PRODUCTIZATION_ENABLED', false);
        Configuration::updateValue('INPOSTSA_HINT_NAME_FALLBACK_ENABLED', true);
        Configuration::updateValue('INPOSTSA_HINT_CACHE_TTL', 86400);

        // GOKINT-1098: InPost price = shop price until configured
        Configuration::updateValue('INPOSTSA_PRICE_GROUP_ID', 0);
        Configuration::updateValue('INPOSTSA_PRICE_ADJUST_PERCENT', '0');
        Configuration::updateValue('INPOSTSA_PRICE_ROUNDING', 'none');

        // GOKINT-930: Bulk offer creation from the product grid
        Configuration::updateValue('INPOSTSA_BULK_LIST_ENABLED', true);
        Configuration::updateValue('INPOSTSA_BULK_CHUNK_SIZE', 25);

        // Image test override (for shops unreachable by InPost, e.g. behind a VPN)
        Configuration::updateValue('INPOSTSA_IMAGE_TEST_MODE_ENABLED', false);
        Configuration::updateValue('INPOSTSA_IMAGE_TEST_URL', '');

        // InPost ShipX (inpostshipping) integration
        Configuration::updateValue('INPOSTSA_SHIPX_INTEGRATION_ENABLED', false);
        Configuration::updateValue('INPOSTSA_SHIPX_AUTO_SHIPMENT_ENABLED', false);
        Configuration::updateValue('INPOSTSA_SHIPX_CARRIER_LOCKER', '');
        Configuration::updateValue('INPOSTSA_SHIPX_CARRIER_COURIER', '');
        Configuration::updateValue('INPOSTSA_SHIPX_DEFAULT_PARCEL_TEMPLATE', 'small');

        // Global GPSR + Manufacturer defaults (fallback for empty offer fields)
        Configuration::updateValue('INPOSTSA_GPSR_DOES_NOT_REQUIRE', true);
        Configuration::updateValue('INPOSTSA_GPSR_BATCH_NUMBER', '');
        Configuration::updateValue('INPOSTSA_GPSR_CE_MARKING', false);
        Configuration::updateValue('INPOSTSA_GPSR_SAFETY_INFORMATION', '');
        Configuration::updateValue('INPOSTSA_MFR_NAME', '');
        Configuration::updateValue('INPOSTSA_MFR_EMAIL', '');
        Configuration::updateValue('INPOSTSA_MFR_PHONE', '');
        Configuration::updateValue('INPOSTSA_MFR_STREET', '');
        Configuration::updateValue('INPOSTSA_MFR_BUILDING_NUMBER', '');
        Configuration::updateValue('INPOSTSA_MFR_APARTMENT_NUMBER', '');
        Configuration::updateValue('INPOSTSA_MFR_CITY', '');
        Configuration::updateValue('INPOSTSA_MFR_POSTAL_CODE', '');
        Configuration::updateValue('INPOSTSA_MFR_COUNTRY_CODE', '');

        // Execute install.sql (legacy schema file)
        if (!$this->installSQL()) {
            return false;
        }

        // Migrate: Add total_items_count column if it doesn't exist
        $this->migrateAddTotalItemsCountColumn();

        // Migrate: Add total column if it doesn't exist
        $this->migrateAddTotalColumn();

        // Deploy translation files to PrestaShop core translations directory
        $this->deployTranslations();

        // Note: Admin tabs are auto-registered by PrestaShop 8 from getTabs()
        // No manual installTabs() call needed

        return true;
    }

    /**
     * Execute SQL installation file
     *
     * @return bool
     */
    private function installSQL(): bool
    {
        $sql_file = dirname(__FILE__) . '/install/install.sql';
        if (!file_exists($sql_file)) {
            return true; // SQL file is optional
        }

        $sql = file_get_contents($sql_file);
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        
        // Remove comments
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql); // Remove /* */ comments
        $sql = preg_replace('/--.*$/m', '', $sql); // Remove -- comments
        $sql = preg_replace('/^\s*[\r\n]/m', '', $sql); // Remove empty lines
        
        $queries = array_filter(array_map('trim', explode(';', $sql)));

        foreach ($queries as $query) {
            if (empty($query) || strlen($query) < 10) {
                continue;
            }
            if (!Db::getInstance()->execute($query)) {
                $error = Db::getInstance()->getMsgError();
                $this->_errors[] = 'SQL Error: ' . $error . ' | Query: ' . substr($query, 0, 200);
                return false;
            }
        }

        return true;
    }

    /**
     * Migration: Add total_items_count column to ps_inpost_order table
     *
     * @return bool
     */
    private function migrateAddTotalItemsCountColumn(): bool
    {
        $tableName = _DB_PREFIX_ . 'inpost_order';
        
        // Check if column already exists
        $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . pSQL($tableName) . '` LIKE "total_items_count"');
        if (!empty($columns)) {
            return true; // Column already exists
        }

        // Add column
        $sql = 'ALTER TABLE `' . pSQL($tableName) . '` 
                ADD COLUMN `total_items_count` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT \'Total Items Count\' 
                AFTER `shipment_data`';
        
        if (!Db::getInstance()->execute($sql)) {
            $this->_errors[] = 'Failed to add total_items_count column: ' . Db::getInstance()->getMsgError();
            return false;
        }

        return true;
    }

    /**
     * Migration: Add total column to ps_inpost_order table
     *
     * @return bool
     */
    private function migrateAddTotalColumn(): bool
    {
        $tableName = _DB_PREFIX_ . 'inpost_order';
        
        // Check if column already exists
        $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . pSQL($tableName) . '` LIKE "total"');
        if (!empty($columns)) {
            return true; // Column already exists
        }

        // Add column
        $sql = 'ALTER TABLE `' . pSQL($tableName) . '` 
                ADD COLUMN `total` DECIMAL(20,6) NOT NULL DEFAULT 0.000000 COMMENT \'Order Total Amount\' 
                AFTER `total_items_count`';
        
        if (!Db::getInstance()->execute($sql)) {
            $this->_errors[] = 'Failed to add total column: ' . Db::getInstance()->getMsgError();
            return false;
        }

        return true;
    }

    /**
     * Deploy XLIFF translation files to PrestaShop core translations directory.
     * PrestaShop 8 reads translations from app/Resources/translations/{locale}/
     * or translations/{locale}/ for modules.
     */
    private function deployTranslations(): void
    {
        $moduleTranslationsDir = dirname(__FILE__) . '/translations';
        $psTranslationsDir = _PS_ROOT_DIR_ . '/translations';

        // Find all locale directories in module translations
        if (!is_dir($moduleTranslationsDir)) {
            return;
        }

        $locales = scandir($moduleTranslationsDir);
        foreach ($locales as $locale) {
            if ($locale === '.' || $locale === '..' || !is_dir($moduleTranslationsDir . '/' . $locale)) {
                continue;
            }

            // Create locale directory in PrestaShop if not exists
            $targetLocaleDir = $psTranslationsDir . '/' . $locale;
            if (!is_dir($targetLocaleDir)) {
                @mkdir($targetLocaleDir, 0755, true);
            }

            // Copy all XLIFF files
            $files = glob($moduleTranslationsDir . '/' . $locale . '/*.xlf');
            foreach ($files as $file) {
                $targetFile = $targetLocaleDir . '/' . basename($file);
                @copy($file, $targetFile);
            }
        }
    }

    /**
     * Uninstall module
     *
     * @return bool
     */
    public function uninstall()
    {
        // Delete admin tabs first (before parent::uninstall)
        foreach ($this->getTabs() as $tabData) {
            $tabId = Tab::getIdFromClassName($tabData['class_name']);
            if ($tabId) {
                $tab = new Tab($tabId);
                if (!$tab->delete()) {
                    $this->_errors[] = 'Failed to delete tab: ' . $tabData['class_name'];
                }
            }
        }

        // Delete configuration values
        Configuration::deleteByName('INPOSTSA_ORGANIZATION_ID');
        Configuration::deleteByName('INPOSTSA_CLIENT_ID');
        Configuration::deleteByName('INPOSTSA_CLIENT_SECRET');
        Configuration::deleteByName('INPOSTSA_API_URL');
        Configuration::deleteByName('INPOSTSA_AUTH_URL');
        Configuration::deleteByName('INPOSTSA_TEST_MODE');
        Configuration::deleteByName('INPOSTSA_ACCESS_TOKEN');
        Configuration::deleteByName('INPOSTSA_ACCESS_TOKEN_EXPIRES_AT');
        Configuration::deleteByName('INPOSTSA_REFRESH_TOKEN');

        // Cron job settings
        Configuration::deleteByName('INPOSTSA_CRON_SYNC_CATEGORIES_ENABLED');
        Configuration::deleteByName('INPOSTSA_CRON_SYNC_CATEGORIES_SCHEDULE');
        Configuration::deleteByName('INPOSTSA_CRON_SYNC_CATEGORIES_LIMIT');
        Configuration::deleteByName('INPOSTSA_CATEGORY_ORPHAN_CLEANUP_ENABLED');
        Configuration::deleteByName('INPOSTSA_CRON_SYNC_OFFERS_ENABLED');
        Configuration::deleteByName('INPOSTSA_CRON_SYNC_OFFERS_SCHEDULE');
        Configuration::deleteByName('INPOSTSA_CRON_SYNC_OFFERS_LIMIT');
        Configuration::deleteByName('INPOSTSA_CRON_SYNC_COMMANDS_ENABLED');
        Configuration::deleteByName('INPOSTSA_CRON_SYNC_COMMANDS_SCHEDULE');
        Configuration::deleteByName('INPOSTSA_CRON_SYNC_COMMANDS_LIMIT');
        Configuration::deleteByName('INPOSTSA_CREATE_COMMAND_MAX_RETRIES');
        Configuration::deleteByName('INPOSTSA_OFFER_EVENTS_CRON_ENABLED');
        Configuration::deleteByName('INPOSTSA_OFFER_EVENTS_CRON_EXPRESSION');
        Configuration::deleteByName('INPOSTSA_OFFER_EVENTS_CRON_LIMIT');

        // Offer defaults
        Configuration::deleteByName('INPOSTSA_OFFER_DEFAULT_RETURN_POLICY');
        Configuration::deleteByName('INPOSTSA_OFFER_DEFAULT_COMPLAINT_POLICY');
        Configuration::deleteByName('INPOSTSA_OFFER_DEFAULT_DELIVERY_DAYS');
        foreach (array_keys(\Inpost\VonHalsky\Service\Offer\OfferFieldMapping::all()) as $field) {
            Configuration::deleteByName(\Inpost\VonHalsky\Service\Offer\OfferFieldMapping::configKey($field));
        }
        Configuration::deleteByName('INPOSTSA_DISABLE_CUSTOMER_EMAILS');
        Configuration::deleteByName('INPOSTSA_EMAIL_TYPES_TO_DISABLE');
        Configuration::deleteByName('INPOSTSA_LOG_BLOCKED_EMAILS');
        Configuration::deleteByName('INPOSTSA_PRODUCTIZATION_ENABLED');
        Configuration::deleteByName('INPOSTSA_HINT_NAME_FALLBACK_ENABLED');
        Configuration::deleteByName('INPOSTSA_HINT_CACHE_TTL');
        Configuration::deleteByName('INPOSTSA_BULK_LIST_ENABLED');
        Configuration::deleteByName('INPOSTSA_BULK_CHUNK_SIZE');
        Configuration::deleteByName('INPOSTSA_IMAGE_TEST_MODE_ENABLED');
        Configuration::deleteByName('INPOSTSA_IMAGE_TEST_URL');
        Configuration::deleteByName('INPOSTSA_SHIPX_INTEGRATION_ENABLED');
        Configuration::deleteByName('INPOSTSA_SHIPX_AUTO_SHIPMENT_ENABLED');
        Configuration::deleteByName('INPOSTSA_SHIPX_CARRIER_LOCKER');
        Configuration::deleteByName('INPOSTSA_SHIPX_CARRIER_COURIER');
        Configuration::deleteByName('INPOSTSA_ORDER_STATE_PAID');
        foreach (['INPOSTSA_ORDERS_SYNC_RANGE', 'INPOSTSA_ORDERS_SYNC_RANGE_DAYS', 'INPOSTSA_ORDER_EVENTS_CREATE_ORDERS',
            'INPOSTSA_ORDERS_SYNC_LAST_COMPLETE', 'INPOSTSA_ORDERS_SYNC_LAST_FULL', 'INPOSTSA_ORDERS_RETRY_CURSOR',
            'INPOSTSA_ORDER_EVENTS_CURSOR', 'INPOSTSA_ORDER_CREATE_LAST_ERROR'] as $key) {
            Configuration::deleteByName($key);
        }
        Configuration::deleteByName('INPOSTSA_SHIPX_DEFAULT_PARCEL_TEMPLATE');
        Configuration::deleteByName('INPOSTSA_PAYMENT_MODULE');
        Configuration::deleteByName('INPOSTSA_GPSR_DOES_NOT_REQUIRE');
        Configuration::deleteByName('INPOSTSA_GPSR_BATCH_NUMBER');
        Configuration::deleteByName('INPOSTSA_GPSR_CE_MARKING');
        Configuration::deleteByName('INPOSTSA_GPSR_SAFETY_INFORMATION');
        Configuration::deleteByName('INPOSTSA_MFR_NAME');
        Configuration::deleteByName('INPOSTSA_MFR_EMAIL');
        Configuration::deleteByName('INPOSTSA_MFR_PHONE');
        Configuration::deleteByName('INPOSTSA_MFR_STREET');
        Configuration::deleteByName('INPOSTSA_MFR_BUILDING_NUMBER');
        Configuration::deleteByName('INPOSTSA_MFR_APARTMENT_NUMBER');
        Configuration::deleteByName('INPOSTSA_MFR_CITY');
        Configuration::deleteByName('INPOSTSA_MFR_POSTAL_CODE');
        Configuration::deleteByName('INPOSTSA_MFR_COUNTRY_CODE');

        // Execute uninstall.sql to drop database tables
        if (!$this->uninstallSQL()) {
            return false;
        }

        // Files uploaded as offer attachments (GOKINT-1099); their table is gone
        \Inpost\VonHalsky\Service\Offer\OfferAttachmentStorage::removeDirectory();

        return parent::uninstall();
    }

    /**
     * Execute SQL uninstallation file
     *
     * @return bool
     */
    private function uninstallSQL(): bool
    {
        $sql_file = dirname(__FILE__) . '/install/uninstall.sql';
        if (!file_exists($sql_file)) {
            return true; // SQL file is optional
        }

        $sql = file_get_contents($sql_file);
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);
        
        // Remove comments
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql); // Remove /* */ comments
        $sql = preg_replace('/--.*$/m', '', $sql); // Remove -- comments
        $sql = preg_replace('/^\s*[\r\n]/m', '', $sql); // Remove empty lines
        
        $queries = array_filter(array_map('trim', explode(';', $sql)));

        foreach ($queries as $query) {
            if (empty($query) || strlen($query) < 10) {
                continue;
            }
            if (!Db::getInstance()->execute($query)) {
                // Silently ignore errors during uninstallation (tables might not exist)
                continue;
            }
        }

        return true;
    }

    /**
     * Load module configuration page
     *
     * @return string
     */
    public function getContent()
    {
        $output = '';

        // Error forwarded by the front OAuth2 callback controller
        if ($oauthError = Tools::getValue('oauth_error')) {
            $output .= $this->displayError($this->l('OAuth2 authorization failed:') . ' ' . Tools::safeOutput($oauthError));
        }

        // Handle OAuth2 connect action
        if (Tools::isSubmit('connect_oauth2')) {
            $output .= $this->handleOAuth2Connect();
        }

        // Handle OAuth2 disconnect action
        if (Tools::isSubmit('disconnect_oauth2')) {
            $output .= $this->handleOAuth2Disconnect();
        }

        // Handle test connection action
        if (Tools::isSubmit('test_connection')) {
            $output .= $this->handleTestConnection();
        }

        // Handle refresh organizations action
        if (Tools::isSubmit('refreshOrganizations')) {
            try {
                $organizationService = $this->getOrganizationService();
                $synced = $organizationService->syncAllOrganizations();

                if ($synced > 0) {
                    $output .= $this->displayConfirmation(
                        sprintf($this->l('Successfully synced %d organization(s) from API.'), $synced)
                    );
                } else {
                    $output .= $this->displayWarning(
                        $this->l('No organizations found. Please check your connection and try again.')
                    );
                }
            } catch (Exception $e) {
                $output .= $this->displayError(
                    $this->l('Error refreshing organizations: ') . $e->getMessage()
                );
            }
        }

        // Handle form submission
        if (Tools::isSubmit('submit' . $this->name)) {
            $this->postProcess();
            $output .= $this->displayConfirmation($this->l('Settings updated successfully.'));
            $output .= $this->savePriceSettings();
        }

        return $this->closeHelpSidebarScript() . $output . $this->renderForm();
    }

    /**
     * PrestaShop's help sidebar ("User Guide") reopens on every page once opened
     * (localStorage helpOpen, admin-dev/themes/default/js/help.js), but this page
     * has no Help button to close it. Runs inline, before help.js's ready handler.
     */
    private function closeHelpSidebarScript(): string
    {
        return '<script>(function () {'
            . 'try { (window.localStorage || window.sessionStorage).setItem("helpOpen", "false"); } catch (e) {}'
            . 'document.addEventListener("DOMContentLoaded", function () {'
            . 'var main = document.getElementById("main"); if (main) { main.classList.remove("helpOpen"); }'
            . 'var help = document.getElementById("help-container"); if (help) { help.parentNode.removeChild(help); }'
            . '});'
            . '})();</script>';
    }

    /**
     * Process configuration form
     */
    protected function postProcess()
    {
        Configuration::updateValue('INPOSTSA_ORGANIZATION_ID', Tools::getValue('INPOSTSA_ORGANIZATION_ID'));
        Configuration::updateValue('INPOSTSA_CLIENT_ID', Tools::getValue('INPOSTSA_CLIENT_ID'));
        
        // Only update client secret if a new value is provided
        $newSecret = trim((string)Tools::getValue('INPOSTSA_CLIENT_SECRET'));
        if ($newSecret !== '') {
            Configuration::updateValue('INPOSTSA_CLIENT_SECRET', $newSecret);
        }

        Configuration::updateValue('INPOSTSA_API_URL', Tools::getValue('INPOSTSA_API_URL'));
        Configuration::updateValue('INPOSTSA_AUTH_URL', Tools::getValue('INPOSTSA_AUTH_URL'));
        Configuration::updateValue('INPOSTSA_USER_ORGS_ENDPOINT', Tools::getValue('INPOSTSA_USER_ORGS_ENDPOINT'));
        Configuration::updateValue('INPOSTSA_FALLBACK_PRODUCT_ID', Tools::getValue('INPOSTSA_FALLBACK_PRODUCT_ID'));
        Configuration::updateValue('INPOSTSA_DEBUG_API_LOGGING', (bool)Tools::getValue('INPOSTSA_DEBUG_API_LOGGING'));

        // GOKINT-926: Customer email suppression
        Configuration::updateValue('INPOSTSA_DISABLE_CUSTOMER_EMAILS', (bool)Tools::getValue('INPOSTSA_DISABLE_CUSTOMER_EMAILS'));
        Configuration::updateValue('INPOSTSA_LOG_BLOCKED_EMAILS', (bool)Tools::getValue('INPOSTSA_LOG_BLOCKED_EMAILS'));
        $emailTypes = [];
        foreach (array_keys(self::EMAIL_TYPE_TEMPLATES) as $emailType) {
            if (Tools::getValue('INPOSTSA_EMAIL_TYPES_TO_DISABLE_' . $emailType)) {
                $emailTypes[] = $emailType;
            }
        }
        Configuration::updateValue('INPOSTSA_EMAIL_TYPES_TO_DISABLE', implode(',', $emailTypes));

        // GOKINT-927: Productization
        Configuration::updateValue('INPOSTSA_PRODUCTIZATION_ENABLED', (bool)Tools::getValue('INPOSTSA_PRODUCTIZATION_ENABLED'));
        Configuration::updateValue('INPOSTSA_HINT_NAME_FALLBACK_ENABLED', (bool)Tools::getValue('INPOSTSA_HINT_NAME_FALLBACK_ENABLED'));
        Configuration::updateValue('INPOSTSA_HINT_CACHE_TTL', (int)Tools::getValue('INPOSTSA_HINT_CACHE_TTL'));

        // GOKINT-930: Bulk listing
        Configuration::updateValue('INPOSTSA_BULK_LIST_ENABLED', (bool)Tools::getValue('INPOSTSA_BULK_LIST_ENABLED'));
        Configuration::updateValue('INPOSTSA_BULK_CHUNK_SIZE', (int)Tools::getValue('INPOSTSA_BULK_CHUNK_SIZE'));

        // Image test override
        Configuration::updateValue('INPOSTSA_IMAGE_TEST_MODE_ENABLED', (bool)Tools::getValue('INPOSTSA_IMAGE_TEST_MODE_ENABLED'));
        Configuration::updateValue('INPOSTSA_IMAGE_TEST_URL', trim((string)Tools::getValue('INPOSTSA_IMAGE_TEST_URL')));

        // InPost ShipX integration
        Configuration::updateValue('INPOSTSA_SHIPX_INTEGRATION_ENABLED', (bool)Tools::getValue('INPOSTSA_SHIPX_INTEGRATION_ENABLED'));
        Configuration::updateValue('INPOSTSA_SHIPX_AUTO_SHIPMENT_ENABLED', (bool)Tools::getValue('INPOSTSA_SHIPX_AUTO_SHIPMENT_ENABLED'));
        Configuration::updateValue('INPOSTSA_SHIPX_CARRIER_LOCKER', (int)Tools::getValue('INPOSTSA_SHIPX_CARRIER_LOCKER'));
        Configuration::updateValue('INPOSTSA_SHIPX_CARRIER_COURIER', (int)Tools::getValue('INPOSTSA_SHIPX_CARRIER_COURIER'));
        Configuration::updateValue('INPOSTSA_ORDER_STATE_PAID', (int)Tools::getValue('INPOSTSA_ORDER_STATE_PAID'));
        Configuration::updateValue('INPOSTSA_SHIPX_DEFAULT_PARCEL_TEMPLATE', (string)Tools::getValue('INPOSTSA_SHIPX_DEFAULT_PARCEL_TEMPLATE'));

        // Global GPSR + Manufacturer defaults
        Configuration::updateValue('INPOSTSA_GPSR_DOES_NOT_REQUIRE', (bool)Tools::getValue('INPOSTSA_GPSR_DOES_NOT_REQUIRE'));
        Configuration::updateValue('INPOSTSA_GPSR_BATCH_NUMBER', Tools::getValue('INPOSTSA_GPSR_BATCH_NUMBER'));
        Configuration::updateValue('INPOSTSA_GPSR_CE_MARKING', (bool)Tools::getValue('INPOSTSA_GPSR_CE_MARKING'));
        Configuration::updateValue('INPOSTSA_GPSR_SAFETY_INFORMATION', Tools::getValue('INPOSTSA_GPSR_SAFETY_INFORMATION'));
        Configuration::updateValue('INPOSTSA_MFR_NAME', Tools::getValue('INPOSTSA_MFR_NAME'));
        Configuration::updateValue('INPOSTSA_MFR_EMAIL', Tools::getValue('INPOSTSA_MFR_EMAIL'));
        Configuration::updateValue('INPOSTSA_MFR_PHONE', Tools::getValue('INPOSTSA_MFR_PHONE'));
        Configuration::updateValue('INPOSTSA_MFR_STREET', Tools::getValue('INPOSTSA_MFR_STREET'));
        Configuration::updateValue('INPOSTSA_MFR_BUILDING_NUMBER', Tools::getValue('INPOSTSA_MFR_BUILDING_NUMBER'));
        Configuration::updateValue('INPOSTSA_MFR_APARTMENT_NUMBER', Tools::getValue('INPOSTSA_MFR_APARTMENT_NUMBER'));
        Configuration::updateValue('INPOSTSA_MFR_CITY', Tools::getValue('INPOSTSA_MFR_CITY'));
        Configuration::updateValue('INPOSTSA_MFR_POSTAL_CODE', Tools::getValue('INPOSTSA_MFR_POSTAL_CODE'));
        Configuration::updateValue('INPOSTSA_MFR_COUNTRY_CODE', Tools::getValue('INPOSTSA_MFR_COUNTRY_CODE'));

        // Orders cron settings
        Configuration::updateValue('INPOSTSA_ORDERS_CRON_ENABLED', (bool)Tools::getValue('INPOSTSA_ORDERS_CRON_ENABLED'));
        Configuration::updateValue('INPOSTSA_ORDERS_CRON_EXPRESSION', Tools::getValue('INPOSTSA_ORDERS_CRON_EXPRESSION'));
        Configuration::updateValue('INPOSTSA_ORDERS_CRON_LIMIT', (int)Tools::getValue('INPOSTSA_ORDERS_CRON_LIMIT'));
        Configuration::updateValue('INPOSTSA_ORDERS_SYNC_RANGE', Tools::getValue('INPOSTSA_ORDERS_SYNC_RANGE') === 'days' ? 'days' : 'continuous');
        Configuration::updateValue('INPOSTSA_ORDERS_SYNC_RANGE_DAYS', max(1, min(3650, (int) Tools::getValue('INPOSTSA_ORDERS_SYNC_RANGE_DAYS') ?: 30)));

        // Order events cron settings
        Configuration::updateValue('INPOSTSA_ORDER_EVENTS_CRON_ENABLED', (bool)Tools::getValue('INPOSTSA_ORDER_EVENTS_CRON_ENABLED'));
        Configuration::updateValue('INPOSTSA_ORDER_EVENTS_CRON_EXPRESSION', Tools::getValue('INPOSTSA_ORDER_EVENTS_CRON_EXPRESSION'));
        Configuration::updateValue('INPOSTSA_ORDER_EVENTS_CRON_LIMIT', (int)Tools::getValue('INPOSTSA_ORDER_EVENTS_CRON_LIMIT'));
        Configuration::updateValue('INPOSTSA_ORDER_EVENTS_CREATE_ORDERS', (int)(bool)Tools::getValue('INPOSTSA_ORDER_EVENTS_CREATE_ORDERS'));

        // Offer cron job settings
        Configuration::updateValue('INPOSTSA_CRON_SYNC_CATEGORIES_ENABLED', (bool)Tools::getValue('INPOSTSA_CRON_SYNC_CATEGORIES_ENABLED'));
        Configuration::updateValue('INPOSTSA_CRON_SYNC_CATEGORIES_SCHEDULE', Tools::getValue('INPOSTSA_CRON_SYNC_CATEGORIES_SCHEDULE'));
        Configuration::updateValue('INPOSTSA_CRON_SYNC_CATEGORIES_LIMIT', (int)Tools::getValue('INPOSTSA_CRON_SYNC_CATEGORIES_LIMIT'));
        Configuration::updateValue('INPOSTSA_CATEGORY_ORPHAN_CLEANUP_ENABLED', (bool)Tools::getValue('INPOSTSA_CATEGORY_ORPHAN_CLEANUP_ENABLED'));


        Configuration::updateValue('INPOSTSA_CRON_SYNC_OFFERS_ENABLED', (bool)Tools::getValue('INPOSTSA_CRON_SYNC_OFFERS_ENABLED'));
        Configuration::updateValue('INPOSTSA_CRON_SYNC_OFFERS_SCHEDULE', Tools::getValue('INPOSTSA_CRON_SYNC_OFFERS_SCHEDULE'));
        Configuration::updateValue('INPOSTSA_CRON_SYNC_OFFERS_LIMIT', (int)Tools::getValue('INPOSTSA_CRON_SYNC_OFFERS_LIMIT'));

        // GOKINT-928: Pending create-command sync + retry
        Configuration::updateValue('INPOSTSA_CRON_SYNC_COMMANDS_ENABLED', (bool)Tools::getValue('INPOSTSA_CRON_SYNC_COMMANDS_ENABLED'));
        Configuration::updateValue('INPOSTSA_CRON_SYNC_COMMANDS_SCHEDULE', Tools::getValue('INPOSTSA_CRON_SYNC_COMMANDS_SCHEDULE'));
        Configuration::updateValue('INPOSTSA_CRON_SYNC_COMMANDS_LIMIT', (int)Tools::getValue('INPOSTSA_CRON_SYNC_COMMANDS_LIMIT'));
        Configuration::updateValue('INPOSTSA_CREATE_COMMAND_MAX_RETRIES', (int)Tools::getValue('INPOSTSA_CREATE_COMMAND_MAX_RETRIES'));

        Configuration::updateValue('INPOSTSA_OFFER_EVENTS_CRON_ENABLED', (bool)Tools::getValue('INPOSTSA_OFFER_EVENTS_CRON_ENABLED'));
        Configuration::updateValue('INPOSTSA_OFFER_EVENTS_CRON_EXPRESSION', Tools::getValue('INPOSTSA_OFFER_EVENTS_CRON_EXPRESSION'));
        Configuration::updateValue('INPOSTSA_OFFER_EVENTS_CRON_LIMIT', (int)Tools::getValue('INPOSTSA_OFFER_EVENTS_CRON_LIMIT'));

        // Offer defaults
        Configuration::updateValue('INPOSTSA_OFFER_DEFAULT_RETURN_POLICY', Tools::getValue('INPOSTSA_OFFER_DEFAULT_RETURN_POLICY'));
        Configuration::updateValue('INPOSTSA_OFFER_DEFAULT_COMPLAINT_POLICY', Tools::getValue('INPOSTSA_OFFER_DEFAULT_COMPLAINT_POLICY'));
        $deliveryDays = trim((string) Tools::getValue('INPOSTSA_OFFER_DEFAULT_DELIVERY_DAYS'));
        Configuration::updateValue('INPOSTSA_OFFER_DEFAULT_DELIVERY_DAYS', ctype_digit($deliveryDays) ? (string) (int) $deliveryDays : '');
        // Product field mapping: only sources the field accepts (a tampered value keeps the saved one)
        foreach (array_keys(\Inpost\VonHalsky\Service\Offer\OfferFieldMapping::all()) as $field) {
            $key = \Inpost\VonHalsky\Service\Offer\OfferFieldMapping::configKey($field);
            $source = trim((string) Tools::getValue($key));
            if (Tools::getIsset($key) && \Inpost\VonHalsky\Service\Offer\OfferFieldMapping::isAllowed($field, $source)) {
                Configuration::updateValue($key, $source);
            }
        }
    }

    /**
     * Render configuration form
     *
     * @return string
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit' . $this->name;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $form = $helper->generateForm($this->getConfigForm());

        // Wrap the generated form so the inline script can attach a sticky,
        // grouped left-hand section navigation (scroll-spy) and cut down on scrolling.
        $navTitle = htmlspecialchars($this->l('Sections'), ENT_QUOTES, 'UTF-8');
        $navOther = htmlspecialchars($this->l('Other'), ENT_QUOTES, 'UTF-8');
        $navGroups = htmlspecialchars(
            json_encode($this->getConfigNavGroups(), JSON_UNESCAPED_UNICODE),
            ENT_QUOTES,
            'UTF-8'
        );

        // "Configure" in Payment > Payment methods always opens the module configuration
        // (fixed link) - land on the InPost payment panel instead of the top of the page
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $navFocus = (strpos($referer, 'payment_methods') !== false || stripos($referer, 'AdminPayment') !== false)
            ? $this->l('InPost payment')
            : '';

        return '<div id="inpostvonhalsky-cfg-wrapper"'
            . ' data-nav-focus="' . $navFocus . '"'
            . ' data-nav-title="' . $navTitle . '"'
            . ' data-nav-other="' . $navOther . '"'
            . ' data-nav-groups="' . $navGroups . '">'
            . $form
            . '</div>'
            . $this->getConfigNavScript();
    }

    /**
     * Logical grouping of the configuration panels for the left-hand navigation.
     *
     * Each group lists the exact panel legend titles (as produced by getConfigForm())
     * that belong to it; the inline script matches panels to groups by their heading
     * text. Any panel not listed here falls into an "Other" group, so nothing is ever
     * hidden if a panel is added or renamed. Keep the titles in sync with the legends
     * in getConfigForm().
     *
     * @return array<int, array{label: string, titles: array<int, string>}>
     */
    private function getConfigNavGroups()
    {
        return [
            [
                'label' => $this->l('Connection'),
                'titles' => [
                    $this->l('API Settings'),
                ],
            ],
            [
                'label' => $this->l('Synchronization'),
                'titles' => [
                    $this->l('Orders Sync Cron Settings'),
                    $this->l('Order Events Sync Cron Settings'),
                    $this->l('Sync Categories Cron Settings'),
                    $this->l('Sync Offers Cron Settings'),
                    $this->l('Pending Commands Sync Settings'),
                    $this->l('Offer Events Sync Cron Settings'),
                ],
            ],
            [
                'label' => $this->l('Offers & Products'),
                'titles' => [
                    $this->l('Productization'),
                    $this->l('Bulk Listing'),
                    $this->l('InPost prices'),
                    $this->l('Offer Defaults'),
                    $this->l('Image Test Override'),
                    $this->l('GPSR & Manufacturer Details (global defaults)'),
                ],
            ],
            [
                'label' => $this->l('Shipping & Payment'),
                'titles' => [
                    $this->l('InPost ShipX integration'),
                    $this->l('Shipping mapping (InPost von Halsky → ShipX)'),
                    $this->l('InPost payment'),
                ],
            ],
            [
                'label' => $this->l('Emails'),
                'titles' => [
                    $this->l('Customer Emails'),
                ],
            ],
        ];
    }

    /**
     * Inline script that builds the sticky left-hand section navigation for the
     * configuration form and keeps the active link in sync while scrolling.
     *
     * Kept self-contained (no dependency on views/js/back.js, which is not loaded
     * on the AdminModules configuration page) and degrades gracefully: if the
     * script does not run, the form simply renders full-width as before.
     *
     * @return string
     */
    private function getConfigNavScript()
    {
        return <<<'HTML'
<script>
(function () {
    'use strict';

    // PrestaShop's l() HTML-encodes entities (e.g. "&" -> "&amp;"), so decode both
    // the group data and the panel headings before comparing/displaying them.
    var decoderEl = document.createElement('textarea');
    function decodeEntities(text) {
        decoderEl.innerHTML = text || '';
        return decoderEl.value;
    }

    function normalize(text) {
        return decodeEntities(text).replace(/\s+/g, ' ').trim();
    }

    // Drop a trailing "(...)" qualifier for a compact nav label.
    function shortLabel(text) {
        return normalize(text).replace(/\s*\([^)]*\)\s*$/, '').trim();
    }

    function init() {
        var wrapper = document.getElementById('inpostvonhalsky-cfg-wrapper');
        if (!wrapper) {
            return;
        }

        var form = wrapper.querySelector('form');
        if (!form) {
            return;
        }

        var panels = form.querySelectorAll('.panel');
        if (panels.length < 2) {
            return; // nothing worth navigating
        }

        var groups;
        try {
            groups = JSON.parse(wrapper.getAttribute('data-nav-groups') || '[]');
        } catch (e) {
            groups = [];
        }
        var otherLabel = wrapper.getAttribute('data-nav-other') || 'Other';

        form.classList.add('inpostvonhalsky-cfg-content');

        // Collect panels (id + heading text) in DOM order.
        var panelInfo = [];
        Array.prototype.forEach.call(panels, function (panel, index) {
            if (!panel.id) {
                panel.id = 'inpostvonhalsky-panel-' + index;
            }
            var heading = panel.querySelector('.panel-heading, .panel-title');
            panelInfo.push({
                id: panel.id,
                title: heading ? normalize(heading.textContent) : ('Section ' + (index + 1)),
                used: false
            });
        });

        // Assign each panel to its group. Match by title *prefix* so a panel whose
        // heading also renders a legend description (e.g. GPSR) is still matched.
        var built = [];
        groups.forEach(function (group) {
            var items = [];
            (group.titles || []).forEach(function (rawTitle) {
                var key = normalize(rawTitle);
                if (!key) {
                    return;
                }
                for (var i = 0; i < panelInfo.length; i++) {
                    var p = panelInfo[i];
                    if (!p.used && p.title.indexOf(key) === 0) {
                        p.used = true;
                        items.push({ id: p.id, label: shortLabel(rawTitle) });
                        break;
                    }
                }
            });
            if (items.length) {
                built.push({ label: group.label, items: items });
            }
        });
        var leftovers = panelInfo.filter(function (p) { return !p.used; });
        if (leftovers.length) {
            built.push({
                label: otherLabel,
                items: leftovers.map(function (p) {
                    return { id: p.id, label: shortLabel(p.title) };
                })
            });
        }
        if (!built.length) {
            return;
        }

        // Reorder the real panels to match the grouped nav order, so scrolling flows
        // straight down the sidebar order instead of jumping between groups. (All
        // inputs stay inside the same form, so submitting is unaffected.)
        built.forEach(function (group) {
            group.items.forEach(function (item) {
                var el = document.getElementById(item.id);
                if (el) {
                    form.appendChild(el);
                }
            });
        });
        // Re-read panels in their new visual order for the scroll-spy.
        var orderedPanels = form.querySelectorAll('.panel');

        var nav = document.createElement('nav');
        nav.className = 'inpostvonhalsky-cfg-nav';

        var title = document.createElement('p');
        title.className = 'inpostvonhalsky-cfg-nav__title';
        title.textContent = wrapper.getAttribute('data-nav-title') || 'Sections';
        nav.appendChild(title);

        var linkByPanel = {};       // panel id -> anchor element
        var groupByPanel = {};      // panel id -> group wrapper element (only multi-item groups)
        var collapsibleGroups = []; // group wrapper elements that can open/close

        function collapseAll() {
            collapsibleGroups.forEach(function (el) {
                el.classList.remove('is-open');
                var header = el.querySelector('.inpostvonhalsky-cfg-nav__group-header');
                if (header) {
                    header.setAttribute('aria-expanded', 'false');
                }
            });
        }

        function openGroup(el) {
            if (!el || el.classList.contains('is-open')) {
                return;
            }
            collapseAll();
            el.classList.add('is-open');
            var header = el.querySelector('.inpostvonhalsky-cfg-nav__group-header');
            if (header) {
                header.setAttribute('aria-expanded', 'true');
            }
        }

        function scrollToPanel(id) {
            var target = document.getElementById(id);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', '#' + id);
                }
            }
        }

        built.forEach(function (group) {
            var groupEl = document.createElement('div');
            groupEl.className = 'inpostvonhalsky-cfg-nav__group';

            if (group.items.length === 1) {
                // Single-panel group: header itself is a direct jump link.
                var solo = document.createElement('a');
                solo.className = 'inpostvonhalsky-cfg-nav__solo';
                solo.href = '#' + group.items[0].id;
                solo.textContent = normalize(group.label);
                solo.title = normalize(group.label);
                solo.setAttribute('data-target', group.items[0].id);
                solo.addEventListener('click', function (event) {
                    event.preventDefault();
                    scrollToPanel(group.items[0].id);
                });
                groupEl.appendChild(solo);
                linkByPanel[group.items[0].id] = solo;
            } else {
                // Multi-panel group: collapsible accordion header + child links.
                var header = document.createElement('button');
                header.type = 'button';
                header.className = 'inpostvonhalsky-cfg-nav__group-header';
                header.setAttribute('aria-expanded', 'false');

                var label = document.createElement('span');
                label.className = 'inpostvonhalsky-cfg-nav__group-label';
                label.textContent = normalize(group.label);

                var chevron = document.createElement('span');
                chevron.className = 'inpostvonhalsky-cfg-nav__chevron';
                chevron.setAttribute('aria-hidden', 'true');

                header.appendChild(label);
                header.appendChild(chevron);
                header.addEventListener('click', function () {
                    if (groupEl.classList.contains('is-open')) {
                        collapseAll();
                    } else {
                        openGroup(groupEl);
                    }
                });

                var items = document.createElement('div');
                items.className = 'inpostvonhalsky-cfg-nav__items';
                group.items.forEach(function (item) {
                    var a = document.createElement('a');
                    a.href = '#' + item.id;
                    a.textContent = item.label;
                    a.title = item.label;
                    a.setAttribute('data-target', item.id);
                    a.addEventListener('click', function (event) {
                        event.preventDefault();
                        scrollToPanel(item.id);
                    });
                    items.appendChild(a);
                    linkByPanel[item.id] = a;
                    groupByPanel[item.id] = groupEl;
                });

                groupEl.appendChild(header);
                groupEl.appendChild(items);
                collapsibleGroups.push(groupEl);
            }

            nav.appendChild(groupEl);
        });

        wrapper.insertBefore(nav, form);
        wrapper.classList.add('inpostvonhalsky-has-nav');

        var offset = 90;      // clears the fixed admin header
        var firstId = built[0].items[0].id;
        var activeGroupEl = null;
        function syncActive() {
            var currentId = firstId;
            Array.prototype.forEach.call(orderedPanels, function (panel) {
                if (panel.getBoundingClientRect().top - offset <= 0) {
                    currentId = panel.id;
                }
            });

            for (var id in linkByPanel) {
                if (linkByPanel.hasOwnProperty(id)) {
                    linkByPanel[id].classList.toggle('active', id === currentId);
                }
            }

            // Auto-open the active panel's group only when the active group changes,
            // so a manual toggle is not immediately undone by a small scroll.
            var groupEl = groupByPanel[currentId] || null;
            if (groupEl !== activeGroupEl) {
                activeGroupEl = groupEl;
                if (groupEl) {
                    openGroup(groupEl);
                }
            }
        }

        var ticking = false;
        window.addEventListener('scroll', function () {
            if (!ticking) {
                window.requestAnimationFrame(function () {
                    syncActive();
                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });

        syncActive();

        // Opened from another page for a given panel (e.g. "Configure" in Payment methods)
        var focus = normalize(wrapper.getAttribute('data-nav-focus') || '');
        if (focus) {
            for (var f = 0; f < panelInfo.length; f++) {
                if (panelInfo[f].title.indexOf(focus) === 0) {
                    scrollToPanel(panelInfo[f].id);
                    break;
                }
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
HTML;
    }

    /**
     * Create configuration form structure
     *
     * @return array Array of form configurations
     */
    protected function getConfigForm()
    {
        $tokenManager = new \Inpost\VonHalsky\Service\ApiTokenManager();
        $isConnected = !empty($tokenManager->getAccessToken()) || !empty($tokenManager->getRefreshToken());

        return [
            // API Settings Panel
            $this->configPanel($this->l('API Settings'), 'icon-cogs', [
                [
                    'type' => 'text',
                    'label' => $this->l('Client ID'),
                    'name' => 'INPOSTSA_CLIENT_ID',
                    'size' => 64,
                    'required' => true,
                    'desc' => $this->l('Your InPost SA Client ID'),
                ],
                [
                    'type' => 'password',
                    'label' => $this->l('Client Secret'),
                    'name' => 'INPOSTSA_CLIENT_SECRET',
                    'size' => 64,
                    'required' => false,
                    'desc' => $this->l('Your InPost SA Client Secret'),
                    'autocomplete' => 'new-password',
                ],
                [
                    'type' => 'html',
                    'name' => 'oauth2_redirect_uri',
                    'html_content' => $this->getRedirectUriFieldHtml(),
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Organization'),
                    'name' => 'INPOSTSA_ORGANIZATION_ID',
                    'required' => true,
                    'desc' => $this->l('Select your InPost organization'),
                    'options' => [
                        'query' => $this->getOrganizationOptions(),
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
                [
                    'type' => 'html',
                    'name' => 'refresh_organizations_button',
                    'html_content' => '<div class="form-group">
                        <div class="col-lg-9 col-lg-offset-3">
                            <button type="submit" name="refreshOrganizations" value="1" class="btn btn-default">
                                <i class="icon-refresh"></i> ' . $this->l('Refresh Organizations List') . '
                            </button>
                            <p class="help-block">' . $this->l('Click to sync organizations from InPost API') . '</p>
                        </div>
                    </div>',
                ],
                [
                    'type' => 'html',
                    'name' => 'oauth2_connection_status',
                    'html_content' => $this->getOAuth2ConnectionStatusHtml($isConnected),
                ],
                [
                    'type' => 'html',
                    'name' => 'oauth2_buttons',
                    'html_content' => $this->getOAuth2ButtonsHtml($isConnected),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('API URL'),
                    'name' => 'INPOSTSA_API_URL',
                    'size' => 100,
                    'required' => true,
                    'desc' => $this->l('InPost API URL'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Authorization Service URL'),
                    'name' => 'INPOSTSA_AUTH_URL',
                    'size' => 100,
                    'required' => true,
                    'desc' => $this->l('InPost Authorization Service URL'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('User Organizations Endpoint'),
                    'name' => 'INPOSTSA_USER_ORGS_ENDPOINT',
                    'size' => 100,
                    'required' => false,
                    'desc' => $this->l('Endpoint for retrieving user organizations'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Fallback Product ID'),
                    'name' => 'INPOSTSA_FALLBACK_PRODUCT_ID',
                    'size' => 64,
                    'required' => false,
                    'desc' => $this->l('Product ID to use when product is not found by reference (productId from InPost API). If not set, first active product will be used.'),
                ],
                $this->switchInput('INPOSTSA_DEBUG_API_LOGGING', $this->l('Debug API Logging'), $this->l('Enable detailed API request/response logging to var/logs/inpost.log (for debugging purposes)'), 'debug'),
            ]),
            // GOKINT-926: Customer Emails Panel
            $this->configPanel($this->l('Customer Emails'), 'icon-envelope', [
                $this->switchInput('INPOSTSA_DISABLE_CUSTOMER_EMAILS', $this->l('Suppress customer emails for InPost orders'), $this->l('Prevents PrestaShop from sending the selected customer emails for orders that originate from InPost (InPost already notifies the buyer).'), 'disable_mail'),
                [
                    'type' => 'checkbox',
                    'label' => $this->l('Email types to suppress'),
                    'name' => 'INPOSTSA_EMAIL_TYPES_TO_DISABLE',
                    'desc' => $this->l('Leaving all unchecked suppresses every type. Note: PS 8.2 has no separate invoice email — the invoice PDF is attached to the order confirmation / status emails.'),
                    'values' => [
                        'query' => [
                            ['id' => 'order_confirmation', 'name' => $this->l('Order confirmation'), 'val' => 'order_confirmation'],
                            ['id' => 'order_status_update', 'name' => $this->l('Order status change'), 'val' => 'order_status_update'],
                            ['id' => 'shipment', 'name' => $this->l('Shipment / tracking'), 'val' => 'shipment'],
                            ['id' => 'credit_slip', 'name' => $this->l('Credit slip'), 'val' => 'credit_slip'],
                            ['id' => 'order_comment', 'name' => $this->l('Order comment'), 'val' => 'order_comment'],
                            ['id' => 'virtual_product', 'name' => $this->l('Virtual product download'), 'val' => 'virtual_product'],
                        ],
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
                $this->switchInput('INPOSTSA_LOG_BLOCKED_EMAILS', $this->l('Log suppressed emails'), $this->l('Write a log entry each time a customer email is suppressed.'), 'log_mail'),
            ]),
            // GOKINT-927: Productization Panel
            $this->configPanel($this->l('Productization'), 'icon-magic', [
                $this->switchInput('INPOSTSA_PRODUCTIZATION_ENABLED', $this->l('Enable productization from InPost'), $this->l('Allow filling offer fields and attributes from the InPost /hints endpoint by EAN.'), 'prod'),
                $this->switchInput('INPOSTSA_HINT_NAME_FALLBACK_ENABLED', $this->l('Attribute name fallback'), $this->l('When a hint attribute UUID does not match, fall back to matching by normalized attribute name (recommended).'), 'hint_fb'),
                $this->textInput('INPOSTSA_HINT_CACHE_TTL', $this->l('Hint cache TTL (seconds)'), $this->l('How long /hints responses are cached (default: 86400 = 24h).'), '86400'),
            ]),
            // GOKINT-930: Bulk Listing Panel
            $this->configPanel($this->l('Bulk Listing'), 'icon-list', [
                $this->switchInput('INPOSTSA_BULK_LIST_ENABLED', $this->l('Enable bulk listing from product grid'), $this->l('Adds a "List on InPost" bulk action to the Catalog product list.'), 'bulk'),
                $this->textInput('INPOSTSA_BULK_CHUNK_SIZE', $this->l('Batch size'), $this->l('Products processed per AJAX request during bulk creation (default: 25).'), '25'),
            ]),
            // Image Test Override Panel (for shops unreachable by InPost, e.g. VPN)
            $this->configPanel($this->l('Image Test Override'), 'icon-picture-o', [
                $this->switchInput('INPOSTSA_IMAGE_TEST_MODE_ENABLED', $this->l('Enable image test override'), $this->l('For testing only: replace every offer image URL with the fixed URL below, so InPost can fetch images even when this shop is not publicly reachable (e.g. behind a VPN).'), 'img_test'),
                $this->textInput('INPOSTSA_IMAGE_TEST_URL', $this->l('Test image URL'), $this->l('Public URL used for all offer images when the override is enabled (e.g. https://picsum.photos/800).'), 'https://example.com/sample.jpg'),
            ]),
            // Orders Sync Cron Settings Panel
            $this->configPanel($this->l('Orders Sync Cron Settings'), 'icon-clock-o', [
                $this->switchInput('INPOSTSA_ORDERS_CRON_ENABLED', $this->l('Enable Automatic Order Synchronization'), $this->l('Enable automatic order synchronization via cron job'), 'active'),
                $this->cronExpressionInput('INPOSTSA_ORDERS_CRON_EXPRESSION', $this->l('Cron expression for scheduling (e.g., "0 */6 * * *" for every 6 hours, "0 0 * * *" for daily at midnight). Format: minute hour day month weekday'), '0 */6 * * *'),
                $this->cronLimitInput('INPOSTSA_ORDERS_CRON_LIMIT', $this->l('Maximum number of orders to sync per cron execution (1-30, default: 30)'), '30'),
                [
                    'type' => 'select',
                    'label' => $this->l('Import range'),
                    'name' => 'INPOSTSA_ORDERS_SYNC_RANGE',
                    'options' => [
                        'query' => [
                            ['id' => 'continuous', 'name' => $this->l('Continuous - orders changed since the last import (whole history once a day)')],
                            ['id' => 'days', 'name' => $this->l('Fixed window - orders changed in the last N days')],
                        ],
                        'id' => 'id',
                        'name' => 'name',
                    ],
                    'desc' => $this->l('Which InPost orders the import reads (InPost "updated at" date). Use the fixed window to never import old orders. Also applies to "Sync orders" in the orders list.'),
                ],
                $this->textInput('INPOSTSA_ORDERS_SYNC_RANGE_DAYS', $this->l('Window (days)'), $this->l('Number of days for the fixed window (default: 30).'), '30'),
            ]),
            // Order Events Sync Cron Settings Panel
            $this->configPanel($this->l('Order Events Sync Cron Settings'), 'icon-clock-o', [
                $this->switchInput('INPOSTSA_ORDER_EVENTS_CRON_ENABLED', $this->l('Enable Automatic Order Events Synchronization'), $this->l('Enable automatic order event synchronization via cron job'), 'events'),
                $this->cronExpressionInput('INPOSTSA_ORDER_EVENTS_CRON_EXPRESSION', $this->l('Cron expression for scheduling (e.g., "*/15 * * * *" for every 15 minutes, "0 */1 * * *" for every hour). Format: minute hour day month weekday'), '*/15 * * * *'),
                $this->cronLimitInput('INPOSTSA_ORDER_EVENTS_CRON_LIMIT', $this->l('Maximum number of events to sync per cron execution (1-100, default: 100)'), '100'),
                $this->switchInput('INPOSTSA_ORDER_EVENTS_CREATE_ORDERS', $this->l('Create orders from events'), $this->l('On: an event about an order not imported yet (e.g. a new accepted order) creates the shop order right away. Off: events only refresh orders already imported (status, cancellation) and new orders wait for the order import.'), 'events_create'),
                [
                    'type' => 'html',
                    'name' => 'event_cron_info',
                    'html_content' => '<div class="form-group">
                        <div class="col-lg-9 col-lg-offset-3">
                            <div class="alert alert-info">
                                <h4><i class="icon-info-circle"></i> ' . $this->l('Event Sync Cron Setup') . '</h4>
                                <p>' . $this->l('Event synchronization runs automatically via the same cron command as order sync. The command checks both cron expressions and executes syncs when needed.') . '</p>
                                <p style="margin-top: 10px;"><strong>' . $this->l('How it works:') . '</strong></p>
                                <ul style="margin-left: 20px;">
                                    <li>' . $this->l('System cron calls the command every minute') . '</li>
                                    <li>' . $this->l('The command checks if event cron is enabled and if the scheduled time has come') . '</li>
                                    <li>' . $this->l('If conditions are met, it executes event synchronization with the configured limit') . '</li>
                                    <li>' . $this->l('Events update existing order records in the database based on InPost order ID') . '</li>
                                </ul>
                                <p style="margin-top: 10px;"><strong>' . $this->l('Last execution:') . '</strong> ' . 
                                ($this->getLastEventCronExecution() ?: $this->l('Never')) . '</p>
                                <p><strong>' . $this->l('Next execution:') . '</strong> ' . 
                                ($this->getNextEventCronExecution() ?: $this->l('Not scheduled')) . '</p>
                            </div>
                        </div>
                    </div>',
                ],
            ]),
            // Sync Categories Cron Settings Panel
            $this->configPanel($this->l('Sync Categories Cron Settings'), 'icon-clock-o', [
                $this->switchInput('INPOSTSA_CRON_SYNC_CATEGORIES_ENABLED', $this->l('Enable Automatic Category Synchronization'), $this->l('Enable automatic category synchronization via cron job'), 'sync_cat'),
                $this->cronExpressionInput('INPOSTSA_CRON_SYNC_CATEGORIES_SCHEDULE', $this->l('Cron expression for scheduling (e.g., "0 2 * * *" for daily at 2 AM). Format: minute hour day month weekday'), '0 2 * * *'),
                $this->cronLimitInput('INPOSTSA_CRON_SYNC_CATEGORIES_LIMIT', $this->l('Maximum number of categories to sync per cron execution (default: 100)'), '100'),
                $this->switchInput('INPOSTSA_CATEGORY_ORPHAN_CLEANUP_ENABLED', $this->l('Deactivate orphaned categories'), $this->l('After a full sync, deactivate local categories that no longer exist in InPost. Skipped automatically when the sync is partial.'), 'orphan_cat'),
                $this->cronInfoBox('sync_categories_cron_info', $this->l('Syncs InPost categories to local database. Uses same cron command as other sync jobs.'), [
                    $this->l('Fetches category tree from InPost API'),
                    $this->l('Updates local category mappings'),
                    $this->l('Run daily to keep categories in sync'),
                ]),
            ]),
            // Sync Offers Cron Settings Panel
            $this->configPanel($this->l('Sync Offers Cron Settings'), 'icon-clock-o', [
                $this->switchInput('INPOSTSA_CRON_SYNC_OFFERS_ENABLED', $this->l('Enable Automatic Offer Synchronization'), $this->l('Enable automatic offer synchronization via cron job'), 'sync_off'),
                $this->cronExpressionInput('INPOSTSA_CRON_SYNC_OFFERS_SCHEDULE', $this->l('Cron expression for scheduling (e.g., "0 */6 * * *" for every 6 hours). Format: minute hour day month weekday'), '0 */6 * * *'),
                $this->cronLimitInput('INPOSTSA_CRON_SYNC_OFFERS_LIMIT', $this->l('Maximum number of offers to sync per cron execution (default: 50)'), '50'),
                $this->cronInfoBox('sync_offers_cron_info', $this->l('Syncs offers from InPost API to local database. Creates/updates offer records.'), [
                    $this->l('Fetches offers from InPost API'),
                    $this->l('Creates or updates local offer records'),
                    $this->l('Links offers to PrestaShop products'),
                ]),
            ]),
            // GOKINT-928: Pending Commands Sync Settings Panel
            $this->configPanel($this->l('Pending Commands Sync Settings'), 'icon-clock-o', [
                $this->switchInput('INPOSTSA_CRON_SYNC_COMMANDS_ENABLED', $this->l('Enable Pending Command Polling'), $this->l('Poll asynchronous create-offer commands and finalize them (SUCCESS/FAILURE)'), 'sync_cmd'),
                $this->cronExpressionInput('INPOSTSA_CRON_SYNC_COMMANDS_SCHEDULE', $this->l('Cron expression for scheduling (default: "*/3 * * * *")'), '*/3 * * * *'),
                $this->textInput('INPOSTSA_CRON_SYNC_COMMANDS_LIMIT', $this->l('Limit per Run'), $this->l('Maximum number of pending commands to poll per cron execution (default: 50)'), '50'),
                $this->textInput('INPOSTSA_CREATE_COMMAND_MAX_RETRIES', $this->l('Max Create Retries'), $this->l('Automatic re-attempts after a FAILURE before the offer is marked ERROR_NOT_CREATED (default: 3)'), '3'),
            ]),
            // Offer Events Sync Cron Settings Panel
            $this->configPanel($this->l('Offer Events Sync Cron Settings'), 'icon-clock-o', [
                $this->switchInput('INPOSTSA_OFFER_EVENTS_CRON_ENABLED', $this->l('Enable Automatic Offer Events Synchronization'), $this->l('Enable automatic synchronization of offer events from InPost API'), 'offer_events'),
                $this->cronExpressionInput('INPOSTSA_OFFER_EVENTS_CRON_EXPRESSION', $this->l('Cron expression for scheduling (e.g., "*/20 * * * *" for every 20 minutes). Format: minute hour day month weekday'), '*/20 * * * *'),
                $this->cronLimitInput('INPOSTSA_OFFER_EVENTS_CRON_LIMIT', $this->l('Maximum number of offer events to sync per cron execution (default: 100)'), '100'),
                $this->cronInfoBox('offer_events_cron_info', $this->l('Fetches offer events from InPost API for synced offers.'), [
                    $this->l('Fetches events for offers that have been synced'),
                    $this->l('Updates local event records'),
                    $this->l('Provides audit trail for offer changes'),
                ]),
            ]),
            // GOKINT-1098: InPost prices Panel
            $this->configPanel($this->l('InPost prices'), 'icon-money', $this->priceSettingsInputs()),
            // Offer Defaults Panel
            $this->configPanel($this->l('Offer Defaults'), 'icon-tag', [
                [
                    'type' => 'textarea',
                    'label' => $this->l('Default Return Policy'),
                    'name' => 'INPOSTSA_OFFER_DEFAULT_RETURN_POLICY',
                    'rows' => 5,
                    'cols' => 80,
                    'desc' => $this->l('Default return policy text for new offers'),
                ],
                [
                    'type' => 'textarea',
                    'label' => $this->l('Default Complaint Policy'),
                    'name' => 'INPOSTSA_OFFER_DEFAULT_COMPLAINT_POLICY',
                    'rows' => 5,
                    'cols' => 80,
                    'desc' => $this->l('Default complaint policy text for new offers'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Default delivery days'),
                    'name' => 'INPOSTSA_OFFER_DEFAULT_DELIVERY_DAYS',
                    'class' => 'fixed-width-sm',
                    'desc' => $this->l('Days to ship sent when neither the offer nor the "Delivery days" product field mapping gives a value. Leave empty to not send it.'),
                ],
            ]),
            // Product field mapping (defaults of new offers)
            $this->configPanel($this->l('Default product field mapping'), 'icon-exchange', $this->offerFieldMappingInputs()),
            // InPost ShipX integration status + toggles
            $this->configPanel($this->l('InPost ShipX integration'), 'icon-truck', [
                [
                    'type' => 'html',
                    'name' => 'INPOSTSA_SHIPX_STATUS_HTML',
                    'html_content' => $this->getShipXStatusHtml(),
                ],
                $this->switchInput('INPOSTSA_SHIPX_INTEGRATION_ENABLED', $this->l('Enable ShipX integration'), $this->l('Map accepted InPost von Halsky orders to the InPost ShipX (inpostshipping) module for shipping.'), 'shipx'),
                $this->switchInput('INPOSTSA_SHIPX_AUTO_SHIPMENT_ENABLED', $this->l('Auto-create shipment on order acceptance'), $this->l('When an InPost von Halsky order is accepted, automatically pass the address + email and create a ShipX shipment and label.'), 'shipx_auto'),
                [
                    'type' => 'select',
                    'label' => $this->l('Default parcel size'),
                    'name' => 'INPOSTSA_SHIPX_DEFAULT_PARCEL_TEMPLATE',
                    'desc' => $this->l('ShipX parcel template used for shipments created from InPost von Halsky orders (small = Gabaryt A, medium = B, large = C).'),
                    'options' => [
                        'query' => [
                            ['id' => 'small', 'name' => $this->l('Small (Gabaryt A)')],
                            ['id' => 'medium', 'name' => $this->l('Medium (Gabaryt B)')],
                            ['id' => 'large', 'name' => $this->l('Large (Gabaryt C)')],
                            ['id' => 'xlarge', 'name' => $this->l('X-Large')],
                        ],
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
            ]),
            // Shipping mapping: InPost von Halsky delivery -> ShipX carrier
            $this->configPanel($this->l('Shipping mapping (InPost von Halsky → ShipX)'), 'icon-map-marker', [
                [
                    'type' => 'select',
                    'label' => $this->l('Locker (Paczkomat) delivery → carrier'),
                    'name' => 'INPOSTSA_SHIPX_CARRIER_LOCKER',
                    'options' => [
                        'query' => $this->getShipXCarrierChoices(),
                        'id' => 'id',
                        'name' => 'name',
                    ],
                    'desc' => $this->l('PrestaShop InPost (ShipX) carrier used for locker deliveries from InPost von Halsky.'),
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Courier delivery → carrier'),
                    'name' => 'INPOSTSA_SHIPX_CARRIER_COURIER',
                    'options' => [
                        'query' => $this->getShipXCarrierChoices(),
                        'id' => 'id',
                        'name' => 'name',
                    ],
                    'desc' => $this->l('PrestaShop InPost (ShipX) carrier used for courier deliveries from InPost von Halsky.'),
                ],
            ]),
            // The hidden "InPost" payment of imported orders (also opened by "Configure" in Payment > Payment methods)
            $this->configPanel($this->l('InPost payment'), 'icon-credit-card', [
                [
                    'type' => 'html',
                    'name' => 'INPOSTSA_PAYMENT_INFO',
                    'html_content' => '<div class="alert alert-info">'
                        // l() returns the text already HTML-escaped
                        . $this->l('The "InPost von Halsky" payment method is used only for orders imported from InPost - it is never offered in the shop checkout and needs no setup. The order shows how the buyer paid in InPost (e.g. "InPost - BLIK", "InPost - Card"), with the InPost payment id as the transaction id. Restrictions in Payment > Preferences (currencies, countries, groups) do not apply to it.')
                        . '</div>',
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Status of paid orders'),
                    'name' => 'INPOSTSA_ORDER_STATE_PAID',
                    'options' => [
                        'query' => $this->getOrderStateChoices(),
                        'id' => 'id',
                        'name' => 'name',
                    ],
                    'desc' => $this->l('Order status given to new orders imported from InPost (they are already paid). Choose a status marked as paid, so the payment is recorded on the order.'),
                ],
            ]),
            // Global GPSR + Manufacturer defaults (fallback for empty offer fields)
            [
                'form' => [
                    'legend' => [
                        'title' => $this->l('GPSR & Manufacturer Details (global defaults)'),
                        'icon' => 'icon-shield',
                    ],
                    'description' => $this->l('Used when an offer leaves these fields empty. If the offer provides its own GPSR / manufacturer data, the offer values are used instead.'),
                    'input' => [
                        [
                            'type' => 'switch',
                            'label' => $this->l('Product does not require GPSR info'),
                            'name' => 'INPOSTSA_GPSR_DOES_NOT_REQUIRE',
                            'is_bool' => true,
                            'desc' => $this->l('Default GPSR exemption for offers without their own GPSR data.'),
                            'values' => [
                                ['id' => 'gpsr_exempt_on', 'value' => 1, 'label' => $this->l('Exempt')],
                                ['id' => 'gpsr_exempt_off', 'value' => 0, 'label' => $this->l('Required')],
                            ],
                        ],
                        [
                            'type' => 'switch',
                            'label' => $this->l('CE marking'),
                            'name' => 'INPOSTSA_GPSR_CE_MARKING',
                            'is_bool' => true,
                            'values' => [
                                ['id' => 'gpsr_ce_on', 'value' => 1, 'label' => $this->l('Yes')],
                                ['id' => 'gpsr_ce_off', 'value' => 0, 'label' => $this->l('No')],
                            ],
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Batch number'),
                            'name' => 'INPOSTSA_GPSR_BATCH_NUMBER',
                            'required' => false,
                        ],
                        [
                            'type' => 'textarea',
                            'label' => $this->l('Safety information'),
                            'name' => 'INPOSTSA_GPSR_SAFETY_INFORMATION',
                            'required' => false,
                            'rows' => 3,
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Manufacturer name'),
                            'name' => 'INPOSTSA_MFR_NAME',
                            'required' => false,
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Manufacturer email'),
                            'name' => 'INPOSTSA_MFR_EMAIL',
                            'required' => false,
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Manufacturer phone'),
                            'name' => 'INPOSTSA_MFR_PHONE',
                            'required' => false,
                            'desc' => $this->l('E.164 format, e.g. +48123456789.'),
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Manufacturer street'),
                            'name' => 'INPOSTSA_MFR_STREET',
                            'required' => false,
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Manufacturer building number'),
                            'name' => 'INPOSTSA_MFR_BUILDING_NUMBER',
                            'required' => false,
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Manufacturer apartment number'),
                            'name' => 'INPOSTSA_MFR_APARTMENT_NUMBER',
                            'required' => false,
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Manufacturer city'),
                            'name' => 'INPOSTSA_MFR_CITY',
                            'required' => false,
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Manufacturer postal code'),
                            'name' => 'INPOSTSA_MFR_POSTAL_CODE',
                            'required' => false,
                        ],
                        [
                            'type' => 'text',
                            'label' => $this->l('Manufacturer country code'),
                            'name' => 'INPOSTSA_MFR_COUNTRY_CODE',
                            'required' => false,
                            'desc' => $this->l('2-letter ISO 3166-1 code, e.g. PL.'),
                        ],
                    ],
                    'submit' => [
                        'title' => $this->l('Save'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Detect the InPost ShipX (inpostshipping) module and render a status banner.
     *
     * @return string HTML
     */
    private function getShipXStatusHtml()
    {
        if (!Module::isInstalled('inpostshipping')) {
            return '<div class="alert alert-warning">'
                . $this->l('InPost ShipX module (inpostshipping) is not installed. Shipping integration is unavailable.')
                . '</div>';
        }

        $enabled = Module::isEnabled('inpostshipping');
        $instance = Module::getInstanceByName('inpostshipping');
        $version = ($instance && !empty($instance->version)) ? $instance->version : '?';
        $sandbox = (bool) Configuration::get('INPOST_SHIPPING_SANDBOX_MODE_ENABLED');
        $token = $sandbox
            ? Configuration::get('INPOST_SHIPPING_SANDBOX_API_TOKEN')
            : Configuration::get('INPOST_SHIPPING_API_TOKEN');
        $org = $sandbox
            ? Configuration::get('INPOST_SHIPPING_SANDBOX_ORGANIZATION_ID')
            : Configuration::get('INPOST_SHIPPING_ORGANIZATION_ID');

        $rows = [
            $this->l('Status') . ': ' . ($enabled ? $this->l('installed & active') : $this->l('installed (inactive)')),
            $this->l('Version') . ': ' . $version,
            $this->l('Mode') . ': ' . ($sandbox ? 'sandbox' : 'production'),
            $this->l('API token') . ': ' . (!empty($token) ? $this->l('configured') : $this->l('missing')),
            $this->l('Organization ID') . ': ' . (!empty($org) ? $this->l('configured') : $this->l('missing')),
        ];

        $ready = $enabled && !empty($token) && !empty($org);
        $class = $ready ? 'alert-success' : 'alert-warning';

        return '<div class="alert ' . $class . '"><strong>'
            . $this->l('InPost ShipX detected') . '</strong><br>'
            . implode('<br>', array_map('htmlspecialchars', $rows))
            . '</div>';
    }

    /**
     * PrestaShop carriers owned by the inpostshipping module (for shipping mapping).
     *
     * @return array<int,array<string,mixed>>
     */
    private function getShipXCarrierChoices()
    {
        $choices = [['id' => 0, 'name' => $this->l('— none —')]];
        if (!Module::isInstalled('inpostshipping')) {
            return $choices;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT c.id_carrier AS id, c.name FROM ' . _DB_PREFIX_ . 'carrier c '
            . 'WHERE c.external_module_name = "inpostshipping" AND c.deleted = 0 AND c.active = 1 '
            . 'ORDER BY c.name'
        ) ?: [];
        foreach ($rows as $row) {
            $choices[] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }

        return $choices;
    }

    /**
     * Order statuses for the "Status of paid orders" select
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function getOrderStateChoices(): array
    {
        $choices = [];
        foreach (OrderState::getOrderStates((int) $this->context->language->id) ?: [] as $state) {
            if (!empty($state['deleted'])) {
                continue;
            }
            $choices[] = [
                'id' => (int) $state['id_order_state'],
                'name' => $state['name'] . (!empty($state['paid']) ? '' : ' (' . $this->l('not paid') . ')'),
            ];
        }

        return $choices;
    }

    /**
     * HelperForm panel with a Save button
     *
     * @param string $title Translated panel title
     * @param string $icon Legend icon class
     * @param array<int, array<string, mixed>> $inputs
     * @return array<string, mixed>
     */
    private function configPanel(string $title, string $icon, array $inputs): array
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $title,
                    'icon' => $icon,
                ],
                'input' => $inputs,
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    /**
     * Inputs of the "InPost prices" panel (GOKINT-1098)
     *
     * @return array<int, array<string, mixed>>
     */
    private function priceSettingsInputs(): array
    {
        $groups = [['id' => 0, 'name' => $this->l('-- none: the shop price --')]];
        foreach (Group::getGroups((int) Configuration::get('PS_LANG_DEFAULT')) ?: [] as $group) {
            $groups[] = ['id' => (int) $group['id_group'], 'name' => (string) $group['name']];
        }

        $info = '<p class="help-block">'
            . $this->l('Prices sent to InPost. Prices in your shop do not change. An offer with a manual price (set on the offer or with "Set price" on the offers list) keeps it - these settings do not apply to it.')
            . '</p><ol class="help-block">'
            . '<li>' . $this->l('Base price: the price for the customer group below, or the shop price when no group is chosen.') . '</li>'
            . '<li>' . $this->l('Price change: the base price plus or minus the percentage.') . '</li>'
            . '<li>' . $this->l('Rounding of the result.') . '</li>'
            . '</ol><p class="help-block">'
            . $this->l('Example: shop price 100.00, +5 %, rounding to ,99 - InPost price 105.99.')
            . ' ' . $this->l('After a change all offers are recalculated, and the ones whose price changed are sent to InPost by the offer sync cron.')
            . '</p>';

        return [
            [
                'type' => 'html',
                'name' => 'price_settings_info',
                'html_content' => $info,
            ],
            [
                'type' => 'select',
                'label' => $this->l('Customer group of the InPost prices'),
                'name' => 'INPOSTSA_PRICE_GROUP_ID',
                'desc' => $this->l('Offers get the price a customer of this group would pay: its specific prices, catalog price rules and group reduction. Create a group (e.g. "InPost") with no customers and set the InPost prices for it in Catalog. Products without such a price get the regular price.'),
                'options' => [
                    'query' => $groups,
                    'id' => 'id',
                    'name' => 'name',
                ],
            ],
            [
                'type' => 'text',
                'label' => $this->l('Price change (%)'),
                'name' => 'INPOSTSA_PRICE_ADJUST_PERCENT',
                'class' => 'fixed-width-sm',
                'suffix' => '%',
                'desc' => sprintf(
                    $this->l('E.g. 5 = 5%% more expensive than in the shop, -5 = 5%% cheaper, 0 = the same. From %s to %s.'),
                    (int) \Inpost\VonHalsky\Service\Offer\OfferPriceResolver::MIN_PERCENT,
                    (int) \Inpost\VonHalsky\Service\Offer\OfferPriceResolver::MAX_PERCENT
                ),
            ],
            [
                'type' => 'select',
                'label' => $this->l('Rounding'),
                'name' => 'INPOSTSA_PRICE_ROUNDING',
                'desc' => $this->l('Applied after the price change. "None" keeps the price exact (to the grosz).'),
                'options' => [
                    'query' => [
                        ['id' => 'none', 'name' => $this->l('None')],
                        ['id' => 'x99', 'name' => $this->l('Up to ,99 (104.20 -> 104.99)')],
                        ['id' => 'integer', 'name' => $this->l('To whole zloty (104.49 -> 104.00)')],
                    ],
                    'id' => 'id',
                    'name' => 'name',
                ],
            ],
        ];
    }

    /**
     * Save the InPost price settings (GOKINT-1098). A change recalculates the
     * prices of all offers: the first batch right away, the rest in the
     * offer_sync cron.
     *
     * @return string Messages to show
     */
    private function savePriceSettings(): string
    {
        $resolver = \Inpost\VonHalsky\Service\Offer\OfferPriceResolver::class;
        $output = '';

        $previous = [
            'INPOSTSA_PRICE_GROUP_ID' => (string) (int) Configuration::get('INPOSTSA_PRICE_GROUP_ID'),
            'INPOSTSA_PRICE_ADJUST_PERCENT' => (string) ($resolver::parsePercent(Configuration::get('INPOSTSA_PRICE_ADJUST_PERCENT')) ?? 0.0),
            'INPOSTSA_PRICE_ROUNDING' => (string) (Configuration::get('INPOSTSA_PRICE_ROUNDING') ?: $resolver::ROUNDING_NONE),
        ];

        $percent = $resolver::parsePercent(Tools::getValue('INPOSTSA_PRICE_ADJUST_PERCENT'));
        if ($percent === null) {
            $output .= $this->displayError(sprintf(
                $this->l('Price change: enter a number from %s to %s, e.g. 5 or -3.5. The previous value is kept.'),
                (int) $resolver::MIN_PERCENT,
                (int) $resolver::MAX_PERCENT
            ));
        }
        $rounding = (string) Tools::getValue('INPOSTSA_PRICE_ROUNDING');

        $new = [
            'INPOSTSA_PRICE_GROUP_ID' => (string) max(0, (int) Tools::getValue('INPOSTSA_PRICE_GROUP_ID')),
            'INPOSTSA_PRICE_ADJUST_PERCENT' => $percent === null ? $previous['INPOSTSA_PRICE_ADJUST_PERCENT'] : (string) $percent,
            'INPOSTSA_PRICE_ROUNDING' => in_array($rounding, $resolver::ROUNDINGS, true) ? $rounding : $resolver::ROUNDING_NONE,
        ];
        if ($new === $previous) {
            return $output;
        }

        foreach ($new as $key => $value) {
            Configuration::updateValue($key, $value);
        }
        \Inpost\VonHalsky\Service\Offer\OfferPriceRecalculator::request();
        \Inpost\VonHalsky\Service\Util\ShopLog::add('InPost: price settings changed: ' . json_encode($new) . ' (was ' . json_encode($previous) . ')', 1);

        // First batch right away; without the Symfony container the cron does it all
        $result = null;
        try {
            $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
            if ($container !== null) {
                $result = $container->get('Inpost\VonHalsky\Service\Offer\OfferPriceRecalculator')->runPending();
            }
        } catch (\Throwable $e) {
            \Inpost\VonHalsky\Service\Util\ShopLog::add('InPost: price recalculation after the settings change failed: ' . $e->getMessage(), 3);
        }

        if ($result === null) {
            return $output . $this->displayConfirmation($this->l('InPost prices will be recalculated by the offer sync cron within a few minutes.'));
        }
        $message = sprintf($this->l('InPost prices recalculated: %d offer(s) checked, %d changed. Changed offers are sent to InPost by the offer sync cron.'), $result['checked'], $result['changed']);
        if (!$result['done']) {
            $message .= ' ' . $this->l('The remaining offers are recalculated by the offer sync cron within a few minutes.');
        }

        return $output . $this->displayConfirmation($message);
    }

    /**
     * Selects of the product field mapping panel: each product field of a new offer
     * takes a built-in PrestaShop product field of a fitting kind or a feature
     *
     * @return array<int, array<string, mixed>>
     */
    private function offerFieldMappingInputs(): array
    {
        $features = [];
        if (Feature::isFeatureActive()) {
            foreach (Feature::getFeatures((int) Configuration::get('PS_LANG_DEFAULT')) ?: [] as $row) {
                $features[] = ['id' => 'feature:' . (int) $row['id_feature'], 'name' => (string) $row['name']];
            }
            usort($features, static function (array $a, array $b): int {
                return strcasecmp($a['name'], $b['name']);
            });
        }

        $dimensionUnit = (string) Configuration::get('PS_DIMENSION_UNIT');
        $weightUnit = (string) Configuration::get('PS_WEIGHT_UNIT');
        $lengthDesc = sprintf($this->l('Converted from the shop dimension unit (%s) to millimetres. Dimensions are sent only when width, height and length are all known.'), $dimensionUnit);
        $descriptions = [
            'NAME' => $this->l('Offer name. Combination offers get the combination added, e.g. "T-shirt - Size: M".'),
            'DESCRIPTION' => $this->l('Offer description (HTML tags removed). The summary is used when it is empty.'),
            'BRAND' => $this->l('Brand of the product.'),
            'SKU' => $this->l('Your product code in the offer. Combinations use their own one (or the product one with "-<combination id>").'),
            'EAN' => $this->l('Barcode - also used to find the product in the InPost catalogue. Combinations use only their own EAN.'),
            'MPN' => $this->l('Manufacturer product number - with the brand it identifies a product without an EAN. Combinations use their own MPN when set.'),
            'MODEL' => $this->l('Model of the product. The product name is used when the field is empty.'),
            'SUPER_MODEL' => $this->l('Groups the variants of one product. Combination offers always use the product name.'),
            'WIDTH' => $lengthDesc,
            'HEIGHT' => $lengthDesc,
            'LENGTH' => $lengthDesc,
            'WEIGHT' => sprintf($this->l('Converted from the shop weight unit (%s) to grams. Combinations add their weight impact.'), $weightUnit),
            'DELIVERY_DAYS' => $this->l('Product feature with the days to ship, e.g. "3" or "2-3 dni" (the larger number is used). Applies to every offer without its own value - also existing ones; when empty, the default delivery days of Offer Defaults is used.'),
        ];

        $inputs = [[
            'type' => 'html',
            'name' => 'offer_field_mapping_info',
            'html_content' => '<p class="help-block">' . $this->l('Which PrestaShop data fills the product fields of new offers. Existing offers are not changed. InPost category attributes (e.g. "Kod producenta") are mapped separately in Category mapping - product fields such as MPN can be chosen there too.') . '</p>',
        ]];
        foreach (\Inpost\VonHalsky\Service\Offer\OfferFieldMapping::all() as $field => $definition) {
            $fields = [];
            foreach (\Inpost\VonHalsky\Service\Mapping\ProductField::all() as $productField) {
                if (\Inpost\VonHalsky\Service\Offer\OfferFieldMapping::kindFits($definition['kind'], $productField['kind'])) {
                    $fields[] = ['id' => $productField['key'], 'name' => $this->l($productField['label'])];
                }
            }
            $groups = [];
            if (!empty($fields)) {
                $groups[] = ['label' => $this->l('Product fields'), 'options' => $fields];
            }
            if (!empty($features)) {
                $groups[] = ['label' => $this->l('Features'), 'options' => $features];
            }

            $input = [
                'type' => 'select',
                'label' => $this->l($definition['label']),
                'name' => \Inpost\VonHalsky\Service\Offer\OfferFieldMapping::configKey($field),
                'options' => [
                    'optiongroup' => ['query' => $groups, 'label' => 'label'],
                    'options' => ['query' => 'options', 'id' => 'id', 'name' => 'name'],
                ],
                'desc' => $descriptions[$field] ?? '',
            ];
            if ($definition['optional']) {
                $input['options']['default'] = ['value' => \Inpost\VonHalsky\Service\Offer\ProductFieldReader::NONE, 'label' => $this->l('-- do not fill --')];
            }
            $inputs[] = $input;
        }

        return $inputs;
    }

    /**
     * Enabled/Disabled switch field
     *
     * @param string $name Configuration key
     * @param string $label Translated label
     * @param string $desc Translated description
     * @param string $idPrefix Prefix of the radio ids ("<prefix>_on" / "<prefix>_off")
     * @return array<string, mixed>
     */
    private function switchInput(string $name, string $label, string $desc, string $idPrefix): array
    {
        return [
            'type' => 'switch',
            'label' => $label,
            'name' => $name,
            'is_bool' => true,
            'desc' => $desc,
            'values' => [
                ['id' => $idPrefix . '_on', 'value' => 1, 'label' => $this->l('Enabled')],
                ['id' => $idPrefix . '_off', 'value' => 0, 'label' => $this->l('Disabled')],
            ],
        ];
    }

    /**
     * Optional text field with a placeholder
     *
     * @return array<string, mixed>
     */
    private function textInput(string $name, string $label, string $desc, string $placeholder): array
    {
        return [
            'type' => 'text',
            'label' => $label,
            'name' => $name,
            'required' => false,
            'desc' => $desc,
            'placeholder' => $placeholder,
        ];
    }

    /**
     * Cron expression field of a cron panel
     *
     * @return array<string, mixed>
     */
    private function cronExpressionInput(string $name, string $desc, string $placeholder): array
    {
        return $this->textInput($name, $this->l('Cron Expression'), $desc, $placeholder);
    }

    /**
     * "Limit per Sync" field of a cron panel
     *
     * @return array<string, mixed>
     */
    private function cronLimitInput(string $name, string $desc, string $placeholder): array
    {
        return $this->textInput($name, $this->l('Limit per Sync'), $desc, $placeholder);
    }

    /**
     * Info box of a cron panel: what the job does + the system cron line to install
     *
     * @param string $name Field name
     * @param string $intro Translated intro sentence
     * @param string[] $steps Translated "How it works" steps
     * @return array<string, mixed>
     */
    private function cronInfoBox(string $name, string $intro, array $steps): array
    {
        $items = '';
        foreach ($steps as $step) {
            $items .= '<li>' . $step . '</li>';
        }

        return [
            'type' => 'html',
            'name' => $name,
            'html_content' => '<div class="form-group"><div class="col-lg-9 col-lg-offset-3"><div class="alert alert-info">'
                . '<h4><i class="icon-info-circle"></i> ' . $this->l('Cron Setup Instructions') . '</h4>'
                . '<p>' . $intro . '</p>'
                . '<code style="background-color: #17a2b8; color: white; padding: 5px 10px; display: block; margin: 10px 0;">* * * * * cd ' . _PS_ROOT_DIR_ . ' && php bin/console inpost:cron:run >> /var/log/inpost-cron.log 2>&1</code>'
                . '<p style="margin-top: 10px;"><strong>' . $this->l('How it works:') . '</strong></p>'
                . '<ul style="margin-left: 20px;">' . $items . '</ul>'
                . '</div></div></div>',
        ];
    }

    /**
     * Get current configuration values
     *
     * @return array
     */
    protected function getConfigFormValues()
    {
        $values = [
            'INPOSTSA_ORGANIZATION_ID' => Configuration::get('INPOSTSA_ORGANIZATION_ID'),
            'INPOSTSA_CLIENT_ID' => Configuration::get('INPOSTSA_CLIENT_ID'),
            'INPOSTSA_CLIENT_SECRET' => '', // Never prefill secret for security
            'INPOSTSA_API_URL' => Configuration::get('INPOSTSA_API_URL'),
            'INPOSTSA_AUTH_URL' => Configuration::get('INPOSTSA_AUTH_URL'),
            'INPOSTSA_USER_ORGS_ENDPOINT' => Configuration::get('INPOSTSA_USER_ORGS_ENDPOINT'),
            'INPOSTSA_FALLBACK_PRODUCT_ID' => Configuration::get('INPOSTSA_FALLBACK_PRODUCT_ID'),
            'INPOSTSA_DEBUG_API_LOGGING' => Configuration::get('INPOSTSA_DEBUG_API_LOGGING'),
            'INPOSTSA_TEST_MODE' => Configuration::get('INPOSTSA_TEST_MODE'),

            // GOKINT-926: Customer email suppression
            'INPOSTSA_DISABLE_CUSTOMER_EMAILS' => Configuration::get('INPOSTSA_DISABLE_CUSTOMER_EMAILS'),
            'INPOSTSA_LOG_BLOCKED_EMAILS' => Configuration::get('INPOSTSA_LOG_BLOCKED_EMAILS'),

            // GOKINT-927: Productization
            'INPOSTSA_PRODUCTIZATION_ENABLED' => Configuration::get('INPOSTSA_PRODUCTIZATION_ENABLED'),
            'INPOSTSA_HINT_NAME_FALLBACK_ENABLED' => Configuration::get('INPOSTSA_HINT_NAME_FALLBACK_ENABLED'),
            'INPOSTSA_HINT_CACHE_TTL' => Configuration::get('INPOSTSA_HINT_CACHE_TTL') ?: 86400,

            // GOKINT-930: Bulk listing
            'INPOSTSA_BULK_LIST_ENABLED' => Configuration::get('INPOSTSA_BULK_LIST_ENABLED'),
            'INPOSTSA_BULK_CHUNK_SIZE' => Configuration::get('INPOSTSA_BULK_CHUNK_SIZE') ?: 25,

            // GOKINT-1098: InPost prices
            'INPOSTSA_PRICE_GROUP_ID' => (int) Configuration::get('INPOSTSA_PRICE_GROUP_ID'),
            'INPOSTSA_PRICE_ADJUST_PERCENT' => Configuration::get('INPOSTSA_PRICE_ADJUST_PERCENT') ?: '0',
            'INPOSTSA_PRICE_ROUNDING' => Configuration::get('INPOSTSA_PRICE_ROUNDING') ?: 'none',
            'INPOSTSA_IMAGE_TEST_MODE_ENABLED' => Configuration::get('INPOSTSA_IMAGE_TEST_MODE_ENABLED'),
            'INPOSTSA_IMAGE_TEST_URL' => Configuration::get('INPOSTSA_IMAGE_TEST_URL'),
            'INPOSTSA_SHIPX_INTEGRATION_ENABLED' => Configuration::get('INPOSTSA_SHIPX_INTEGRATION_ENABLED'),
            'INPOSTSA_SHIPX_AUTO_SHIPMENT_ENABLED' => Configuration::get('INPOSTSA_SHIPX_AUTO_SHIPMENT_ENABLED'),
            'INPOSTSA_SHIPX_CARRIER_LOCKER' => Configuration::get('INPOSTSA_SHIPX_CARRIER_LOCKER'),
            'INPOSTSA_SHIPX_CARRIER_COURIER' => Configuration::get('INPOSTSA_SHIPX_CARRIER_COURIER'),
            'INPOSTSA_ORDER_STATE_PAID' => (int) Configuration::get('INPOSTSA_ORDER_STATE_PAID') ?: (int) Configuration::get('PS_OS_PAYMENT'),
            'INPOSTSA_SHIPX_DEFAULT_PARCEL_TEMPLATE' => Configuration::get('INPOSTSA_SHIPX_DEFAULT_PARCEL_TEMPLATE'),
            'INPOSTSA_GPSR_DOES_NOT_REQUIRE' => Configuration::get('INPOSTSA_GPSR_DOES_NOT_REQUIRE'),
            'INPOSTSA_GPSR_BATCH_NUMBER' => Configuration::get('INPOSTSA_GPSR_BATCH_NUMBER'),
            'INPOSTSA_GPSR_CE_MARKING' => Configuration::get('INPOSTSA_GPSR_CE_MARKING'),
            'INPOSTSA_GPSR_SAFETY_INFORMATION' => Configuration::get('INPOSTSA_GPSR_SAFETY_INFORMATION'),
            'INPOSTSA_MFR_NAME' => Configuration::get('INPOSTSA_MFR_NAME'),
            'INPOSTSA_MFR_EMAIL' => Configuration::get('INPOSTSA_MFR_EMAIL'),
            'INPOSTSA_MFR_PHONE' => Configuration::get('INPOSTSA_MFR_PHONE'),
            'INPOSTSA_MFR_STREET' => Configuration::get('INPOSTSA_MFR_STREET'),
            'INPOSTSA_MFR_BUILDING_NUMBER' => Configuration::get('INPOSTSA_MFR_BUILDING_NUMBER'),
            'INPOSTSA_MFR_APARTMENT_NUMBER' => Configuration::get('INPOSTSA_MFR_APARTMENT_NUMBER'),
            'INPOSTSA_MFR_CITY' => Configuration::get('INPOSTSA_MFR_CITY'),
            'INPOSTSA_MFR_POSTAL_CODE' => Configuration::get('INPOSTSA_MFR_POSTAL_CODE'),
            'INPOSTSA_MFR_COUNTRY_CODE' => Configuration::get('INPOSTSA_MFR_COUNTRY_CODE'),

            // Orders cron settings
            'INPOSTSA_ORDERS_CRON_ENABLED' => Configuration::get('INPOSTSA_ORDERS_CRON_ENABLED'),
            'INPOSTSA_ORDERS_CRON_EXPRESSION' => Configuration::get('INPOSTSA_ORDERS_CRON_EXPRESSION') ?: '*/10 * * * *',
            'INPOSTSA_ORDERS_CRON_LIMIT' => Configuration::get('INPOSTSA_ORDERS_CRON_LIMIT') ?: 30,
            'INPOSTSA_ORDERS_SYNC_RANGE' => Configuration::get('INPOSTSA_ORDERS_SYNC_RANGE') === 'days' ? 'days' : 'continuous',
            'INPOSTSA_ORDERS_SYNC_RANGE_DAYS' => \Inpost\VonHalsky\Service\Cron\OrderSyncJob::rangeDays(),

            // Order events cron settings
            'INPOSTSA_ORDER_EVENTS_CRON_ENABLED' => Configuration::get('INPOSTSA_ORDER_EVENTS_CRON_ENABLED'),
            'INPOSTSA_ORDER_EVENTS_CRON_EXPRESSION' => Configuration::get('INPOSTSA_ORDER_EVENTS_CRON_EXPRESSION') ?: '*/15 * * * *',
            'INPOSTSA_ORDER_EVENTS_CRON_LIMIT' => Configuration::get('INPOSTSA_ORDER_EVENTS_CRON_LIMIT') ?: 100,
            'INPOSTSA_ORDER_EVENTS_CREATE_ORDERS' => (int) \Inpost\VonHalsky\Service\Cron\OrderEventSyncJob::isCreatingOrders(),

            // Offer cron job settings
            'INPOSTSA_CRON_SYNC_CATEGORIES_ENABLED' => Configuration::get('INPOSTSA_CRON_SYNC_CATEGORIES_ENABLED'),
            'INPOSTSA_CRON_SYNC_CATEGORIES_SCHEDULE' => Configuration::get('INPOSTSA_CRON_SYNC_CATEGORIES_SCHEDULE'),
            'INPOSTSA_CRON_SYNC_CATEGORIES_LIMIT' => Configuration::get('INPOSTSA_CRON_SYNC_CATEGORIES_LIMIT'),
            'INPOSTSA_CATEGORY_ORPHAN_CLEANUP_ENABLED' => Configuration::get('INPOSTSA_CATEGORY_ORPHAN_CLEANUP_ENABLED'),

            'INPOSTSA_CRON_SYNC_OFFERS_ENABLED' => Configuration::get('INPOSTSA_CRON_SYNC_OFFERS_ENABLED'),
            'INPOSTSA_CRON_SYNC_OFFERS_SCHEDULE' => Configuration::get('INPOSTSA_CRON_SYNC_OFFERS_SCHEDULE'),
            'INPOSTSA_CRON_SYNC_OFFERS_LIMIT' => Configuration::get('INPOSTSA_CRON_SYNC_OFFERS_LIMIT'),

            // GOKINT-928: Pending create-command sync + retry
            'INPOSTSA_CRON_SYNC_COMMANDS_ENABLED' => Configuration::get('INPOSTSA_CRON_SYNC_COMMANDS_ENABLED'),
            'INPOSTSA_CRON_SYNC_COMMANDS_SCHEDULE' => Configuration::get('INPOSTSA_CRON_SYNC_COMMANDS_SCHEDULE') ?: '*/3 * * * *',
            'INPOSTSA_CRON_SYNC_COMMANDS_LIMIT' => Configuration::get('INPOSTSA_CRON_SYNC_COMMANDS_LIMIT') ?: 50,
            // Use the 5-arg default (applied only when the key is unset) so that an
            // explicitly saved 0 (= no automatic retries) is preserved instead of
            // falling back to 3 via a truthiness check.
            'INPOSTSA_CREATE_COMMAND_MAX_RETRIES' => (int) Configuration::get('INPOSTSA_CREATE_COMMAND_MAX_RETRIES', null, null, null, 3),

            // Offer events cron settings
            'INPOSTSA_OFFER_EVENTS_CRON_ENABLED' => Configuration::get('INPOSTSA_OFFER_EVENTS_CRON_ENABLED'),
            'INPOSTSA_OFFER_EVENTS_CRON_EXPRESSION' => Configuration::get('INPOSTSA_OFFER_EVENTS_CRON_EXPRESSION') ?: '*/20 * * * *',
            'INPOSTSA_OFFER_EVENTS_CRON_LIMIT' => Configuration::get('INPOSTSA_OFFER_EVENTS_CRON_LIMIT') ?: 100,

            // Offer defaults
            'INPOSTSA_OFFER_DEFAULT_RETURN_POLICY' => Configuration::get('INPOSTSA_OFFER_DEFAULT_RETURN_POLICY'),
            'INPOSTSA_OFFER_DEFAULT_COMPLAINT_POLICY' => Configuration::get('INPOSTSA_OFFER_DEFAULT_COMPLAINT_POLICY'),
            'INPOSTSA_OFFER_DEFAULT_DELIVERY_DAYS' => Configuration::get('INPOSTSA_OFFER_DEFAULT_DELIVERY_DAYS'),
        ];

        // Product field mapping: the source in effect (default when not configured)
        foreach (array_keys(\Inpost\VonHalsky\Service\Offer\OfferFieldMapping::all()) as $field) {
            $key = \Inpost\VonHalsky\Service\Offer\OfferFieldMapping::configKey($field);
            $values[$key] = \Inpost\VonHalsky\Service\Offer\OfferFieldMapping::effective($field, Configuration::get($key));
        }

        // GOKINT-926: expand the stored CSV into per-type checkbox values
        $disabledTypes = array_filter(array_map(
            'trim',
            explode(',', (string) Configuration::get('INPOSTSA_EMAIL_TYPES_TO_DISABLE'))
        ));
        foreach (array_keys(self::EMAIL_TYPE_TEMPLATES) as $emailType) {
            $values['INPOSTSA_EMAIL_TYPES_TO_DISABLE_' . $emailType] = in_array($emailType, $disabledTypes, true) ? 1 : 0;
        }

        return $values;
    }

    /**
     * Get OAuth2 redirect URI field HTML (read-only, copyable)
     *
     * @return string
     */
    protected function getRedirectUriFieldHtml(): string
    {
        $redirectUri = \Inpost\VonHalsky\Service\Auth\OAuth2ServiceFactory::getRedirectUri();
        $escaped = htmlspecialchars($redirectUri, ENT_QUOTES, 'UTF-8');

        return '<div class="form-group">
            <label class="control-label col-lg-3">' . $this->l('OAuth2 Redirect URI') . '</label>
            <div class="col-lg-9">
                <div class="input-group">
                    <input type="text" class="form-control" id="inpostsa_redirect_uri" value="' . $escaped . '" readonly onclick="this.select();">
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-default" onclick="var f=document.getElementById(\'inpostsa_redirect_uri\');f.select();try{navigator.clipboard.writeText(f.value);}catch(e){document.execCommand(\'copy\');}">
                            <i class="icon-copy"></i> ' . $this->l('Copy') . '
                        </button>
                    </span>
                </div>
                <p class="help-block">' . $this->l('Paste this address as the Redirect URI when creating your credentials in the InPost Merchant Portal. It must match exactly.') . '</p>
            </div>
        </div>';
    }

    /**
     * Get OAuth2 connection status HTML
     *
     * @param bool $isConnected
     * @return string
     */
    protected function getOAuth2ConnectionStatusHtml(bool $isConnected): string
    {
        if ($isConnected) {
            return '<div class="alert alert-success">
                <i class="icon-check"></i> ' . $this->l('OAuth2 Connected') . '
            </div>';
        }

        return '<div class="alert alert-warning">
            <i class="icon-warning"></i> ' . $this->l('OAuth2 Not Connected') . '
        </div>';
    }

    /**
     * Get OAuth2 buttons HTML
     *
     * @param bool $isConnected
     * @return string
     */
    protected function getOAuth2ButtonsHtml(bool $isConnected): string
    {
        $currentUrl = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;

        if ($isConnected) {
            return '<button type="submit" name="disconnect_oauth2" class="btn btn-danger">
                <i class="icon-unlink"></i> ' . $this->l('Disconnect OAuth2') . '
            </button>
            <button type="submit" name="test_connection" class="btn btn-default" style="margin-left: 10px;">
                <i class="icon-stethoscope"></i> ' . $this->l('Test Connection') . '
            </button>';
        }

        return '<button type="submit" name="connect_oauth2" class="btn btn-primary">
            <i class="icon-link"></i> ' . $this->l('Connect with OAuth2') . '
        </button>';
    }

    /**
     * Handle OAuth2 connect action
     *
     * @return string
     */
    protected function handleOAuth2Connect(): string
    {
        try {
            $clientId = Configuration::get('INPOSTSA_CLIENT_ID');
            $clientSecret = Configuration::get('INPOSTSA_CLIENT_SECRET');
            $apiUrl = Configuration::get('INPOSTSA_API_URL');
            $authServiceUrl = Configuration::get('INPOSTSA_AUTH_URL');

            if (empty($clientId) || empty($clientSecret) || empty($apiUrl) || empty($authServiceUrl)) {
                return $this->displayError($this->l('Please configure Client ID, Client Secret, API URL, and Authorization Service URL before connecting.'));
            }

            // Build return URL - where to redirect after OAuth callback
            $returnUrl = $this->context->link->getAdminLink('AdminModules', true, [], [
                'configure' => $this->name,
                'module_name' => $this->name,
                'conf' => 4
            ]);

            // Redirect URI is the front callback controller (see OAuth2ServiceFactory)
            $authData = \Inpost\VonHalsky\Service\Auth\OAuth2ServiceFactory::create()->startAuthorization($returnUrl);

            // Redirect to authorization URL
            Tools::redirect($authData['authorizationUrl']);

            // In case redirect does not terminate execution, return an empty string to satisfy return type
            return '';
        } catch (\Exception $e) {
            return $this->displayError($this->l('OAuth2 connection failed: ') . $e->getMessage());
        }
    }

    /**
     * Handle OAuth2 disconnect action
     *
     * @return string
     */
    protected function handleOAuth2Disconnect(): string
    {
        try {
            $tokenManager = new \Inpost\VonHalsky\Service\ApiTokenManager();
            $tokenManager->clearTokens();

            return $this->displayConfirmation($this->l('OAuth2 connection has been disconnected successfully.'));

        } catch (\Exception $e) {
            return $this->displayError($this->l('Disconnect failed: ') . $e->getMessage());
        }
    }

    /**
     * Handle test connection action
     *
     * @return string
     */
    protected function handleTestConnection(): string
    {
        try {
            $accessToken = \Inpost\VonHalsky\Service\Auth\OAuth2ServiceFactory::create()->getValidAccessToken();

            if ($accessToken) {
                return $this->displayConfirmation($this->l('Connection test successful! API access is working properly.'));
            } else {
                return $this->displayError($this->l('Connection test failed. Please reconnect using OAuth2.'));
            }

        } catch (\Exception $e) {
            return $this->displayError($this->l('Connection test failed: ') . $e->getMessage());
        }
    }

    /**
     * Add CSS/JS to back office header
     */
    public function hookDisplayBackOfficeHeader()
    {
        // Always load CSS (needed for menu item hiding)
        $this->context->controller->addCSS($this->_path . 'views/css/back.css');
    }

    /**
     * Hook when product is updated
     *
     * @param array $params
     */
    public function hookActionProductUpdate($params)
    {
        $product = $params['product'] ?? null;
        if ($product instanceof \Product) {
            $this->syncProductOffers((int) $product->id, 'hookActionProductUpdate');
        }
    }

    /**
     * Hook when product quantity is updated
     *
     * @param array $params
     */
    public function hookActionUpdateQuantity($params)
    {
        // PrestaShop fires this for the combination and again for the product total
        // (id_product_attribute 0) - each call refreshes the offers selling that stock
        $this->syncProductOffers(
            (int) ($params['id_product'] ?? 0),
            'hookActionUpdateQuantity',
            (int) ($params['id_product_attribute'] ?? 0)
        );
    }

    /**
     * Combination saved (price impact, EAN, reference, attributes): refresh its offers
     *
     * @param array $params
     */
    public function hookActionObjectCombinationUpdateAfter($params)
    {
        $combination = $params['object'] ?? null;
        if ($combination instanceof \Combination && $combination->id) {
            $this->syncProductOffers((int) $combination->id_product, 'hookActionObjectCombinationUpdateAfter', (int) $combination->id);
        }
    }

    /**
     * Combination deleted: its offers have nothing left to sell - stock 0
     *
     * @param array $params
     */
    public function hookActionObjectCombinationDeleteAfter($params)
    {
        $combination = $params['object'] ?? null;
        if (!($combination instanceof \Combination) || !$combination->id) {
            return;
        }

        $idProduct = (int) $combination->id_product;
        try {
            $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
            if ($container === null) {
                // Stock is re-read before sending; the offer just needs the sync
                $this->flagProductOffersForApiSync($idProduct, (int) $combination->id);
                return;
            }

            $container->get('Inpost\VonHalsky\Service\Sync\ProductOfferSyncService')
                ->markCombinationDeleted($idProduct, (int) $combination->id);
        } catch (\Throwable $e) {
            \Inpost\VonHalsky\Service\Util\ShopLog::add(
                'InPost: Error in hookActionObjectCombinationDeleteAfter: ' . $e->getMessage(),
                3,
                null,
                'Product',
                $idProduct
            );
        }
    }

    /**
     * Specific price saved (promotion, price for a group): refresh the offers of
     * its product - their InPost price may come from it (GOKINT-1098)
     *
     * @param array $params
     */
    public function hookActionObjectSpecificPriceAddAfter($params)
    {
        $this->onSpecificPriceChange($params, 'hookActionObjectSpecificPriceAddAfter');
    }

    /**
     * @param array $params
     */
    public function hookActionObjectSpecificPriceUpdateAfter($params)
    {
        $this->onSpecificPriceChange($params, 'hookActionObjectSpecificPriceUpdateAfter');
    }

    /**
     * @param array $params
     */
    public function hookActionObjectSpecificPriceDeleteAfter($params)
    {
        $this->onSpecificPriceChange($params, 'hookActionObjectSpecificPriceDeleteAfter');
    }

    /**
     * Catalog price rule saved or deleted: it may change the price of any
     * offer - recalculate all of them in the offer_sync cron. PrestaShop applies
     * the rule after this hook, the cron runs later.
     *
     * @param array $params
     */
    public function hookActionObjectSpecificPriceRuleAddAfter($params)
    {
        \Inpost\VonHalsky\Service\Offer\OfferPriceRecalculator::request();
    }

    /**
     * @param array $params
     */
    public function hookActionObjectSpecificPriceRuleUpdateAfter($params)
    {
        \Inpost\VonHalsky\Service\Offer\OfferPriceRecalculator::request();
    }

    /**
     * @param array $params
     */
    public function hookActionObjectSpecificPriceRuleDeleteAfter($params)
    {
        \Inpost\VonHalsky\Service\Offer\OfferPriceRecalculator::request();
    }

    private function onSpecificPriceChange(array $params, string $hookName): void
    {
        $specificPrice = $params['object'] ?? null;
        if (!($specificPrice instanceof \SpecificPrice)) {
            return;
        }
        // Never an offer price: cart prices (the InPost order import sets the InPost
        // price that way), prices of one customer. Prices of catalog price rules come
        // with the rule hooks.
        if ((int) $specificPrice->id_cart > 0
            || (int) $specificPrice->id_customer > 0
            || (int) $specificPrice->id_specific_price_rule > 0
        ) {
            return;
        }

        // PrestaShop runs this hook inside ObjectModel::add/update/delete, before
        // SpecificPrice flushes its own cache - without this the old price is read
        \SpecificPrice::flushCache();
        if ((int) $specificPrice->id_product > 0) {
            $idProductAttribute = (int) $specificPrice->id_product_attribute;
            $this->syncProductOffers((int) $specificPrice->id_product, $hookName, $idProductAttribute > 0 ? $idProductAttribute : null);
        } else {
            // A specific price for all products
            \Inpost\VonHalsky\Service\Offer\OfferPriceRecalculator::request();
        }
    }

    /**
     * Real-time price/stock sync of the offers of a product.
     *
     * Outside the Symfony kernel (front office checkout, webservice) the module
     * services are not available - the offers are only flagged for the
     * offer_sync cron then.
     */
    /**
     * @param int|null $idProductAttribute null = every offer of the product; a combination id =
     *        its offers and the whole-product offer; 0 = only the whole-product offer
     */
    private function syncProductOffers(int $idProduct, string $hookName, ?int $idProductAttribute = null): void
    {
        if ($idProduct <= 0) {
            return;
        }

        try {
            $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
            if ($container === null) {
                $this->flagProductOffersForApiSync($idProduct, $idProductAttribute);
                return;
            }

            $container->get('Inpost\VonHalsky\Service\Sync\ProductOfferSyncService')
                ->syncProductUpdate($idProduct, $idProductAttribute);
        } catch (\Throwable $e) {
            \Inpost\VonHalsky\Service\Util\ShopLog::add(
                'InPost: Error in ' . $hookName . ': ' . $e->getMessage(),
                3,
                null,
                'Product',
                $idProduct
            );
        }
    }

    /**
     * Flag the offers of a product as requiring an API sync (picked up by the
     * offer_sync cron, which re-reads price and stock before sending). Scope of
     * $idProductAttribute as in syncProductOffers().
     */
    private function flagProductOffersForApiSync(int $idProduct, ?int $idProductAttribute = null): void
    {
        $where = 'prestashop_offer_id = ' . $idProduct;
        if ($idProductAttribute !== null) {
            $where .= ' AND prestashop_product_attribute_id IN (0, ' . (int) $idProductAttribute . ')';
        }

        \Db::getInstance()->update(
            'inpost_offer',
            [
                'require_api_sync' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            $where
        );
    }

    /**
     * Hook fired when a product image is added.
     */
    public function hookActionObjectImageAddAfter($params)
    {
        $this->markOfferDirtyByImage($params);
    }

    /**
     * Hook fired when a product image is updated (e.g. cover changed, position changed).
     */
    public function hookActionObjectImageUpdateAfter($params)
    {
        $this->markOfferDirtyByImage($params);
    }

    /**
     * Hook fired when a product image is deleted.
     */
    public function hookActionObjectImageDeleteAfter($params)
    {
        $this->markOfferDirtyByImage($params);
    }

    /**
     * Mark all offers tied to the image's product as requiring API resync.
     * Cron `offer_sync` will then PATCH the offer with the new images[] payload.
     *
     * Uses raw SQL (no Doctrine/Symfony container) so the hook works equally
     * in admin, CLI and front contexts.
     */
    private function markOfferDirtyByImage(array $params): void
    {
        $imageId = 0;
        try {
            $image = $params['object'] ?? null;
            if (!($image instanceof \Image)) {
                return;
            }
            $imageId = (int) $image->id;
            $idProduct = (int) $image->id_product;
            if (!$idProduct) {
                return;
            }

            $this->flagProductOffersForApiSync($idProduct);
        } catch (\Exception $e) {
            \Inpost\VonHalsky\Service\Util\ShopLog::add(
                'InPost: Error in image hook: ' . $e->getMessage(),
                3,
                null,
                'Image',
                $imageId
            );
        }
    }

    /**
     * Our email-type names mapped to PrestaShop 8.2 mail template names.
     * There is no dedicated "invoice" template in PS 8.2 (the invoice PDF is an
     * attachment to order_conf / status mails), so it is not listed here.
     */
    private const EMAIL_TYPE_TEMPLATES = [
        'order_confirmation' => ['order_conf'],
        'order_status_update' => [
            'preparation', 'shipped', 'payment', 'order_canceled', 'refund',
            'payment_error', 'outofstock', 'bankwire', 'cheque',
            'cashondelivery', 'order_changed',
        ],
        'shipment' => ['in_transit'],
        'credit_slip' => ['credit_slip'],
        'order_comment' => ['order_merchant_comment'],
        'virtual_product' => ['download_product'],
    ];

    /**
     * Hook before email is sent. Suppresses selected customer emails for orders
     * that originate from InPost, so the customer is not double-notified (by the
     * shop and by InPost). Fail-open: any unexpected condition lets the mail
     * through — a missed suppression is a cosmetic duplicate, a wrongly blocked
     * mail is a lost order notification.
     *
     * @param array $params
     * @return bool true to allow the mail, false to abort Mail::send()
     */
    public function hookActionEmailSendBefore($params)
    {
        try {
            // Gate 1: feature master switch
            if (!Configuration::get('INPOSTSA_DISABLE_CUSTOMER_EMAILS')) {
                return true;
            }

            $template = (string) ($params['template'] ?? '');
            if ($template === '') {
                return true;
            }

            // Gate 2: only templates in the configured type set (cheap check
            // first — this hook fires on every mail the shop sends).
            if (!$this->isInpostSuppressedTemplate($template)) {
                return true;
            }

            $idOrder = $this->resolveOrderIdFromMailParams($params);
            if (!$idOrder) {
                return true;
            }

            if (!$this->isInpostOrder($idOrder)) {
                return true;
            }

            if (Configuration::get('INPOSTSA_LOG_BLOCKED_EMAILS')) {
                \Inpost\VonHalsky\Service\Util\ShopLog::add(
                    'InPost: suppressed customer email "' . $template . '" for InPost order',
                    1,
                    null,
                    'Order',
                    $idOrder
                );
            }

            return false;
        } catch (\Throwable $e) {
            \Inpost\VonHalsky\Service\Util\ShopLog::add(
                'InPost: Error in email hook (mail allowed through): ' . $e->getMessage(),
                3,
                null,
                'Mail',
                null
            );
            return true;
        }
    }

    /**
     * Whether the given PS mail template is in the configured suppression set.
     * Empty configuration ⇒ suppress every mapped template.
     */
    private function isInpostSuppressedTemplate(string $template): bool
    {
        $configured = (string) Configuration::get('INPOSTSA_EMAIL_TYPES_TO_DISABLE');
        $types = array_filter(array_map('trim', explode(',', $configured)));
        if (empty($types)) {
            $types = array_keys(self::EMAIL_TYPE_TEMPLATES);
        }

        foreach ($types as $type) {
            if (isset(self::EMAIL_TYPE_TEMPLATES[$type])
                && in_array($template, self::EMAIL_TYPE_TEMPLATES[$type], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the PrestaShop order id from mail params. Prefers the {id_order}
     * template var; falls back to the {order_name} order reference.
     */
    private function resolveOrderIdFromMailParams(array $params): int
    {
        $vars = $params['templateVars'] ?? null;
        if (!is_array($vars)) {
            return 0;
        }

        $idOrder = (int) ($vars['{id_order}'] ?? 0);
        if ($idOrder > 0) {
            return $idOrder;
        }

        $reference = trim((string) ($vars['{order_name}'] ?? ''));
        if ($reference !== '') {
            $order = \Order::getByReference($reference);
            if ($order && $order->count() > 0) {
                $first = $order->getFirst();
                if ($first instanceof \Order) {
                    return (int) $first->id;
                }
            }
        }

        return 0;
    }

    /**
     * Whether the given PrestaShop order originates from InPost. Raw SQL keeps
     * the hook container-free; _DB_PREFIX_ is used directly because the
     * InpostOrder entity hardcodes the ps_ prefix.
     */
    private function isInpostOrder(int $idOrder): bool
    {
        return (bool) \Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'inpost_order` WHERE `id_order` = ' . (int) $idOrder . ' LIMIT 1'
        );
    }

    /**
     * Hook for cron job execution (PrestaShop CronJobsModule integration)
     *
     * @param array $params
     * @return void
     */
    /**
     * Inject the "List on InPost" bulk action into the product grid (GOKINT-930).
     * Container-free and defensive: a throw here would break the whole Catalog
     * page, so any failure is swallowed and logged.
     *
     * @param array $params
     * @return void
     */
    public function hookActionProductGridDefinitionModifier(array $params)
    {
        try {
            if (!Configuration::get('INPOSTSA_BULK_LIST_ENABLED')) {
                return;
            }

            $definition = $params['definition'] ?? null;
            if (!($definition instanceof \PrestaShop\PrestaShop\Core\Grid\Definition\GridDefinition)) {
                return;
            }

            $action = new \PrestaShop\PrestaShop\Core\Grid\Action\Bulk\Type\SubmitBulkAction('inpost_bulk_list');
            $action->setName($this->l('List on InPost'))
                ->setOptions([
                    'submit_route' => 'inpost_bulk_offers_select',
                    'confirm_message' => $this->l('Prepare InPost offers for the selected products?'),
                ]);

            $definition->getBulkActions()->add($action);
        } catch (\Throwable $e) {
            \Inpost\VonHalsky\Service\Util\ShopLog::add(
                'InPost: Error in product grid modifier hook: ' . $e->getMessage(),
                3,
                null,
                'Product',
                null
            );
        }
    }

    public function hookActionCronJob($params)
    {
        try {
            $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
            if (!$container) {
                \Inpost\VonHalsky\Service\Util\ShopLog::add(
                    'InPost Cron: Service container not available',
                    3,
                    null,
                    'InPost',
                    0
                );
                return;
            }

            // Get CronSyncCommand - it has all jobs and configuration logic
            $cronCommand = $container->get('Inpost\VonHalsky\Command\CronSyncCommand');
            if (!$cronCommand) {
                \Inpost\VonHalsky\Service\Util\ShopLog::add(
                    'InPost Cron: CronSyncCommand not available',
                    3,
                    null,
                    'InPost',
                    0
                );
                return;
            }

            // Execute via Symfony console application
            $application = new \Symfony\Component\Console\Application();
            $application->add($cronCommand);
            $application->setAutoExit(false);

            $input = new \Symfony\Component\Console\Input\ArrayInput([
                'command' => 'inpost:cron:run',
            ]);
            $output = new \Symfony\Component\Console\Output\NullOutput();

            $exitCode = $application->run($input, $output);

            if ($exitCode !== 0) {
                \Inpost\VonHalsky\Service\Util\ShopLog::add(
                    'InPost Cron: Command failed with exit code ' . $exitCode,
                    2,
                    null,
                    'InPost',
                    0
                );
            }

        } catch (\Exception $e) {
            \Inpost\VonHalsky\Service\Util\ShopLog::add(
                'InPost Cron: General error: ' . $e->getMessage(),
                3,
                null,
                'InPost',
                0
            );
        }
    }

    /**
     * Last run of the order event cron job (as recorded by CronManager)
     *
     * @return string|null
     */
    private function getLastEventCronExecution(): ?string
    {
        $lastRun = $this->getEventCronLastRunTimestamp();

        return $lastRun !== null ? date('Y-m-d H:i:s', $lastRun) : null;
    }

    /**
     * Next run of the order event cron job: last run + interval derived from the
     * configured expression - the same rule CronManager uses to decide when to run.
     *
     * @return string|null
     */
    private function getNextEventCronExecution(): ?string
    {
        if (!Configuration::get('INPOSTSA_ORDER_EVENTS_CRON_ENABLED')) {
            return null;
        }

        $lastRun = $this->getEventCronLastRunTimestamp();
        if ($lastRun === null) {
            return null;
        }

        $interval = (new \Inpost\VonHalsky\Service\Cron\CronJobConfigurator())->getIntervalFromCronExpression(
            (string) Configuration::get('INPOSTSA_ORDER_EVENTS_CRON_EXPRESSION'),
            600
        );

        return date('Y-m-d H:i:s', $lastRun + $interval);
    }

    private function getEventCronLastRunTimestamp(): ?int
    {
        try {
            /** @var \Inpost\VonHalsky\Service\Cron\CronManager $cronManager */
            $cronManager = $this->get(\Inpost\VonHalsky\Service\Cron\CronManager::class);

            return $cronManager->getLastRunTimestamp('order_event_sync');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get OrganizationService instance
     *
     * @return \Inpost\VonHalsky\Service\Api\Organization\OrganizationService
     */
    private function getOrganizationService()
    {
        return new \Inpost\VonHalsky\Service\Api\Organization\OrganizationService(
            \Inpost\VonHalsky\Service\Auth\OAuth2ServiceFactory::create(),
            new \Inpost\VonHalsky\Helper\Config(),
            new \Inpost\VonHalsky\Service\Api\ApiErrorHandler()
        );
    }

    /**
     * Get organization options for dropdown
     *
     * @return array
     */
    private function getOrganizationOptions()
    {
        $options = [
            [
                'id' => '',
                'name' => $this->l('-- Select Organization --'),
            ],
        ];

        try {
            $organizationService = $this->getOrganizationService();
            $organizations = $organizationService->getActiveOrganizations();

            foreach ($organizations as $org) {
                $options[] = [
                    'id' => $org['organization_id'],
                    'name' => $org['name'] . ' (' . $org['organization_id'] . ')',
                ];
            }
        } catch (Exception $e) {
            \Inpost\VonHalsky\Service\Util\ShopLog::add(
                'InPost SA: Error loading organizations - ' . $e->getMessage(),
                3
            );

            // If error occurs, add current config value as fallback option
            $currentOrgId = Configuration::get('INPOSTSA_ORGANIZATION_ID');
            if ($currentOrgId) {
                $options[] = [
                    'id' => $currentOrgId,
                    'name' => $currentOrgId . ' ' . $this->l('(from config)'),
                ];
            }
        }

        return $options;
    }
}
