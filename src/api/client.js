import apiFetch from '@wordpress/api-fetch';

const NS = 'modern-mailer/v1';

/**
 * One place that talks to the REST API.
 *
 * Every call goes through here so the nonce, the namespace and error handling
 * are decided once. WordPress's apiFetch rejects with the decoded body rather
 * than an Error, which reads as an empty message if you let it through - so
 * failures are normalised into something with a `message` a component can show.
 */
const request = async ( path, options = {} ) => {
	try {
		return await apiFetch( { path: `/${ NS }${ path }`, ...options } );
	} catch ( error ) {
		throw {
			message:
				error?.message ||
				'The request failed. Check that you are still signed in.',
			code: error?.code || 'unknown',
		};
	}
};

export const getBootstrap = () => request( '/bootstrap' );
export const getSettings = () => request( '/settings' );
export const getDashboard = () => request( '/dashboard' );

export const getQueue = () => request( '/queue' );
export const getConnection = ( slot ) => request( `/connections/${ slot }` );

export const saveSettings = ( data ) =>
	request( '/settings', { method: 'POST', data } );

export const saveConnection = ( slot, data ) =>
	request( `/connections/${ slot }`, { method: 'POST', data } );

export const verifyConnection = ( slot ) =>
	request( `/connections/${ slot }/verify`, { method: 'POST' } );

/** Clear a connection: its provider, credentials and any grant it holds. */
export const disconnectConnection = ( slot ) =>
	request( `/connections/${ slot }/disconnect`, { method: 'POST' } );

export const sendTestEmail = ( to ) =>
	request( '/test-email', { method: 'POST', data: { to } } );

export const queueAction = ( action ) =>
	request( `/queue/${ action }`, { method: 'POST' } );

/**
 * Wizard bookkeeping.
 *
 * The step is recorded server-side rather than kept in the URL, because
 * connecting a mailbox hands the browser to Google or Microsoft and gets it
 * back as a fresh page load - and the wizard has to resume where it was rather
 * than at the beginning.
 *
 * There is no getter here. The state is small and the shell needs it on the
 * first paint anyway, so it rides along on /bootstrap rather than costing a
 * second request.
 */
const setupAction = ( action, extra = {} ) =>
	request( '/setup', { method: 'POST', data: { action, ...extra } } );

export const setSetupStep = ( step ) => setupAction( 'step', { step } );
export const completeSetup = () => setupAction( 'complete' );
export const skipSetup = () => setupAction( 'skip' );
