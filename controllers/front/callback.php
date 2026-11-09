<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class InpostvonhalskyCallbackModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        // Only the OAuth2 redirect from InPost is handled here
        if ($code = Tools::getValue('code')) {
            $this->processCallback($code);
            return;
        }

        Tools::redirect($this->context->link->getPageLink('index'));
    }

    private function processCallback($code)
    {
        $state = Tools::getValue('state');

        try {
            if (!$state) {
                throw new Exception('State parameter missing');
            }

            $tokenManager = new \Inpost\VonHalsky\Service\ApiTokenManager();
            $returnUrl = $tokenManager->getReturnUrl($state);

            $oauthService = \Inpost\VonHalsky\Service\Auth\OAuth2ServiceFactory::create();
            $oauthService->exchangeCode($code, $state);

            // Redirect to stored return URL or fallback to home
            if ($returnUrl) {
                Tools::redirect($returnUrl);
            } else {
                Tools::redirect($this->context->link->getPageLink('index'));
            }
        } catch (Exception $e) {
            // Try to get return URL for error redirect
            $tokenManager = new \Inpost\VonHalsky\Service\ApiTokenManager();
            $returnUrl = $state ? $tokenManager->getReturnUrl($state) : null;

            if ($returnUrl) {
                // Remove conf param and add error
                $returnUrl = preg_replace('/[&?]conf=\d+/', '', $returnUrl);
                $separator = strpos($returnUrl, '?') !== false ? '&' : '?';
                Tools::redirect($returnUrl . $separator . 'oauth_error=' . urlencode($e->getMessage()));
            } else {
                die('OAuth error: ' . htmlspecialchars($e->getMessage()));
            }
        }
    }
}