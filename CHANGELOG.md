# Changelog

All notable changes to the InPost von Halsky module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-09

First public release.

### Features
- OAuth2 connection to the InPost von Halsky API with automatic token refresh, integration status page and downloadable diagnostic report.
- Category mapping of shop categories to InPost leaf categories, with a mapping assistant that suggests categories by name, path and product names.
- Attribute value sources: every InPost category attribute takes its value from a PrestaShop feature, an attribute group (combinations) or a product field. Dictionary values are matched by name or by hand.
- Offers:
  - single offer form and bulk listing from the product grid;
  - one offer per combination;
  - category change of existing offers;
  - bulk close, reopen and delete;
  - delivery days from a product feature or a default.
- Bulk attribute edit of many offers at once, with a preview.
- Dedicated InPost prices: from a customer group with a percentage change and rounding, or a manual price per offer or in bulk. Prices in the shop never change.
- Offer attachments: user manuals, energy labels, certificates and safety information.
- Productization: offer data filled in from the InPost catalogue by EAN.
- Automatic synchronisation of product, stock, combination, feature and image changes. Offer statuses and asynchronous commands are followed until done.
- Orders:
  - event-driven creation of PrestaShop orders plus incremental import;
  - InPost prices and delivery cost, invoice details and the InPost payment method;
  - optional suppression of customer e-mails.
- ShipX integration: shipments for InPost orders in the InPost ShipX module.
- One cron dispatcher (`inpost:cron:run`) or the PrestaShop CronJobs module; background jobs panel with live progress.

### Requirements
- PrestaShop 8.0 – 8.x, PHP 8.1+.
- InPost von Halsky API SDK `inpostapi/inpost-vonhalsky-api-sdk-php` ^2.0.
