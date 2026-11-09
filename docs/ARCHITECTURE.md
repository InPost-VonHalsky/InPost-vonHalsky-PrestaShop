# InPost von Halsky - Architecture Documentation

> **Comprehensive technical architecture overview for developers and system architects**

---

## 📋 **Table of Contents**

- [Overview](#overview)
- [Technology Stack](#technology-stack)
- [System Architecture](#system-architecture)
- [Module Structure](#module-structure)
- [Core Components](#core-components)
- [Data Flow](#data-flow)
- [Design Patterns](#design-patterns)
- [Security Architecture](#security-architecture)
- [Performance Optimization](#performance-optimization)
- [PrestaShop 9 Compatibility](#prestashop-9-compatibility)
- [Integration Points](#integration-points)
- [Scalability Considerations](#scalability-considerations)

---

## 🎯 **Overview**

The InPost von Halsky module is built using **modern Symfony-based architecture** integrated with PrestaShop 8+. It follows **service-oriented design principles** with clear separation of concerns across layers:

- **Presentation Layer**: Symfony controllers, Twig templates, JavaScript
- **Business Logic Layer**: Service classes, validators, transformers
- **Data Access Layer**: Doctrine entities and repositories
- **Integration Layer**: OAuth2 service, API client, HTTP transport

### **Architectural Goals**

1. **Maintainability**: Clean code, SOLID principles, testable components
2. **Scalability**: Efficient batch processing, optimized queries, caching
3. **Forward Compatibility**: PrestaShop 9-ready (Doctrine, Symfony HttpClient)
4. **Security**: OAuth2, encrypted credentials, input validation
5. **Performance**: Database indexing, batch operations, async processing

---

## 🛠️ **Technology Stack**

### **Core Framework**
- **PrestaShop**: 8.0.0 to 8.99.99 (8.x)
- **Symfony Components** (version depends on PrestaShop version):
  - `symfony/service-contracts` - Service container interfaces
  - `symfony/console` - CLI commands (PS 8.x only)
  - `symfony/dependency-injection` - Service container
  - `symfony/routing` - URL routing (PS 8.x)
  - `symfony/form` - Form building (PS 8.x)
  - `symfony/validator` - Data validation (PS 8.x)

### **HTTP Client & Authentication**
- **Guzzle HTTP Client**: `guzzlehttp/guzzle` ^7.0
- **OAuth2 Client**: `league/oauth2-client` ^2.7 (PKCE support)
- **InPost API SDK**: `inpostapi/inpost-vonhalsky-api-sdk-php` ^2.0 ([GitHub](https://github.com/InPost-VonHalsky/InPost-vonHalsky-api-sdk-php))

### **Database**
- **ORM**: Doctrine 2.x (via PrestaShop's Doctrine integration)
- **Database**: MySQL 5.7+ / MariaDB 10.3+
- **Schema Management**: Doctrine annotations + SQL migrations (`install/install.sql`)

### **Frontend**
- **Templating**: Twig (PS 8.x)
- **JavaScript**: Vanilla JS + AJAX
- **CSS**: PrestaShop admin theme
- **UI Components**: PrestaShop HelperTreeCategories, custom autocomplete

### **Development Tools**
- **Composer**: Dependency management
- **PHP**: 8.1
- **PSR Standards**: PSR-12 coding style, PSR-4 autoloading

---

## 🏗️ **System Architecture**

### **High-Level Architecture Diagram**

```
┌─────────────────────────────────────────────────────────────────┐
│                      PrestaShop Admin Interface                   │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐ │
│  │Configuration │  │ Offer CRUD   │  │ Category Mapping    │ │
│  │    Page      │  │   Interface  │  │   (Dual Tree View)  │ │
│  └──────┬───────┘  └──────┬───────┘  └──────────┬───────────┘ │
└─────────┼──────────────────┼──────────────────────┼─────────────┘
          │                  │                      │
          │  AJAX / Form     │  AJAX / Form         │  AJAX
          ▼                  ▼                      ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Symfony Controllers Layer                     │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐ │
│  │ Configuration│  │    Offer     │  │  CategoryMapping    │ │
│  │ Controller   │  │  Controller  │  │    Controller       │ │
│  └──────┬───────┘  └──────┬───────┘  └──────────┬───────────┘ │
└─────────┼──────────────────┼──────────────────────┼─────────────┘
          │                  │                      │
          │  Service Call    │  Service Call        │  Service Call
          ▼                  ▼                      ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Business Logic Layer                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐ │
│  │ OAuth2       │  │    Offer     │  │    Category         │ │
│  │ Service      │  │   Service    │  │    Service          │ │
│  └──────┬───────┘  └──────┬───────┘  └──────────┬───────────┘ │
│         │                  │                      │              │
│  ┌──────▼───────┐  ┌──────▼───────┐  ┌──────────▼───────────┐ │
│  │  API Client  │  │ Validator    │  │    Transformer      │ │
│  │  (Guzzle)    │  │  Services    │  │    Services         │ │
│  └──────┬───────┘  └──────────────┘  └─────────────────────┘ │
└─────────┼──────────────────┬──────────────────┬────────────────┘
          │                  │                  │
          │  HTTP Req        │  Repository      │  Repository
          ▼                  ▼                  ▼
┌─────────────────┐    ┌─────────────────────────────────────────┐
│  InPost API     │    │        Doctrine Data Access Layer       │
│  - OAuth2 Auth  │    │  ┌──────────────┐  ┌────────────────┐  │
│  - Categories   │    │  │Repositories  │  │   Entities     │  │
│  - Attributes   │    │  │- OfferRepo   │  │- InpostOffer   │  │
│  - Offers       │    │  │- CategoryRepo│  │- InpostCategory│  │
│  - Orders       │    │  │- AttributeRepo   │- InpostAttribute│  │
│  - Events       │    │  └──────┬───────┘  └────────┬───────┘  │
└─────────────────┘    └─────────┼──────────────────┼───────────┘
                                 │                  │
                                 │  Doctrine ORM    │
                                 ▼                  ▼
                        ┌─────────────────────────────────────────┐
                        │         MySQL / MariaDB                 │
                        │  17 Tables:                             │
                        │  - ps_inpost_offer                      │
                        │  - ps_inpost_offer_product              │
                        │  - ps_inpost_category_mapping           │
                        │  - ps_inpost_attribute                  │
                        │  - ps_inpost_offer_event                │
                        │  - ps_inpost_order                      │
                        │  - ...and 11 more                       │
                        └─────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│              Background Processing (Cron Jobs)                   │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐ │
│  │ Sync         │  │ Sync Offers  │  │ Sync Events         │ │
│  │ Categories   │  │ & Send       │  │ & Process           │ │
│  └──────────────┘  └──────────────┘  └──────────────────────┘ │
│          ▲                  ▲                      ▲            │
│          │ AJAX Endpoints (AdminInpostCronController)          │
│          │ Console Commands (src/Command/) for development     │
└──────────┴──────────────────┴──────────────────────┴────────────┘
```

### **Layer Responsibilities**

| Layer | Responsibility | Technologies |
|-------|----------------|--------------|
| **Presentation** | User interface, forms, templates | Twig, JavaScript, Symfony Forms |
| **Controller** | HTTP request handling, route mapping | Symfony Controllers |
| **Business Logic** | Core business rules, transformations | Service classes, validators |
| **Data Access** | Database operations, queries | Doctrine repositories, entities |
| **Integration** | External API communication | Guzzle HTTP Client, OAuth2 (PKCE) |
| **Background** | Async processing, scheduled tasks | AJAX endpoints, Symfony Console (PS 8.x) |

---

## 📁 **Module Structure**

```
modules/inpostvonhalsky/
│
├── inpostvonhalsky.php                    # Main module class (PSR-4 autoload, hooks)
├── composer.json                   # Dependencies and autoload configuration
├── logo.png                        # Module logo (64x64)
│
├── config/
│   ├── services.yml               # Symfony service definitions
│   └── routes.yml                 # Symfony routes (admin endpoints)
│
├── src/
│   ├── Command/                   # Symfony Console commands (PS 8.x, development)
│   │   ├── SyncCategoriesCommand.php
│   │   ├── SyncAttributesCommand.php
│   │   ├── SyncOffersCommand.php
│   │   ├── SyncEventsCommand.php
│   │   ├── SendOfferCommand.php
│   │   ├── OfferAttachmentsCommand.php  # inpost:offer:attachments (1.1.58)
│   │   ├── SendPendingOffersCommand.php
│   │   ├── StatusCommand.php
│   │   ├── SetRefreshTokenCommand.php
│   │   └── TestCategoryDepthCommand.php
│   │
│   ├── Controller/
│   │   └── Admin/                 # Admin controllers
│   │       ├── AdminInpostCronController.php   # AJAX cron endpoints
│   │       ├── OfferController.php             # Offer CRUD
│   │       ├── CategoryMappingController.php   # Category mapping
│   │       ├── AttributeMappingController.php  # Attribute mapping
│   │       └── Ajax/                           # AJAX endpoints
│   │           ├── CategoryTreeController.php
│   │           ├── CategoryController.php
│   │           ├── EventController.php
│   │           ├── OrganizationController.php
│   │           ├── ProductAutocompleteController.php
│   │           └── RefreshOrganizationsController.php
│   │
│   ├── Entity/                    # Doctrine entities (16 entities)
│   │   ├── Offer.php
│   │   ├── OfferProduct.php
│   │   ├── OfferAttributes.php
│   │   ├── OfferStock.php
│   │   ├── OfferPrice.php
│   │   ├── OfferManufacturer.php
│   │   ├── OfferManual.php
│   │   ├── OfferEvent.php
│   │   ├── OfferGpsr.php
│   │   ├── InpostOrder.php
│   │   ├── CategoryMapping.php
│   │   ├── CategoryLink.php
│   │   ├── Attribute.php
│   │   ├── CategoryAttribute.php
│   │   ├── AttributeDictionary.php
│   │   └── Organization.php
│   │
│   ├── Repository/                # Doctrine repositories
│   │   ├── OfferRepository.php
│   │   ├── CategoryMappingRepository.php
│   │   ├── AttributeRepository.php
│   │   ├── OrganizationRepository.php
│   │   └── ... (one per entity)
│   │
│   ├── Form/
│   │   └── Type/                  # Form types (PS 8.x)
│   │       └── OfferType.php
│   │
│   ├── Service/                   # Business logic services
│   │   ├── ApiTokenManager.php    # Token storage and management
│   │   ├── Auth/
│   │   │   ├── OAuth2Service.php              # OAuth2 authentication (PKCE)
│   │   │   └── OAuth2ServiceFactory.php       # Factory for service creation
│   │   ├── Api/
│   │   │   ├── ApiClient.php                  # Base HTTP client (Guzzle)
│   │   │   ├── ApiException.php               # API exception handling
│   │   │   ├── ApiErrorHandler.php            # Error handling utilities
│   │   │   ├── ApiServiceAbstract.php         # Base API service
│   │   │   ├── HttpClientFactory.php           # HTTP client factory
│   │   │   ├── OrganizationApiService.php     # Organizations endpoint
│   │   │   ├── CategoryApiService.php         # Categories endpoint
│   │   │   ├── AttributeApiService.php        # Attributes endpoint
│   │   │   ├── OfferApiService.php            # Offers endpoint
│   │   │   └── EventApiService.php            # Events endpoint
│   │   ├── Business/
│   │   │   ├── OfferBuilderService.php        # Build offer from product
│   │   │   └── OfferValidatorService.php      # Validate before send
│   │   ├── Sync/
│   │   │   ├── OfferSyncService.php           # Offer sync logic
│   │   │   └── ProductOfferSyncService.php   # Product-to-offer sync
│   │   ├── Offer/
│   │   │   ├── OfferValidationService.php    # Comprehensive validation
│   │   │   ├── SendOfferService.php          # Send to InPost API
│   │   │   ├── ImageAttachmentService.php    # Image upload handling
│   │   │   ├── OfferAttachmentService.php    # Document attachments: queue, upload, removal (1.1.58)
│   │   │   ├── OfferAttachmentStorage.php    # Uploaded files in download/inpostvonhalsky/
│   │   │   ├── OfferAttachmentValidator.php  # Type, size (10 MB), PDF/JPG/PNG checks
│   │   │   ├── OfferPriceResolver.php        # InPost price: customer group, % change, rounding (1.1.57)
│   │   │   ├── OfferPriceRecalculator.php    # Recalculates offer prices after a settings or price change
│   │   │   └── BulkPriceEditService.php      # "Set price" on the offer list (preview + apply)
│   │   ├── Mapping/
│   │   │   ├── CategoryMappingService.php    # Category mapping logic
│   │   │   ├── AttributeMappingService.php   # Attribute mapping logic
│   │   │   └── ProductMappingService.php     # Product mapping orchestration
│   │   ├── Cron/
│   │   │   ├── CronExecutor.php             # Cron job execution
│   │   │   └── OfferAttachmentSyncJob.php   # offer_attachment_sync (every 3 min): attachment queue (1.1.58)
│   │   └── Util/
│   │       ├── LoggerService.php            # Centralized logging
│   │       ├── InpostLoggerService.php      # Module-specific logger
│   │       ├── ConfigurationService.php     # Config access helper
│   │       └── CronExpressionParser.php     # Cron expression parsing
│   │
│   └── Validator/                 # Custom validators
│       └── ... (validators for business rules)
│
├── views/
│   └── templates/
│       └── admin/                 # Twig admin templates
│           ├── configuration/
│           │   └── form.html.twig           # Multi-tab config form
│           ├── offer/
│           │   ├── list.html.twig           # Offer grid
│           │   ├── form.html.twig           # Offer form
│           │   └── send_modal.html.twig     # Send confirmation
│           ├── category_mapping/
│           │   ├── list.html.twig           # Mapping list
│           │   └── form.html.twig           # Dual-tree form
│           ├── attribute_mapping/
│           │   └── form.html.twig           # Attribute mapping
│           ├── orders/
│           │   ├── list.html.twig           # Orders grid
│           │   └── detail.html.twig         # Order detail
│           └── events/
│               └── list.html.twig           # Events log
│
├── translations/                  # Translation files
│   ├── pl.php                    # Polish
│   └── en.php                    # English
│
├── install/
│   ├── install.sql               # Initial schema creation
│   └── uninstall.sql             # Schema cleanup
│
├── sql/                          # SQL migration scripts
│   └── ... (upgrade scripts)
│
├── views/                        # Frontend assets
│   ├── templates/                # Twig/Smarty templates
│   ├── css/                      # Stylesheets
│   └── js/                       # JavaScript
│
├── translations/                 # Translation files
│   ├── pl.php                    # Polish
│   └── en.php                    # English
│
└── docs/                         # Documentation
    ├── quick-start.md            # User guide (Polish)
    ├── quick-start.en.md         # User guide (English)
    ├── ARCHITECTURE.md           # This file
    ├── DATABASE_SCHEMA.md        # Database structure
    └── screenshots/              # Screenshots used by the user guide
```

---

## 🔧 **Core Components**

### **1. Main Module Class** (`inpostvonhalsky.php`)

```php
<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

// Autoload Composer dependencies
$autoloadFile = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadFile)) {
    require_once $autoloadFile;
}

class Inpostvonhalsky extends Module
{
    public function __construct()
    {
        $this->name = 'inpostvonhalsky';
        $this->tab = 'shipping_logistics';
        $this->version = '1.1.0';
        $this->author = 'SII Poland';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => '8.99.99'
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('InPost von Halsky');
        $this->description = $this->l('Integration module for InPost von Halsky platform.');
    }

    public function install()
    {
        if (!parent::install() ||
            !$this->registerHook('header') ||
            !$this->registerHook('displayBackOfficeHeader') ||
            !$this->registerHook('actionProductAdd') ||
            !$this->registerHook('actionProductUpdate') ||
            !$this->registerHook('actionProductDelete') ||
            !$this->registerHook('actionCategoryUpdate') ||
            !$this->registerHook('actionCategoryDelete') ||
            !$this->registerHook('actionUpdateQuantity') ||
            !$this->registerHook('actionEmailSendBefore') ||
            !$this->registerHook('actionCronJob')
        ) {
            return false;
        }

        // Create configuration values, execute install.sql, etc.
        return true;
    }
}
```

**Responsibilities**:
- Module registration and metadata
- Installation/uninstallation orchestration
- Hook registration (product, category, stock, email, cron hooks)
- Autoloader initialization
- Admin tab registration

---

### **2. OAuth2 Service** (`Service/Auth/OAuth2Service.php`)

```php
<?php
namespace Inpost\VonHalsky\Service\Auth;

use GuzzleHttp\Client as HttpClient;
use League\OAuth2\Client\Token\AccessToken;
use Inpost\VonHalsky\Service\ApiTokenManager;

class OAuth2Service
{
    private ApiTokenManager $tokenManager;
    private string $clientId;
    private string $clientSecret;
    private string $authorizationServiceUrl;
    private string $redirectUri;

    public function __construct(
        ApiTokenManager $tokenManager,
        string $clientId,
        string $clientSecret,
        string $apiUrl,
        string $authorizationServiceUrl,
        string $redirectUri
    ) {
        $this->tokenManager = $tokenManager;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->authorizationServiceUrl = $authorizationServiceUrl;
        $this->redirectUri = $redirectUri;
    }

    /**
     * Exchange authorization code for access token (PKCE flow)
     */
    public function exchangeCode(string $code, string $state): AccessToken
    {
        $codeVerifier = $this->tokenManager->getPkceCodeVerifier($state);
        
        $httpClient = new HttpClient();
        $response = $httpClient->post($this->authorizationServiceUrl . '/oauth2/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
                'code_verifier' => $codeVerifier
            ]
        ]);

        $responseData = json_decode($response->getBody()->getContents(), true);
        $accessToken = new AccessToken($responseData);
        
        $this->tokenManager->storeTokens($accessToken);
        return $accessToken;
    }

    /**
     * Get valid access token (auto-refresh if needed)
     */
    public function getValidAccessToken(): ?string
    {
        $accessToken = $this->tokenManager->getAccessToken();
        
        if ($accessToken && !$this->isTokenExpired($accessToken)) {
            return $accessToken;
        }

        // Try to refresh
        $refreshToken = $this->tokenManager->getRefreshToken();
        if ($refreshToken) {
            $newAccessToken = $this->refreshToken($refreshToken);
            return $newAccessToken->getToken();
        }

        return null; // User needs to reconnect
    }
}
```

**Responsibilities**:
- OAuth2 PKCE (Proof Key for Code Exchange) flow
- Token acquisition and refresh
- Token expiration management
- Integration with ApiTokenManager for storage

---

### **3. API Client** (`Service/Api/ApiClient.php`)

```php
<?php
namespace Inpost\VonHalsky\Service\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Inpost\VonHalsky\Service\Auth\OAuth2Service;
use Inpost\VonHalsky\Service\Util\ConfigurationService;
use Inpost\VonHalsky\Service\Util\LoggerService;

class ApiClient
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 1000;

    private $httpClient;
    private $oauth2Service;
    private $config;
    private $logger;

        $this->oauth2Service = $oauth2Service;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function get(string $endpoint, array $query = [], array $headers = []): array
    {
        return $this->request('GET', $endpoint, [
            'query' => $query,
            'headers' => $headers,
        ]);
    }

    public function post(string $endpoint, ?array $data = null, array $headers = []): array
    {
        $options = ['headers' => $headers];
        if ($data !== null) {
            $options['json'] = $data;
        }
        return $this->request('POST', $endpoint, $options);
    }

    private function request(string $method, string $endpoint, array $options = []): array
    {
        $url = $this->buildUrl($endpoint);
        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            
            try {
                // Inject OAuth2 token
                $options['headers'] = $this->injectAuthorizationHeader($options['headers'] ?? []);
                $options['headers']['Accept'] = 'application/json';
                $options['headers']['Content-Type'] = 'application/json';
                $options['http_errors'] = false; // Handle errors manually

                $response = $this->httpClient->request($method, $url, $options);
                $statusCode = $response->getStatusCode();
                $content = $response->getBody()->getContents();

                // Handle success (2xx)
                if ($statusCode >= 200 && $statusCode < 300) {
                    return $this->decodeJsonResponse($content, $url);
                }

                // Handle 401 - try token refresh once
                if ($statusCode === 401 && $attempt === 1) {
                    $this->oauth2Service->refreshAccessToken();
                    continue; // Retry with new token
                }

                // Handle client errors (4xx) - don't retry
                if ($statusCode >= 400 && $statusCode < 500) {
                    throw new ApiException(
                        "Client error: {$statusCode}",
                        $statusCode,
                        $this->decodeJsonResponse($content, $url)
                    );
                }

                // Handle server errors (5xx) - retry with backoff
                if ($statusCode >= 500 && $attempt < self::MAX_RETRIES) {
                    $delay = self::RETRY_DELAY_MS * pow(2, $attempt - 1);
                    usleep($delay * 1000);
                    continue;
                }

                throw new ApiException("Unexpected status: {$statusCode}", $statusCode);

            } catch (GuzzleException $e) {
                if ($attempt < self::MAX_RETRIES) {
                    $delay = self::RETRY_DELAY_MS * pow(2, $attempt - 1);
                    usleep($delay * 1000);
                    continue;
                }
                throw new ApiException("HTTP error: {$e->getMessage()}", 0, null, $e);
            }
        }
    }

    private function injectAuthorizationHeader(array $headers): array
    {
        $token = $this->oauth2Service->getValidAccessToken();
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }
}
```

**Responsibilities**:
- HTTP request abstraction using Guzzle
- Automatic OAuth2 token injection and refresh
- Exponential backoff retry logic for transient failures
- Error handling and logging
- JSON request/response handling

---

### **4. Doctrine Entity Example** (`Entity/Offer.php`)

```php
<?php
namespace Inpost\VonHalsky\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="Inpost\VonHalsky\Repository\OfferRepository")
 * @ORM\Table(
 *     name="inpost_offer",
 *     indexes={
 *         @ORM\Index(name="IDX_STATUS", columns={"status"}),
 *         @ORM\Index(name="IDX_REQUIRE_API_SYNC", columns={"require_api_sync"}),
 *     }
 * )
 */
class Offer
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PUBLISHED = 'PUBLISHED';
    public const STATUS_CLOSED = 'CLOSED';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     * @ORM\Column(type="integer", name="offer_id", options={"unsigned"=true})
     */
    private ?int $offerId = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=true, name="inpost_offer_id")
     */
    private ?string $inpostOfferId = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=false, name="organization_id")
     */
    private string $organizationId;

    /**
     * @ORM\Column(type="integer", nullable=false, name="shop_id", options={"unsigned"=true, "default"=1})
     */
    private int $shopId = 1;

    /**
     * @ORM\Column(type="string", length=20, nullable=false, options={"default"="DRAFT"})
     */
    private string $status = 'DRAFT';

    /**
     * @ORM\Column(type="boolean", name="require_api_sync", options={"default": false})
     */
    private bool $requireApiSync = false;

    /**
     * @ORM\OneToOne(targetEntity="OfferProduct", mappedBy="offer", cascade={"persist", "remove"})
     */
    private ?OfferProduct $product = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, name="status")
     */
    private ?string $status = null;

    /**
     * @ORM\Column(type="boolean", name="require_data_update", options={"default": false})
     */
    private bool $requireDataUpdate = false;

    /**
     * @ORM\Column(type="boolean", name="require_api_sync", options={"default": false})
     */
    private bool $requireApiSync = false;

    /**
     * @ORM\OneToOne(targetEntity="InpostOfferProduct", mappedBy="offer", cascade={"persist", "remove"})
     */
    private ?InpostOfferProduct $product = null;

    // Getters and setters...
}
```

**Key Features**:
- Doctrine annotations for ORM mapping
- Relationship definitions (OneToOne, OneToMany, ManyToOne)
- Type hints (PHP 8.1+)
- Business logic flags (`require_data_update`, `require_api_sync`)

---

### **5. Symfony Controller Example** (`Controller/Admin/OfferController.php`)

```php
<?php
namespace Inpost\VonHalsky\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Inpost\VonHalsky\Form\Type\OfferType;
use Inpost\VonHalsky\Service\Offer\OfferBuilderService;

class OfferController extends AbstractController
{
    private OfferBuilderService $offerBuilder;

    public function __construct(OfferBuilderService $offerBuilder)
    {
        $this->offerBuilder = $offerBuilder;
    }

    public function listAction(Request $request): Response
    {
        // Repository query for offers grid
        // Render list.html.twig
    }

    public function createAction(Request $request): Response
    {
        $form = $this->createForm(OfferType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $offer = $this->offerBuilder->buildFromProduct($data['product_id']);
            // Save offer...
            $this->addFlash('success', 'Offer created successfully');
            return $this->redirectToRoute('inpost_offer_list');
        }

        return $this->render('@Modules/inpostvonhalsky/admin/offer/form.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
```

**Responsibilities**:
- HTTP request handling
- Form creation and validation
- Service orchestration
- Template rendering
- Flash messages

---

### **6. Symfony Console Command** (`Command/SyncCategoriesCommand.php`)

```php
<?php
namespace Inpost\VonHalsky\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Inpost\VonHalsky\Service\Sync\CategorySyncService;

class SyncCategoriesCommand extends Command
{
    protected static $defaultName = 'inpost:categories:sync';

    private CategorySyncService $syncService;

    public function __construct(CategorySyncService $syncService)
    {
        parent::__construct();
        $this->syncService = $syncService;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Synchronize InPost category tree from API')
            ->addOption('language', 'l', InputOption::VALUE_OPTIONAL, 'Language code', 'pl')
            ->addOption('clean', 'c', InputOption::VALUE_NONE, 'Remove categories not in API')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Preview without changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $language = $input->getOption('language');
        $clean = $input->getOption('clean');
        $dryRun = $input->getOption('dry-run');

        $output->writeln('<info>InPost Categories Sync</info>');
        $output->writeln('======================');

        if ($dryRun) {
            $output->writeln('<comment>DRY RUN MODE</comment>');
        }

        $result = $this->syncService->sync($language, $clean, $dryRun);

        $output->writeln(sprintf('Created: %d', $result['created']));
        $output->writeln(sprintf('Updated: %d', $result['updated']));
        $output->writeln(sprintf('Deleted: %d', $result['deleted']));

        return Command::SUCCESS;
    }
}
```

**Responsibilities**:
- CLI command definition
- Input options and arguments
- Service invocation
- Progress output
- Exit codes

---

## 🔄 **Data Flow**

### **Offer Creation Flow**

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. Admin User → Create Offer Form                              │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. OfferController::createAction()                              │
│    - Create Symfony form                                        │
│    - Handle request                                             │
│    - Validate form data                                         │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. OfferBuilderService::buildFromProduct()                      │
│    - Load PrestaShop product (id_product)                       │
│    - Extract product data (name, desc, ean13, manufacturer)     │
│    - Find category mapping (PrestaShop → InPost)                │
│    - Map attributes (PrestaShop attrs → InPost attrs)           │
│    - Transform attribute values (ID → ID)                       │
│    - Build complete offer object                                │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. OfferValidatorService::validate()                            │
│    - Check required fields                                      │
│    - Validate category mapping exists                           │
│    - Validate attribute completeness                            │
│    - Check business rules                                       │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 5. EntityManager::persist($offer)                               │
│    - Save InpostOffer entity                                    │
│    - Save related entities (product, stock, price, etc.)        │
│    - Set require_api_sync = 1                                   │
│    - Commit transaction                                         │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 6. SendOfferService::sendOffer() [Manual or Cron]               │
│    - Check require_api_sync flag                                │
│    - Prepare API payload                                        │
│    - Upload product images                                      │
│    - POST to /api/v1/offers                                     │
│    - Store inpost_offer_id                                      │
│    - Set require_api_sync = 0                                   │
│    - Log success/failure                                        │
└─────────────────────────────────────────────────────────────────┘
```

### **Category Sync Flow**

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. Trigger: Cron Job OR CLI Command OR Admin Button            │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. CategorySyncService::sync()                                  │
│    - Call CategoryApiService::getCategories()                   │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. CategoryApiService::getCategories()                          │
│    - OAuth2Service::getAccessToken()                            │
│    - GET /api/v1/categories?language=pl                         │
│    - Parse JSON response                                        │
│    - Return flat category array                                 │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. CategorySyncService::buildHierarchy()                        │
│    - Group categories by parent_id                              │
│    - Calculate level (depth in tree)                            │
│    - Mark leaf categories (is_leaf = 1)                         │
│    - Build parent-child relationships                           │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 5. CategorySyncService::saveCategories()                        │
│    - For each category:                                         │
│      - Find existing by inpost_category_id + language           │
│      - If exists: update fields                                 │
│      - If not: create new InpostCategoryMapping                 │
│    - If --clean: delete categories not in API response          │
│    - EntityManager::flush()                                     │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 6. Return Summary                                               │
│    - Created count                                              │
│    - Updated count                                              │
│    - Deleted count                                              │
│    - Total categories                                           │
└─────────────────────────────────────────────────────────────────┘
```

### **Order Sync Flow**

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. Cron Job: Every 10 minutes                                   │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. OrderSyncService::syncOrders()                               │
│    - Get last sync timestamp                                    │
│    - Call OrderApiService::getOrders(since: timestamp)          │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. OrderApiService::getOrders()                                 │
│    - GET /api/v1/orders?since={timestamp}                       │
│    - Return orders array                                        │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. OrderSyncService::processOrders()                            │
│    - For each order:                                            │
│      - Find InpostOffer by inpost_offer_id                      │
│      - Check if order already exists (inpost_order_id)          │
│      - If not: create PrestaShop order                          │
│      - Map order data to PrestaShop order                       │
│      - Create InpostOrderEvent entity                           │
│      - Save order and event                                     │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 5. Create PrestaShop Order                                      │
│    - Find/create customer                                       │
│    - Create cart with offer products                            │
│    - Create order with InPost payment method                    │
│    - Link order to InpostOrderEvent                             │
└──────────────────────┬──────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────┐
│ 6. Update Sync Timestamp                                        │
│    - Store last_sync_at in configuration                        │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🎨 **Design Patterns**

### **1. Repository Pattern**

**Purpose**: Separate data access logic from business logic

**Implementation**:
```php
// Repository
class InpostOfferRepository extends EntityRepository
{
    public function findRequiringDataUpdate(int $limit = 100): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.requireDataUpdate = :flag')
            ->setParameter('flag', true)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

// Service usage
class UpdateOfferDataService
{
    private InpostOfferRepository $offerRepo;

    public function updatePendingOffers(): void
    {
        $offers = $this->offerRepo->findRequiringDataUpdate(100);
        foreach ($offers as $offer) {
            // Update offer data from product...
        }
    }
}
```

---

### **2. Service Layer Pattern**

**Purpose**: Encapsulate business logic

**Implementation**:
```php
class OfferBuilderService
{
    private ProductRepository $productRepo;
    private CategoryMapperService $categoryMapper;
    private AttributeMapperService $attributeMapper;

    public function buildFromProduct(int $productId): InpostOffer
    {
        $product = $this->productRepo->find($productId);
        $offer = new InpostOffer();

        // Build offer from product...
        $offer->setName($product->getName());
        $offer->setDescription($product->getDescription());
        $offer->setCategoryId($this->categoryMapper->mapCategory($product->getCategory()));

        $attributes = $this->attributeMapper->mapAttributes($product);
        // ... set attributes

        return $offer;
    }
}
```

---

### **3. Factory Pattern**

**Purpose**: Create complex objects

**Implementation**:
```php
class OfferFactory
{
    public function createFromApiResponse(array $data): InpostOffer
    {
        $offer = new InpostOffer();
        $offer->setInpostOfferId($data['id']);
        $offer->setOrganizationId($data['organization_id']);
        $offer->setStatus($data['status']);

        $product = new InpostOfferProduct();
        $product->setName($data['product']['name']);
        // ... map product data

        $offer->setProduct($product);

        return $offer;
    }
}
```

---

### **4. Observer Pattern (PrestaShop Hooks)**

**Purpose**: React to events

**Implementation**:
```php
// Module hook registration
public function install(): bool
{
    return parent::install()
        && $this->registerHook('actionProductUpdate')
        && $this->registerHook('actionProductDelete');
}

// Hook callback
public function hookActionProductUpdate($params): void
{
    $productId = $params['id_product'];

    // Find all offers for this product
    $offers = $this->offerRepo->findByProductId($productId);

    // Flag for data update
    foreach ($offers as $offer) {
        $offer->setRequireDataUpdate(true);
    }

    $this->entityManager->flush();
}
```

---

### **5. Strategy Pattern (Value Transformers)**

**Purpose**: Interchange algorithms

**Implementation**:
```php
interface ValueTransformerInterface
{
    public function transform($value): mixed;
}

class SizeValueTransformer implements ValueTransformerInterface
{
    private const MAPPING = [
        'XS' => 'extra-small',
        'S' => 'small',
        'M' => 'medium',
        'L' => 'large',
        'XL' => 'extra-large',
    ];

    public function transform($value): mixed
    {
        return self::MAPPING[strtoupper($value)] ?? $value;
    }
}

// Service using strategy
class ValueTransformerService
{
    /** @var ValueTransformerInterface[] */
    private array $transformers = [];

    public function addTransformer(string $attributeCode, ValueTransformerInterface $transformer): void
    {
        $this->transformers[$attributeCode] = $transformer;
    }

    public function transform(string $attributeCode, $value): mixed
    {
        if (isset($this->transformers[$attributeCode])) {
            return $this->transformers[$attributeCode]->transform($value);
        }
        return $value;
    }
}
```

---

### **6. Command Pattern (CLI Commands)**

**Purpose**: Encapsulate requests as objects

**Implementation**:
All Symfony Console commands follow this pattern - each command is an object encapsulating an operation with input parameters and execution logic.

---

### **7. Template Method Pattern (AbstractCron)**

**Purpose**: Define skeleton of algorithm, let subclasses override specific steps

**Implementation**:
```php
<?php
namespace Inpost\VonHalsky\Cron;

use Psr\Log\LoggerInterface;

abstract class AbstractCron
{
    protected LoggerInterface $logger;
    protected ConfigurationService $config;

    public function __construct(LoggerInterface $logger, ConfigurationService $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Template method - defines the algorithm skeleton
     */
    public function execute(): void
    {
        $jobName = $this->getJobName();

        // Step 1: Check if job is enabled
        if (!$this->isJobEnabled()) {
            $this->logger->info("[InPost-Cron] {$jobName} is disabled, skipping");
            return;
        }

        // Step 2: Get job limit
        $limit = $this->getJobLimit();
        $this->logger->info("[InPost-Cron] Starting {$jobName} with limit: {$limit}");

        // Step 3: Execute job (hook method for subclasses)
        try {
            $result = $this->executeJob($limit);

            $this->logger->info("[InPost-Cron] Completed {$jobName}", [
                'processed' => $result['processed'] ?? 0,
                'failed' => $result['failed'] ?? 0
            ]);

        } catch (\Exception $e) {
            $this->logger->error("[InPost-Cron] Error in {$jobName}: {$e->getMessage()}", [
                'exception' => $e
            ]);
            throw $e;
        }
    }

    // Hook methods - must be implemented by subclasses
    abstract protected function getJobName(): string;
    abstract protected function isJobEnabled(): bool;
    abstract protected function getJobLimit(): int;
    abstract protected function executeJob(int $limit): array;
}
```

**Concrete Implementation**:
```php
class SyncOffersCron extends AbstractCron
{
    private OfferSyncService $syncService;

    public function __construct(
        LoggerInterface $logger,
        ConfigurationService $config,
        OfferSyncService $syncService
    ) {
        parent::__construct($logger, $config);
        $this->syncService = $syncService;
    }

    protected function getJobName(): string
    {
        return 'Sync Offers';
    }

    protected function isJobEnabled(): bool
    {
        return (bool) $this->config->get('INPOST_CRON_SYNC_OFFERS_ENABLED');
    }

    protected function getJobLimit(): int
    {
        return (int) $this->config->get('INPOST_CRON_SYNC_OFFERS_LIMIT', 50);
    }

    protected function executeJob(int $limit): array
    {
        return $this->syncService->syncOffers($limit);
    }
}
```

**Benefits**:
- Consistent cron job structure across all jobs
- Centralized logging and error handling
- Configuration-driven enable/disable/limit
- Easy to add new cron jobs (just extend AbstractCron)

**Configuration Pattern** (3 keys per job):
```php
// Enable/disable job
INPOST_CRON_SYNC_OFFERS_ENABLED = 1

// Cron schedule (crontab format)
INPOST_CRON_SYNC_OFFERS_SCHEDULE = "*/10 * * * *"

// Max items to process per execution
INPOST_CRON_SYNC_OFFERS_LIMIT = 50
```

---

### **8. Hook/Observer Pattern with Database Flags**

**Purpose**: React to PrestaShop events and trigger async updates via flags

**Enhanced Implementation with Flag System**:
```php
<?php
// Module registration
public function install(): bool
{
    return parent::install()
        && $this->registerHook('actionObjectProductUpdateAfter')
        && $this->registerHook('actionObjectProductDeleteAfter')
        && $this->registerHook('actionObjectCategoryUpdateAfter')
        && $this->registerHook('actionProductUpdate')
        && $this->registerHook('actionUpdateQuantity');
}

/**
 * Hook: Product updated
 * Action: Flag all related offers for data update
 */
public function hookActionObjectProductUpdateAfter($params): void
{
    $product = $params['object'];
    $productId = $product->id;

    $this->logger->info("[InPost-Hook] Product updated", ['id' => $productId]);

    // Find all offers for this product
    $offers = $this->offerRepository->findBy(['productId' => $productId]);

    if (empty($offers)) {
        $this->logger->debug("[InPost-Hook] No offers found for product {$productId}");
        return;
    }

    $offerIds = array_map(fn($offer) => $offer->getId(), $offers);

    // Flag offers for data update (bulk update)
    $this->flagOffersForDataUpdate($offerIds);

    $this->logger->info("[InPost-Hook] Flagged {count($offers)} offers for update");
}

/**
 * Bulk flag offers for data update
 */
private function flagOffersForDataUpdate(array $offerIds): void
{
    if (empty($offerIds)) {
        return;
    }

    $connection = $this->entityManager->getConnection();

    // Batch update with SQL for performance
    $sql = 'UPDATE ' . _DB_PREFIX_ . 'inpost_offer
            SET require_data_update = 1, updated_at = NOW()
            WHERE entity_id IN (' . implode(',', array_map('intval', $offerIds)) . ')';

    $connection->executeStatement($sql);
}

/**
 * Hook: Category updated
 * Action: Flag offers in category for potential category remapping
 */
public function hookActionObjectCategoryUpdateAfter($params): void
{
    $category = $params['object'];
    $categoryId = $category->id;

    // Find products in this category
    $products = Product::getProducts(
        Context::getContext()->language->id,
        0,
        0,
        'id_product',
        'ASC',
        $categoryId
    );

    $productIds = array_column($products, 'id_product');

    // Find offers for these products
    if (!empty($productIds)) {
        $offers = $this->offerRepository->findByProductIds($productIds);
        $offerIds = array_map(fn($offer) => $offer->getId(), $offers);

        $this->flagOffersForDataUpdate($offerIds);

        $this->logger->info("[InPost-Hook] Category update: flagged " . count($offerIds) . " offers");
    }
}

/**
 * Hook: Stock quantity updated
 * Action: Flag offers for stock sync
 */
public function hookActionUpdateQuantity($params): void
{
    $productId = $params['id_product'];

    // Find offers
    $offers = $this->offerRepository->findBy(['productId' => $productId]);

    if (!empty($offers)) {
        $connection = $this->entityManager->getConnection();

        $offerIds = array_map(fn($offer) => $offer->getId(), $offers);

        // Flag for stock-specific update
        $sql = 'UPDATE ' . _DB_PREFIX_ . 'inpost_offer
                SET require_stock_update = 1, updated_at = NOW()
                WHERE entity_id IN (' . implode(',', array_map('intval', $offerIds)) . ')';

        $connection->executeStatement($sql);

        $this->logger->info("[InPost-Hook] Stock update: flagged " . count($offerIds) . " offers");
    }
}
```

**Database Flag Pattern**:
```sql
-- Offer flags for different update types
ALTER TABLE ps_inpost_offer ADD COLUMN require_data_update TINYINT(1) DEFAULT 0;
ALTER TABLE ps_inpost_offer ADD COLUMN require_api_sync TINYINT(1) DEFAULT 0;
ALTER TABLE ps_inpost_offer ADD COLUMN require_image_sync TINYINT(1) DEFAULT 0;
ALTER TABLE ps_inpost_offer ADD COLUMN require_stock_update TINYINT(1) DEFAULT 0;
ALTER TABLE ps_inpost_offer ADD COLUMN require_price_update TINYINT(1) DEFAULT 0;

-- Indexes for flag queries
CREATE INDEX IDX_OFFER_REQUIRE_DATA_UPDATE ON ps_inpost_offer(require_data_update);
CREATE INDEX IDX_OFFER_REQUIRE_API_SYNC ON ps_inpost_offer(require_api_sync);
CREATE INDEX IDX_OFFER_REQUIRE_IMAGE_SYNC ON ps_inpost_offer(require_image_sync);
```

**Cron Job Processing Flags**:
```php
class UpdateOfferDataCron extends AbstractCron
{
    protected function executeJob(int $limit): array
    {
        // Find offers flagged for data update
        $offers = $this->offerRepository->createQueryBuilder('o')
            ->where('o.requireDataUpdate = :flag')
            ->setParameter('flag', true)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $processed = 0;
        $failed = 0;

        foreach ($offers as $offer) {
            try {
                // Update offer data from product
                $this->offerBuilder->updateOfferFromProduct($offer);

                // Clear flag
                $offer->setRequireDataUpdate(false);
                $offer->setRequireApiSync(true);  // Set API sync flag
                $offer->setUpdatedAt(new \DateTime());

                $processed++;

            } catch (\Exception $e) {
                $this->logger->error("[InPost-Cron] Failed to update offer {$offer->getId()}", [
                    'error' => $e->getMessage()
                ]);
                $failed++;
            }
        }

        $this->entityManager->flush();

        return ['processed' => $processed, 'failed' => $failed];
    }
}
```

**Benefits of Flag System**:
- **Async processing**: Hooks set flags instantly, cron processes later
- **Batch efficiency**: Process multiple flagged offers in one cron run
- **Selective updates**: Different flags for different update types
- **Resilience**: If cron fails, flags remain set for retry
- **Performance**: Bulk SQL updates, indexed flag queries

---

### **9. Configuration Inheritance Pattern (Multi-Shop)**

**Purpose**: Cascade configuration from global → shop group → shop with explicit overrides

**Implementation**:
```php
<?php
namespace Inpost\VonHalsky\Service\Util;

use Configuration;
use Context;
use Shop;
use ShopGroup;

class ConfigurationService
{
    private Context $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    /**
     * Get configuration with inheritance
     *
     * Resolution order:
     * 1. Shop-specific value
     * 2. Shop group value (if shop value is null/empty)
     * 3. Global value (if group value is null/empty)
     */
    public function get(string $key, $default = null, ?int $shopId = null)
    {
        $shopId = $shopId ?? $this->context->shop->id;

        // Step 1: Try shop-specific value
        $shopValue = Configuration::get($key, null, null, $shopId);

        // Check for explicit "disabled" value
        if ($shopValue === 'disabled') {
            return 'disabled';
        }

        // If shop value exists and is not empty, return it
        if ($shopValue !== null && $shopValue !== '') {
            return $shopValue;
        }

        // Step 2: Try shop group value
        $shop = new Shop($shopId);
        if ($shop->id_shop_group) {
            $groupValue = Configuration::get($key, null, $shop->id_shop_group, null);

            if ($groupValue !== null && $groupValue !== '') {
                return $groupValue;
            }
        }

        // Step 3: Try global value
        $globalValue = Configuration::get($key, null, null, null);

        if ($globalValue !== null && $globalValue !== '') {
            return $globalValue;
        }

        // Step 4: Return default
        return $default;
    }

    /**
     * Set configuration for specific shop
     */
    public function set(string $key, $value, ?int $shopId = null): bool
    {
        $shopId = $shopId ?? $this->context->shop->id;

        return Configuration::updateValue($key, $value, false, null, $shopId);
    }

    /**
     * Set global configuration (all shops)
     */
    public function setGlobal(string $key, $value): bool
    {
        return Configuration::updateGlobalValue($key, $value);
    }

    /**
     * Check if InPost is enabled for specific shop
     */
    public function isEnabledForShop(?int $shopId = null): bool
    {
        $orgId = $this->get('INPOST_ORGANIZATION_ID', null, $shopId);

        // Explicitly disabled
        if ($orgId === 'disabled') {
            return false;
        }

        // No organization = not configured = disabled
        if (empty($orgId)) {
            return false;
        }

        return true;
    }

    /**
     * Get organization ID with shop context
     */
    public function getOrganizationId(?int $shopId = null): ?string
    {
        $orgId = $this->get('INPOST_ORGANIZATION_ID', null, $shopId);

        if ($orgId === 'disabled' || empty($orgId)) {
            return null;
        }

        return $orgId;
    }
}
```

**Usage in Services**:
```php
class OfferSyncService
{
    private ConfigurationService $config;

    public function syncOffersForAllShops(): array
    {
        $results = [];
        $shops = Shop::getShops(true);

        foreach ($shops as $shop) {
            $shopId = $shop['id_shop'];

            // Check if InPost is enabled for this shop
            if (!$this->config->isEnabledForShop($shopId)) {
                $results[$shopId] = ['status' => 'disabled'];
                continue;
            }

            // Get shop-specific organization
            $organizationId = $this->config->getOrganizationId($shopId);

            // Sync offers for this shop
            $results[$shopId] = $this->syncOffers($organizationId, $shopId);
        }

        return $results;
    }
}
```

**Configuration Hierarchy Example**:
```php
// Global Configuration
Configuration::updateGlobalValue('INPOST_CLIENT_ID', 'global_client_123');
Configuration::updateGlobalValue('INPOST_ORGANIZATION_ID', 'org_global_456');

// Shop Group 1 Configuration
Configuration::updateValue('INPOST_ORGANIZATION_ID', 'org_group1_789', false, 1, null);

// Shop 1 (in Group 1)
// [inherits] client_id from global
// [inherits] organization_id from group
// Result: client_id=global_client_123, org_id=org_group1_789

// Shop 2 (in Group 1)
Configuration::updateValue('INPOST_CLIENT_ID', 'shop2_client_999', false, null, 2);
Configuration::updateValue('INPOST_ORGANIZATION_ID', 'disabled', false, null, 2);
// Result: client_id=shop2_client_999, org_id=disabled (InPost disabled for Shop 2)

// Shop 3 (in Group 2)
Configuration::updateValue('INPOST_ORGANIZATION_ID', 'org_shop3_111', false, null, 3);
// Result: client_id=global_client_123 (inherited), org_id=org_shop3_111 (specific)
```

**Inheritance Resolution Table**:

| Shop | Client ID | Source | Organization ID | Source | InPost Status |
|------|-----------|--------|-----------------|--------|---------------|
| Shop 1 | global_client_123 | Global | org_group1_789 | Group 1 | ✅ Enabled |
| Shop 2 | shop2_client_999 | Shop 2 | disabled | Shop 2 | ❌ Disabled |
| Shop 3 | global_client_123 | Global | org_shop3_111 | Shop 3 | ✅ Enabled |

**Benefits**:
- **DRY Configuration**: Set common values once globally
- **Selective Overrides**: Override only what's different per shop
- **Explicit Disable**: Use "disabled" to explicitly turn off InPost for specific shops
- **Flexible Structure**: Supports various multi-shop strategies (shared org, separate orgs, selective integration)

---

## 🔐 **Security Architecture**

### **1. OAuth2 Token Security**

- **Storage**: Encrypted in `ps_configuration` table
- **Encryption**: PrestaShop's `Configuration::updateValue()` with encryption flag
- **Scope**: Token never exposed in logs or frontend
- **Refresh**: Automatic refresh before expiration
- **Transmission**: HTTPS only

```php
// Encrypted storage
Configuration::updateGlobalValue(
    'INPOST_SA_ACCESS_TOKEN',
    $accessToken,
    false,  // not simple value
    true    // encrypt
);

// Encrypted retrieval
$token = Configuration::get('INPOST_SA_ACCESS_TOKEN');
```

---

### **2. API Credentials Protection**

- **Client Secret**: Encrypted in database
- **No hardcoding**: All credentials from configuration
- **Environment-specific**: Different credentials per environment
- **Access control**: Only admin users can view/edit configuration

---

### **3. Input Validation**

- **Symfony Validators**: On all form inputs
- **Type Hints**: PHP 8.1 strict types
- **Doctrine Constraints**: Database-level validation
- **Custom Validators**: Business rule validation

```php
use Symfony\Component\Validator\Constraints as Assert;

class OfferType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(['min' => 3, 'max' => 255]),
                ],
            ])
            ->add('price', MoneyType::class, [
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(),
                ],
            ]);
    }
}
```

---

### **4. CSRF Protection**

- **Symfony Forms**: Automatic CSRF tokens
- **AJAX Requests**: Token validation
- **Admin Controllers**: PrestaShop security checks

---

### **5. SQL Injection Prevention**

- **Doctrine ORM**: Parameterized queries
- **No raw SQL**: All queries through QueryBuilder or DQL
- **Type casting**: Strict type enforcement

---

### **6. XSS Prevention**

- **Twig Auto-Escaping**: Enabled by default
- **HTML Purifier**: For user-generated content
- **Content Security Policy**: Recommended headers

---

## ⚡ **Performance Optimization**

### **1. Database Optimization**

**Indexing Strategy**:
```sql
-- Status queries (filter by status)
CREATE INDEX IDX_OFFER_STATUS ON ps_inpost_offer(status);

-- Flag queries (find offers needing sync)
CREATE INDEX IDX_OFFER_REQUIRE_DATA_UPDATE ON ps_inpost_offer(require_data_update);
CREATE INDEX IDX_OFFER_REQUIRE_API_SYNC ON ps_inpost_offer(require_api_sync);

-- Composite index for common query (status + shop)
CREATE INDEX IDX_OFFER_STATUS_SHOP ON ps_inpost_offer(status, id_shop);

-- Category tree queries
CREATE INDEX IDX_CATEGORY_PARENT_LEVEL ON ps_inpost_category_mapping(parent_id, level);
CREATE INDEX IDX_CATEGORY_ACTIVE_LEAF ON ps_inpost_category_mapping(is_active, is_leaf);
```

**Query Optimization**:
```php
// Bad: N+1 query problem
foreach ($offers as $offer) {
    $product = $offer->getProduct(); // Lazy load - N queries
}

// Good: Eager loading with JOIN
$offers = $offerRepo->createQueryBuilder('o')
    ->leftJoin('o.product', 'p')
    ->addSelect('p')
    ->getQuery()
    ->getResult(); // 1 query with JOIN
```

---

### **2. Batch Processing**

- **Configurable batch sizes** in cron settings
- **Memory-efficient iteration** using Doctrine batch processing
- **Progress tracking** to resume on failure

```php
public function syncOffers(int $batchSize = 100): void
{
    $offset = 0;

    do {
        $offers = $this->apiClient->getOffers($batchSize, $offset);

        foreach ($offers as $offerData) {
            $this->processOffer($offerData);
        }

        $this->entityManager->flush();
        $this->entityManager->clear(); // Free memory

        $offset += $batchSize;

    } while (count($offers) === $batchSize);
}
```

---

### **3. Caching**

- **Organization cache**: Store in `ps_inpost_organization` table
- **Category tree cache**: Stored in database, rebuilt on sync
- **Configuration cache**: PrestaShop's configuration cache
- **HTTP response cache** (future): For API responses

---

### **4. Async Processing**

- **Cron jobs**: Heavy operations run in background
- **Flags for sync**: `require_data_update`, `require_api_sync`
- **Event-driven updates**: Product update → flag → cron picks up

---

## 🚀 **Forward Compatibility Considerations**

### **Current Platform Support**

| Component | PS 8.x Approach | Notes |
|-----------|-----------------|-------|
| **ORM** | Doctrine entities | Not legacy ObjectModel |
| **HTTP Client** | Guzzle 7.x | |
| **Forms** | Symfony Forms (offers) + HelperForm (config page) | |
| **Console** | Symfony Console + AJAX endpoints | |
| **Services** | Symfony DI | Service container |
| **Database** | SQL migrations | Consistent schema |

### **Architecture Benefits**

1. **Doctrine entities** - Not legacy ObjectModel
2. **Service-oriented design** - Clear separation of concerns
3. **PSR-compliant code** - Modern PHP standards (PHP 8.1)
4. **AJAX cron endpoints** - Platform-agnostic scheduled tasks
5. **Guzzle HTTP client** - Stable, well-supported library

### **Future PrestaShop 9 Considerations**

**Note**: PrestaShop 9 compatibility has not been validated. The module currently supports:
- **PrestaShop 8.x** (PHP 8.1)

For PS 9 migration, consider:
- PHP version requirements (PS 9 may require PHP 8.2+)
- Symfony component versions
- HTTP client library (may need to switch back to Symfony HttpClient if Guzzle is removed)
- Console command availability

**Recommendation**: Test thoroughly when PS 9 becomes available and update dependencies as needed.

---

## 🔌 **Integration Points**

### **1. PrestaShop Core**
- **Product catalog**: Read product data for offers
- **Category tree**: Map PrestaShop categories to InPost
- **Attributes**: Map product attributes to InPost attributes
- **Orders**: Create orders from InPost marketplace
- **Configuration**: Store module settings
- **Hooks**: React to product updates

### **2. InPost API**
- **OAuth2 authentication**
- **Categories endpoint**: Sync category tree
- **Attributes endpoint**: Sync attributes and dictionaries
- **Offers endpoint**: Create, update, list offers
- **Orders endpoint**: Retrieve marketplace orders
- **Events endpoint**: Track offer/order events
- **Images endpoint**: Upload product images

### **3. PrestaShop CronJobsModule**
- **Register cron jobs** for automated synchronization
- **Configurable schedules** per sync operation
- **Execution monitoring**

### **4. PrestaShop Admin**
- **Top-level menu**: InPost von Halsky
- **Admin controllers**: Symfony-based
- **Admin templates**: Twig
- **AJAX endpoints**: For dynamic UI interactions

---

## 📊 **Scalability Considerations**

### **High Volume Scenarios**

**10,000+ Products**:
- **Batch processing**: Process offers in chunks (100-500)
- **Database indexing**: Optimize frequent queries
- **Cron staggering**: Spread heavy operations across time

**1,000+ Orders/day**:
- **Frequent order sync**: Every 10 minutes
- **Order queue**: Process orders asynchronously
- **Event logging**: Separate table with archiving strategy

**Multi-Shop (10+ Shops)**:
- **Per-shop organization**: Each shop has own InPost org
- **Parallel processing**: Process shops concurrently (if resources allow)
- **Shop-specific cron jobs**: Separate schedules per shop

### **Resource Management**

- **Memory**: Use Doctrine `clear()` after batch processing
- **Database**: Connection pooling, query optimization
- **API**: Respect rate limits, implement backoff strategies
- **Logging**: Rotate logs, limit verbosity in production

---

## 📝 **Summary**

The InPost von Halsky module architecture prioritizes:

1. **Modern Standards**: Symfony components, Doctrine ORM, PSR compliance
2. **Forward Compatibility**: PrestaShop 9 ready from day one
3. **Maintainability**: Clear separation of concerns, SOLID principles
4. **Security**: OAuth2, encryption, validation
5. **Performance**: Indexing, batch processing, caching
6. **Scalability**: Async processing, resource management

This architecture ensures a robust, maintainable, and future-proof integration with InPost von Halsky marketplace platform.

---

**Next**: [Database Schema Documentation](DATABASE_SCHEMA.md)
