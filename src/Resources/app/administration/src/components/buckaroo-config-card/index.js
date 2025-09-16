import template from './buckaroo-config-card.html.twig';

const { Component } = Shopware;

Component.register('buckaroo-config-card', {
    template,

    mounted() {
        this.$nextTick(() => {
            this.$forceUpdate();
        });
    },
    watch: {
        value: {
            handler() {
                this.$nextTick(() => {
                    this.$forceUpdate();
                });
            },
            deep: true,
            immediate: true
        },
        
        currentSalesChannelId: {
            handler(newChannelId, oldChannelId) {
                if (newChannelId !== oldChannelId) {
                    
                    this.$nextTick(() => {
                        this.$forceUpdate();
                    });
                }
            },
            immediate: false
        }
    },
    computed: {
        canShowCredentialTester() {
            const key = this.getValueForName('websiteKey');
            const secretKey = this.getValueForName('secretKey');
            const isGeneralConfig = this.card?.name === 'general';
            
            if (!isGeneralConfig) {
                return false;
            }

            const hasWebsiteKey = key !== undefined && key !== null && key !== '';
            const hasSecretKey = secretKey !== undefined && secretKey !== null && secretKey !== '';
            const canShow = hasWebsiteKey || hasSecretKey;
            return canShow;
        },
        
        hasValidConfigData() {
            return this.value && typeof this.value === 'object' && Object.keys(this.value).length > 0;
        },
        
       reactiveValue() {
            return this.value;
        }
    },

    emits: ['input'],

    model: {
        prop: 'value',
        event: 'input'
    },

    props: {
        card: {
            type: Object,
            required: false,
            default: () => ({ elements: [] })
        },
        methods: {
            type: Object,
            required: true,
        },
        isNotDefaultSalesChannel: {
            type: Boolean,
            required: true,
        },
        currentSalesChannelId: {
            type: String,
            required: true,
        },
        value: {
            type: Object,
            required: false,
            default: () => ({})
        },
    },

    methods: {
        getElementBind(element, props = {}) {
            if (!this.methods || !this.methods.getElementBind) {
                return {
                    name: element.name,
                    type: element.type || 'text',
                    config: element.config || {},
                    value: this.getValueForName(element.name.replace('BuckarooPayments.config.', ''))
                };
            }
            
            const baseBinding = this.methods.getElementBind(element, props);
            
            const fieldName = element.name.replace('BuckarooPayments.config.', '');
            const currentValue = this.getValueForName(fieldName);
            
            baseBinding.value = currentValue;

            
            return baseBinding;
        },

        getInheritWrapperBind(element) {
            if (!this.methods || !this.methods.getInheritWrapperBind) {
                const fieldName = element.name.replace('BuckarooPayments.config.', '');
                return {
                    name: element.name,
                    currentValue: this.getValueForName(fieldName)
                };
            }
            
            const baseBinding = this.methods.getInheritWrapperBind(element);
            const fieldName = element.name.replace('BuckarooPayments.config.', '');
            const currentValue = this.getValueForName(fieldName);

            baseBinding.currentValue = currentValue;

            
            return baseBinding;
        },

        getFieldError(name) {
            if (!this.methods || !this.methods.getFieldError) {
                return null;
            }
            return this.methods.getFieldError(name);
        },

        kebabCase(string) {
            if (!this.methods || !this.methods.kebabCase) {
                return string ? string.toLowerCase().replace(/[A-Z]/g, '-$&').replace(/^-/, '') : '';
            }
            return this.methods.kebabCase(string);
        },

        getInlineSnippet(title) {
            try {
                if (typeof title === 'object' && title !== null) {
                    const locale = this.$i18n?.locale || 'en-GB';
                    
                    if (title[locale]) {
                        return title[locale];
                    }
                    
                    if (title['en-GB']) {
                        return title['en-GB'];
                    }
                    const firstKey = Object.keys(title)[0];
                    if (firstKey && title[firstKey]) {
                        return title[firstKey];
                    }
                    
                    return JSON.stringify(title);
                }
                
                if (typeof title === 'string') {
                    if (this.$t && typeof this.$t === 'function') {
                        return this.$t(title);
                    }
                    return title;
                }
                return String(title);
                
            } catch (error) {
                console.warn('Translation error for:', title, error);
                return typeof title === 'object' ? JSON.stringify(title) : String(title);
            }
        },

        getInheritedValue(element) {
            if (!this.methods || !this.methods.getInheritedValue) {
                return null;
            }
            return this.methods.getInheritedValue(element);
        },

        getValueForName(name) {
            const currentValue = this.reactiveValue;
            
            if (!currentValue || typeof currentValue !== 'object') {
                return null;
            }

            let val = undefined;

            const keyVariations = [
                `BuckarooPayments.config.${name}`, // Full prefixed key
                name.toLowerCase(),
                name.charAt(0).toLowerCase() + name.slice(1),
                name.charAt(0).toUpperCase() + name.slice(1)
            ];

            for (const key of keyVariations) {
                if (currentValue[key] !== undefined) {
                    val = currentValue[key];
                    break;
                }
            }

            if (val === undefined && currentValue['BuckarooPayments.config'] && typeof currentValue['BuckarooPayments.config'] === 'object') {
                for (const key of keyVariations) {
                    if (currentValue['BuckarooPayments.config'][key] !== undefined) {
                        val = currentValue['BuckarooPayments.config'][key];
                        break;
                    }
                }
            }

            if (val && typeof val === 'object' && val.hasOwnProperty('_value')) {
                val = val._value;
            }
            return val;
        },

        canShow(element) {
            if (!element || !element.name) {
                return false;
            }
            
            const name = element.name.replace('BuckarooPayments.config.', '');

            const advancedToggleFields = [
                'orderStatus',
                'paymentSuccesStatus',
                'automaticallyCloseOpenOrders',
                'sendInvoiceEmail'
            ];
            if (advancedToggleFields.includes(name)) {
                const advancedConfig = this.getValueForName('advancedConfiguration');
                return Boolean(advancedConfig);
            }

            if (name === 'idealprocessingRenderMode') {
                return Boolean(this.getValueForName('idealprocessingShowissuers'));
            }

            if (name === 'idealRenderMode') {
                return Boolean(this.getValueForName('idealShowissuers'));
            }

            const idealFastCheckoutFields = [
                'idealFastCheckoutEnabled',
                'idealFastCheckoutVisibility',
                'idealFastCheckoutLogoScheme'
            ];
            if (idealFastCheckoutFields.includes(name)) {
                return Boolean(this.getValueForName('idealFastCheckout'));
            }

            if (name === 'afterpayPaymentstatus') {
                return Boolean(this.getValueForName('afterpayCaptureonshippent'));
            }

            if (name === 'afterpayOldtax') {
                return Boolean(this.getValueForName('afterpayEnabledold'));
            }

            return true;
        },

        onInput(value) {
            this.$emit('input', value);
        },
        onFieldInput(fieldName, eventOrValue) {

            try {
                let actualValue = eventOrValue;
                
                if (eventOrValue && typeof eventOrValue === 'object') {
                    if (eventOrValue.target) {
                        const target = eventOrValue.target;

                        if (target.type === 'checkbox' || target.type === 'radio') {
                            actualValue = target.checked;
                        } else if (target.tagName === 'SELECT' || target.type === 'select-one' || target.type === 'select-multiple') {
                            if (target.multiple) {
                                actualValue = Array.from(target.selectedOptions).map(option => option.value);
                            } else {
                                actualValue = target.value;
                            }
                        } else {
                            actualValue = target.value;
                        }
                    } else if (eventOrValue.hasOwnProperty('value')) {
                        actualValue = eventOrValue.value;
                    } else if (eventOrValue.hasOwnProperty('id') && eventOrValue.hasOwnProperty('name')) {
                        actualValue = eventOrValue.id;
                    } else if (Array.isArray(eventOrValue)) {
                        const totalCharacters = eventOrValue.filter(item => typeof item === 'string' && item.length === 1).length;
                        const hasCommas = eventOrValue.some(item => item === ',');
                        const hasLongStrings = eventOrValue.some(item => typeof item === 'string' && item.length > 1);

                        const isCharacterArray = totalCharacters > 10 && hasCommas;

                        
                        if (isCharacterArray) {
                            const correctValues = eventOrValue.filter(item => typeof item === 'string' && item.length > 1);
                            const characterPart = eventOrValue.filter(item => typeof item === 'string' && item.length === 1);
                            const rejoined = characterPart.join('');
                            
                            let splitValues = [];
                            if (rejoined.includes(',')) {
                                splitValues = rejoined.split(',').map(item => item.trim()).filter(item => item.length > 0);
                            } else if (rejoined.length > 0) {
                                splitValues = [rejoined];
                            }

                            actualValue = [...splitValues, ...correctValues].filter(item => item && item.length > 0);
                        } else {
                            actualValue = eventOrValue
                                .filter(item => {
                                    if (item === null || item === undefined || item === '') {
                                        return false;
                                    }

                                    if (typeof item === 'string' && item.length === 1) {
                                        return false;
                                    }

                                    if (typeof item === 'string' && (item.startsWith('+') || /^\d+$/.test(item))) {

                                        return false;
                                    }
                                    
                                    return true;
                                })
                                .map(item => {
                                    if (typeof item === 'object' && item !== null) {
                                        let extractedValue = item.id || item.value || item.code || item.key || item;
                                        return extractedValue;
                                    }
                                    return item;
                                });
                        }

                    } else {
                        const possibleKeys = ['id', 'value', 'key', 'code'];
                        for (const key of possibleKeys) {
                            if (eventOrValue[key] !== undefined) {
                                actualValue = eventOrValue[key];
                                break;
                            }
                        }
                    }
                } else if (typeof eventOrValue === 'boolean') {
                    actualValue = eventOrValue;
                } else if (typeof eventOrValue === 'string' || typeof eventOrValue === 'number') {
                    actualValue = eventOrValue;
                }

                if (actualValue === "on") {
                    actualValue = true;
                } else if (actualValue === "off") {
                    actualValue = false;
                }
                const cleanFieldName = fieldName.replace('BuckarooPayments.config.', '');
                const updatedValue = { ...this.value };

                updatedValue[cleanFieldName] = actualValue;
                updatedValue[fieldName] = actualValue;

                this.$emit('input', updatedValue);
                
            } catch (error) {
                console.error('Error in onFieldInput:', error);
                console.error('Error details:', error.stack);
            }
        }
    }
});
