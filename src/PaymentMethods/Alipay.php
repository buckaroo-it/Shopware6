<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\AlipayPaymentHandler;

class Alipay extends AbstractPayment
{
	/*
	* @return string
	*/
	public function getBuckarooKey(): string
	{
		return 'Alipay';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function getName(): string
	{
		return 'Alipay';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function getDescription(): string
	{
		return 'Pay with Alipay';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function getMedia(): string
	{
		return __DIR__  . '/../Resources/views/storefront/buckaroo/payments/alipay.svg';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function getPaymentHandler(): string
	{
		return AlipayPaymentHandler::class;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null
	 */
	public function getTemplate(): ?string
	{
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<mixed>
	 */
	public function getTranslations(): array
	{
		return [
			'de-DE' => [
				'name'        => $this->getName(),
				'description' => 'Bezahlen mit Alipay',
			],
			'en-GB' => [
				'name'        => $this->getName(),
				'description' => $this->getDescription(),
			],
		];
	}
}
