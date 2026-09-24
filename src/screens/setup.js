import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useNavigate, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
	ArrowRight,
	ArrowLeft,
	Check,
	CircleCheck,
	KeyRound,
	Plug,
	Send,
	ShieldCheck,
	ShieldOff,
	TriangleAlert,
	Route as RouteIcon,
	ScrollText,
	LifeBuoy,
	Loader2,
} from 'lucide-react';
import {
	getConnection,
	saveConnection,
	verifyConnection,
	sendTestEmail,
	setSetupStep,
	completeSetup,
	skipSetup,
} from '../api/client';
import { useToast } from '../components/toast';
import {
	Button,
	Card,
	CardContent,
	Alert,
	AlertDescription,
	FormField,
	Spinner,
	inputClass,
} from '../components/ui';
import { cn } from '../lib/utils';
import ProviderPicker from '../components/provider-picker';
import ProviderForm, { missingRequired } from '../components/provider-form';
import ProviderLogo from '../components/provider-logo';
import RedirectUri from '../components/redirect-uri';
import GoogleConnect from '../components/google-connect';
import GoogleSetupGuide from '../components/google-setup-guide';
import MicrosoftConnect from '../components/microsoft-connect';
import OneClickConnect from '../components/one-click-connect';

/**
 * Guided setup.
 *
 * The plugin does nothing at all until a connection exists - it declines to
 * take over wp_mail() rather than intercept a send it cannot complete - so the
 * gap between activating it and mail actually leaving the site is the one place
 * a site can sit broken without anything looking wrong. This is the path across
 * that gap, and it is the screen an admin is sent to the moment they activate.
 *
 * Everything it does is done through the same REST routes the connections
 * screen uses, against the primary connection. That is deliberate: the wizard
 * is a different route through the existing settings, not a second way of
 * storing them, so nothing it writes can be a shape the rest of the app does
 * not already understand. Leaving halfway keeps whatever was saved.
 *
 * The step is recorded on the server as it changes. Connecting a mailbox hands
 * the browser to Google or Microsoft and gets it back as a fresh page load with
 * no memory of what was happening, and the callbacks return here rather than to
 * the connections screen for as long as the wizard is open.
 */

const STEPS = [
	{ id: 'provider', label: __( 'Provider', 'modern-mailer-oauth' ) },
	{ id: 'connect', label: __( 'Credentials', 'modern-mailer-oauth' ) },
	{ id: 'verify', label: __( 'Verify', 'modern-mailer-oauth' ) },
	{ id: 'test', label: __( 'Test', 'modern-mailer-oauth' ) },
	{ id: 'done', label: __( 'Finish', 'modern-mailer-oauth' ) },
];

const STEP_IDS = [ 'welcome', ...STEPS.map( ( step ) => step.id ) ];

/**
 * The progress rail.
 *
 * The connector belongs to the step before it rather than being one rule drawn
 * behind the row, which is what this was first. A single rule has to be inset
 * by half a node to start and end at the centres, and that inset is only
 * correct while every item is exactly a node wide - the moment the labels
 * appear beside them the last node stops being half a node from the right edge
 * and the rule runs past it. A segment between two nodes cannot come adrift of
 * either, at any width, in any translation.
 *
 * Labels show only where there is room for all five without crowding. Below
 * that the card's own heading says where you are, which it says anyway.
 */
