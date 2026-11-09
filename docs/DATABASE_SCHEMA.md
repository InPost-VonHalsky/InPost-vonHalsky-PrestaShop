# InPost von Halsky - Database Schema Documentation

> **Complete database schema reference for all 17 tables with relationships, indexes, and business logic**

---

## 📋 **Table of Contents**

- [Overview](#overview)
- [Entity Relationship Diagram](#entity-relationship-diagram)
- [Table Naming Convention](#table-naming-convention)
- [OAuth2 Authentication Table](#oauth2-authentication-table)
- [Core Tables](#core-tables)
- [Event Tables](#event-tables)
- [Mapping Tables](#mapping-tables)
- [Organization Table](#organization-table)
- [Order Table](#order-table)
- [Relationships Summary](#relationships-summary)
- [Common Patterns](#common-patterns)
- [Migration Scripts](#migration-scripts)

---

## 🎯 **Overview**

The InPost von Halsky module uses **17 database tables** organized into five logical groups:

1. **OAuth2 Authentication Table** (1 table): PKCE parameters for OAuth2 authentication
2. **Core Offer Tables** (8 tables): Main offer data and related information
3. **Event Tables** (1 table): Offer event tracking
4. **Mapping Tables** (5 tables): Category and attribute mappings
5. **Organization Table** (1 table): InPost organization cache
6. **Order Table** (1 table): InPost marketplace orders

> **Added since this document was written:** `ps_inpost_offer_attachment` (1.1.58, described below as 8a) and the `price_mode` / `manual_price` / `shop_price` columns of `ps_inpost_offer_price` (1.1.57). `ps_inpost_product_hint_cache` and `ps_inpost_log_journal` exist in `install/install.sql` but are not described here yet, so the real table count is higher than 17.

All tables use:
- **Doctrine ORM** for PrestaShop 9 compatibility
- **Optimized indexes** for common queries
- **Foreign key constraints** for data integrity
- **PrestaShop naming** (`ps_` prefix)
- **Timestamp tracking** (`created_at`, `updated_at`)

---

## 🗺️ **Entity Relationship Diagram**

```
┌────────────────────────────┐
│ ps_inpost_pkce_params      │
│ (OAuth2)                   │
│ PK: id                     │
│ UK: state                  │
└────────────────────────────┘

┌────────────────────────────┐
│ ps_inpost_organization     │
│ (cache)                    │
│ PK: organization_id        │
└────────────┬───────────────┘
             │ Referenced by
             │ organization_id
             ▼
┌────────────────────────────────────────────────────────────────┐
│ ps_inpost_offer                                                │
│ PK: offer_id                                                  │
│ FK: organization_id → ps_inpost_organization                 │
│ UK: inpost_offer_id                                           │
└────┬──────┬──────┬──────┬──────┬──────┬───────────────────────┘
     │      │      │      │      │      │
     │ 1:1  │ 1:1  │ 1:1  │ 1:1  │ 1:1  │ 1:N
     ▼      ▼      ▼      ▼      ▼      ▼
┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐
│ ps_      │ │ ps_      │ │ ps_      │ │ ps_      │ │ ps_      │ │ ps_      │
│ inpost_  │ │ inpost_  │ │ inpost_  │ │ inpost_  │ │ inpost_  │ │ inpost_  │
│ offer_   │ │ offer_   │ │ offer_   │ │ offer_   │ │ offer_   │ │ offer_   │
│ product  │ │ stock    │ │ price    │ │ manufac- │ │ gpsr     │ │ manual   │
│          │ │          │ │          │ │ turer    │ │          │ │          │
│ PK: offer│ │ PK: offer│ │ PK: offer│ │ PK: offer│ │ PK: offer│ │ PK: entity│
│ _product│ │ _stock_id│ │ _price_id│ │ _manufac│ │ _gpsr_id │ │ _id      │
│ _id     │ │          │ │          │ │ turer_id│ │          │ │          │
│ FK:     │ │ FK:      │ │ FK:      │ │ FK:     │ │ FK:      │ │ FK:      │
│ offer_id│ │ offer_id │ │ offer_id │ │ offer_id│ │ offer_id │ │ offer_id │
└────┬────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘
     │
     │ 1:N
     ▼
┌──────────────────┐
│ ps_inpost_offer_ │
│ attributes       │
│ PK: offer_       │
│ attribute_id     │
│ FK: offer_id     │
│ FK: offer_       │
│ product_id       │
└──────────────────┘

┌────────────────────────────┐
│ ps_inpost_offer_event      │
│ PK: entity_id              │
│ (references inpost_offer_id)│
└────────────────────────────┘

┌────────────────────────────────────────────────────────────────┐
│ ps_inpost_category_mapping                                     │
│ PK: entity_id                                                  │
│ FK: parent_id → self (tree)                                    │
│ UK: (inpost_category_id, language)                             │
└────┬───────────────────────────────────────────────────────────┘
     │ Referenced by
     │ mapping_id
     ▼
┌────────────────────────────────────────────────────────────────┐
│ ps_inpost_category_link                                        │
│ PK: link_id                                                    │
│ FK: mapping_id → ps_inpost_category_mapping                    │
│ UK: (mapping_id, prestashop_category_id, shop_id)              │
└────────────────────────────────────────────────────────────────┘

┌────────────────────────────┐
│ ps_inpost_attribute        │
│ PK: entity_id              │
│ UK: (inpost_attribute_id,  │
│      lang)                 │
└────┬───────────────────────┘
     │ Referenced by
     │ attribute_id
     ├──────────────┬──────────────┐
     │              │              │
     ▼              ▼              ▼
┌───────────┐ ┌────────────┐ ┌───────────────────┐
│ ps_inpost_│ │ ps_inpost_ │ │ ps_inpost_        │
│ category_ │ │ offer_      │ │ attribute_        │
│ attribute │ │ attributes  │ │ dictionary        │
│           │ │             │ │                   │
│ PK: entity│ │ (see above) │ │ PK: entity_id     │
│ _id       │ │             │ │ FK: inpost_       │
│ FK:      │ │             │ │ attribute_id     │
│ mapping_id│ │             │ │                   │
│ FK:      │ │             │ │                   │
│ attribute│ │             │ │                   │
│ _id      │ │             │ │                   │
└───────────┘ └────────────┘ └───────────────────┘
```

---

## 📏 **Table Naming Convention**

| Prefix | Purpose | Example |
|--------|---------|---------|
| `ps_` | PrestaShop standard prefix | `ps_inpost_offer` |
| `inpost_` | Module namespace | All module tables |
| Underscore separation | Word delimiter | `offer_product`, `category_mapping` |

---

## 🔐 **OAuth2 Authentication Table**

---

### **1. ps_inpost_pkce_params**

**Purpose**: Store PKCE (Proof Key for Code Exchange) parameters for OAuth2 authentication flow with InPost API.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `state` | VARCHAR(255) | NO | - | OAuth state parameter (CSRF protection) |
| `code_verifier` | VARCHAR(255) | NO | - | PKCE code verifier (random string) |
| `expires_at` | INT(10) UNSIGNED | NO | - | Expiration timestamp (Unix timestamp) |
| `created_at` | DATETIME | NO | - | Record creation time |

#### **Indexes**

```sql
PRIMARY KEY (id)
UNIQUE KEY UNQ_STATE (state)
INDEX IDX_EXPIRES_AT (expires_at)
```

#### **Business Logic**

- **PKCE Flow**: Used during OAuth2 authorization code flow with PKCE extension
- **State Parameter**: Prevents CSRF attacks, must match between authorization request and token exchange
- **Code Verifier**: Random string used to generate `code_challenge` sent to authorization server
- **Expiration**: Records expire after a set time (typically 10 minutes) for security
- **Cleanup**: Expired records should be cleaned up periodically

#### **Sample Queries**

```sql
-- Find valid PKCE parameters by state
SELECT * FROM ps_inpost_pkce_params
WHERE state = 'abc123xyz'
AND expires_at > UNIX_TIMESTAMP();

-- Clean up expired records
DELETE FROM ps_inpost_pkce_params
WHERE expires_at < UNIX_TIMESTAMP();
```

---

## 📦 **Core Tables**

---

### **2. ps_inpost_offer**

**Purpose**: Main offer entity linking PrestaShop products to InPost marketplace offers.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `offer_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `inpost_offer_id` | VARCHAR(64) | YES | NULL | InPost API offer ID (UUID) |
| `organization_id` | VARCHAR(64) | NO | - | InPost organization ID |
| `prestashop_offer_id` | INT(10) UNSIGNED | YES | NULL | PrestaShop product ID |
| `prestashop_product_attribute_id` | INT(10) UNSIGNED | NO | 0 | PrestaShop combination the offer sells (1.1.42); 0 = the whole product. externalId: `ps_product_{id}` / `ps_product_{id}_{combination}` |
| `shop_id` | INT(10) UNSIGNED | NO | 1 | PrestaShop shop ID (multi-shop) |
| `status` | VARCHAR(20) | NO | 'DRAFT' | Offer status (DRAFT, PENDING, PUBLISHED, REJECTED, CLOSED, SOLDOUT) |
| `is_draft` | TINYINT(1) UNSIGNED | NO | 1 | Draft status: 0=published, 1=draft |
| `publication_status` | VARCHAR(20) | YES | NULL | Publication status |
| `shipping_time` | VARCHAR(255) | YES | NULL | Delivery time description |
| `affiliation_product_url` | TEXT | YES | NULL | Product URL for affiliation |
| `post_sale_return_policy` | TEXT | YES | NULL | Return policy text |
| `post_sale_complaint_policy` | TEXT | YES | NULL | Complaint policy text |
| `language` | VARCHAR(5) | YES | NULL | Language code (pl, en, etc.) |
| `inpost_category_id` | VARCHAR(255) | YES | NULL | InPost category UUID |
| `inpost_validation` | TEXT | YES | NULL | Validation errors from InPost API (JSON) |
| `inpost_validation_status` | VARCHAR(50) | YES | NULL | Computed validation status (errors, passed, pending) |
| `require_api_sync` | TINYINT(1) | NO | 0 | Flag: needs sync to InPost API (0/1) |
| `require_image_sync` | TINYINT(1) | NO | 0 | Flag: images need upload (0/1) |
| `require_stock_update` | TINYINT(1) | NO | 0 | Flag: stock needs update (0/1) |
| `last_data_sync_at` | DATETIME | YES | NULL | When data was last synced from product |
| `last_api_sync_at` | DATETIME | YES | NULL | When offer was last sent to InPost API |
| `synced_at` | DATETIME | YES | NULL | Last successful sync timestamp |
| `created_at` | DATETIME | NO | - | Record creation time |
| `updated_at` | DATETIME | NO | - | Auto-updated on change |

#### **Indexes**

```sql
PRIMARY KEY (offer_id)
UNIQUE KEY UNQ_INPOST_OFFER_ID (inpost_offer_id)
INDEX IDX_INPOST_OFFER_ID (inpost_offer_id)
INDEX IDX_ORGANIZATION_ID (organization_id)
INDEX IDX_PRESTASHOP_OFFER_ID (prestashop_offer_id)
INDEX IDX_SHOP_ID (shop_id)
INDEX IDX_STATUS (status)
INDEX IDX_IS_DRAFT (is_draft)
INDEX IDX_PUBLICATION_STATUS (publication_status)
INDEX IDX_INPOST_CATEGORY_ID (inpost_category_id)
INDEX IDX_VALIDATION_STATUS (inpost_validation_status)
INDEX IDX_REQUIRE_API_SYNC (require_api_sync)
INDEX IDX_REQUIRE_IMAGE_SYNC (require_image_sync)
INDEX IDX_REQUIRE_STOCK_UPDATE (require_stock_update)
INDEX IDX_CREATED_AT (created_at)
INDEX IDX_UPDATED_AT (updated_at)
```

#### **Foreign Keys**

```sql
-- Note: No FK to ps_product as prestashop_offer_id stored as INT (product can be deleted)
-- Note: No FK to ps_shop as shop_id stored as INT (shop can be deleted)
```

#### **Business Logic**

**Sync Flags**:
- `require_api_sync = 1`: Set when offer data changed locally and needs to be sent to InPost API
- `require_image_sync = 1`: Set when product images need to be uploaded to InPost
- `require_stock_update = 1`: Set when stock quantity needs to be updated

**Workflow**:
1. Offer created → `status = 'DRAFT'`, `is_draft = 1`, `require_api_sync = 1`
2. Product updated → offer data refreshed from product → set `require_api_sync = 1`
3. Cron job or manual: `require_api_sync = 1` → send to InPost API → store `inpost_offer_id` → `require_api_sync = 0`
4. Images added → `require_image_sync = 1` → upload images → `require_image_sync = 0`
5. Stock changed → `require_stock_update = 1` → update stock → `require_stock_update = 0`

**Status Values**:
- `DRAFT`: Local draft, not sent to InPost
- `PENDING`: Sent to InPost, pending review
- `PUBLISHED`: Published and visible on marketplace
- `REJECTED`: Rejected by InPost
- `CLOSED`: Closed/archived
- `SOLDOUT`: Out of stock

**Validation Status**:
- `errors`: Validation errors present (check `inpost_validation` JSON)
- `passed`: Validation passed
- `pending`: Not yet validated

#### **Sample Queries**

```sql
-- Find offers needing sync to InPost API
SELECT * FROM ps_inpost_offer
WHERE require_api_sync = 1
AND inpost_offer_id IS NULL  -- New offers not yet sent
LIMIT 50;

-- Get active offers for shop
SELECT * FROM ps_inpost_offer
WHERE shop_id = 1
AND status = 'PUBLISHED'
ORDER BY updated_at DESC;

-- Get offers with validation errors
SELECT * FROM ps_inpost_offer
WHERE inpost_validation_status = 'errors'
ORDER BY updated_at DESC;

-- Get offer with all relations (for display)
SELECT
    o.*,
    p.name as product_name,
    p.sku,
    st.quantity,
    pr.price,
    pr.currency,
    m.name as manufacturer_name
FROM ps_inpost_offer o
LEFT JOIN ps_inpost_offer_product p ON p.offer_id = o.offer_id
LEFT JOIN ps_inpost_offer_stock st ON st.offer_id = o.offer_id
LEFT JOIN ps_inpost_offer_price pr ON pr.offer_id = o.offer_id
LEFT JOIN ps_inpost_offer_manufacturer m ON m.offer_id = o.offer_id
WHERE o.inpost_offer_id = 'abc-123-def';
```

---

### **3. ps_inpost_offer_product**

**Purpose**: Store product information for offers (name, description, brand, SKU, EAN, dimensions, etc.).

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `offer_product_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `offer_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_offer.offer_id |
| `product_id` | INT(10) UNSIGNED | NO | - | PrestaShop product ID (reference) |
| `name` | VARCHAR(255) | NO | - | Product name |
| `description` | TEXT | YES | NULL | Product description |
| `brand` | VARCHAR(128) | YES | NULL | Brand name |
| `model` | VARCHAR(128) | YES | NULL | Model/reference |
| `sku` | VARCHAR(64) | NO | - | SKU |
| `ean` | VARCHAR(13) | NO | - | EAN-8 or EAN-13 (required by InPost API) |
| `super_model` | VARCHAR(100) | YES | NULL | Super model |
| `manufacturer_product_number` | VARCHAR(100) | YES | NULL | Manufacturer product number |
| `dimension_width` | INT | YES | NULL | Dimension width in mm |
| `dimension_height` | INT | YES | NULL | Dimension height in mm |
| `dimension_length` | INT | YES | NULL | Dimension length in mm |
| `dimension_weight` | INT | YES | NULL | Dimension weight in grams |
| `category_id` | VARCHAR(64) | YES | NULL | Category ID (legacy) |
| `inpost_category_id` | VARCHAR(255) | YES | NULL | Selected InPost category ID for this offer |
| `category_mapping_id` | INT(10) UNSIGNED | YES | NULL | Category mapping ID (FK to inpost_category_mapping) |
| `created_at` | DATETIME | NO | - | Created timestamp |
| `updated_at` | DATETIME | NO | - | Updated timestamp |

#### **Indexes**

```sql
PRIMARY KEY (offer_product_id)
INDEX IDX_OFFER_ID (offer_id)
INDEX IDX_PRODUCT_ID (product_id)
INDEX IDX_EAN (ean)
```

#### **Foreign Keys**

```sql
FOREIGN KEY (offer_id) REFERENCES ps_inpost_offer(offer_id) ON DELETE CASCADE
```

#### **Business Logic**

- **1:1 relationship** with `ps_inpost_offer`
- Data populated from PrestaShop product when offer created
- `inpost_category_id` populated from category mapping (PrestaShop category → InPost category)
- `category_mapping_id` links to `ps_inpost_category_mapping` for category context
- Dimensions stored in millimeters (width, height, length) and grams (weight)
- EAN is required by InPost API (EAN-8 or EAN-13 format)

#### **Sample Queries**

```sql
-- Get product data for offer
SELECT * FROM ps_inpost_offer_product WHERE offer_id = 123;

-- Search offers by EAN
SELECT p.*, o.status
FROM ps_inpost_offer_product p
JOIN ps_inpost_offer o ON o.offer_id = p.offer_id
WHERE p.ean = '5901234123457';

-- Get products with dimensions
SELECT p.*, o.status
FROM ps_inpost_offer_product p
JOIN ps_inpost_offer o ON o.offer_id = p.offer_id
WHERE p.dimension_width IS NOT NULL
AND p.dimension_height IS NOT NULL
AND p.dimension_length IS NOT NULL
AND p.dimension_weight IS NOT NULL;
```

---

### **4. ps_inpost_offer_attributes**

**Purpose**: Store product attributes for offers (mapped from PrestaShop attributes).

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `offer_attribute_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `offer_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_offer.offer_id |
| `attribute_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_attribute.attribute_id |
| `offer_product_id` | INT(10) UNSIGNED | YES | NULL | FK to ps_inpost_offer_product.offer_product_id |
| `category_mapping_id` | VARCHAR(255) | YES | NULL | Category mapping ID |
| `inpost_attribute_id` | VARCHAR(36) | YES | NULL | InPost attribute ID (UUID) |
| `value` | TEXT | NO | - | Attribute value |
| `attribute_values` | TEXT | YES | NULL | Attribute values (JSON array) |
| `value_source` | VARCHAR(16) | NO | 'manual' | Origin of the value (1.1.41): `ps` (mapped PrestaShop feature / attribute group - follows product changes), `hint` (InPost product suggestion), `manual` (entered by the merchant - never overwritten) |
| `lang` | VARCHAR(10) | YES | NULL | Language code |
| `created_at` | DATETIME | NO | - | Created timestamp |
| `updated_at` | DATETIME | NO | - | Updated timestamp |

#### **Indexes**

```sql
PRIMARY KEY (offer_attribute_id)
INDEX IDX_OFFER_ID (offer_id)
INDEX IDX_ATTRIBUTE_ID (attribute_id)
INDEX IDX_OFFER_PRODUCT_ID (offer_product_id)
INDEX IDX_CATEGORY_MAPPING_ID (category_mapping_id)
INDEX IDX_INPOST_ATTRIBUTE_ID (inpost_attribute_id)
UNIQUE KEY UNQ_OFFER_PRODUCT_CATEGORY_ATTRIBUTE (offer_product_id, category_mapping_id, inpost_attribute_id)
```

#### **Foreign Keys**

```sql
FOREIGN KEY (offer_product_id) REFERENCES ps_inpost_offer_product(offer_product_id) ON DELETE CASCADE
```

#### **Business Logic**

- **Dual relationship**: Links to both `offer_id` (offer) and `offer_product_id` (product)
- **1:N relationship** with `ps_inpost_offer_product` (one product can have multiple attributes)
- `attribute_values` stored as JSON: `["value1", "value2"]` or `["dict-option-id-1"]`
- `value` field stores single value (legacy), `attribute_values` stores JSON array (preferred)
- Linked to `ps_inpost_attribute` via `attribute_id` for metadata (attribute type, requirements)
- Unique constraint ensures one attribute per product per category mapping

#### **Sample Queries**

```sql
-- Get all attributes for offer product (active only)
SELECT a.*, attr.name, attr.type, attr.required
FROM ps_inpost_offer_attributes a
JOIN ps_inpost_attribute attr ON attr.inpost_attribute_id = a.inpost_attribute_id
WHERE a.offer_product_id = 456
AND attr.is_active = 1;

-- Get attributes for offer (active only)
SELECT a.*, attr.name, attr.type
FROM ps_inpost_offer_attributes a
JOIN ps_inpost_attribute attr ON attr.attribute_id = a.attribute_id
WHERE a.offer_id = 123
AND attr.is_active = 1;

-- Check required attributes are present (active only)
SELECT COUNT(*) as missing_required
FROM ps_inpost_attribute attr
JOIN ps_inpost_category_attribute ca ON ca.attribute_id = attr.attribute_id
WHERE ca.mapping_id = 789
AND attr.required = 1
AND attr.is_active = 1
AND NOT EXISTS (
    SELECT 1 FROM ps_inpost_offer_attributes oa
    WHERE oa.offer_product_id = 456
    AND oa.inpost_attribute_id = attr.inpost_attribute_id
);
```

---

### **5. ps_inpost_offer_stock**

**Purpose**: Store stock/inventory information for offers.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `offer_stock_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `offer_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_offer.offer_id |
| `quantity` | INT(11) | NO | 0 | Available quantity |
| `unit` | VARCHAR(50) | YES | NULL | Unit of measurement (pcs, kg, etc.) |
| `availability` | VARCHAR(20) | NO | 'available' | Availability status |
| `created_at` | DATETIME | NO | - | Created timestamp |
| `updated_at` | DATETIME | NO | - | Updated timestamp |

#### **Indexes**

```sql
PRIMARY KEY (offer_stock_id)
INDEX IDX_OFFER_ID (offer_id)
```

#### **Foreign Keys**

```sql
FOREIGN KEY (offer_id) REFERENCES ps_inpost_offer(offer_id) ON DELETE CASCADE
```

#### **Business Logic**

- **1:1 relationship** with `ps_inpost_offer`
- Quantity synced from PrestaShop product stock
- `availability` field tracks stock status (available, unavailable, etc.)
- When stock changes, parent offer flagged with `require_stock_update = 1`

---

### **6. ps_inpost_offer_price**

**Purpose**: Store pricing information for offers.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `offer_price_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `offer_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_offer.offer_id |
| `price` | DECIMAL(12,4) | NO | - | Price amount |
| `currency` | VARCHAR(3) | NO | 'PLN' | Currency code (PLN, EUR, etc.) |
| `tax_rate` | DECIMAL(5,2) | YES | NULL | Tax rate percentage |
| `price_mode` | VARCHAR(16) | NO | 'auto' | `auto` = from the shop (with the InPost price settings), `manual` = typed in (1.1.57) |
| `manual_price` | DECIMAL(12,4) | YES | NULL | Manual gross price, sent as it is when `price_mode` = `manual` (1.1.57) |
| `shop_price` | DECIMAL(12,4) | YES | NULL | Last gross price of the shop, shown next to the InPost price (1.1.57) |
| `created_at` | DATETIME | NO | - | Created timestamp |
| `updated_at` | DATETIME | NO | - | Updated timestamp |

#### **Indexes**

```sql
PRIMARY KEY (offer_price_id)
INDEX IDX_OFFER_ID (offer_id)
```

#### **Foreign Keys**

```sql
FOREIGN KEY (offer_id) REFERENCES ps_inpost_offer(offer_id) ON DELETE CASCADE
```

#### **Business Logic**

- **1:1 relationship** with `ps_inpost_offer`
- Automatic price (`price_mode` = `auto`): the shop price with the module's price settings (`INPOSTSA_PRICE_GROUP_ID`, `INPOSTSA_PRICE_ADJUST_PERCENT`, `INPOSTSA_PRICE_ROUNDING`); `INPOSTSA_PRICE_RECALC_PENDING` marks a recalculation of all offers still in progress
- Manual price (`price_mode` = `manual`): `manual_price` is sent as it is, shop price changes and the price settings do not apply
- Price synced from PrestaShop product price (with tax)
- Currency from shop configuration (defaults to PLN)
- `tax_rate` stores VAT/tax percentage for reference

---

### **7. ps_inpost_offer_manufacturer**

**Purpose**: Store manufacturer/supplier information for offers.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `offer_manufacturer_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `offer_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_offer.offer_id |
| `name` | VARCHAR(128) | NO | - | Manufacturer name |
| `address` | VARCHAR(255) | YES | NULL | Full address (legacy field) |
| `website` | VARCHAR(255) | YES | NULL | Website URL |
| `address_street` | VARCHAR(255) | YES | NULL | Street address |
| `address_building_number` | VARCHAR(50) | YES | NULL | Building number |
| `address_apartment_number` | VARCHAR(50) | YES | NULL | Apartment number |
| `address_city` | VARCHAR(255) | YES | NULL | City |
| `address_postal_code` | VARCHAR(20) | YES | NULL | Postal code |
| `address_country_code` | CHAR(2) | YES | NULL | Country code (PL, DE, etc.) |
| `email` | VARCHAR(255) | YES | NULL | Email address |
| `phone` | VARCHAR(50) | YES | NULL | Phone number |
| `created_at` | DATETIME | NO | - | Created timestamp |
| `updated_at` | DATETIME | NO | - | Updated timestamp |

#### **Indexes**

```sql
PRIMARY KEY (offer_manufacturer_id)
INDEX IDX_OFFER_ID (offer_id)
```

#### **Foreign Keys**

```sql
FOREIGN KEY (offer_id) REFERENCES ps_inpost_offer(offer_id) ON DELETE CASCADE
```

#### **Business Logic**

- **1:1 relationship** with `ps_inpost_offer`
- Populated from PrestaShop manufacturer/supplier data
- Required by InPost for certain product categories
- Address can be stored as full `address` (legacy) or structured fields (preferred)

---

### **8. ps_inpost_offer_manual**

**Purpose**: Store product manuals/documentation links for offers.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `entity_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `offer_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_offer.offer_id |
| `title` | VARCHAR(255) | YES | NULL | Manual title/description |
| `url` | TEXT | YES | NULL | Manual URL |

#### **Indexes**

```sql
PRIMARY KEY (entity_id)
INDEX IDX_OFFER_ID (offer_id)
```

#### **Foreign Keys**

```sql
FOREIGN KEY (offer_id) REFERENCES ps_inpost_offer(offer_id) ON DELETE CASCADE
```

#### **Business Logic**

- **1:N relationship** with `ps_inpost_offer` (offer can have multiple manuals)
- Optional documentation (user manuals, spec sheets, etc.)
- `title` field provides description for the manual/document

---

### **8a. ps_inpost_offer_attachment** (1.1.58)

**Purpose**: Document attachments of offers (user manuals, energy labels, certificates, safety information) and their upload state. Sent to InPost by the `offer_attachment_sync` cron job.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `attachment_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `offer_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_offer.offer_id (ON DELETE CASCADE) |
| `attachment_type` | VARCHAR(40) | NO | - | InPost AttachmentType (e.g. MANUAL, ENERGY_LABEL) |
| `source` | VARCHAR(16) | NO | - | `upload` (own file) or `ps_attachment` (file of the product in PrestaShop) |
| `id_ps_attachment` | INT(10) UNSIGNED | YES | NULL | PrestaShop attachment id (source `ps_attachment`) |
| `local_file` | VARCHAR(255) | YES | NULL | File name in `download/inpostvonhalsky/` (source `upload`) |
| `file_name` | VARCHAR(255) | NO | - | File name shown in InPost |
| `mime` | VARCHAR(128) | NO | - | MIME type |
| `file_size` | INT(10) UNSIGNED | NO | 0 | Size in bytes |
| `status` | VARCHAR(20) | NO | 'PENDING' | `PENDING`, `UPLOADING`, `UPLOADED`, `ERROR`, `DELETING` |
| `inpost_offer_id` | VARCHAR(64) | YES | NULL | InPost offer the file was sent to |
| `inpost_attachment_id` | VARCHAR(64) | YES | NULL | InPost attachment id |
| `command_id` | VARCHAR(64) | YES | NULL | Asynchronous upload command id |
| `error` | TEXT | YES | NULL | Last error |
| `retry_count` | SMALLINT(5) UNSIGNED | NO | 0 | Failed transport attempts |
| `created_at` / `updated_at` | DATETIME | NO | - | Timestamps |

#### **Indexes and keys**

```sql
PRIMARY KEY (attachment_id)
INDEX IDX_ATTACHMENT_OFFER_ID (offer_id)
INDEX IDX_ATTACHMENT_STATUS (status)
FOREIGN KEY (offer_id) REFERENCES ps_inpost_offer(offer_id) ON DELETE CASCADE
```

#### **Business Logic**

- **1:N relationship** with `ps_inpost_offer` (one offer, many attachments); an offer cannot have two attachments with the same `file_name`
- Own files are limited to PDF / JPG / PNG, 10 MB; they are stored in `download/inpostvonhalsky/`
- A file added to a draft stays `PENDING` until the offer exists in InPost; removal sets `DELETING` and is retried until InPost accepts it
- Created by `upgrade/upgrade-1.1.58.php` on existing installations

---

### **9. ps_inpost_offer_gpsr**

**Purpose**: Store GPSR (General Product Safety Regulation) compliance data for offers.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `offer_gpsr_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `offer_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_offer.offer_id |
| `batch_number` | VARCHAR(500) | YES | NULL | GPSR batch number |
| `ce_marking` | TINYINT(1) | NO | 0 | GPSR CE marking (boolean) |
| `safety_information` | TEXT | YES | NULL | GPSR safety information |
| `does_not_require_info` | TINYINT(1) | NO | 0 | GPSR does not require info (boolean) |
| `created_at` | DATETIME | NO | - | Created timestamp |
| `updated_at` | DATETIME | NO | - | Updated timestamp |

#### **Indexes**

```sql
PRIMARY KEY (offer_gpsr_id)
UNIQUE KEY UNQ_OFFER_ID (offer_id)
```

#### **Foreign Keys**

```sql
FOREIGN KEY (offer_id) REFERENCES ps_inpost_offer(offer_id) ON DELETE CASCADE
```

#### **Business Logic**

- **1:1 relationship** with `ps_inpost_offer`
- GPSR compliance data required for certain product categories in EU
- `ce_marking`: Indicates if product has CE marking
- `does_not_require_info`: Flag for products that don't require GPSR information
- `safety_information`: Free-text field for safety-related information
- `batch_number`: Product batch/lot number for traceability

---

## 📅 **Event Tables**

---

### **10. ps_inpost_offer_event**

**Purpose**: Track offer lifecycle events from InPost API.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `entity_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `inpost_offer_event_id` | VARCHAR(36) | YES | NULL | InPost API event ID (UUID) |
| `offer_event_type` | VARCHAR(32) | NO | - | Event type (see below) |
| `occured_at` | DATETIME | NO | - | When event occurred (API timestamp) |
| `inpost_offer_id` | VARCHAR(255) | NO | - | InPost API offer ID |
| `prestashop_offer_id` | VARCHAR(255) | YES | NULL | Reference to inpost_offer.inpost_offer_id |

#### **Event Types**

- `CREATED`: Offer created in InPost
- `REJECTED`: Offer rejected by InPost
- `REOPENED`: Offer reopened after rejection
- `CLOSED`: Offer closed
- `PUBLISHED`: Offer published to marketplace
- `VALIDATION_FAILED`: Failed validation
- `SOLDOUT`: Out of stock
- `UPDATED`: Offer data updated

#### **Indexes**

```sql
PRIMARY KEY (entity_id)
INDEX IDX_INPOST_OFFER_EVENT_ID (inpost_offer_event_id)
INDEX IDX_PRESTASHOP_OFFER_ID (prestashop_offer_id)
INDEX IDX_INPOST_OFFER_ID (inpost_offer_id)
INDEX IDX_OFFER_EVENT_TYPE (offer_event_type)
INDEX IDX_OCCURRED_AT (occured_at)
```

#### **Business Logic**

- Events synced from InPost `/events` API endpoint
- Used for audit trail and troubleshooting
- Can trigger local offer status updates
- No cascading delete (keep event history even if offer deleted)
- `prestashop_offer_id` references `inpost_offer.inpost_offer_id` (soft reference)

#### **Sample Queries**

```sql
-- Get recent events for offer
SELECT * FROM ps_inpost_offer_event
WHERE prestashop_offer_id = 'abc-123'
ORDER BY occured_at DESC
LIMIT 10;

-- Find all VALIDATION_FAILED events
SELECT e.*, o.status, o.inpost_validation
FROM ps_inpost_offer_event e
LEFT JOIN ps_inpost_offer o ON o.inpost_offer_id = e.inpost_offer_id
WHERE e.offer_event_type = 'VALIDATION_FAILED'
ORDER BY e.occured_at DESC;
```

---

## 🗺️ **Mapping Tables**

---

### **11. ps_inpost_category_mapping**

**Purpose**: Store InPost category tree (synced from API).

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `entity_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `inpost_category_id` | VARCHAR(255) | NO | - | InPost API category ID |
| `inpost_parent_id` | VARCHAR(255) | YES | NULL | Parent InPost category ID |
| `name` | VARCHAR(255) | NO | - | Category name |
| `description` | TEXT | YES | NULL | Category description |
| `parent_id` | INT(10) UNSIGNED | YES | NULL | Parent entity_id (self-reference) |
| `level` | INT(10) UNSIGNED | NO | 0 | Category level in hierarchy (0 = root) |
| `is_leaf` | TINYINT(1) UNSIGNED | NO | 0 | Is leaf category (no children) |
| `is_active` | TINYINT(1) UNSIGNED | NO | 1 | Is active |
| `sort_order` | INT(10) UNSIGNED | NO | 0 | Display sort order |
| `language` | VARCHAR(5) | NO | 'pl' | Language code |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Created timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Auto-updated |
| `synced_at` | TIMESTAMP | YES | NULL | Last sync with API |

#### **Indexes**

```sql
PRIMARY KEY (entity_id)
UNIQUE KEY UNQ_CATEGORY_LANG (inpost_category_id, language)
INDEX IDX_INPOST_PARENT_ID (inpost_parent_id)
INDEX IDX_PARENT_ID (parent_id)
INDEX IDX_LEVEL (level)
INDEX IDX_IS_LEAF (is_leaf)
INDEX IDX_IS_ACTIVE (is_active)
INDEX IDX_LANGUAGE (language)
INDEX IDX_ACTIVE_LEVEL (is_active, level)
INDEX IDX_ACTIVE_LEAF (is_active, is_leaf)
```

#### **Foreign Keys**

```sql
FOREIGN KEY (parent_id) REFERENCES ps_inpost_category_mapping(entity_id) ON DELETE CASCADE
```

#### **Business Logic**

- **Tree structure**: `parent_id` points to parent category
- **Leaf categories**: Only leaf categories (`is_leaf = 1`) can be mapped to PrestaShop categories
- **Synced automatically** from InPost `/categories` API
- **Hierarchical levels**: `level = 0` is root, `level = 1` is first child, etc.
- **Multi-language**: Each category has entries per language

#### **Sample Queries**

```sql
-- Get root categories
SELECT * FROM ps_inpost_category_mapping
WHERE parent_id IS NULL
AND is_active = 1
ORDER BY sort_order;

-- Get leaf categories for mapping
SELECT * FROM ps_inpost_category_mapping
WHERE is_leaf = 1
AND is_active = 1
ORDER BY level, sort_order;

-- Build category tree (recursive CTE)
WITH RECURSIVE category_tree AS (
    SELECT entity_id, name, parent_id, level, 0 as depth
    FROM ps_inpost_category_mapping
    WHERE parent_id IS NULL

    UNION ALL

    SELECT c.entity_id, c.name, c.parent_id, c.level, t.depth + 1
    FROM ps_inpost_category_mapping c
    JOIN category_tree t ON c.parent_id = t.entity_id
)
SELECT * FROM category_tree ORDER BY depth, name;
```

---

### **12. ps_inpost_category_link**

**Purpose**: Link PrestaShop categories to InPost categories (N:M mapping).

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `link_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `mapping_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_category_mapping.entity_id |
| `prestashop_category_id` | INT(10) UNSIGNED | NO | - | PrestaShop category ID (ps_category.id_category) |
| `shop_id` | INT(10) UNSIGNED | YES | NULL | Shop ID (multi-shop support) |

#### **Indexes**

```sql
PRIMARY KEY (link_id)
UNIQUE KEY UNQ_MAPPING_CATEGORY_SHOP (mapping_id, prestashop_category_id, shop_id)
INDEX IDX_SHOP_ID (shop_id)
INDEX IDX_MAPPING_ID (mapping_id)
INDEX IDX_PRESTASHOP_CATEGORY_ID (prestashop_category_id)
```

#### **Foreign Keys**

```sql
FOREIGN KEY (mapping_id) REFERENCES ps_inpost_category_mapping(entity_id) ON DELETE CASCADE
```

#### **Business Logic**

- **N:M relationship**: One PrestaShop category can map to multiple InPost categories, and vice versa
- **Manual mappings only**: Created via admin UI, not auto-synced
- **Shop-specific**: Different mappings per shop (multi-shop support)
- **Leaf categories only**: Can only link to InPost categories where `is_leaf = 1`

#### **Sample Queries**

```sql
-- Find InPost categories for PrestaShop category
SELECT im.*, cl.shop_id
FROM ps_inpost_category_link cl
JOIN ps_inpost_category_mapping im ON im.entity_id = cl.mapping_id
WHERE cl.prestashop_category_id = 123
AND cl.shop_id = 1;

-- Find PrestaShop categories mapped to InPost category
SELECT cl.*, c.name as ps_category_name
FROM ps_inpost_category_link cl
JOIN ps_category_lang c ON c.id_category = cl.prestashop_category_id
WHERE cl.mapping_id = 456
AND c.id_lang = 1;
```

---

### **13. ps_inpost_attribute**

**Purpose**: Store global InPost attributes (synced from API).

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `entity_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `inpost_attribute_id` | VARCHAR(36) | NO | - | InPost API attribute ID (UUID) |
| `name` | VARCHAR(255) | NO | - | Attribute name |
| `type` | VARCHAR(64) | YES | NULL | Attribute type (TEXT_VALUE, etc.) |
| `lang` | VARCHAR(10) | YES | NULL | Language code |
| `expected_value` | VARCHAR(64) | YES | NULL | Expected value type (NULL_OR_ONE, etc.) |
| `description` | TEXT | YES | NULL | Attribute description |
| `required` | TINYINT(1) UNSIGNED | NO | 0 | Is required (0/1) |
| `is_active` | TINYINT(1) UNSIGNED | NO | 1 | Is active (0/1) |
| `synced_at` | TIMESTAMP | YES | NULL | Last sync with API |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Created timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Auto-updated |

#### **Indexes**

```sql
PRIMARY KEY (entity_id)
UNIQUE KEY UNQ_ATTRIBUTE_ID_LANG (inpost_attribute_id, lang)
INDEX IDX_INPOST_ATTRIBUTE_ID (inpost_attribute_id)
INDEX IDX_TYPE (type)
INDEX IDX_LANG (lang)
INDEX IDX_IS_ACTIVE (is_active)
INDEX IDX_SYNCED_AT (synced_at)
INDEX IDX_ACTIVE_LANG (is_active, lang)
```

#### **Business Logic**

- **Global attributes**: Synced from InPost `/attributes` API
- **Multi-language**: Each attribute has entries per language
- **Type definitions**: Define validation rules (TEXT, NUMBER, DICTIONARY, etc.)
- **Requirement indicator**: `required = 1` means must be provided
- **Active status**: `is_active = 0` marks attributes as inactive (e.g., deprecated by InPost)
- **Sync tracking**: `synced_at` records when attribute was last synchronized from API
- **Filtering**: Use `is_active = 1` to filter only active attributes in queries

#### **Sample Queries**

```sql
-- Get all active attributes
SELECT * FROM ps_inpost_attribute
WHERE is_active = 1
ORDER BY name;

-- Get active attributes by type
SELECT type, COUNT(*) as count
FROM ps_inpost_attribute
WHERE is_active = 1
GROUP BY type;

-- Get attributes that need sync (synced_at is NULL or old)
SELECT * FROM ps_inpost_attribute
WHERE is_active = 1
AND (synced_at IS NULL OR synced_at < DATE_SUB(NOW(), INTERVAL 1 DAY))
ORDER BY synced_at ASC;
```

---

### **14. ps_inpost_category_attribute**

**Purpose**: Link categories to their required/optional attributes with PrestaShop attribute mappings.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `entity_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `inpost_category_mapping_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_category_mapping.entity_id |
| `inpost_attribute_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_attribute.entity_id |
| `prestashop_attribute_code` | VARCHAR(255) | YES | NULL | Deprecated since 1.1.41 (attribute group id, kept in sync for rollback) |
| `ps_source_type` | VARCHAR(16) | YES | NULL | PrestaShop value source: `feature` or `attribute_group` (1.1.41) |
| `ps_source_id` | INT(10) UNSIGNED | YES | NULL | `id_feature` or `id_attribute_group` (1.1.41) |
| `required` | TINYINT(1) UNSIGNED | NO | 0 | Is attribute required for this category |
| `display_order` | INT(10) UNSIGNED | NO | 0 | Display order in category |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Created timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Auto-updated |

#### **Indexes**

```sql
PRIMARY KEY (entity_id)
UNIQUE KEY UNQ_CATEGORY_ATTRIBUTE (inpost_category_mapping_id, inpost_attribute_id)
INDEX IDX_CATEGORY_ID (inpost_category_mapping_id)
INDEX IDX_ATTRIBUTE_ID (inpost_attribute_id)
INDEX IDX_REQUIRED (required)
INDEX IDX_DISPLAY_ORDER (display_order)
```

#### **Foreign Keys**

```sql
FOREIGN KEY (inpost_category_mapping_id) REFERENCES ps_inpost_category_mapping(entity_id) ON DELETE CASCADE
FOREIGN KEY (inpost_attribute_id) REFERENCES ps_inpost_attribute(entity_id) ON DELETE CASCADE
```

#### **Business Logic**

- **Auto-synced relationships**: Created during attribute sync
- **PrestaShop value source** (1.1.41): `ps_source_type` + `ps_source_id`, set in the
  "Value source" dialog of Category mapping. Offer attribute values are read from the
  product feature, or from the combination for an attribute group (products with several
  combinations get no value - the offer covers all of them)
- **Category-specific requirement**: `required` flag can override global attribute requirement
- **Display order**: Controls order of attributes in admin UI
- Example: InPost "Wiek dziecka" attribute → PrestaShop feature "Wiek dziecka"

---

### **15. ps_inpost_attribute_dictionary**

**Purpose**: Store dictionary option values for attributes with predefined value sets.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `entity_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `inpost_attribute_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_attribute.entity_id |
| `inpost_dictionary_option_id` | VARCHAR(64) | NO | - | InPost dictionary option ID |
| `lang` | VARCHAR(10) | YES | NULL | Language code |
| `value` | VARCHAR(255) | NO | - | Dictionary value label |
| `active` | TINYINT(1) UNSIGNED | NO | 1 | Is active option |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Created timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Auto-updated |

#### **Indexes**

```sql
PRIMARY KEY (entity_id)
UNIQUE KEY UNQ_ATTRIBUTE_DICTIONARY (inpost_attribute_id, inpost_dictionary_option_id, lang)
INDEX IDX_ATTRIBUTE_ID (inpost_attribute_id)
INDEX IDX_OPTION_ID (inpost_dictionary_option_id)
INDEX IDX_LANG (lang)
```

#### **Foreign Keys**

```sql
FOREIGN KEY (inpost_attribute_id) REFERENCES ps_inpost_attribute(entity_id) ON DELETE CASCADE
```

#### **Business Logic**

- **Predefined values**: For attributes with fixed option sets (colors, sizes, etc.)
- **Auto-synced**: From InPost `/attributes/{id}/dictionary` API
- **Multi-language**: Each option has entries per language
- Used in value mapping dropdowns in admin UI
- `inpost_attribute_id` references `ps_inpost_attribute.entity_id` (not `inpost_attribute_id`)

---

### **15a. ps_inpost_attribute_value_map** (1.1.41)

**Purpose**: Which InPost dictionary option a PrestaShop value becomes, when matching by name is not enough.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `value_map_id` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `attribute_id` | INT(10) UNSIGNED | NO | - | FK to ps_inpost_attribute.attribute_id (ON DELETE CASCADE) |
| `ps_source_type` | VARCHAR(16) | NO | - | `feature` or `attribute_group` |
| `ps_value_id` | INT(10) UNSIGNED | NO | - | `id_feature_value` or `id_attribute` |
| `dictionary_value_code` | VARCHAR(128) | NO | - | ps_inpost_attribute_dictionary.value_code |
| `created_at` / `updated_at` | DATETIME | NO | - | Timestamps |

`UNIQUE (attribute_id, ps_source_type, ps_value_id)`. Per InPost attribute, shared by all
categories using it. Values without a row are matched to an active option by label, then
by code (case and whitespace insensitive).

---

## 🏢 **Organization Table**

---

### **16. ps_inpost_organization**

**Purpose**: Cache InPost organizations for dropdown selection.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `organization_id` | VARCHAR(255) | NO | - | InPost organization ID (PK) |
| `name` | VARCHAR(255) | NO | - | Organization name |
| `type` | VARCHAR(100) | YES | NULL | Organization type (SELLER, etc.) |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Created timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Auto-updated |
| `cached_at` | TIMESTAMP | YES | NULL | Last cache refresh time |
| `is_active` | TINYINT(1) | NO | 1 | Is active |

#### **Indexes**

```sql
PRIMARY KEY (organization_id)
INDEX idx_name (name)
INDEX idx_is_active (is_active)
INDEX idx_cached_at (cached_at)
```

#### **Business Logic**

- **Cache table**: Stores organizations fetched from InPost API
- **Refreshed manually**: Via admin "Refresh Organizations" button or CLI
- **Used in configuration**: Organization dropdown selection

---

## 📦 **Order Table**

---

### **17. ps_inpost_order**

**Purpose**: Store InPost marketplace orders synced from API.

#### **Columns**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id_inpost_order` | INT(10) UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `id_order` | INT(10) UNSIGNED | YES | NULL | PrestaShop Order ID (soft reference) |
| `id_shop` | INT(10) UNSIGNED | NO | 1 | PrestaShop Shop ID |
| `inpost_order_id` | VARCHAR(64) | NO | - | InPost Order ID (UUID) |
| `status` | VARCHAR(50) | NO | 'new' | Order status |
| `point_name` | VARCHAR(255) | YES | NULL | Pickup Point Name |
| `point_address` | TEXT | YES | NULL | Pickup Point Address |
| `sender_point_name` | VARCHAR(255) | YES | NULL | Sender Point Name |
| `receiver_email` | VARCHAR(255) | YES | NULL | Receiver Email |
| `receiver_phone` | VARCHAR(50) | YES | NULL | Receiver Phone |
| `shipment_data` | TEXT | YES | NULL | Full Shipment Data (JSON) |
| `date_add` | DATETIME | NO | - | Created timestamp |
| `date_upd` | DATETIME | NO | - | Updated timestamp |

#### **Indexes**

```sql
PRIMARY KEY (id_inpost_order)
UNIQUE KEY UNQ_INPOST_ORDER_ID (inpost_order_id)
INDEX IDX_ID_ORDER (id_order)
INDEX IDX_ID_SHOP (id_shop)
INDEX IDX_STATUS (status)
INDEX IDX_DATE_ADD (date_add)
INDEX IDX_DATE_UPD (date_upd)
```

#### **Business Logic**

- **Order sync**: Stores orders received from InPost marketplace API
- **PrestaShop link**: `id_order` soft reference to `ps_orders.id_order` (allows order deletion)
- **JSON storage**: `shipment_data` contains full API response for reference
- **Status values**: `new`, `accepted`, `refused`, `shipped`, `delivered`, `cancelled`
- **Multi-shop**: Each order scoped to specific shop via `id_shop`

#### **Sample Queries**

```sql
-- Get pending orders for shop
SELECT * FROM ps_inpost_order
WHERE id_shop = 1
AND status = 'new'
ORDER BY date_add DESC;

-- Find order by InPost ID
SELECT * FROM ps_inpost_order
WHERE inpost_order_id = 'abc-123-def';

-- Get orders with PrestaShop link
SELECT io.*, o.reference as ps_reference
FROM ps_inpost_order io
LEFT JOIN ps_orders o ON o.id_order = io.id_order
WHERE io.id_shop = 1;
```

---

## 🔗 **Relationships Summary**

### **One-to-One (1:1)**
- `ps_inpost_offer` ↔ `ps_inpost_offer_product`
- `ps_inpost_offer` ↔ `ps_inpost_offer_stock`
- `ps_inpost_offer` ↔ `ps_inpost_offer_price`
- `ps_inpost_offer` ↔ `ps_inpost_offer_manufacturer`
- `ps_inpost_offer` ↔ `ps_inpost_offer_gpsr`

### **One-to-Many (1:N)**
- `ps_inpost_offer` → `ps_inpost_offer_manual` (one offer, many manuals)
- `ps_inpost_offer` → `ps_inpost_offer_attachment` (one offer, many document attachments, 1.1.58)
- `ps_inpost_offer` → `ps_inpost_offer_attributes` (one offer, many attributes)
- `ps_inpost_offer_product` → `ps_inpost_offer_attributes` (one product, many attributes)
- `ps_inpost_category_mapping` → self (parent-child tree)
- `ps_inpost_category_mapping` → `ps_inpost_category_attribute` (one category, many attributes)
- `ps_inpost_attribute` → `ps_inpost_attribute_dictionary` (one attribute, many dictionary values)

### **Many-to-Many (N:M)**
- `ps_inpost_category_mapping` ↔ `ps_category` (via `ps_inpost_category_link`)
- `ps_inpost_category_mapping` ↔ `ps_inpost_attribute` (via `ps_inpost_category_attribute`)

### **Foreign Key Relationships**
- `ps_inpost_offer` → `ps_inpost_organization` (via `organization_id` FK)
- `ps_inpost_offer_attributes` → `ps_inpost_offer_product` (via `offer_product_id` FK)
- `ps_inpost_offer_attributes` → `ps_inpost_attribute` (via `attribute_id` FK)
- `ps_inpost_category_attribute` → `ps_inpost_category_mapping` (via `inpost_category_mapping_id` FK)
- `ps_inpost_category_attribute` → `ps_inpost_attribute` (via `inpost_attribute_id` FK)
- `ps_inpost_attribute_dictionary` → `ps_inpost_attribute` (via `inpost_attribute_id` FK)

### **Reference Links (No FK)**
- `ps_inpost_offer` references `prestashop_offer_id` (no FK to allow product deletion)
- `ps_inpost_offer` references `shop_id` (no FK to allow shop deletion)
- `ps_inpost_offer_event` references `inpost_offer_id` (event history preserved, soft reference)
- `ps_inpost_order` references `id_order` (soft reference to ps_orders, allows order deletion)

---

## 🔄 **Common Patterns**

### **Timestamp Tracking**
All tables have:
- `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`
- `updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

### **Sync Flags**
`ps_inpost_offer` uses flags for incremental sync:
- `require_api_sync`: Offer data changed, needs send to InPost API
- `require_image_sync`: Product images need upload to InPost
- `require_stock_update`: Stock quantity needs update

### **Soft References**
Some relationships use VARCHAR IDs without FK constraints to allow flexible data retention:
- Offer events keep history even if offer deleted
- Organizations referenced but not enforced

---

## 🔧 **Migration Scripts**

### **Installation SQL** (`install/install.sql`)

```sql
-- Create all 17 tables with indexes and foreign keys
-- See individual table definitions above

-- Example for ps_inpost_offer
CREATE TABLE IF NOT EXISTS `ps_inpost_offer` (
    `offer_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `inpost_offer_id` VARCHAR(64) DEFAULT NULL,
    `organization_id` VARCHAR(64) NOT NULL,
    `prestashop_offer_id` INT(10) UNSIGNED DEFAULT NULL,
    `shop_id` INT(10) UNSIGNED NOT NULL DEFAULT 1,
    `status` VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
    `is_draft` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `publication_status` VARCHAR(20) DEFAULT NULL,
    `shipping_time` VARCHAR(255) DEFAULT NULL,
    `affiliation_product_url` TEXT DEFAULT NULL,
    `post_sale_return_policy` TEXT DEFAULT NULL,
    `post_sale_complaint_policy` TEXT DEFAULT NULL,
    `language` VARCHAR(5) DEFAULT NULL,
    `inpost_category_id` VARCHAR(255) DEFAULT NULL,
    `inpost_validation` TEXT DEFAULT NULL,
    `inpost_validation_status` VARCHAR(50) DEFAULT NULL,
    `require_api_sync` TINYINT(1) NOT NULL DEFAULT 0,
    `require_image_sync` TINYINT(1) NOT NULL DEFAULT 0,
    `require_stock_update` TINYINT(1) NOT NULL DEFAULT 0,
    `last_data_sync_at` DATETIME DEFAULT NULL,
    `last_api_sync_at` DATETIME DEFAULT NULL,
    `synced_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`offer_id`),
    UNIQUE KEY `UNQ_INPOST_OFFER_ID` (`inpost_offer_id`),
    INDEX `IDX_INPOST_OFFER_ID` (`inpost_offer_id`),
    INDEX `IDX_ORGANIZATION_ID` (`organization_id`),
    INDEX `IDX_PRESTASHOP_OFFER_ID` (`prestashop_offer_id`),
    INDEX `IDX_SHOP_ID` (`shop_id`),
    INDEX `IDX_STATUS` (`status`),
    INDEX `IDX_IS_DRAFT` (`is_draft`),
    INDEX `IDX_PUBLICATION_STATUS` (`publication_status`),
    INDEX `IDX_INPOST_CATEGORY_ID` (`inpost_category_id`),
    INDEX `IDX_VALIDATION_STATUS` (`inpost_validation_status`),
    INDEX `IDX_REQUIRE_API_SYNC` (`require_api_sync`),
    INDEX `IDX_REQUIRE_IMAGE_SYNC` (`require_image_sync`),
    INDEX `IDX_REQUIRE_STOCK_UPDATE` (`require_stock_update`),
    INDEX `IDX_CREATED_AT` (`created_at`),
    INDEX `IDX_UPDATED_AT` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repeat for all 17 tables...
```

### **Upgrade Example** (`upgrade/upgrade-1.0.1.php`)

```php
<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    // Add new column to ps_inpost_offer
    $sql = 'ALTER TABLE `' . _DB_PREFIX_ . 'inpost_offer`
            ADD COLUMN `new_field` VARCHAR(255) NULL DEFAULT NULL AFTER `language`';

    return Db::getInstance()->execute($sql);
}
```

---

**Next**: [API Integration Documentation](API_INTEGRATION.md)
