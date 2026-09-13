import { getRequestToken } from '@nextcloud/auth';

/**
 * Return the HTTP status and JSON body. Network failures reject the promise.
 *
 * @param {string} url endpoint to call
 * @param {object} [options] request options
 * @param {string} [options.method] HTTP method
 * @param {URLSearchParams|FormData|null} [options.body] request body
 * @return {Promise<{ok: boolean, payload: object}>}
 */
export async function request(url, options = {}) {
	const { method = 'GET', body = null } = options;
	const response = await fetch(url, {
		method,
		headers: { requesttoken: getRequestToken() ?? '' },
		body,
	});

	let payload = {};
	try {
		payload = await response.json();
	} catch (error) {
		payload = {};
	}

	return { ok: response.ok, payload };
}

/**
 * Send a POST request to a connector endpoint.
 *
 * @param {string} url endpoint to call
 * @param {URLSearchParams|FormData} [body] request body
 * @return {Promise<{ok: boolean, payload: object}>}
 */
export function post(url, body) {
	return request(url, { method: 'POST', body: body ?? new URLSearchParams() });
}
