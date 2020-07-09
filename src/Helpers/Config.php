<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Helpers;

use Buckaroo\Shopware6\Service\SettingsService;

class Config
{
    /** @var SettingsService $settingsService */
    private $settingsService;

    /**
     * Helper constructor.
     * @param SettingsService $settingsService
     */
    public function __construct(SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * @return string
     */
    public function websiteKey()
    {
        return $this->settingsService->getSetting('websiteKey');
    }

    /**
     * @return string
     */
    public function secretKey()
    {
        return $this->settingsService->getSetting('secretKey');
    }

    /**
     * @return string
     */
    public function guid()
    {
        return $this->settingsService->getSetting('guid');
    }

    /**
     * Get the environment (test / live) for a payment method
     * true = live
     * false = test
     *
     * @param  $name Name of the payment method
     * @return string
     */
    public function isLive($name)
    {
        $paymentEnv = strtolower($this->get('buckaroo_environment', 'test'));
        $env        = strtolower($this->get("buckaroo_{$name}_env", 'test'));

        return $paymentEnv == 'live' && $env == 'live';
    }

    /**
     * Whether to send the Klarna invoice via mail or email
     * true = mail
     * false = email
     *
     * @return boolean
     */
    public function klarnaPayInvoiceSendByMail()
    {
        return $this->get('buckaroo_klarna_pay_invoice_send', 'email') == 'mail';
    }

    /**
     * Whether to send status mails to the customer when the klarna payment has been paid
     *
     * @return boolean
     */
    public function klarnaSendPayStatusMail()
    {
        return $this->get('buckaroo_klarna_send_pay_status_mail', 'no') == 'yes';
    }

    /**
     * Whether to send status mails to the customer when the klarna payment has been cancelled
     *
     * @return boolean
     */
    public function klarnaSendCancelStatusMail()
    {
        return $this->get('buckaroo_klarna_send_cancel_status_mail', 'no') == 'yes';
    }

    /**
     * Whether to use pay or authorize/capture method for Afterpay
     * true = pay
     * false = authorize/capture
     *
     * @return boolean
     */
    public function afterPayUsePay()
    {
        return $this->get('buckaroo_afterpay_handling', 'authorize_capture') == 'pay';
    }

    /**
     * Whether to use redirect or encrypt method for Creditcard
     * true = encrypt
     * false = redirect
     *
     * @return boolean
     */
    public function creditcardUseEncrypt()
    {
        return $this->get('buckaroo_creditcard_handling', 'redirect') == 'encrypt';
    }

    /**
     * Whether to use pay or authorize/capture method for Afterpay
     * true = pay
     * false = authorize/capture
     *
     * @return boolean
     */
    public function afterPayNewUsePay()
    {
        return $this->get('buckaroo_afterpay_new_handling', 'authorize_capture') == 'pay';
    }

    /**
     * Whether to send status mails to the customer when the Afterpay payment has been captured
     *
     * @return boolean
     */
    public function afterpaySendCaptureStatusMail()
    {
        return $this->get('buckaroo_afterpay_send_capture_status_mail', 'no') == 'yes';
    }

    /**
     * Whether the application uses additional address fields or not
     *
     * @return boolean
     */
    public function useAdditionalAddressField()
    {
        return $this->get('buckaroo_use_additional_address_field', 'no') == 'yes';
    }

    /**
     * Whether to send status mails to the customer when the Afterpay payment has been cancelled
     *
     * @return boolean
     */
    public function afterpaySendCancelStatusMail()
    {
        return $this->get('buckaroo_afterpay_send_cancel_status_mail', 'no') == 'yes';
    }

    /**
     * Whether to send status mails to the customer when the status of the payment changes
     *
     * @return boolean
     */
    public function sendStatusMail()
    {
        return $this->get('buckaroo_send_status_mail', 'no') == 'yes';
    }

    /**
     * Whether to send status mails to the customer when the payment has been refunded
     *
     * @return boolean
     */
    public function sendRefundStatusMail()
    {
        return $this->get('buckaroo_send_refund_status_mail', 'no') == 'yes';
    }

    /**
     * Whether to send paymentlink to the customer upon order or upon capture
     *
     * @return boolean
     */
    public function guaranteePaymentInvite()
    {
        return $this->get('buckaroo_guarantee_payment_invite', 'no') == 'yes';
    }

    /**
     * Get a list of female salutations (used to determine sex of customer)
     *
     * @return string[]
     */
    public function femaleSalutations()
    {
        $str         = $this->get('buckaroo_female_salutations', "mrs, ms, miss, ma'am, frau, mevrouw, mevr");
        $salutations = explode(',', $str);
        return array_map('trim', $salutations);
    }

    /**
     * @return string
     */
    public function getGiftCards()
    {
        return $this->get('buckaroo_giftCards');
    }

    /**
     * Show Apple pay button on checkout page
     *
     * @return boolean
     */
    public function applepayMerchantGUID()
    {
        return $this->get('buckaroo_applepay_merchant_guid');
    }
}
