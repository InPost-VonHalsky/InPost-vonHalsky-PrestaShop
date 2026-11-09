# InPost von Halsky - PrestaShop Module

Integration of a PrestaShop shop with the InPost von Halsky marketplace: products are listed as InPost offers and InPost orders are created in PrestaShop.

PrestaShop 8.x · PHP 8.1+ · User guide: [English](docs/quick-start.en.md) / [Polski](docs/quick-start.md) · Changes: [CHANGELOG.md](CHANGELOG.md)

## Features

- **OAuth2 connection** to the InPost API with automatic token refresh; integration status page and downloadable diagnostic report
- **Category mapping** of shop categories to InPost leaf categories, with a mapping assistant (suggestions by name, path and product names)
- **Attribute value sources**: each InPost category attribute takes its value from a PrestaShop feature, attribute group (combinations) or product field; dictionary values matched by name or by hand; attribute assistant for all mapped categories
- **Offers**: single offer form, bulk listing from the product grid, **one offer per combination** (own stock, price, EAN, images and attribute values), category change of existing offers, bulk close / reopen / delete (only closed offers can be deleted; those existing in InPost only locally), delivery days from a product feature or a default
- **Bulk attribute edit**: change attributes of many offers at once (set, clear, restore, or map to a PrestaShop source), with a preview
- **Dedicated InPost prices**: the price sent to InPost from a customer group, a percentage change and rounding; manual price per offer or in bulk ("Set price"); prices in the shop never change
- **Offer attachments**: user manuals, energy labels, certificates and safety information (PDF, JPG, PNG up to 10 MB) from the computer or from the product's files, sent by a queue
- **Productization**: fills offer data from the InPost catalogue by EAN
- **Automatic synchronisation**: product, stock, combination, feature and image changes flag the offers and are sent by cron; offer statuses, validation and asynchronous create commands followed until done
- **Orders**: event-driven creation of PrestaShop orders plus incremental import; per-variant products, InPost prices and delivery cost, invoice details, InPost payment method, optional suppression of customer e-mails
- **ShipX integration**: shipments for InPost orders in the InPost ShipX module
- **Cron**: one dispatcher command `inpost:cron:run` (every minute) or the PrestaShop CronJobs module; background jobs panel with live progress

## Requirements

- **PrestaShop** 8.0.0 – 8.x
- **PHP** 8.1 or later, with `curl`, `json` and `mbstring`
- **MySQL** 5.7+ or **MariaDB** 10.3+
- A system cron (or the PrestaShop CronJobs module)
- **InPost von Halsky API credentials** (Client ID and Client Secret)
- **Composer** 2 is needed only for installation methods B and C

## Installation

