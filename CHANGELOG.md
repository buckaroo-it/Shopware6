# Unreleased

## iDEAL | Wero co-branding (mandatory from 29 January 2026)

- **Payment method name**: Renamed from "iDEAL" to "iDEAL | Wero" across frontend (checkout, UI labels), backend (admin, configuration), and payment method lists.
- **Logo update**: Replaced the iDEAL logo with the co-branded iDEAL | Wero logo (SVG) in checkout, admin payment list, and payment method media. The co-branded logo is used even when rail selection tooling is not used.
- **iDEAL | Wero fast checkout**: Admin labels and alt texts for the fast checkout button now use "iDEAL | Wero". For the fast checkout button image, replace the PNG assets `idealFastCheckoutLogoLight.png` and `idealFastCheckoutLogoDark.png` in `Resources/public/storefront/buckaroo/` with the co-branded iDEAL | Wero versions from the [Buckaroo Media repository](https://github.com/buckaroo-it/Media) (PNG format). The SVG logo is already provided as `ideal-wero.svg`.

**Note:** This change must not be released before 29 January 2026. Logo switch must be fully completed by 31 March 2026.

# 1.0.0
- First version BuckarooPayment for Shopware 6.1

# 1.0.5
Added payment methods

* Ideal
* IdealProcessing
* Bancontact
* Creditcards
* AfterPay
* Sofort
* PayPal
* Bank Transfer

# 1.0.6
Added payment methods

* ApplePay
* Giropay
* KBC
* Sepa Direct Debit
* Payconiq

# 1.0.7
Added payment methods

* Giftcards
* Request To Pay

# 1.0.8
Updated refund tab

# 1.0.9
## Added
Stock reserve option
Invoices on paid status

## Added payment methods
* Creditcards Client Sided
* In3
* Eps
* Przelewy24
* Alipay

# 1.1.0
- BP-379 Compatibility with 6.3
- BP-309 Added payment fee (fixed amount) to all payment methods
- BP-201 Added payment method WeChatPay
- BP-204 Added payment method Trustly

# 1.1.1
- BP-206 Add payment method KlarnaKP

# 1.1.2
- BP-584 Multiple issues after code review
- BP-583 Add new bank to iDEAL payment method

# 1.1.3
- BP-643 Add payment method Billink

# 1.1.4
- BP-683 Add Klarna 'Pay' and 'PayInInstallments'

# 1.2.0
- BP-765 Update to Shopware 6.4 and test

# 1.2.1
- BP-817 Add payment method Belfius
- BP-906 Translation issue
in3 / fix country for guest mode
Modified error log in exception handler. The injected logger does not
Make it possible for Billink to enable bothe methods (B2B+B2C)
- BP-946 Add giftcard Huis & Tuin Cadeau (giftcard)
- BP-941 All plugins - PayPal (V2) cancellation returns to the homepage
- BP-945 Add payment method Billink Authorize/Capture
- BP-957 Make 'VAT number' field non-required (BILLINK)
- BP-970 Add CreditCard brand PostePay

# 1.2.2
#5 Edit order - impossible
- BP-985 Fix warnings

# 1.2.3
#6 Fix for partial payment issue with cancelled parts
#8 Version in composer.json is not updated
Fix composer stable
#6 Fix getOrderTransactionStatesNameFromAction function
#9 Fix for partial payment pushes issue
- BP-996 Add accept terms & conditions for Billink
- BP-1017 Choosing Postepay card uses VISA instead
FIX AfterPay. Country does not seem to be filled and therefor 
- BP-1020 Afterpay terms and condition with a address in Belgium is showing the terms for NL.
#12 Update AsyncPaymentHandler.php
- BP-452 Fix KBS title
- BP-1052 Afterpay refund from Shopware backend is showing a error.
- BP-1064 Wrong language errormessage in shopware checkout
- BP-1065 Use {% blocks %} to override
#15 Incorrect signature on partial payments
- BP-1020 Fix text
#16 Partial payments - order gets incorrectly cancelled
- BP-1088 Billink logo update

# 1.2.4
- BP-1146 Company name not filled at Billink
Update README.md
- BP-1163 remove case sensitive logos 

# 1.3.0
- BP-1189 New Sofort logo added
- BP-1193 Shopware 6 CSE tooltip
- BP-1172 Fix 'composer' warning
- BP-1236 Shopware 6 - incorrect amount for partial refunds
- BP-969 Add payment method PayPerEmail + Paylink (restore deleted config)
- BP-1333 In3 invalid request when ordering as company
- BP-1336 Unkown button in order buckaroo tab
- BP-1331 Empty cart at browser go back from payment
- BP-1340 User logout after payment cancel
- BP-1335 Applepay disables pay button without any message
- BP-1341 last partial refund doesn't update payment status to "Refunded"
- BP-1332 No validation message for payment fields in checkout
- BP-1330 TotalPrice not changeable
- BP-1339 invalid payments from admin order

# 1.3.1
- BP-1432 Plugin crashes for shopware 6.4.9.0
- BP-1433 Uncaught PHP Exception TypeError: "round(): Argument #1 ($num) must be of type int|float, string given"
- BP-1434 Fixing typeError inside _registerCheckoutSubmitButton (Thanks to @nielsVoogt)

# 1.3.2
- BP-1440 Add ideal issuers selectbox

# 1.3.3
- BP-1815 Add support for Shopware 6 version 6.4.13.0
- BP-1675 Add support for multiple sales channels (multi-store)
- BP-1726 iDEAL status message is not clear for consumers
- BP-1766 Add transaction label and refund label
- BP-1435 Change service version of Transfer payment method
- BP-1441 Option in plugin config to set SendMail and DueDate parameters for Transfer
- BP-1508 Update README file
[#57] Fix | Use sales channel URL instead of base url (Thank you @MelvinAchterhuis !)
- BP-1462 Apple Pay shows a error in the checkout "errorOccurred"  

# 1.4.0
- BP-1981 Add a option to switch on payperemail (in the backend only)
- BP-1896 Change gender selection for BNPL methods
- BP-1878 Update contribution guidelines
- BP-1480 "Please enter a valid value" error message improvement for iDEAL
- BP-1466 Add Support for Afterpay B2B
- BP-212 Add a PayPal Express button

# 1.5.0
Add support for Shopware 6 version 6.4.16.0
[#74] Update README.md versions change and extra notice.
- BP-1471 Remove 'Handelsbanken'
- BP-2004 Add payment method AfterPay (old) -> Digiaccept
- BP-1869 Rebranding Afterpay 
- BP-2057 Fix issue with the mailer function when installing the PostNL plugin (Thank you @daniel-memo-ict !)
# 1.5.1
[#82] Fix Afterpay old transaction only in test mode
# 1.6.0
Add support for Shopware 6 version 6.4.17.2
- BP-2112 Fix Klarna gender values
BP 2106 issue with redirect
- BP-2114 Afterpay (old) name mapping incorrect
- BP-2128 Dutch translation improvements
- BP-1982 Add a notification payment methods are in test mode
# 1.7.0
Add support for Shopware 6 version 6.4.19.0
- BP-2179 Fix custom products for Klarna
- BP-2106 Fix a issue with redirect URL's
- BP-2181 Fix uncaught PHP Exception TypeError for payment fee configuration
- BP-2291 Remove Request to Pay method
- BP-2241 Change the Billink rejected (690) message (more consumer friendly)
- BP-2322 Added validation on the 'Buckaroo Fee' input field
- BP-2331 Solve frontend issue on the checkout page
# 2.0.0
Compatible from Shopware 6.5.0 up to 6.5.1.1
- BP-1473 Important changes code changes
- BP-1474 Recommanded Changes
- BP-1475 Optional Changes
- BP-1570 Pipeline Changes
- BP-1860 Use the Buckaroo PHP SDK
- BP-2161 Refactor Apple Pay
- BP-2573 Allow invoice pushes that have missing additional parameter orderId
- BP-2164 Solve an issue related to cookie_samesite (use session)
- BP-2341 Update the checkout with the newest payment method logo's
- BP-2342 Make sure phone number is 'pre-filled' when already filled in
- BP-2162 Refactor CheckoutHelper
- BP-2326 Add PayPal Seller Protection
- BP-2386 Add iDEAL issuer "YourSafe"
- BP-2563 Solve an issue with creating refunds with Credit Cards(redirect)
- BP-2562 User is not redirected to Payment section after rejected/failed iDEAL order
- BP-2613 Solve admin and payment process issue/error for specific merchant
- BP-2483 Live credit card transactions are not refundable
- BP-2658 Cannot create a partial refund with Afterpay

# 2.1.0
Compatible from Shopware 6.5.0 up to 6.5.5.2
- BP-2675 Add payment method: PayByBank
- BP-2795 Add payment method: iDEAL QR
- BP-2847 Add payment method In3 (V3 API)
- BP-2884 Add iDEAL issuer N26
- BP-2921 Add iDEAL issuer Nationale Nederlanden
- BP-2907 iDEAL issuer logo and name change into "Van Lanschot Kempen"
- BP-2678 Rename Creditcards into Cards
- BP-2904 Pay the remaining group transaction amount with iDEAL, Bancontact and Credit cards
- BP-2966 Show the correct software/platform version in the software header of the requests
- BP-2663 Fix: Mask some field input when saved
- BP-2841 Fix: PayByBank visual improvements
- BP-2944 Fix: Cannot partially refund group transactions
- BP-2937 Fix: Buckaroo Subscription orders are not visible in the consumer's account
- BP-2956 Fix: Plugin settings page does not have a proper browser title

# 2.2.0
Compatible from Shopware 6.5.0 up to 6.5.6.1
- BP-3014 Add payment method MB WAY
- BP-2983 Add payment method Multibanco
- BP-3019 Add separate authorize capture flow for Riverty | Afterpay
- BP-2991 For In3 (V3) set the iDEAL In3 logo as the default
- BP-3029 Remove BIC/IBAN fields for Giropay
- BP-3048 Payment fee bug with Klarna KP
- BP-3054 In3 (V3) set a new default for the frontend label
- BP-3019 Set payment to status authorize for authorizations
- BP-3019 Fix flow settings label
- BP-3116 Fix a "div" that is opened and never closed
- BP-3122 Change and improve the Riverty/AfterPay rejection message
- BP-3145 Add a option to not show the iDEAL issuers selection in the checkout (preparation for iDEAL 2.0)
- BP-3168 Enabling and Disabling the "Show Issuer Selection" config hides another config
- BP-3166 Buckaroo payment fee is not present in the Admin Refund menu

# 2.2.1
- BP-3393 - Fix for the separate authorize/capture flow for Riverty which could set incorrect attempts to processing.

# 2.3.0
- Add support for Shopware 6.5.8.10
- BP-3558 Add support for Shopware 6.6.3.0
- BP-3401 Improve the plugin user interface with a payment method overview.
- BP-3470 Add payment method: Knaken Settle.
- BP-3348 Resize payment method logo’s for better readabillity.
- BP-3383 Add Riverty Strong Customer Authentication setting (SCA).
- BP-3393 Add additional order status setting for authorized Riverty transactions.
- BP-3321 Change new required fields for Riverty specificly for Germany.
- BP-3336 Update the consumer financial warning text for BNPL methods.
- BP-3362 Display additional product information in the Buckaroo Payment tab.
- BP-3400 Minor iDEAL adjustments/improvements (plugin configuration).
- BP-3437 Add product image URL's for Riverty transactions.
- BP-3515 Remove the logo selection for iDEAL In3.
- BP-3444 Merge iDEAL collecting and processing as one single payment method with additional configuration.
- BP-3418 Fix: iDEAL without issuer is not sending ContinueOnIncomplete.
- BP-3376 Fix: Riverty refund with custom amount is not working.
- BP-3330 Fix: No description send for refund requests.
- BP-3288 Fix: Refund issue with the tax amount.
- BP-3466 Fix: Full order amount discount (Promotions) makes it unable to place the order.
- BP-3565 Fix: Riverty TOC URL's are showing a error 404 in some cases.
- BP-3534 Fix: Apple Pay button is not always clickable for specific merchant.
- BP-3614 Fix: Manual capture is not always working for Klarna (authorize/capture).

# 2.3.1
- Minor fixes

# 2.4.0

- Add support for Shopware 6.6.6.0
- Add support for Shopware 6.5.8.14
- BP-3629 Add payment method: Blik
- BP-3490 Add a Bancontact option to let consumer fill in card information in the Shopware checkout instead of the Buckaroo hosted payment page.
- BP-3629 Adjust the default Riverty | Afterpay name just to Riverty
- BP-3711 Adjust the Riverty | Afterpay logo to the new Riverty logo
- BP-3637 Fix Javascript error: Plugin "BuckarooPaymentValidateSubmit" is already registered
- BP-3785 Remove payment method Giropay (discontinued)

# 2.5.0

- BP-3911 Add support for Shopware 6.6.8.2
- BP-3850 Add support for Shopware 6.6.7.0 & 6.6.7.1
- BP-4015 Added support for EUR currency in Przelewy24.
- BP-3877 Enhanced Security for Cards (with Cliënt Side Encryption): ensuring a safer checkout experience.
- BP-3888 Improved Security for Bancontact (with Cliënt Side Encryption): ensuring a safer checkout experience.
- BP-4016 Remove the payment method Sofort (Discontinued).
- BP-4017 Updated the bank transfer logo to a globally recognized version.
- BP-3594 Resolved an extension conflict with the Wexo DHLShipping module regarding Riverty and B2B orders.
- BP-3868 Resolved an issue where canceling an iDEAL transaction could result in an error page.
- BP-3893 Resolved an issue with the PayPal Express button that was sometimes not displayed (in the cart page).
- BP-3906 Resolved an error: The selected payment method does not exist.
- BP-3910 Resolved an error: Plugin "BuckarooPaymentValidateSubmit" is already registered.
- BP-3799 Resolve an issue with PayPal orders that contain a “+” sign and can’t process the PUSH correctly.
- BP-3893 Resolve an issue where the PayPal Express button occasionally failed to appear on the product page.
- BP-3985 Resolve an issue with Klarna KP and refunds that triggered an error when “Automatically Pay Reservation” was enabled in the Plaza.
- BP-3886 iDEAL cancellation can result into a error page.
- BP-3985 Resolved an issue where Klarna refunds from Shopware displayed an error when Automatic Capture was enabled in the Buckaroo Plaza.

# 2.6.0

- BP-3998 Add support for Shopware 6.6.9.0
- BP-4148 Add transaction key to custom fields.
- BP-4148 Add a setting that orders are closed automatically when failed/cancelled.
- BP-4150 Implement better support for displaying error messages for headless implementations.
- BP-3807 Add iDEAL fast checkout button.
- BP-4943 Resolve error message: Plugin “BuckarooPaymentValidateSubmit” is already registered.

# 2.7.0

- BP-4208 Rebranding for “Knaken Settle” into “goSettle”.
- BP-4204 Fix: The setting that automatically closes orders when they fail or are canceled has no effect on status 890.

# 2.8.0

- BP-4232 Use a more generic "PayByBank" logo.
- BP-4257 Adjust payment method names, to not contain the prefix "Buckaroo" anymore.
- BP-4273 Added setting to auto-cancel order and delivery status on failed transactions.
- BP-4191 Replace "Cards (CSE)" with "Cards Hosted Fields".
- BP-4203 Removed “Test” option for iDEAL QR (since there is no sandbox anymore).
- BP-4312 Added the parameter “ConsumerEmail” for Trustly payments.
- BP-4221 Fix: iDEAL Fast Checkout shipping cost issue.
- BP-4227 Fix: Error “An error occurred while processing your payment” for iDEAL Fast Checkout.

# 2.8.1

- BP-4317 Fix: PayPal Express sending wrong amount and add option for Apple Pay redirect to Buckaroo hosted payment.
- BP-4245 Fix: iDEAL Fast Checkout does not always work with guest orders.
- BP-4272: Fix: Missing ConsumerEmail parameter when using Trustly payment method.
- BP-4297 Fix: Clicking multiple times on the “Place order button for Riverty” can result in a page with errors.
- BP-4328 Fix: Updating the Buckaroo plugin results into a internal server error.

# 2.8.2

- BP-4399 Fix: Riverty transaction key sometimes missing via Shopware API.
- BP-4259 Fix: Prevent expired push from overwriting successful payment status.
- BP-4399 Fix: "Can't ship to this location" error on some PayPal Express attempts.

# 2.9.0 

- BP-4387 When "Cards" (Hosted Fields) is selected other payment methods are not selectable in the dropdown.
- BP-4316 Add support for Shopware 6.6.10.x.
- BP-4484 Billink V2 Shopware support.
- BP-4423 Fix: In3, null check bypassed due to early initialization with empty string.

# 2.9.1

- BP-4426-Refactor-Shopware-6-Frontend
- BP-4495 (Migration Fix)

# 3.0.0

- BP-4373 Add compatibility with Shopware version 6.7.2.2.
- BP-4542 Rename “iDEAL In3” back to “In3”.
- BP-5011 Enable Plaza captures to correctly reflect back in Shopware.
- BP-5006 Improve Buckaroo Hosted Fields.
- BP-4509 Improve transaction processing time.
- BP-4574 Fix issue where input fields were missing for certain payment methods in SW 6.7.
- BP-4584 Fix missing consumer financial warning for BNPL methods in SW 6.7.
- BP-4586 Fix order placement failure for Riverty with authorize/capture flow in SW 6.7.
- BP-4588 Remove unwanted system text for payment methods without input fields.
- BP-4594 Fix In3 TypeError and add fallback for additionalAddressLine2 (#317).
- BP-5016 Fix error when In3 transactions include a configured payment fee.
- BP-5015 Fix issue where payment fees were not always included in total price.
- BP-5014 Fix “CHECKOUT__PAYMENT_TOKEN_INVALIDATED” error after PayPerEmail completion.
- BP-5009 Fix no checkout error is displayed for failed orders.
- BP-5007 Fix issue where COC number and company name were incorrectly displayed on private In3 orders.
- BP-5005 Fix issue where payment push failed due to missing or invalid URL configuration.
- BP-4615 Resolve an display issue with credit card logo’s.

# 3.1.0

- BP-5093 Add support for Shopware 6.7.4.0
- BP-5071 Add support for Shopware 6.7.3.1
- BP-4601 Add payment method: Twint
- BP-5001 Add payment method: Bizum
- BP-5000 Add payment method: Swish
- BP-5117 Enable capture for Riverty
- BP-4526 Send customer e-mail address for intersolve gift card transactions for refund purposes when losing the physical giftcard
- BP-5017 Resolve: issue with Riverty order placement with Shopware 6.7.3.1
- BP-5073 Resolve: Apple Pay layout issue on the confirmation page
- BP-5002 Resolve: rounding issue when getting Shopware product data for BNPL methods
- BP-5078 Resolve: issue with parsed TAX amount to Riverty (new API)
- BP-5082 Resolve: Payment fee percentage is displayed as fixed fee in the checkout.
- BP-5084 Resolve: Currency symbol mismatch between checkout total and payment method display (SEK, CHF)
- BP-5044 Resolve: Migration failure in Buckaroo Plugin due to the removal of Sofort.