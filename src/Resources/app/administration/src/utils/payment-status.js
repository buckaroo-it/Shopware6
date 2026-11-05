/**
 * Reads payment method settings from the system config value object and
 * resolves the status the storefront will actually use.
 *
 * These rules repeat SettingsService (getEnabled, isTestEnvironment and
 * SHARED_ENVIRONMENT_METHODS). Keep both in sync: if they differ, the admin shows
 * another mode than the shop uses. AdminPaymentStatusSyncTest checks the shared list.
 */

// Methods without their own environment fall back to another method's (mirrors SettingsService::SHARED_ENVIRONMENT_METHODS)
export const SHARED_ENVIRONMENT_METHODS = {
    idealqr: 'ideal',
    klarnain: 'klarna',
};

const CONFIG_PREFIX = 'BuckarooPayments.config.';

const PHP_NUMERIC_STRING = /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/;

/**
 * Returns a copy of the settings value with the given fields set. The settings form keeps
 * both full ("BuckarooPayments.config.x") and short ("x") keys, so both are updated.
 * Field names may be given in either form.
 */
export function withConfigValues(value, fields) {
    const updated = { ...value };

    Object.entries(fields).forEach(([name, fieldValue]) => {
        const shortName = name.replace(CONFIG_PREFIX, '');
        updated[shortName] = fieldValue;
        updated[`${CONFIG_PREFIX}${shortName}`] = fieldValue;
    });

    return updated;
}

/**
 * Same rule as Shopware's SystemConfigLoader: a sales channel value only replaces the value of
 * "All Sales Channels" when it is a boolean or not empty in PHP terms. null, 0, "", "0", [] and {}
 * are ignored, so the sales channel uses the inherited value.
 */
export function overridesInheritedValue(ownValue) {
    if (typeof ownValue === 'boolean') {
        return true;
    }

    if (ownValue === null || ownValue === undefined || ownValue === 0 || ownValue === '' || ownValue === '0') {
        return false;
    }

    if (Array.isArray(ownValue)) {
        return ownValue.length > 0;
    }

    if (typeof ownValue === 'object') {
        return Object.keys(ownValue).length > 0;
    }

    return true;
}

/**
 * Settings as the storefront resolves them for a sales channel: its own values, falling back
 * to the "All Sales Channels" values for every setting it has no own value for.
 */
export function withInheritedValues(value, inheritedValue) {
    if (!inheritedValue || typeof inheritedValue !== 'object') {
        return value;
    }

    const resolved = { ...inheritedValue };

    Object.entries(value || {}).forEach(([name, ownValue]) => {
        if (overridesInheritedValue(ownValue)) {
            resolved[name] = ownValue;
        }
    });

    return resolved;
}

/**
 * Same rule as the storefront's CheckoutConfirmTemplateSubscriber::getSettingAsBool() for switches:
 * true, 1, "1" and "true" are on; anything else, including "0" and "false", is off.
 */
export function isSwitchValueOn(switchValue) {
    if (typeof switchValue === 'boolean') {
        return switchValue;
    }

    if (typeof switchValue === 'number') {
        return switchValue === 1;
    }

    if (typeof switchValue === 'string') {
        return switchValue === '1' || switchValue.toLowerCase() === 'true';
    }

    return false;
}

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

/**
 * Same rule as SettingsService::getEnabled(), which uses PHP 8's `$value != 0`: null, false and
 * numeric zero ("0", "0.0") are off; any other value, including a non-numeric string such as
 * "false", is on. Matching it shows the merchant the status the storefront actually uses.
 */
export function isPaymentEnabled(value, method) {
    const enabled = getConfigValue(value, `${method}Enabled`);

    if (enabled === null || enabled === undefined || typeof enabled === 'boolean') {
        return Boolean(enabled);
    }

    if (typeof enabled === 'number') {
        return enabled !== 0;
    }

    // PHP numeric string: optional whitespace, sign, decimals and exponent (no hex)
    if (typeof enabled === 'string') {
        return PHP_NUMERIC_STRING.test(enabled) ? Number(enabled) !== 0 : true;
    }

    return true;
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
