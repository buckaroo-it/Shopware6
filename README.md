<p align="center">
  <a href="https://www.buckaroo.nl">
    <img src="https://raw.githubusercontent.com/buckaroo-it/Media/main/Buckaroo/README.md%20Headers/buckaroo-shopware6-header-rounded.png" alt="Buckaroo — Payments for Shopware 6" width="100%">
  </a>
</p>

<h1 align="center">Buckaroo for Shopware 6</h1>


<p align="center">
  <a href="https://packagist.org/packages/buckaroo/shopware6"><img src="https://img.shields.io/packagist/v/buckaroo/shopware6.svg?label=release" alt="Latest release"></a>
  <a href="https://docs.buckaroo.io/docs/shopware-6"><img src="https://img.shields.io/badge/docs-docs.buckaroo.io-1a1a4b.svg" alt="Documentation"></a>
  <a href="https://store.shopware.com/en/bucka93323165700f/buckaroo-payments-shopware-6.html"><img src="https://img.shields.io/badge/Shopware%20Store-listing-189eff.svg" alt="Shopware Store"></a>
</p>

<p align="center">
  <a href="#about">About</a> &middot;
  <a href="#requirements">Requirements</a> &middot;
  <a href="#installation">Installation</a> &middot;
  <a href="#upgrade">Upgrade</a> &middot;
  <a href="#configuration">Configuration</a> &middot;
  <a href="#payment-methods">Payment methods</a> &middot;
  <a href="#support">Support</a> &middot;
  <a href="#contribute">Contribute</a>
</p>

---

## About

Shopware is a modular online shop system developed in Germany since 2004, available both as open source software and in commercial editions.

The Buckaroo plugin for Shopware 6 connects your store to the Buckaroo payment gateway, so you can start accepting payments within minutes. Buckaroo is a Dutch Payment Service Provider and a certified Shopware Technology Partner.

Card payments run through Hosted Fields, which keeps the card entry inside your own checkout instead of redirecting the customer away.

