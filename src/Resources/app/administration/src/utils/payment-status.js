/**
 * Reads payment method settings from the system config value object and
 * resolves the status the storefront will actually use.
 */

// Methods without their own environment fall back to another method's (mirrors SettingsService::SHARED_ENVIRONMENT_METHODS)
export const SHARED_ENVIRONMENT_METHODS = {
    idealqr: 'ideal',
    klarnain: 'klarna',
};

export function getConfigValue(value, name) {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const key = `BuckarooPayments.config.${name}`;
    let val;

    if (value[key] !== undefined) {
        val = value[key];
    } else if (value[name] !== undefined) {
        val = value[name];
    } else if (value['BuckarooPayments.config'] && typeof value['BuckarooPayments.config'] === 'object') {
        val = value['BuckarooPayments.config'][name];
    } else {
        const variations = [
            name.toLowerCase(),
            name.charAt(0).toLowerCase() + name.slice(1),
            name.charAt(0).toUpperCase() + name.slice(1),
        ];

        for (const variation of variations) {
            if (value[`BuckarooPayments.config.${variation}`] !== undefined) {
                val = value[`BuckarooPayments.config.${variation}`];
                break;
            }
            if (value[variation] !== undefined) {
                val = value[variation];
                break;
            }
        }
    }

    if (val && typeof val === 'object' && Object.prototype.hasOwnProperty.call(val, '_value')) {
        val = val._value;
    }

    return val;
}

export function isPaymentEnabled(value, method) {
    const enabled = getConfigValue(value, `${method}Enabled`);

    if (typeof enabled === 'string') {
        return enabled.toLowerCase() === 'true' || enabled === '1';
    }

    return Boolean(enabled);
}

function getNormalizedEnvironment(value, method) {
    const environment = getConfigValue(value, `${method}Environment`);

    return typeof environment === 'string' ? environment.trim().toLowerCase() : '';
}

/**
 * Same rules as SettingsService::isTestEnvironment(): only an explicit "test" is test mode.
 *
 * @returns {'live'|'test'}
 */
export function getPaymentMode(value, method) {
    let configMethod = method;

    if (SHARED_ENVIRONMENT_METHODS[method]) {
        if (getNormalizedEnvironment(value, method) === 'live') {
            return 'live';
        }
        configMethod = SHARED_ENVIRONMENT_METHODS[method];
    }

    return getNormalizedEnvironment(value, configMethod) === 'test' ? 'test' : 'live';
}
