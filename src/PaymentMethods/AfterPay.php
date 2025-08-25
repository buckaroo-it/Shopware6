<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;

class AfterPay extends AbstractPayment
{
	   /*
	    * @return string
	    */
	   public function getBuckarooKey(): string
	   {
	       return 'afterpay';
	   }

	   /**
	    * {@inheritDoc}
	    *
	    * @return string
	    */
	   public function getName(): string
	   {
	       return 'Riverty';
	   }

	   /**
	    * {@inheritDoc}
	    *
	    * @return string
	    */
	   public function getDescription(): string
	   {
	       return 'Pay with Riverty';
	   }

	   /**
	    * {@inheritDoc}
	    *
	    * @return string
	    */
	   public function getPaymentHandler(): string
	   {
	       return AfterPayPaymentHandler::class;
	   }

	   /**
	    * {@inheritDoc}
	    *
	    * @return string
	    */
	   public function getMedia(): string
	   {
	       return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/afterpay.svg';
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
	               'description' => 'Bezahlen mit Riverty',
	           ],
	           'en-GB' => [
	               'name'        => $this->getName(),
	               'description' => $this->getDescription(),
	           ],
	       ];
	   }

	   public function canCapture(): bool
	   {
	       return false;
	   }
}
