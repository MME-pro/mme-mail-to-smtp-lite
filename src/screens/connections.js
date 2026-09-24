import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Check, ShieldCheck, Send, AlertTriangle, Unplug } from 'lucide-react';
import {
	getConnection,
	saveConnection,
	verifyConnection,
	disconnectConnection,
	sendTestEmail,
} from '../api/client';
import { useToast } from '../components/toast';
import { Panel, Button, Badge, FormField, Spinner, inputClass } from '../components/ui';
import { cn } from '../lib/utils';
import GoogleConnect from '../components/google-connect';
import GoogleSetupGuide from '../components/google-setup-guide';
import RedirectUri from '../components/redirect-uri';
import MicrosoftConnect from '../components/microsoft-connect';
import OneClickConnect from '../components/one-click-connect';
import ProviderForm, { missingRequired } from '../components/provider-form';
import ProviderPicker from '../components/provider-picker';

/**
 * What the connection is for, said once on the screen.
 */
const describeSlot = () =>
	__(
		'Every message WordPress sends goes out over this connection.',
		'modern-mailer-oauth'
	);

const ConnectionPanel = ( { slot, categories, title } ) => {
	const toast = useToast();
	const queryClient = useQueryClient();
	const [ provider, setProvider ] = useState( '' );
	const [ values, setValues ] = useState( {} );
	const [ verifyResult, setVerifyResult ] = useState( null );
	const [ dirty, setDirty ] = useState( false );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'connection', slot ],
		queryFn: () => getConnection( slot ),
	} );

	useEffect( () => {
		if ( ! data ) {
			return;
		}

		setProvider( data.provider );

		const chosen = data.providers.find( ( p ) => p.slug === data.provider );
		const seed = {};

		// Secrets included. The server sends the stored value now, so the field
		// can show it masked and reveal it - which is the only way to check that
		// what was pasted is what was saved.
		( chosen?.fields || [] ).forEach( ( field ) => {
			seed[ field.key ] = field.value ?? '';
		} );

		setValues( seed );
		setDirty( false );
	}, [ data ] );

	const current = data?.providers.find( ( p ) => p.slug === provider );

	// A setup mode as it stands in the form right now: an unsaved edit if there
	// is one, otherwise whatever the provider declared. Read from the declared
	// field rather than from a constant default, so the fallback follows the
	// schema instead of duplicating it.
	const modeOf = ( key ) =>
		values[ key ] ??
		current?.fields?.find( ( f ) => f.key === key )?.value ??
		'own_client';

	const googleMode = modeOf( 'google_setup_mode' );
	const microsoftMode = modeOf( 'ms_setup_mode' );

	// Which sign-in block belongs under this provider. Both merged tiles and
	// the legacy slugs are handled, because a connection keeps its stored slug
	// until the migration runs and must stay editable in the meantime.
	const isGoogle = provider === 'google' || provider === 'gmail_oauth';
	const isMicrosoft = provider === 'microsoft' || provider === 'outlook';

	const save = useMutation( {
		mutationFn: () => saveConnection( slot, { provider, ...values } ),
		onSuccess: () => {
			// Secrets are no longer cleared here. They used to be, because what
			// was stored could not be read back and a field still showing the
			// typed value would have been lying about where it came from. The
			// server sends them now, and the refetch below replaces the form with
			// what was actually saved.

			setVerifyResult( null );
			setDirty( false );
			queryClient.invalidateQueries( { queryKey: [ 'connection', slot ] } );
			queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
			toast( __( 'Connection saved.', 'modern-mailer-oauth' ) );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	const disconnect = useMutation( {
		mutationFn: () => disconnectConnection( slot ),
		onSuccess: ( result ) => {
			setProvider( '' );
			setValues( {} );
			setVerifyResult( null );
			setDirty( false );
			queryClient.invalidateQueries( { queryKey: [ 'connection', slot ] } );
			queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
			toast( result.message );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	const verify = useMutation( {
		// Verification runs against what is stored, never against what is on
		// screen - the server has to build the provider from saved credentials
		// to talk to it at all. So unsaved edits are persisted first. Without
		// that, pressing Verify on a filled-in but never-saved connection tests
		// the previous state, and a fresh one answers "choose a provider" while
		// the provider is plainly selected in front of you.
		mutationFn: async () => {
			// Providers declare what they cannot work without, so an incomplete
			// connection can be answered here instead of over the wire. The
			// server checks one field at a time - it stops at the first thing
			// missing - which turns filling in a form into a sequence of round
			// trips. Name all of it at once.
			const gaps = missingRequired( current, values );

			if ( gaps.length ) {
				return {
					ok: false,
					message: sprintf(
						/* translators: %s: comma-separated list of field labels. */
						__( 'Fill in %s before verifying.', 'modern-mailer-oauth' ),
						gaps.map( ( field ) => field.label ).join( ', ' )
					),
				};
			}

			if ( dirty ) {
				await save.mutateAsync();
			}

			return verifyConnection( slot );
		},
		// The result is kept in the page rather than shown as a toast: a
		// verification failure names the exact misconfiguration, and that is
		// the last thing that should vanish after three seconds.
		onSuccess: ( result ) => setVerifyResult( result ),
		onError: ( error ) => setVerifyResult( { ok: false, message: error.message } ),
	} );

	if ( isLoading ) {
		return <Spinner />;
	}

	return (
		<div className="grid gap-5">
			<Panel
				title={ title || __( 'Connection', 'modern-mailer-oauth' ) }
				description={ describeSlot( slot ) }
			>
				<ProviderPicker
					providers={ data.providers }
					selected={ provider }
					onSelect={ ( slug ) => {
						setProvider( slug );
						setValues( {} );
						setVerifyResult( null );
						setDirty( true );
					} }
				/>
			</Panel>

			{ current && (
				<Panel
					title={ current.label }
					description={ current.summary }
					actions={
						// Google opens a guide rather than leaving for one. Its setup
						// turns on a step that decides whether the connection survives a
						// fortnight - publishing the consent screen - and that step is
						// absent from Google's own documentation, because it is not a
						// Gmail API question. Every other provider still links out,
						// where the vendor page really is the best thing to read.
						isGoogle ? (
							<GoogleSetupGuide
								docsUrl={ current.docs }
								redirectUri={ data.oauth?.redirect_uri || '' }
							/>
						) : current.docs ? (
							<a
								href={ current.docs }
								target="_blank"
								rel="noreferrer"
								className="text-[13px] text-brand-deep no-underline hover:underline self-center"
							>
								{ __( 'Documentation', 'modern-mailer-oauth' ) }
							</a>
						) : null
					}
				>
					{ /* Before the credential fields, because it is needed before
					     they exist: this address goes into the app registration,
					     and the client ID and secret only come back out of it. */ }
					{ isGoogle && googleMode === 'own_client' && data.oauth?.redirect_uri && (
						<div className="mb-5">
							<RedirectUri value={ data.oauth.redirect_uri } />
						</div>
					) }

					{ isMicrosoft && microsoftMode === 'own_signin' && data.ms_oauth?.redirect_uri && (
						<div className="mb-5">
							<RedirectUri
								value={ data.ms_oauth.redirect_uri }
								warning={
									data.ms_oauth.clean_redirect
										? null
										: __(
												'Plain permalinks put a query string in this address, which Entra rejects unless the app excludes personal accounts. Any other permalink setting fixes it.',
												'modern-mailer-oauth'
										  )
								}
							/>
						</div>
					) }

					<ProviderForm
						provider={ current }
						values={ values }						onChange={ ( key, value ) =>
							{
								setDirty( true );
								setValues( ( state ) => ( { ...state, [ key ]: value } ) );
							}
						}
					/>

					<div className="flex flex-wrap items-center gap-2 mt-5 pt-5 border-t border-border">
						<Button
							variant="default"
							busy={ save.isPending }
							onClick={ () => save.mutate() }
						>
							{ __( 'Save connection', 'modern-mailer-oauth' ) }
						</Button>
						<Button busy={ verify.isPending } onClick={ () => verify.mutate() }>
							<ShieldCheck size={ 14 } />
							{ __( 'Verify', 'modern-mailer-oauth' ) }
						</Button>

						{ /* Offered only once there is something to disconnect,
						     and pushed to the far right so it is never the
						     button somebody reaches for on the way to Save. */ }
						{ data.provider && (
							<Button
								variant="outline"
								className="ml-auto border-danger/30 text-danger hover:bg-danger/10 hover:text-danger"
								busy={ disconnect.isPending }
								onClick={ () => {
									// eslint-disable-next-line no-alert
									if (
										window.confirm(
											__(
												'This clears the provider, every credential and the From address on this connection. Mail routed here stops. Continue?',
												'modern-mailer-oauth'
											)
										)
									) {
										disconnect.mutate();
									}
								} }
							>
								<Unplug size={ 14 } />
								{ __( 'Disconnect', 'modern-mailer-oauth' ) }
							</Button>
						) }
					</div>

					{ /* Keyed on the provider currently selected, not the saved
					     one. Someone setting Gmail up needs the redirect URI and
					     the reason they cannot sign in yet before they have ever
					     saved - hiding the section until then hides it exactly
					     when it is wanted.

					     Which Gmail block appears follows the setup mode being
					     edited rather than the stored one, so flipping the
					     radio swaps the sign-in block straight away instead of
					     after a save. */ }
					{ /* A service account needs no sign-in at all, so Google
					     shows a block only in the two modes that do. */ }
					{ isGoogle && googleMode === 'one_click' && (
						<OneClickConnect
							family="google"
							oneClick={ data.one_click }
							dirty={ dirty || data.provider !== provider }
						/>
					) }

					{ isGoogle && googleMode === 'own_client' && (
						<GoogleConnect
							oauth={ data.oauth }
							dirty={ dirty || data.provider !== provider }
						/>
					) }

					{ isMicrosoft && microsoftMode === 'one_click' && (
						<OneClickConnect
							family="microsoft"
							oneClick={ data.one_click }
							dirty={ dirty || data.provider !== provider }
							heading={ __( 'Mailbox', 'modern-mailer-oauth' ) }
						/>
					) }

					{ /* The delegated Azure app. Like the Gmail block above it
					     follows the mode being edited rather than the stored
					     one, so flipping the radio swaps the sign-in panel in
					     straight away instead of after a save. The app-only
					     mode shows nothing here on purpose: it mints its own
					     tokens and there is no sign-in to offer. */ }
					{ isMicrosoft && microsoftMode === 'own_signin' && (
						<MicrosoftConnect
							oauth={ data.ms_oauth }
							dirty={ dirty || data.provider !== provider }
						/>
					) }

					{ verifyResult && (
						<div
							className={ `flex items-start gap-2 mt-3 p-3 rounded-lg text-[13px] ${
								verifyResult.ok ? 'bg-success-subtle' : 'bg-danger-subtle'
							}` }
						>
							{ verifyResult.ok ? (
								<Check size={ 15 } className="shrink-0 mt-0.5 text-success" />
							) : (
								<AlertTriangle
									size={ 15 }
									className="shrink-0 mt-0.5 text-danger"
								/>
							) }
							<span className="text-foreground">{ verifyResult.message }</span>
						</div>
					) }
				</Panel>
			) }
		</div>
	);
};

const TestEmail = () => {
	const toast = useToast();
	const [ to, setTo ] = useState( window.mmoa?.currentUserEmail || '' );

	const send = useMutation( {
		mutationFn: () => sendTestEmail( to ),
		onSuccess: ( result ) => toast( result.message, result.ok ? 'ok' : 'bad' ),
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	return (
		<Panel
			title={ __( 'Send a test message', 'modern-mailer-oauth' ) }
			description={ __(
				'Goes out over the primary connection.',
				'modern-mailer-oauth'
			) }
		>
			<div className="flex flex-wrap gap-3 items-end">
				<div className="flex-1 min-w-[240px]">
					<FormField
						label={ __( 'Recipient', 'modern-mailer-oauth' ) }
						htmlFor="mmoa-test-to"
					>
						<input
							id="mmoa-test-to"
							type="email"
							className={ inputClass }
							value={ to }
							onChange={ ( e ) => setTo( e.target.value ) }
						/>
					</FormField>
				</div>
				<Button busy={ send.isPending } onClick={ () => send.mutate() }>
					<Send size={ 14 } />
					{ __( 'Send test', 'modern-mailer-oauth' ) }
				</Button>
			</div>
		</Panel>
	);
};


const Connections = () => {
	const { data: bootstrap, isLoading } = useQuery( { queryKey: [ 'bootstrap' ] } );

	if ( isLoading ) {
		return <Spinner />;
	}

	return (
		<div className="grid gap-5 min-w-0">
			<ConnectionPanel
				slot="primary"
				title={ __( 'Primary', 'modern-mailer-oauth' ) }
				categories={ bootstrap?.categories || {} }
			/>

			<TestEmail />
		</div>
	);
};

export default Connections;
