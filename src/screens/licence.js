import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { KeyRound, CircleCheck, TriangleAlert, Info, RefreshCw, Unplug } from 'lucide-react';
import {
	getLicence,
	activateLicence,
	deactivateLicence,
	verifyDomain,
} from '../api/client';
import { useToast } from '../components/toast';
import {
	Panel,
	Button,
	Badge,
	Input,
	FormField,
	Spinner,
	Alert,
	AlertDescription,
} from '../components/ui';

/**
 * What this month came to.
 *
 * A bar used to live here, showing how much of a monthly allowance was gone.
 * Nothing is capped now, so there is no proportion left to draw - the count on
 * its own is the whole story.
 */
const Meter = ( { usage } ) => (
	<div className="flex flex-wrap items-center gap-3">
		<Badge variant="brand" dot>
			{ __( 'Unlimited', 'modern-mailer-oauth' ) }
		</Badge>
		<p className="m-0 text-[13px] text-muted-foreground">
			{ sprintf(
				/* translators: 1: number of messages sent this month, 2: the month, e.g. 2026-09. */
				__( '%1$d sent in %2$s.', 'modern-mailer-oauth' ),
				usage.sent,
				usage.month
			) }
		</p>
	</div>
);

const Licence = () => {
	const toast = useToast();
	const queryClient = useQueryClient();
	const [ key, setKey ] = useState( '' );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'licence' ],
		queryFn: getLicence,
	} );

	useEffect( () => {
		if ( data?.licence?.key_hint ) {
			setKey( '' );
		}
	}, [ data ] );

	const refresh = ( result ) => {
		queryClient.setQueryData( [ 'licence' ], result );
		queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
	};

	const activate = useMutation( {
		mutationFn: () => activateLicence( key.trim() ),
		onSuccess: ( result ) => {
			refresh( result );
			toast( result.message, result.ok ? 'ok' : 'bad' );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	const release = useMutation( {
		mutationFn: deactivateLicence,
		onSuccess: ( result ) => {
			refresh( result );
			toast( result.message, result.ok ? 'ok' : 'bad' );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	const recheck = useMutation( {
		mutationFn: verifyDomain,
		onSuccess: ( result ) => {
			refresh( result );
			toast( result.message, result.ok ? 'ok' : 'bad' );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	if ( isLoading ) {
		return <Spinner />;
	}

	const licence = data?.licence ?? {};
	const usage = data?.usage ?? {};

	// A site that has filtered the service away gets told why there is nothing
	// here, rather than being left looking at a screen that does not work.
	if ( ! licence.available ) {
		return (
			<Panel
				title={ __( 'Licence', 'modern-mailer-oauth' ) }
				description={ __( 'The licensing service is switched off on this site.', 'modern-mailer-oauth' ) }
			>
				<Alert variant="info">
					<Info />
					<AlertDescription>
						{ __(
							'Something on this site has filtered mmoa_portal_url to an empty value, so nothing is sent to the licensing service and no licence can be activated. Sending is unaffected: this service is never on the path a message takes.',
							'modern-mailer-oauth'
						) }
					</AlertDescription>
				</Alert>
			</Panel>
		);
	}

	return (
		<div className="stagger grid gap-5">
			<Panel
				title={ __( 'Licence', 'modern-mailer-oauth' ) }
				description={
					licence.pro
						? __( 'This site is licensed. Every provider is available and nothing is metered.', 'modern-mailer-oauth' )
						: __( 'This site has no licence key. Every provider is available and nothing is metered.', 'modern-mailer-oauth' )
				}
				actions={
					licence.pro ? (
						<Badge variant="brand" dot>
							{ licence.plan || __( 'Licensed', 'modern-mailer-oauth' ) }
						</Badge>
					) : (
						<Badge variant="secondary">{ __( 'Free', 'modern-mailer-oauth' ) }</Badge>
					)
				}
			>
				<div className="grid gap-5">
					{ /* The licence is cached with a grace period so a portal
					     outage cannot stop a paying site working. When that
					     grace is what is keeping it alive, say so - it is the
					     one state where everything looks fine and is not. */ }
					{ licence.in_grace && (
						<Alert variant="warning">
							<TriangleAlert />
							<AlertDescription>
								{ __(
									'This licence has not been confirmed recently and is running on its grace period. The site keeps working, but if the licensing service stays unreachable it will fall back to the free tier. Nothing about sending is affected either way.',
									'modern-mailer-oauth'
								) }
							</AlertDescription>
						</Alert>
					) }

					{ /* Unverified is the one thing that stops a key working,
					     and the reason is almost always something the admin
					     can fix, so it names it and offers the retry. */ }
					{ licence.registered && ! licence.verified && (
						<Alert variant="warning">
							<TriangleAlert />
							<AlertDescription>
								<p className="m-0">
									{ __(
										'This site has not been verified yet, so it cannot hold a licence. The licensing service has to reach it over HTTPS at its own address - a site behind basic auth, on a private network, or with a broken certificate will sit here.',
										'modern-mailer-oauth'
									) }
								</p>
								{ licence.verify_error && (
									<p className="mt-2 mb-0">
										{ __( 'Last attempt:', 'modern-mailer-oauth' ) }{ ' ' }
										<code className="font-mono text-xs">{ licence.verify_error }</code>
									</p>
								) }
								<div className="mt-3">
									<Button size="sm" busy={ recheck.isPending } onClick={ () => recheck.mutate() }>
										<RefreshCw size={ 14 } />
										{ __( 'Check again', 'modern-mailer-oauth' ) }
									</Button>
								</div>
							</AlertDescription>
						</Alert>
					) }

					{ licence.pro ? (
						<div className="flex flex-wrap items-center gap-4 rounded-lg border border-success/25 bg-success-subtle px-4 py-3">
							<CircleCheck className="size-4 shrink-0 text-success" />
							<div className="min-w-0 flex-1">
								<p className="m-0 text-sm font-medium">
									{ licence.key_hint
										? sprintf(
												/* translators: %s: the first characters of the licence key. */
												__( 'Key %s… is active on this domain.', 'modern-mailer-oauth' ),
												licence.key_hint
										  )
										: __( 'A licence is active on this domain.', 'modern-mailer-oauth' ) }
								</p>
								<p className="mt-1 mb-0 text-xs text-muted-foreground">
									{ licence.expires
										? sprintf(
												/* translators: %s: expiry date. */
												__( 'Renews or expires on %s.', 'modern-mailer-oauth' ),
												String( licence.expires ).slice( 0, 10 )
										  )
										: __( 'It does not expire.', 'modern-mailer-oauth' ) }
								</p>
							</div>

							<Button
								variant="outline"
								size="sm"
								className="border-danger/30 text-danger hover:bg-danger/10 hover:text-danger"
								busy={ release.isPending }
								onClick={ () => {
									// Releasing frees the key for another
									// domain, which is the point - but it is
									// not obvious that this site drops to the
									// free tier immediately.
									// eslint-disable-next-line no-alert
									if (
										window.confirm(
											__(
												'Release this licence? This site returns to the free tier and the key can then be used on another domain.',
												'modern-mailer-oauth'
											)
										)
									) {
										release.mutate();
									}
								} }
							>
								<Unplug size={ 14 } />
								{ __( 'Release', 'modern-mailer-oauth' ) }
							</Button>
						</div>
					) : (
						<div className="flex flex-wrap items-end gap-3">
							<div className="min-w-[280px] flex-1">
								<FormField
									label={ __( 'Licence key', 'modern-mailer-oauth' ) }
									htmlFor="mmoa-licence-key"
									help={ __(
										'From your purchase email. It reads MME-XXXX-XXXX-XXXX-XXXX, and it works on one domain at a time.',
										'modern-mailer-oauth'
									) }
								>
									<Input
										id="mmoa-licence-key"
										value={ key }
										spellCheck={ false }
										placeholder="MME-XXXX-XXXX-XXXX-XXXX"
										onChange={ ( event ) => setKey( event.target.value ) }
										onKeyDown={ ( event ) =>
											'Enter' === event.key && key.trim() && activate.mutate()
										}
									/>
								</FormField>
							</div>

							<Button
								variant="brand"
								busy={ activate.isPending }
								disabled={ '' === key.trim() }
								onClick={ () => activate.mutate() }
							>
								<KeyRound size={ 14 } />
								{ __( 'Activate', 'modern-mailer-oauth' ) }
							</Button>
						</div>
					) }
				</div>
			</Panel>

			<Panel
				title={ __( 'This month', 'modern-mailer-oauth' ) }
				description={ __(
					'Counted on this site. The licensing service is told the totals and nothing else - never a recipient, a subject or a message.',
					'modern-mailer-oauth'
				) }
			>
				<Meter usage={ usage } />
			</Panel>

			{ licence.last_error && (
				<Alert variant="info">
					<Info />
					<AlertDescription>
						{ sprintf(
							/* translators: %s: the error the licensing service last returned. */
							__( 'The last check-in with the licensing service did not succeed: %s. Nothing about sending is affected, and it will try again.', 'modern-mailer-oauth' ),
							licence.last_error
						) }
					</AlertDescription>
				</Alert>
			) }
		</div>
	);
};

export default Licence;
