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
BP-379 Compatibility with 6.3
BP-309 Added payment fee (fixed amount) to all payment methods
BP-201 Added payment method WeChatPay
BP-204 Added payment method Trustly

# 1.1.1
BP-206 Add payment method KlarnaKP

# 1.1.2
BP-584 Multiple issues after code review
BP-583 Add new bank to iDEAL payment method

# 1.1.3
BP-643 Add payment method Billink

# 1.1.4
BP-683 Add Klarna 'Pay' and 'PayInInstallments'

# 1.2.0
BP-765 Update to Shopware 6.4 and test

# 1.2.1
BP-817 Add payment method Belfius
BP-906 Translation issue
in3 / fix country for guest mode
Modified error log in exception handler. The injected logger does not
Make it possible for Billink to enable bothe methods (B2B+B2C)
BP-946 Add giftcard Huis & Tuin Cadeau (giftcard)
BP-941 All plugins - PayPal (V2) cancellation returns to the homepage
BP-945 Add payment method Billink Authorize/Capture
BP-957 Make 'VAT number' field non-required (BILLINK)
BP-970 Add CreditCard brand PostePay

# 1.2.2
#5 Edit order - impossible
BP-985 Fix warnings

# 1.2.3
#6 Fix for partial payment issue with cancelled parts
#8 Version in composer.json is not updated
Fix composer stable
#6 Fix getOrderTransactionStatesNameFromAction function
#9 Fix for partial payment pushes issue
BP-996 Add accept terms & conditions for Billink
BP-1017 Choosing Postepay card uses VISA instead
FIX AfterPay. Country does not seem to be filled and therefor 
BP-1020 Afterpay terms and condition with a address in Belgium is showing the terms for NL.
#12 Update AsyncPaymentHandler.php
BP-452 Fix KBS title
BP-1052 Afterpay refund from Shopware backend is showing a error.
BP-1064 Wrong language errormessage in shopware checkout
BP-1065 Use {% blocks %} to override
#15 Incorrect signature on partial payments
BP-1020 Fix text
#16 Partial payments - order gets incorrectly cancelled
BP-1088 Billink logo update

# 1.2.4
BP-1146 Company name not filled at Billink
Update README.md
BP-1163 remove case sensitive logos 

# 1.3.0
BP-1189 New Sofort logo added
BP-1193 Shopware 6 CSE tooltip
BP-1172 Fix 'composer' warning
BP-1236 Shopware 6 - incorrect amount for partial refunds
BP-969 Add payment method PayPerEmail + Paylink (restore deleted config)
BP-1333 In3 invalid request when ordering as company
BP-1336 Unkown button in order buckaroo tab
BP-1331 Empty cart at browser go back from payment
BP-1340 User logout after payment cancel
BP-1335 Applepay disables pay button without any message
BP-1341 last partial refund doesn't update payment status to "Refunded"
BP-1332 No validation message for payment fields in checkout
BP-1330 TotalPrice not changeable
BP-1339 invalid payments from admin order

# 1.3.1
BP-1432 Plugin crashes for shopware 6.4.9.0
BP-1433 Uncaught PHP Exception TypeError: "round(): Argument #1 ($num) must be of type int|float, string given"
BP-1434 Fixing typeError inside _registerCheckoutSubmitButton (Thanks to @nielsVoogt)

# 1.3.2
BP-1440 Add ideal issuers selectbox

# 1.3.3
BP-1815 Add support for Shopware 6 version 6.4.13.0
BP-1675 Add support for multiple sales channels (multi-store)
BP-1726 iDEAL status message is not clear for consumers
BP-1766 Add transaction label and refund label
BP-1435 Change service version of Transfer payment method
BP-1441 Option in plugin config to set SendMail and DueDate parameters for Transfer
BP-1508 Update README file
[#57] Fix | Use sales channel URL instead of base url (Thank you @MelvinAchterhuis !)
BP-1462 Apple Pay shows a error in the checkout "errorOccurred"  