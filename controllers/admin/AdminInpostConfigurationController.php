<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Menu shortcut controller.
 *
 * Exists only so the module's admin menu can carry a "Configuration" entry next
 * to Offers / Orders / Category Mapping. It immediately redirects to the module
 * configuration page (AdminModules&configure=inpostvonhalsky) and renders nothing.
 */
class AdminInpostConfigurationController extends ModuleAdminController
{
    public function initContent()
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminModules', true, [], [
                'configure' => 'inpostvonhalsky',
            ])
        );
    }
}