const Rail = ( { current } ) => {
	const index = Math.max( 0, STEPS.findIndex( ( step ) => step.id === current ) );

	return (
		<ol className="m-0 flex list-none items-center p-0">
			{ STEPS.map( ( step, i ) => {
				const done = i < index;
				const active = i === index;
				const last = i === STEPS.length - 1;

				return (
					<li
						key={ step.id }
						className={ cn( 'flex items-center gap-2.5', ! last && 'flex-1' ) }
						aria-current={ active ? 'step' : undefined }
					>
						<span
							className={ cn(
								'inline-flex size-8 shrink-0 items-center justify-center rounded-full border text-[13px] font-medium transition-all duration-300',
								done && 'border-brand bg-brand text-brand-foreground',
								active && 'border-brand bg-card text-brand-deep ring-4 ring-brand/15',
								! done && ! active && 'border-border bg-card text-muted-foreground'
							) }
						>
							{ done ? <Check className="size-4" strokeWidth={ 3 } /> : i + 1 }
						</span>

						<span
							className={ cn(
								'hidden text-[13px] whitespace-nowrap lg:inline',
								active ? 'font-medium text-foreground' : 'text-muted-foreground'
							) }
						>
							{ step.label }
						</span>

						{ /* The segment to the next node. It fills rather than
						     switching colour, so moving on reads as travel in
						     the direction the wizard is going. */ }
						{ ! last && (
							<span
								aria-hidden="true"
								className="mx-2 h-px min-w-4 flex-1 bg-border"
							>
								<span
									className="block h-px bg-brand transition-[width] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]"
									style={ {
										width: done ? '100%' : '0%',
										boxShadow: done
											? '0 0 8px 0 color-mix(in oklab, var(--brand) 60%, transparent)'
											: 'none',
									} }
								/>
							</span>
						) }
					</li>
				);
			} ) }
		</ol>
	);
};

/**
 * The shell every step is poured into: heading, lead, body, and one row of
 * actions that is always in the same place.
 *
 * Keyed on the step by its caller, so React remounts it on each move and the
 * rise animation plays. Without the key the card mutates in place and the
 * transition between two steps is a flicker of replaced text.
 */
const Step = ( { eyebrow, title, lead, children, back, actions } ) => (
	<Card className="overflow-hidden">
		<CardContent className="grid gap-6">
			<div className="grid gap-2">
				{ eyebrow && (
					<p className="m-0 text-xs tracking-[0.14em] text-muted-foreground uppercase">
						{ eyebrow }
					</p>
				) }
				<h2 className="font-display text-[24px] leading-tight font-normal tracking-[-0.02em] m-0">
					{ title }
				</h2>
				{ lead && (
					<p className="m-0 max-w-[62ch] text-sm leading-relaxed text-muted-foreground">
						{ lead }
					</p>
				) }
			</div>

			{ children }

			<div className="flex flex-wrap items-center gap-2 border-t border-border pt-6">
				{ back && (
					<Button variant="ghost" onClick={ back }>
						<ArrowLeft />
						{ __( 'Back', 'modern-mailer-oauth' ) }
					</Button>
				) }
				<div className="ml-auto flex flex-wrap items-center gap-2">{ actions }</div>
			</div>
		</CardContent>
	</Card>
);

/**
 * The cover page.
 *
 * It states what the wizard is going to ask for before it asks, because the
 * honest answer to "how long will this take" is "it depends whether you already
 * have a mailbox to connect", and somebody who does not should find that out
 * here rather than three steps in.
 */
const Welcome = ( { onStart, onSkip } ) => (
	<Step
		eyebrow={ __( 'Guided setup', 'modern-mailer-oauth' ) }
		title={ __( 'Let us get this site sending properly.', 'modern-mailer-oauth' ) }
		lead={ __(
			'WordPress is currently handing email to the server’s own mail function, which most inboxes now treat as unsigned post. Four short steps connect a real mailbox instead, and nothing is written until you press Save.',
			'modern-mailer-oauth'
		) }
		actions={
			<>
				<Button variant="ghost" onClick={ onSkip }>
					{ __( 'Not now', 'modern-mailer-oauth' ) }
				</Button>
				<Button variant="brand" size="lg" onClick={ onStart }>
					{ __( 'Begin setup', 'modern-mailer-oauth' ) }
					<ArrowRight />
				</Button>
			</>
		}
	>
		<ul className="m-0 grid list-none gap-3 p-0 sm:grid-cols-3">
			{ [
				{
					icon: Plug,
					title: __( 'Pick a provider', 'modern-mailer-oauth' ),
					body: __(
						'Microsoft and Google sign in with a single click. Everything else takes an API key.',
						'modern-mailer-oauth'
					),
				},
				{
					icon: ShieldCheck,
					title: __( 'Prove it works', 'modern-mailer-oauth' ),
					body: __(
						'The credentials are checked against the provider before you leave this screen.',
						'modern-mailer-oauth'
					),
				},
				{
					icon: Send,
					title: __( 'Send one message', 'modern-mailer-oauth' ),
					body: __(
						'A real email, sent with the safety nets off, so a failure cannot hide behind a retry.',
						'modern-mailer-oauth'
					),
				},
			].map( ( { icon: Icon, title, body } ) => (
				<li key={ title } className="rounded-lg border border-border bg-muted/30 p-4">
					<Icon className="size-4 text-brand-deep" />
					<p className="mt-2.5 mb-1 text-[13px] font-medium">{ title }</p>
					<p className="m-0 text-xs leading-relaxed text-muted-foreground">{ body }</p>
				</li>
			) ) }
		</ul>
	</Step>
);