PrestaShop does not install the Composer dependencies of a module. The module therefore has to arrive on the server **together with its `vendor/` directory**. It contains the [InPost von Halsky API SDK](https://github.com/InPost-VonHalsky/InPost-vonHalsky-api-sdk-php) and the OAuth2 client. Choose one of the methods below.

> The module directory must be named **`inpostvonhalsky`**.

### Method A: release package (recommended for shops)

1. Download `inpostvonhalsky-x.y.z.zip` from [Releases](https://github.com/InPost-VonHalsky/InPost-vonHalsky-PrestaShop/releases), section *Assets*. The package already contains `vendor/`.
2. In the admin panel go to **Modules → Module Manager → Upload a module** and drop the zip file.
3. Click **Configure** next to **InPost von Halsky**.

> ⚠️ Do **not** use **Code → Download ZIP** on GitHub. That archive has no `vendor/` directory and its top directory has the wrong name, so the module would not start.

### Method B: from the repository with Composer (developers)

Use this method when you deploy the shop from git or want to follow the source code.

```bash
cd /path/to/prestashop/modules
git clone https://github.com/InPost-VonHalsky/InPost-vonHalsky-PrestaShop.git inpostvonhalsky
cd inpostvonhalsky
git checkout v1.0.0          # pick a release tag; main may contain unreleased changes
composer install --no-dev --optimize-autoloader
```

Then go to **Modules → Module Manager**, find **InPost von Halsky**, click **Install**, then **Configure**.

Run `composer install` **inside the module directory**, not in the PrestaShop root. The module has its own `composer.json` and its own `vendor/autoload.php`.

The web server user must be able to read the module files (for example `chown -R www-data:www-data inpostvonhalsky`). `composer install` also writes `vendor/.htaccess`, which blocks web access to `vendor/` on Apache. On nginx, deny `/modules/inpostvonhalsky/vendor/` in the server configuration.

### Method C: Composer on your machine, files uploaded to the server

Use this method when the server has no Composer or no shell access.

```bash
git clone --branch v1.0.0 https://github.com/InPost-VonHalsky/InPost-vonHalsky-PrestaShop.git inpostvonhalsky
cd inpostvonhalsky
composer install --no-dev --optimize-autoloader
```

Upload the whole `inpostvonhalsky/` directory, **including `vendor/`**, to `modules/` on the server (SFTP, rsync, deployment tool). Then install the module in **Module Manager** as in method B.

Use PHP 8.1 locally, or add `--ignore-platform-reqs` only if you are sure the server meets the requirements.

### Updating

- **Method A:** upload the new zip in **Module Manager** in the same way.
- **Methods B and C:** update the files and the dependencies:
  ```bash
  git fetch --tags && git checkout vX.Y.Z
  composer install --no-dev --optimize-autoloader
  ```
  For method C, upload the directory again.

Then click **Upgrade** next to the module in **Module Manager** if PrestaShop shows it. Upgrades are additive: offers, mappings and orders are kept.

## Configuration

Full description: [user guide](docs/quick-start.en.md). In short:

1. **InPost von Halsky → Configuration → API Settings**:
   - enter the Client ID and Client Secret;
   - copy the OAuth2 Redirect URI to the InPost Merchant Portal;
   - click **Connect with OAuth2**;
   - pick the organization and click **Test Connection**.

   The API URLs point to the **staging** environment by default. Switch them to production when you go live (the user guide has both sets of URLs).
2. **Category Mapping**: map shop categories to InPost categories and set the attribute value sources.
3. **Cron**: run the dispatcher every minute:
   ```cron
   * * * * * php /path/to/prestashop/bin/console inpost:cron:run > /dev/null 2>&1
   ```
   You can also use the PrestaShop CronJobs module. Each job's own interval is enforced inside the module.
4. List products: **Catalog → Products → Bulk actions → List on InPost**, or **Offers → Create New Offer**.

## Console commands

Commands for manual runs and diagnostics:
- dispatcher: `inpost:cron:run` (`--status` shows the jobs);
- sync: `inpost:categories:sync`, `inpost:offer:sync`, `inpost:offer-events:sync`, `inpost:orders:sync`, `inpost:command:sync`;
- offers: `inpost:offer:send`, `inpost:offer:refresh`, `inpost:offer:images`, `inpost:offer:attachments`;
- orders and mapping: `inpost:orders:get`, `inpost:mapping:suggest`;
- diagnostics: `inpost:status`.

## Development

```
inpostvonhalsky/
├── inpostvonhalsky.php      # Main module class (install, hooks, configuration)
├── config.xml               # Module metadata
├── composer.json            # Dependencies (InPost API SDK, OAuth2 client)
├── config/                  # Symfony services and admin routes
├── controllers/             # Legacy admin / front controllers
├── src/                     # Namespace Inpost\VonHalsky\ (commands, controllers, services, repositories)
├── install/                 # Database schema (install.sql / uninstall.sql)
├── upgrade/                 # Upgrade scripts (upgrade-X.Y.Z.php), added when the schema changes
├── translations/            # Polish and English translations
├── views/                   # Templates, JS, CSS
└── docs/                    # User guide, architecture, database schema
```

Static analysis:

```bash
composer install
composer stan
```

Technical details: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md).

### Releasing

1. Set the version in `inpostvonhalsky.php`, `config.xml`, `composer.json` and the user guides, and add a `CHANGELOG.md` section.
2. Push a tag `vX.Y.Z`.

The **Release** GitHub Actions workflow builds `inpostvonhalsky-X.Y.Z.zip` with `vendor/` and attaches it to the GitHub release.

## Troubleshooting

- **The module does not start / class `InpostApi\...` not found**: `vendor/` is missing. Use the release zip or run `composer install` in the module directory.
- **Logs**: `var/logs/` of PrestaShop (`exception-*.log`, `prod-YYYY-MM-DD.log`), **Advanced Parameters → Logs**, and the module's status page with a downloadable diagnostic report.
- More in the [user guide](docs/quick-start.en.md#8-common-problems).

## Support

- InPost von Halsky API portal and integration questions: [apiportal@inpost.pl](mailto:apiportal@inpost.pl)
- Bugs and feature requests: [GitHub Issues](https://github.com/InPost-VonHalsky/InPost-vonHalsky-PrestaShop/issues)

## Related projects

- [InPost von Halsky PHP SDK](https://github.com/InPost-VonHalsky/InPost-vonHalsky-api-sdk-php)
- [InPost von Halsky module for Magento 2](https://github.com/InPost-VonHalsky/InPost-vonHalsky-Magento)

## License

[Academic Free License 3.0 (AFL-3.0)](https://opensource.org/license/afl-3-0-php)
