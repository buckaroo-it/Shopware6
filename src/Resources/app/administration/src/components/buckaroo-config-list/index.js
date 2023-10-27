const { Component } = Shopware;
import template from "./buckaroo-config-list.html.twig";

Component.extend("buckaroo-config-list", "sw-system-config", {
    template,
    data() {
        return {
            alwayShow: {
                'websiteKey': true,
                'secretKey': true,
                'guid': true,
                'transactionLabel': true,
                'refundLabel': true,
                'creditcardEnabled': true,
                'creditcardsEnabled': true,
                'idealEnabled': true,
                'idealqrEnabled': true,
                'idealprocessingEnabled': true,
                'belfiusEnabled': true,
                'bancontactmrcashEnabled': true,
                'afterpayEnabled': true,
                'BillinkEnabled': true,
                'sofortueberweisungEnabled': true,
                'paypalEnabled': true,
                'transferEnabled': true,
                'giropayEnabled': true,
                'KBCPaymentButtonEnabled': true,
                'sepadirectdebitEnabled': true,
                'payconiqEnabled': true,
                'applepayEnabled': true,
                'giftcardsEnabled': true,
                'capayableEnabled': true,
                'epsEnabled': true,
                'Przelewy24Enabled': true,
                'AlipayEnabled': true,
                'WeChatPayEnabled': true,
                'TrustlyEnabled': true,
                'klarnaEnabled': true,
                'klarnainEnabled': true,
                'klarnakpEnabled': true,
                'advancedConfiguration': true,
                'payperemailEnabled': true,
                'paybybankEnabled': true,
                'mbwayEnabled': true,
                'multibancoEnabled': true,
            },
            toggleAdvanced: {
                'sendInvoiceEmail': true,
                'paymentSuccesStatus': true,
                'orderStatus': true,
            }
        }
    },
    methods: {
        getConfigValue: function(name) {
            return this.actualConfigData[this.currentSalesChannelId]["BuckarooPayments.config."+name];
        },
        canShow: function(element) {

            const conditionalFields = {
                paylinkEnabled: [
                    {key:'payperemailEnabled', values: [true]}
                ],
                capayableLogo: [
                    {key:'capayableVersion',values: [undefined, 'v3']},
                    {key:'capayableEnabled',values: [true]}
                ],
                allowedcreditcard: [
                    {key:'creditcardEnabled', values: [true]}
                ],
                allowedcreditcards: [
                    {key:'creditcardsEnabled', values: [true]}
                ],
                allowedgiftcards: [
                    {key:'giftcardsEnabled', values: [true]}
                ],
                applepayShowProduct:[
                    {key:'applepayEnabled', values: [true]}
                ],
                applepayShowCart:[
                    {key:'applepayEnabled', values: [true]}
                ],
                idealRenderMode:[
                    {key:'idealEnabled', values: [true]}
                ],
                transferSendEmail:[
                    {key:'transferEnabled', values: [true]}
                ],
                transferDateDue:[
                    {key:'transferEnabled', values: [true]}
                ],
                afterpayCustomerType:[
                    {key:'afterpayEnabled', values: [true]}
                    ],
                afterpayB2bMinAmount:[
                    {key:'afterpayEnabled', values: [true]}
                    ],
                afterpayB2bMaxAmount:[
                    {key:'afterpayEnabled', values: [true]}
                ],
                BillinkCreateInvoiceAfterShipment: [
                    {key:'BillinkEnabled', values: [true]}
                ]
            }
            let configKey = element.name.replace("BuckarooPayments.config.", "");

            if (configKey in this.alwayShow) {
                return true;
            }

            if (configKey in this.toggleAdvanced) {
                return this.getConfigValue("advancedConfiguration") === true;
            }

            let id = configKey.split(/([A-Z][a-z]+)/).filter(function (e) {
                return e});
            id.pop();
            id = id.join("");

            
            if (Object.keys(conditionalFields).indexOf(configKey) !== -1) {
                return this.canShowConditionalField(conditionalFields[configKey]);
            }

            return this.getConfigValue(id + "Enabled") === true;
        },
        canShowConditionalField(conditionalFieldMapping) {

            const getConfig = function (name) {
                return this.getConfigValue(name);
            }.bind(this);
            if (!Array.isArray(conditionalFieldMapping)) {
                return false;
            }
            return conditionalFieldMapping
            .map(function(condition) {
                if (typeof condition.key === 'string' && Array.isArray(condition.values)) {
                    return condition.values.indexOf(getConfig(condition.key)) !== -1
                }
                return false;
            })
            .reduce(function(prev, curr) {
                return prev && curr;
            })

        },
    }
})