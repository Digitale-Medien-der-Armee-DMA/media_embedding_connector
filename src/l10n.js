import { t as translate } from '@nextcloud/l10n';

const APP_ID = 'media_embedding_connector';

/**
 * Translate a string for this app.
 *
 * @param {string} text source string
 * @param {object} [placeholders] named placeholders
 * @return {string}
 */
export function t(text, placeholders) {
	return translate(APP_ID, text, placeholders);
}

export { APP_ID };