const Setup = () => {
	const toast = useToast();
	const navigate = useNavigate();
	const queryClient = useQueryClient();

	const bootstrap = queryClient.getQueryData( [ 'bootstrap' ] );

	// Resumed rather than restarted. A sign-in leaves the site entirely and
	// comes back as a fresh page load, so the step the server recorded is the
	// only thing that knows this was step three of five and not the beginning.
	const [ step, setStep ] = useState( () => {
		const state = bootstrap?.setup;

		return state?.in_progress && STEP_IDS.includes( state.step ) ? state.step : 'welcome';
	} );

	const [ provider, setProvider ] = useState( '' );
	const [ values, setValues ] = useState( {} );
	const [ dirty, setDirty ] = useState( false );
	const [ verifyResult, setVerifyResult ] = useState( null );
	const [ testResult, setTestResult ] = useState( null );
	const [ to, setTo ] = useState( window.mmoa?.currentUserEmail || '' );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'connection', 'primary' ],
		queryFn: () => getConnection( 'primary' ),
	} );

	// Seeded from whatever is stored, exactly as the connections screen does
	// it - a wizard run on a site that is already half configured has to show
	// the half that exists rather than a blank form.
	useEffect( () => {
		if ( ! data ) {
			return;
		}

		setProvider( data.provider );

		const chosen = data.providers.find( ( p ) => p.slug === data.provider );
		const seed = {};

		( chosen?.fields || [] ).forEach( ( field ) => {
			seed[ field.key ] = field.value ?? '';
		} );

		setValues( seed );
		setDirty( false );
	}, [ data ] );

	const current = data?.providers.find( ( p ) => p.slug === provider );

	const modeOf = ( key ) =>
		values[ key ] ??
		current?.fields?.find( ( f ) => f.key === key )?.value ??
		'own_client';

	const googleMode = modeOf( 'google_setup_mode' );
	const microsoftMode = modeOf( 'ms_setup_mode' );
	const isGoogle = provider === 'google' || provider === 'gmail_oauth';
	const isMicrosoft = provider === 'microsoft' || provider === 'outlook';

	/**
	 * Move, and tell the server where we got to.
	 *
	 * The write is not awaited and a failure is swallowed on purpose: it exists
	 * so a sign-in can come back to the right step, and an interface that
	 * refused to advance because a bookkeeping call failed would be worse than
	 * one that occasionally resumes a step early.
	 */
	const go = ( next ) => {
		setStep( next );
		setSetupStep( next ).catch( () => {} );
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	};

	const save = useMutation( {
		mutationFn: () => saveConnection( 'primary', { provider, ...values } ),
		onSuccess: () => {
			setDirty( false );
			queryClient.invalidateQueries( { queryKey: [ 'connection', 'primary' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	const verify = useMutation( {
		mutationFn: async () => {
			if ( dirty ) {
				await save.mutateAsync();
			}

			return verifyConnection( 'primary' );
		},
		onSuccess: ( result ) => setVerifyResult( result ),
		onError: ( error ) => setVerifyResult( { ok: false, message: error.message } ),
	} );

	const test = useMutation( {
		mutationFn: () => sendTestEmail( to ),
		onSuccess: ( result ) => setTestResult( result ),
		onError: ( error ) => setTestResult( { ok: false, message: error.message } ),
	} );

	const finish = useMutation( {
		mutationFn: completeSetup,
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
			toast( __( 'Setup finished.', 'modern-mailer-oauth' ) );
			navigate( '/dashboard' );
		},
		onError: () => navigate( '/dashboard' ),
	} );

	const leave = useMutation( {
		mutationFn: skipSetup,
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
			navigate( '/dashboard' );
		},
		onError: () => navigate( '/dashboard' ),
	} );

	if ( isLoading ) {
		return <Spinner />;
	}

	/**
	 * Save, then move on - but never move on from an incomplete form.
	 *
	 * The gaps are named all at once rather than one per attempt, which is what
	 * the server can do: it stops at the first thing missing, and a wizard that
	 * reported one field per round trip would be the slowest possible way to
	 * fill in six of them.
	 */
	const saveThenContinue = async () => {
		const gaps = missingRequired( current, values );

		if ( gaps.length ) {
			toast(
				sprintf(
					/* translators: %s: comma-separated list of field labels. */
					__( 'Fill in %s first.', 'modern-mailer-oauth' ),
					gaps.map( ( field ) => field.label ).join( ', ' )
				),
				'bad'
			);

			return;
		}

		try {
			if ( dirty ) {
				await save.mutateAsync();
			}

			setVerifyResult( null );
			go( 'verify' );
		} catch {
			// Reported by the mutation's own error handler.
		}
	};

	const exit = (
		<button
			type="button"
			onClick={ () => leave.mutate() }
			className="cursor-pointer border-0 bg-transparent p-0 text-[13px] text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline"
		>
			{ __( 'Finish this later', 'modern-mailer-oauth' ) }
		</button>
	);

	const body = () => {
		switch ( step ) {
			case 'provider':
				return (
					<Step
						eyebrow={ sprintf(
							/* translators: 1: current step number, 2: total steps. */
							__( 'Step %1$d of %2$d', 'modern-mailer-oauth' ),
							1,
							STEPS.length
						) }
						title={ __( 'How should this site send its email?', 'modern-mailer-oauth' ) }
						lead={ __(
							'Microsoft and Google can be connected without registering anything, by signing in. The rest need an API key from the service, which takes a minute in their console.',
							'modern-mailer-oauth'
						) }
						back={ () => go( 'welcome' ) }
						actions={
							<Button
								variant="brand"
								size="lg"
								disabled={ ! provider }
								onClick={ () => go( 'connect' ) }
							>
								{ __( 'Continue', 'modern-mailer-oauth' ) }
								<ArrowRight />
							</Button>
						}
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

						{ ! provider && (
							<p className="m-0 text-xs text-muted-foreground">
								{ __(
									'Choose one to continue. It can be changed later without losing anything.',
									'modern-mailer-oauth'
								) }
							</p>
						) }
					</Step>
				);

			case 'connect':
				// Reachable without a provider in one way only: the step is
				// resumed from the server - a sign-in that came back, a
				// browser reopened - on a connection whose provider has since
				// been disconnected. The form is generated from the provider's
				// own schema and there is nothing to generate it from, so this
				// says so rather than rendering an empty card.
				if ( ! current ) {
					return (
						<Step
							eyebrow={ __( 'Guided setup', 'modern-mailer-oauth' ) }
							title={ __( 'No provider chosen yet', 'modern-mailer-oauth' ) }
							lead={ __(
								'This step asks for the credentials the provider needs, and which credentials those are depends on the provider. Go back and pick one.',
								'modern-mailer-oauth'
							) }
							actions={
								<Button variant="brand" size="lg" onClick={ () => go( 'provider' ) }>
									{ __( 'Choose a provider', 'modern-mailer-oauth' ) }
									<ArrowRight />
								</Button>
							}
						/>
					);
				}

				return (
					<Step
						eyebrow={ sprintf(
							/* translators: 1: current step number, 2: total steps. */
							__( 'Step %1$d of %2$d', 'modern-mailer-oauth' ),
							2,
							STEPS.length
						) }
						title={ sprintf(
							/* translators: %s: provider name, e.g. Microsoft. */
							__( 'Connect %s', 'modern-mailer-oauth' ),
							current?.label || __( 'the provider', 'modern-mailer-oauth' )
						) }
						lead={ current?.summary }
						back={ () => go( 'provider' ) }
						actions={
							<Button
								variant="brand"
								size="lg"
								busy={ save.isPending }
								onClick={ saveThenContinue }
							>
								{ __( 'Save and continue', 'modern-mailer-oauth' ) }
								<ArrowRight />
							</Button>
						}
					>
						{ /* Google opens the guide in place rather than linking
						     out, because the step that decides whether the
						     connection survives a fortnight - publishing the
						     consent screen - is absent from Google's own
						     documentation. */ }
						{ current && (
							<div className="flex items-center gap-3 rounded-lg border border-border bg-muted/30 px-4 py-3">
								<ProviderLogo slug={ current.slug } className="size-6 shrink-0" />
								<span className="flex-1 text-[13px] font-medium">{ current.label }</span>
								{ isGoogle ? (
									<GoogleSetupGuide
										docsUrl={ current.docs }
										redirectUri={ data.oauth?.redirect_uri || '' }
									/>
								) : (
									current.docs && (
										<a
											href={ current.docs }
											target="_blank"
											rel="noreferrer"
											className="text-[13px] text-brand-deep no-underline hover:underline"
										>
											{ __( 'Documentation', 'modern-mailer-oauth' ) }
										</a>
									)
								) }
							</div>
						) }

						{ isGoogle && googleMode === 'own_client' && data.oauth?.redirect_uri && (
							<RedirectUri value={ data.oauth.redirect_uri } />
						) }

						{ isMicrosoft && microsoftMode === 'own_signin' && data.ms_oauth?.redirect_uri && (
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
						) }

						<ProviderForm
							provider={ current }
							values={ values }
							onChange={ ( key, value ) => {
								setDirty( true );
								setValues( ( state ) => ( { ...state, [ key ]: value } ) );
							} }
						/>

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

						{ isMicrosoft && microsoftMode === 'own_signin' && (
							<MicrosoftConnect
								oauth={ data.ms_oauth }
								dirty={ dirty || data.provider !== provider }
							/>
						) }
					</Step>
				);

			case 'verify':
				return (
					<Step
						eyebrow={ sprintf(
							/* translators: 1: current step number, 2: total steps. */
							__( 'Step %1$d of %2$d', 'modern-mailer-oauth' ),
							3,
							STEPS.length
						) }
						title={ __( 'Check the credentials reach the mailbox', 'modern-mailer-oauth' ) }
						lead={ __(
							'This asks the provider whether it recognises what was saved, and whether the mailbox is one this connection is allowed to send from. Nothing is emailed yet.',
							'modern-mailer-oauth'
						) }
						back={ () => go( 'connect' ) }
						actions={
							<>
								{ verifyResult && ! verifyResult.ok && (
									<Button variant="ghost" onClick={ () => go( 'test' ) }>
										{ __( 'Continue anyway', 'modern-mailer-oauth' ) }
									</Button>
								) }
								{ verifyResult?.ok ? (
									<Button variant="brand" size="lg" onClick={ () => go( 'test' ) }>
										{ __( 'Continue', 'modern-mailer-oauth' ) }
										<ArrowRight />
									</Button>
								) : (
									<Button
										variant="brand"
										size="lg"
										busy={ verify.isPending }
										onClick={ () => verify.mutate() }
									>
										<ShieldCheck />
										{ verifyResult
											? __( 'Check again', 'modern-mailer-oauth' )
											: __( 'Verify connection', 'modern-mailer-oauth' ) }
									</Button>
								) }
							</>
						}
					>
						<div
							className={ cn(
								'flex items-start gap-3 rounded-lg border p-5 transition-colors',
								! verifyResult && 'border-dashed border-border bg-muted/20',
								verifyResult?.ok && 'border-success/25 bg-success-subtle',
								verifyResult && ! verifyResult.ok && 'border-danger/25 bg-danger-subtle'
							) }
						>
							{ verify.isPending ? (
								<Loader2 className="mt-0.5 size-5 shrink-0 animate-spin text-muted-foreground" />
							) : verifyResult?.ok ? (
								<CircleCheck className="mt-0.5 size-5 shrink-0 text-success" />
							) : verifyResult ? (
								<ShieldOff className="mt-0.5 size-5 shrink-0 text-danger" />
							) : (
								<ShieldCheck className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
							) }

							<div className="min-w-0">
								{ /* The heading says the outcome and the line under it is
								     the provider's own words. They must not be the same
								     sentence: a pass reports itself as "Verified. The
								     credentials are valid…", so a heading reading
								     "Verified." would print the word twice. */ }
								<p className="m-0 text-sm font-medium">
									{ verify.isPending
										? __( 'Asking the provider…', 'modern-mailer-oauth' )
										: verifyResult?.ok
										? __( 'The provider accepted it.', 'modern-mailer-oauth' )
										: verifyResult
										? __( 'The provider refused.', 'modern-mailer-oauth' )
										: __( 'Not checked yet.', 'modern-mailer-oauth' ) }
								</p>
								<p className="mt-1 mb-0 max-w-[62ch] text-[13px] leading-relaxed text-muted-foreground">
									{ verifyResult?.message ||
										__(
											'Press Verify. Any unsaved edit on the previous step is saved first, because the check runs against what is stored.',
											'modern-mailer-oauth'
										) }
								</p>
							</div>
						</div>

						{ verifyResult && ! verifyResult.ok && (
							<Alert variant="warning">
								<TriangleAlert />
								<AlertDescription>
									{ __(
										'The message above comes from the provider, not from this plugin - it names what is actually wrong. Go back a step to correct it.',
										'modern-mailer-oauth'
									) }
								</AlertDescription>
							</Alert>
						) }
					</Step>
				);

			case 'test':
				return (
					<Step
						eyebrow={ sprintf(
							/* translators: 1: current step number, 2: total steps. */
							__( 'Step %1$d of %2$d', 'modern-mailer-oauth' ),
							4,
							STEPS.length
						) }
						title={ __( 'Send one real message', 'modern-mailer-oauth' ) }
						lead={ __(
							'It goes out over this connection with routing, the backup and the retry queue switched off, so a failure here is the connection failing rather than something else quietly covering for it.',
							'modern-mailer-oauth'
						) }
						back={ () => go( 'verify' ) }
						actions={
							<>
								{ /* Outline, not the default fill. Continue is the
								     step's one primary action, and two solid
								     buttons side by side make the reader choose
								     between them rather than read one. */ }
								<Button
									variant="outline"
									busy={ test.isPending }
									onClick={ () => test.mutate() }
								>
									<Send />
									{ testResult
										? __( 'Send another', 'modern-mailer-oauth' )
										: __( 'Send test', 'modern-mailer-oauth' ) }
								</Button>
								<Button variant="brand" size="lg" onClick={ () => go( 'done' ) }>
									{ __( 'Continue', 'modern-mailer-oauth' ) }
									<ArrowRight />
								</Button>
							</>
						}
					>
						<div className="max-w-md">
							<FormField
								label={ __( 'Send to', 'modern-mailer-oauth' ) }
								htmlFor="mmoa-setup-test-to"
								help={ __(
									'Your own address is the fastest way to find out. Somewhere outside this domain tells you more.',
									'modern-mailer-oauth'
								) }
							>
								<input
									id="mmoa-setup-test-to"
									type="email"
									className={ inputClass }
									value={ to }
									onChange={ ( e ) => setTo( e.target.value ) }
								/>
							</FormField>
						</div>

						{ testResult && (
							<Alert variant={ testResult.ok ? 'success' : 'danger' }>
								{ testResult.ok ? <CircleCheck /> : <TriangleAlert /> }
								<AlertDescription>
									<p className="m-0">{ testResult.message }</p>
								</AlertDescription>
							</Alert>
						) }
					</Step>
				);

			case 'done': {
				const sending = !! data.provider;

				return (
					<Step
						eyebrow={ __( 'Setup complete', 'modern-mailer-oauth' ) }
						title={
							sending
								? __( 'WordPress is sending through your provider.', 'modern-mailer-oauth' )
								: __( 'Nothing is configured yet.', 'modern-mailer-oauth' )
						}
						lead={
							sending
								? __(
										'Every message this site sends now goes out over this connection, and every attempt is recorded with whatever the provider said about it.',
										'modern-mailer-oauth'
								  )
								: __(
										'You can leave now and come back to this at any time - the wizard is always available from the dashboard.',
										'modern-mailer-oauth'
								  )
						}
						back={ () => go( 'test' ) }
						actions={
							<Button
								variant="brand"
								size="lg"
								busy={ finish.isPending }
								onClick={ () => finish.mutate() }
							>
								<Check />
								{ __( 'Go to the dashboard', 'modern-mailer-oauth' ) }
							</Button>
						}
					>
						<dl className="m-0 grid gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-3">
							{ [
								{
									term: __( 'Provider', 'modern-mailer-oauth' ),
									value: current?.label || __( 'None', 'modern-mailer-oauth' ),
								},
								{
									term: __( 'Sends as', 'modern-mailer-oauth' ),
									value:
										values.from_email ||
										__( 'Not set', 'modern-mailer-oauth' ),
								},
								{
									term: __( 'Test message', 'modern-mailer-oauth' ),
									value: testResult
										? testResult.ok
											? __( 'Accepted', 'modern-mailer-oauth' )
											: __( 'Refused', 'modern-mailer-oauth' )
										: __( 'Not sent', 'modern-mailer-oauth' ),
									tone: testResult ? ( testResult.ok ? 'ok' : 'bad' ) : null,
								},
							].map( ( { term, value, tone } ) => (
								<div key={ term } className="bg-card px-4 py-3.5">
									<dt className="m-0 text-xs tracking-[0.12em] text-muted-foreground uppercase">
										{ term }
									</dt>
									<dd
										className={ cn(
											'mt-1.5 mb-0 ml-0 truncate text-sm font-medium',
											tone === 'ok' && 'text-success',
											tone === 'bad' && 'text-danger'
										) }
									>
										{ value }
									</dd>
								</div>
							) ) }
						</dl>

						{ /* What to do next, rather than a congratulation. Each of
						     these is a thing this plugin does that nobody
						     discovers by accident, and the moment somebody has
						     just finished connecting is the one moment they are
						     looking at this screen. */ }
						<div className="grid gap-2.5 sm:grid-cols-3">
							{ [
								{
									to: '/connections',
									icon: LifeBuoy,
									title: __( 'Add a backup', 'modern-mailer-oauth' ),
									body: __(
										'A second provider that takes over when the first one fails.',
										'modern-mailer-oauth'
									),
								},
								{
									to: '/routing',
									icon: RouteIcon,
									title: __( 'Route by rule', 'modern-mailer-oauth' ),
									body: __(
										'Send receipts from one mailbox and newsletters from another.',
										'modern-mailer-oauth'
									),
								},
								{
									to: '/logs',
									icon: ScrollText,
									title: __( 'Watch the log', 'modern-mailer-oauth' ),
									body: __(
										'Every send, with the provider’s own words when one fails.',
										'modern-mailer-oauth'
									),
								},
							].map( ( { to: href, icon: Icon, title, body: text } ) => (
								<Link
									key={ href }
									to={ href }
									className="group rounded-lg border border-border bg-card p-4 no-underline transition-all hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-sm"
								>
									<Icon className="size-4 text-brand-deep" />
									<p className="mt-2.5 mb-1 flex items-center gap-1 text-[13px] font-medium text-foreground">
										{ title }
										<ArrowRight className="size-3 opacity-0 transition-opacity group-hover:opacity-100" />
									</p>
									<p className="m-0 text-xs leading-relaxed text-muted-foreground">
										{ text }
									</p>
								</Link>
							) ) }
						</div>
					</Step>
				);
			}

			default:
				return (
					<Welcome
						onStart={ () => go( 'provider' ) }
						onSkip={ () => leave.mutate() }
					/>
				);
		}
	};

	return (
		<div className="mx-auto grid w-full max-w-4xl gap-8">
			{ step !== 'welcome' && (
				<div className="grid gap-4">
					<Rail current={ step } />
					<div className="flex justify-end">{ exit }</div>
				</div>
			) }

			{ /* Keyed, so each step is a fresh mount and the rise animation
			     plays. Mutating the card in place turns a step change into a
			     flicker of replaced text. */ }
			<div key={ step } className="stagger">
				{ body() }
			</div>

			{ step === 'welcome' && (
				<p className="m-0 flex items-center justify-center gap-1.5 text-xs text-muted-foreground">
					<KeyRound className="size-3" />
					{ __(
						'Credentials are stored on this site and sent only to the provider they belong to.',
						'modern-mailer-oauth'
					) }
				</p>
			) }
		</div>
	);
};

export default Setup;
