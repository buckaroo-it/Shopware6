<p align="center">
  <img src="https://www.buckaroo.nl/media/3216/shopware6-logo.png" width="225px" position="center">
</p>

# Buckaroo Payments Shopware 6

### About Shopeware

Shopware is a modular online shop system developed in Germany since 2004. It is available both as open source software and in commercial editions.

### Installation & Configuration 

The Buckaroo Payments Plugin ([Dutch](https://support.buckaroo.nl/categorieen/plugins/shopware-6)) for Shopware 6 enables a ready-to-sell payment gateway. You can choose from popular online payment methods in The Netherlands, Belgium, France, Germany and globally. 

### Install via composer
We recommend you to install the Buckaroo Shopware 6 plugin via composer. It is easy to install, update and maintain.
Run the following commands:

### Install
```
cd S6_INSTALLATION_ROOT
composer require buckaroo/shopware6
ln -s ../../vendor/buckaroo/shopware6 custom/plugins/BuckarooPayments
bin/console plugin:refresh
bin/console plugin:install --activate BuckarooPayments
bin/console cache:clear
```
### Upgrade
```
composer update buckaroo/shopware6
plugin:update BuckarooPayments
```

### Contribute

See [Contribution Guidelines](CONTRIBUTING.md)

### Versioning 
<p align="left">
  <img src="https://www.buckaroo.nl/media/3212/versioning.png" width="600px" position="center">
</p>

- **MAJOR:** Breaking changes that require additional testing/caution
- **MINOR:** Changes that should not have a big impact 
- **PATCHES:** Bug and hotfixes only 

### Additional information
- **Knowledge base & FAQ:** [Dutch](https://support.buckaroo.nl/categorieen/plugins/shopware-6)
- **Support:** https://support.buckaroo.eu/contact
- **Contact:** support@buckaroo.nl or +31 (0)30 711 50 50

