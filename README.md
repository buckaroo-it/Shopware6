<p align="center">
  <img src="https://github.com/user-attachments/assets/fec1c08d-c555-48ad-beab-671488a33295" width="200px" position="center">
</p>

# Buckaroo Shopware 6 Payments Plugin
[![Latest release](https://badgen.net/github/release/buckaroo-it/Shopware_6)](https://github.com/buckaroo-it/Shopware_6/releases)

### Index
- [About](#about)
- [Requirements](#requirements)
- [Installation](#installation)
- [Upgrade](#upgrade)
- [Configuration](#configuration)
- [Contribute](#contribute)
- [Versioning](#versioning)
- [Additional information](#additional-information)
---

### About

Shopware is a modular online shop system developed in Germany since 2004. It is available both as open source software and in commercial editions.

The Buckaroo Payments Plugin ([Dutch](https://support.buckaroo.nl/categorieen/plugins/shopware-6) or [English](https://support.buckaroo.eu/categories/plugins)) for Shopware 6 enables a ready-to-sell payment gateway. You can choose from popular online payment methods in The Netherlands, Belgium, France, Germany and globally.
Start accepting payments within a few minutes.

### Requirements

To use the Buckaroo plugin, please be aware of the following minimum requirements:
- A Buckaroo account ([Dutch](https://www.buckaroo.nl/start) or [English](https://www.buckaroo.eu/solutions/request-form))
- Shopware 6.5.0 up to 6.6.9.0
- PHP 8.1, 8.2, 8.3

### Installation

We recommend you to install the Buckaroo Shopware 6 Payments plugin with composer. It is easy to install, update and maintain.

**Run the following commands:**

```
cd S6_INSTALLATION_ROOT
composer require buckaroo/shopware6
ln -s ../../vendor/buckaroo/shopware6 custom/plugins/BuckarooPayments
bin/console plugin:refresh
bin/console plugin:install --activate BuckarooPayments
bin/console cache:clear
```

### Upgrade

**You can also upgrade/update the Buckaroo plugin with composer. To do this, please run the following commands:**

```
composer update buckaroo/shopware6
plugin:update BuckarooPayments
```

### Configuration

For the configuration of the plugin, please refer to our [Dutch](https://support.buckaroo.nl/categorieen/plugins/shopware-6) or [English](https://support.buckaroo.eu/categories/plugins) support website.
You will find all the necessary information there. But if you still have some unanswered questions, then please contact our [technical support department](mailto:support@buckaroo.nl).

### Contribute

We really appreciate it when developers contribute to improve the Buckaroo plugins.
If you want to contribute as well, then please follow our [Contribution Guidelines](CONTRIBUTING.md).

### Versioning 
<p align="left">
  <img src="https://www.buckaroo.nl/media/3485/shopware6_versioning.png" width="500px" position="center">
</p>

- **MAJOR:** Breaking changes that require additional testing/caution.
- **MINOR:** Changes that should not have a big impact.
- **PATCHES:** Bug and hotfixes only.

### Additional information
- **Knowledge base & FAQ:** Available in [Dutch](https://support.buckaroo.nl/categorieen/plugins/shopware-6) or [English](https://support.buckaroo.nl/categorieen/plugins).
- **Support:** https://support.buckaroo.eu/contact
- **Contact:** [support@buckaroo.nl](mailto:support@buckaroo.nl) or [+31 (0)30 711 50 50](tel:+310307115050)

<b>Please note:</b><br>
This file has been prepared with the greatest possible care and is subject to language and/or spelling errors.