[Full plugin documentation on docs.buckaroo.io](https://docs.buckaroo.io/docs/shopware-6)

---

## Requirements

| Requirement | Supported versions   |
|---|---|
| Shopware | 6.5.8.0 up to 6.7.x |
| PHP | 8.2 or higher        |
| Composer | 2.x                  |

You also need a Buckaroo account. Don't have one yet? [Request an account](https://www.buckaroo.nl/start).

> [!NOTE]
> No administration rebuild is required. The plugin ships pre-built administration assets for all supported Shopware versions, so you do not need to run `bin/build-administration.sh` or any other build command after installing or updating.

---

## Installation

We recommend installing the plugin with Composer. It is the easiest way to install, update and maintain.

Run the following commands from your Shopware 6 root folder:

```bash
composer require buckaroo/shopware6
ln -s ../../vendor/buckaroo/shopware6 custom/plugins/BuckarooPayments
bin/console plugin:refresh
bin/console plugin:install --activate BuckarooPayments
bin/console cache:clear
```

<details>
<summary>Installing from the Shopware Store</summary>

You can also install the plugin without Composer:

1. Sign in to your Shopware 6 administration.
2. Go to **Extensions → Store** and search for Buckaroo.
3. Install the extension and activate it.

</details>

---

## Upgrade

```bash
composer update buckaroo/shopware6
bin/console plugin:update BuckarooPayments
bin/console cache:clear
```

> [!TIP]
> Always test an upgrade on a staging environment first and check the [release notes](https://github.com/buckaroo-it/Shopware6/releases) for breaking changes.

---

## Configuration

Sign in to your Shopware 6 administration and go to **Extensions → My extensions**. Find the Buckaroo extension, make sure it is active, and press **Configure**.

You will need your **Store key** and **Secret key**, which you can find under [API credentials in Buckaroo Plaza](https://plaza.buckaroo.nl/Configuration/Merchant/ApiKeys). The Store key is unique per store, the Secret key applies to your whole account.

To offer Apple Pay you also need your Buckaroo Guid, found in the [Buckaroo Plaza](https://plaza.buckaroo.nl/) under **My Buckaroo → General**.

Step-by-step instructions: [Configuring the Shopware 6 plugin](https://docs.buckaroo.io/docs/shopware-6-configuration)

---

## Payment methods

The plugin supports the following payment methods. Each one can be enabled or disabled individually and switched between live and test mode.

| | | |
|---|---|---|
| [Alipay](https://docs.buckaroo.io/docs/alipay) | [Apple Pay](https://docs.buckaroo.io/docs/apple-pay) | [Bancontact](https://docs.buckaroo.io/docs/bancontact) |
| [Bank Transfer](https://docs.buckaroo.io/docs/transfer) | [Belfius](https://docs.buckaroo.io/docs/belfius) | [Billink](https://docs.buckaroo.io/docs/billink) |
| [Bizum](https://docs.buckaroo.io/docs/bizum) | [Blik](https://docs.buckaroo.io/docs/blik) | [Credit and debit cards](https://docs.buckaroo.io/docs/creditcards) |
| [EPS](https://docs.buckaroo.io/docs/eps) | [Giftcards](https://docs.buckaroo.io/docs/giftcards) | [Google Pay](https://docs.buckaroo.io/docs/google-pay) |
| [iDEAL / Wero](https://docs.buckaroo.io/docs/ideal) | [iDEAL QR](https://docs.buckaroo.io/docs/ideal-qr) | [In3](https://docs.buckaroo.io/docs/in3) |
| [KBC](https://docs.buckaroo.io/docs/kbc) | [Klarna](https://docs.buckaroo.io/docs/klarna-kp) | [MB Way](https://docs.buckaroo.io/docs/mb-way) |
| [Multibanco](https://docs.buckaroo.io/docs/multibanco) | [Pay by Bank](https://docs.buckaroo.io/docs/pay-by-bank) | [PayPal](https://docs.buckaroo.io/docs/paypal) |
| [PayPerEmail](https://docs.buckaroo.io/docs/payperemail) | [Przelewy24](https://docs.buckaroo.io/docs/przelewy24) | [Riverty](https://docs.buckaroo.io/docs/riverty) |
| [SEPA Direct Debit](https://docs.buckaroo.io/docs/sepa-direct-debit) | [Swish](https://docs.buckaroo.io/docs/swish) | [Trustly](https://docs.buckaroo.io/docs/trustly) |
| [Twint](https://docs.buckaroo.io/docs/twint) | [WeChatPay](https://docs.buckaroo.io/docs/wechatpay) | [Wero](https://docs.buckaroo.io/docs/wero) |

> [!IMPORTANT]
> All supported methods appear in the Shopware administration, but you need an active Buckaroo subscription for a method before you can offer it in your checkout.

---

## Support

Having trouble? Work through this list before reaching out:

1. Check the [frequently asked questions](https://docs.buckaroo.io/docs/shopware-6-faq).
2. Confirm you are on the [latest release](https://github.com/buckaroo-it/Shopware6/releases).
3. Enable debug logging in the plugin configuration and reproduce the issue.
4. Verify that your push URL is reachable from outside your network. Buckaroo sends push messages from fixed IP addresses and ports, so make sure these are on your allow list. See [push messages](https://docs.buckaroo.io/docs/integration-push-messages) for the current list.

Still stuck? Contact us and include your Shopware version, plugin version, PHP version, the relevant log lines and the transaction key.

- **Bug reports and feature requests:** [open an issue](https://github.com/buckaroo-it/Shopware6/issues)
- **Technical support:** [support@buckaroo.nl](mailto:support@buckaroo.nl)
- **Phone:** +31 (0)30 711 50 50
- **Gateway status:** [status.buckaroo.io](https://status.buckaroo.io/)

---

## Contribute

We really appreciate it when developers help improve the Buckaroo plugins. Please read our [Contribution Guidelines](https://github.com/buckaroo-it/Shopware6/blob/master/CONTRIBUTING.md) before opening a pull request, and target the `master` branch.

Found a security issue? Please report it privately to [support@buckaroo.nl](mailto:support@buckaroo.nl) instead of opening a public issue.

---

## Versioning

We follow semantic versioning (`MAJOR.MINOR.PATCH`):

- **MAJOR** — breaking changes that require additional testing and caution.
- **MINOR** — new functionality with limited impact.
- **PATCH** — bug fixes and hotfixes only.

All changes are documented in the [changelog](https://github.com/buckaroo-it/Shopware6/blob/master/CHANGELOG.md) and on the [releases page](https://github.com/buckaroo-it/Shopware6/releases).

---

<p align="center">
  <sub>Made with care by <a href="https://www.buckaroo.nl">Buckaroo</a>.<br>
  This document is subject to change; typos and language errors are possible.</sub>
</p>
