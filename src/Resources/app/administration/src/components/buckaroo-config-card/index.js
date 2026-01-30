import template from './buckaroo-config-card.html.twig';

const { Component } = Shopware;

Component.register('buckaroo-config-card', {
    template,

    inject: ['BuckarooPaymentSettingsService'],

    data() {
        return {
            shopwareVersion: null
        };
    },

    mounted() {
        this.fetchShopwareVersion();
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
        configSettings: {
            type: Array,
            required: false,
            default: () => []
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
        fetchShopwareVersion() {
            const service = this.BuckarooPaymentSettingsService;
            if (service && typeof service.getSupportVersion === 'function') {
                service.getSupportVersion().then((data) => {
                    if (data && data.shopware_version) {
                        this.shopwareVersion = data.shopware_version;
                    }
                }).catch(() => {});
            }
        },

        /**
         * Returns true if Shopware version is >= 6.7.4.0 (label fix applies; older versions show duplicate labels if we use enhanced label logic).
         * When version is unknown, returns false to avoid duplicate labels on older Shopware.
         */
        isShopware674OrNewer() {
            if (!this.shopwareVersion || typeof this.shopwareVersion !== 'string') {
                return false;
            }
            const parts = this.shopwareVersion.split('.').map((n) => parseInt(n, 10) || 0);
            const major = parts[0] || 0;
            const minor = parts[1] || 0;
            const patch = parts[2] || 0;
            const build = parts[3] || 0;
            if (major > 6) return true;
            if (major < 6) return false;
            if (minor > 7) return true;
            if (minor < 7) return false;
            if (patch > 4) return true;
            if (patch < 4) return false;
            return build >= 0;
        },

        getElementBind(element, props = {}) {
            if (!this.methods || !this.methods.getElementBind) {
                const label = element.label ? this.getInlineSnippet(element.label) : null;
                return {
                    name: element.name,
                    type: element.type || 'text',
                    config: element.config || {},
                    label: label,
                    value: this.getValueForName(element.name.replace('BuckarooPayments.config.', ''))
                };
            }
            
            const baseBinding = this.methods.getElementBind(element, props);
            
            const fieldName = element.name.replace('BuckarooPayments.config.', '');
            let currentValue = this.getValueForName(fieldName);
            
            // Ensure config object exists
            const config = baseBinding.config || element.config || {};
            
            // For bool fields, ensure we have a proper boolean value
            if (element.type === 'bool') {
                if (currentValue === null || currentValue === undefined) {
                    currentValue = baseBinding.value !== undefined ? baseBinding.value : false;
                } else {
                    // Ensure it's a proper boolean - handle string values like "0", "1", "false", "true"
                    if (typeof currentValue === 'string') {
                        currentValue = currentValue === '1' || currentValue === 'true' || currentValue === 'on';
                    } else {
                        currentValue = Boolean(currentValue);
                    }
                }
            }
            
            // Extract label only for Shopware >= 6.7.4.0; in older versions baseBinding/template already show the label and our enhanced logic causes duplicate labels
            let finalLabel = null;
            const useEnhancedLabels = this.isShopware674OrNewer();

            if (useEnhancedLabels) {
                // For bool/select fields, prioritize configSettings since baseBinding.label is often undefined in SW 6.7.4+
                if ((element.type === 'bool' || element.type === 'single-select' || element.type === 'multi-select') && this.configSettings && Array.isArray(this.configSettings)) {
                    for (const configCard of this.configSettings) {
                        if (configCard.elements && Array.isArray(configCard.elements)) {
                            const configElement = configCard.elements.find(el => el.name === element.name);
                            if (configElement) {
                                if (configElement.label) {
                                    let extractedLabel = this.getInlineSnippet(configElement.label);
                                    if (!extractedLabel || (typeof extractedLabel === 'string' && extractedLabel.trim().length === 0)) {
                                        if (typeof configElement.label === 'object' && configElement.label !== null) {
                                            const locale = this.$i18n?.locale || 'en-GB';
                                            extractedLabel = configElement.label[locale] || configElement.label['en-GB'] || Object.values(configElement.label)[0] || null;
                                        } else if (typeof configElement.label === 'string') {
                                            extractedLabel = configElement.label;
                                        }
                                    }
                                    if (extractedLabel && typeof extractedLabel === 'string' && extractedLabel.trim().length > 0) {
                                        finalLabel = extractedLabel;
                                        break;
                                    }
                                }
                                if (!finalLabel && configElement.config && configElement.config.label) {
                                    let extractedLabel = this.getInlineSnippet(configElement.config.label);
                                    if (!extractedLabel || (typeof extractedLabel === 'string' && extractedLabel.trim().length === 0)) {
                                        if (typeof configElement.config.label === 'object' && configElement.config.label !== null) {
                                            const locale = this.$i18n?.locale || 'en-GB';
                                            extractedLabel = configElement.config.label[locale] || configElement.config.label['en-GB'] || Object.values(configElement.config.label)[0] || null;
                                        } else if (typeof configElement.config.label === 'string') {
                                            extractedLabel = configElement.config.label;
                                        }
                                    }
                                    if (extractedLabel && typeof extractedLabel === 'string' && extractedLabel.trim().length > 0) {
                                        finalLabel = extractedLabel;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }

                if (!finalLabel) {
                    if (baseBinding.label && typeof baseBinding.label === 'string' && baseBinding.label.trim().length > 0) {
                        finalLabel = baseBinding.label;
                    } else if (element.label) {
                        let extractedLabel = this.getInlineSnippet(element.label);
                        if (!extractedLabel || (typeof extractedLabel === 'string' && extractedLabel.trim().length === 0)) {
                            if (typeof element.label === 'object' && element.label !== null) {
                                const locale = this.$i18n?.locale || 'en-GB';
                                extractedLabel = element.label[locale] || element.label['en-GB'] || Object.values(element.label)[0] || null;
                            } else if (typeof element.label === 'string') {
                                extractedLabel = element.label;
                            }
                        }
                        if (extractedLabel && typeof extractedLabel === 'string' && extractedLabel.trim().length > 0) {
                            finalLabel = extractedLabel;
                        }
                    } else if (this.card && this.card.elements && Array.isArray(this.card.elements)) {
                        const rawElement = this.card.elements.find(el => el.name === element.name);
                        if (rawElement && rawElement.label) {
                            let extractedLabel = this.getInlineSnippet(rawElement.label);
                            if (!extractedLabel || (typeof extractedLabel === 'string' && extractedLabel.trim().length === 0)) {
                                if (typeof rawElement.label === 'object' && rawElement.label !== null) {
                                    const locale = this.$i18n?.locale || 'en-GB';
                                    extractedLabel = rawElement.label[locale] || rawElement.label['en-GB'] || Object.values(rawElement.label)[0] || null;
                                } else if (typeof rawElement.label === 'string') {
                                    extractedLabel = rawElement.label;
                                }
                            }
                            if (extractedLabel && typeof extractedLabel === 'string' && extractedLabel.trim().length > 0) {
                                finalLabel = extractedLabel;
                            }
                        }
                    }
                }
                if (!finalLabel && baseBinding.config && baseBinding.config.label && typeof baseBinding.config.label === 'string' && baseBinding.config.label.trim().length > 0) {
                    finalLabel = baseBinding.config.label;
                }
            }

            // Only add label to config for SW >= 6.7.4.0 (avoids duplicate labels in older versions)
            let finalConfig = config;
            if (useEnhancedLabels && finalLabel && typeof finalLabel === 'string' && finalLabel.trim().length > 0) {
                if (element.type === 'bool' || element.type === 'single-select' || element.type === 'multi-select') {
                    finalConfig = {
                        ...config,
                        label: finalLabel
                    };
                }
            }

            const binding = {
                ...baseBinding,
                config: finalConfig
            };

            // Ensure specific fields are treated as real multi-selects in the UI
            const forcedMultiSelectFields = [
                'allowedcreditcard',
                'allowedcreditcards',
                'allowedgiftcards',
                'giftcardsPaymentmethods',
                'payperemailAllowed'
            ];

            if (forcedMultiSelectFields.includes(fieldName)) {
                binding.type = 'multi-select';
                // Force Shopware to render the correct component
                binding.componentName = 'sw-multi-select';

                binding.config = {
                    ...(binding.config || {}),
                    multiple: true,
                    // Prefer options coming from binding.config; fall back to element/config when needed
                    options: (binding.config && binding.config.options)
                        || (config && config.options)
                        || element.options
                        || []
                };

                // Debug logging to inspect how the multi-select is bound
                const sampleOption = Array.isArray(binding.config?.options) && binding.config.options.length > 0
                    ? binding.config.options[0]
                    : null;
                console.debug('[BuckarooConfigCard] getElementBind multi-select binding', {
                    fieldName,
                    bindingType: binding.type,
                    componentName: binding.componentName,
                    optionsCount: Array.isArray(binding.config?.options) ? binding.config.options.length : 0,
                    currentValue,
                    sampleOption
                });
            }
            
            // Only set binding.label for SW >= 6.7.4.0; older versions already show the label and would show duplicates
            if (useEnhancedLabels && finalLabel && typeof finalLabel === 'string' && finalLabel.trim().length > 0) {
                if (element.type === 'bool') {
                    binding.label = finalLabel;
                } else if (!binding.label || (typeof binding.label === 'string' && binding.label.trim().length === 0)) {
                    binding.label = finalLabel;
                }
            }

            if (element.type === 'bool') {
                binding.value = currentValue;
                // Only set fallback label for SW >= 6.7.4.0 to avoid duplicate labels in older versions
                if (useEnhancedLabels && (!binding.label || (typeof binding.label === 'string' && binding.label.trim().length === 0))) {
                    binding.label = binding.config?.label || element.name.replace('BuckarooPayments.config.', '').replace(/([A-Z])/g, ' $1').trim();
                }
            }
            
            // Debug: Log if label is missing for bool/select fields
            if ((element.type === 'bool' || element.type === 'single-select' || element.type === 'multi-select') && (!binding.label || (typeof binding.label === 'string' && binding.label.trim().length === 0))) {
                console.warn('Missing label for field:', element.name, 'Type:', element.type, 'Element label:', element.label, 'Extracted:', finalLabel, 'BaseBinding label:', baseBinding.label, 'Final binding label:', binding.label);
            }
            
            return binding;
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

                // Determine the element definition once so we can branch on its type
                const element = this.card?.elements?.find(
                    el => el.name === fieldName
                        || el.name.replace('BuckarooPayments.config.', '') === fieldName.replace('BuckarooPayments.config.', '')
                );

                if (actualValue === "on") {
                    actualValue = true;
                } else if (actualValue === "off") {
                    actualValue = false;
                }
                
                // For bool fields, ensure we always have a proper boolean
                if (element && element.type === 'bool') {
                    if (typeof actualValue === 'string') {
                        actualValue = actualValue === '1' || actualValue === 'true' || actualValue === 'on';
                    } else {
                        actualValue = Boolean(actualValue);
                    }
                }

                // For multi-select fields, ALWAYS store an array of selected values.
                // This ensures components like sw-multi-select keep multiple selections
                // instead of degrading to a single selected option.
                if (element && element.type === 'multi-select') {
                    console.debug('[BuckarooConfigCard] onFieldInput before normalize (multi-select)', {
                        fieldName,
                        rawEvent: eventOrValue,
                        rawValue: actualValue
                    });

                    if (Array.isArray(actualValue)) {
                        // Normalize array items to primitive ids / values
                        actualValue = actualValue
                            .filter(item => item !== null && item !== undefined && item !== '')
                            .map(item => {
                                if (typeof item === 'object' && item !== null) {
                                    return item.id || item.value || item.code || item.key || item;
                                }
                                return item;
                            });
                    } else if (typeof actualValue === 'string') {
                        // Support comma-separated string values (just in case)
                        actualValue = actualValue
                            .split(',')
                            .map(v => v.trim())
                            .filter(v => v.length > 0);
                    } else if (actualValue === null || actualValue === undefined) {
                        actualValue = [];
                    } else {
                        // Fallback: wrap single primitive value into an array
                        actualValue = [actualValue];
                    }

                    console.debug('[BuckarooConfigCard] onFieldInput after normalize (multi-select)', {
                        fieldName,
                        normalizedValue: actualValue
                    });
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
