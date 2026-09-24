import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Wand2 } from 'lucide-react';
import { getSettings, saveSettings } from '../api/client';
import { useToast } from '../components/toast';
import { Panel, Button, FormField, Spinner, ToggleRow, inputClass } from '../components/ui';

const Settings = () => {
	const toast = useToast();
	const queryClient = useQueryClient();
	const [ values, setValues ] = useState( null );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'settings' ],
		queryFn: getSettings,
	} );

	useEffect( () => {
		if ( data ) {
			setValues( data.values );
		}
	}, [ data ] );

	const save = useMutation( {
		mutationFn: () => saveSettings( values ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'settings' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
			toast( __( 'Settings saved.', 'modern-mailer-oauth' ) );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	if ( isLoading || ! values ) {
		return <Spinner />;
	}

	const locked = data.locked;
	const set = ( key, value ) => setValues( ( s ) => ( { ...s, [ key ]: value } ) );

	return (
		<div className="grid gap-5">
			<Panel title={ __( 'Reliability', 'modern-mailer-oauth' ) }>
				<div className="grid gap-4">
					<ToggleRow
						id="mmoa-queue-enabled"
						checked={ values.queue_enabled }
						onChange={ ( v ) => set( 'queue_enabled', v ) }
						label={ __(
							'Hold on to messages that failed for a temporary reason and retry them',
							'modern-mailer-oauth'
						) }
					/>

					{ /* Directly under the toggle it qualifies, because it answers the
					     question that sentence raises: a queued message is stored in
					     full, so how long can somebody's correspondence sit there? */ }
					{ values.queue_enabled && (
						<div className="max-w-sm">
							<FormField
								label={ __( 'Discard undelivered messages after (days)', 'modern-mailer-oauth' ) }

								htmlFor="mmoa-queue-retention"
							>
								<input
									id="mmoa-queue-retention"
									type="number"
									min="1"
									className={ inputClass }
									value={ values.queue_retention }
									onChange={ ( e ) => set( 'queue_retention', e.target.value ) }
								/>
							</FormField>
						</div>
					) }
				</div>
			</Panel>

			<Panel
				title={ __( 'Failure detection', 'modern-mailer-oauth' ) }
				description={ __(
					'Almost nothing in WordPress checks what wp_mail() returned, so this decides when the plugin says so itself.',
					'modern-mailer-oauth'
				) }
			>
				<div className="grid gap-4">
					<div className="grid gap-4 sm:grid-cols-2">
						<FormField
							label={ __( 'Report broken after N failures', 'modern-mailer-oauth' ) }
							help={ __(
								'Consecutive failures, not failures in total. One success resets the count, so a single bad address never triggers it.',
								'modern-mailer-oauth'
							) }
							htmlFor="mmoa-threshold"
						>
							<input
								id="mmoa-threshold"
								type="number"
								min="1"
								className={ inputClass }
								value={ values.alert_threshold }
								onChange={ ( e ) => set( 'alert_threshold', e.target.value ) }
							/>
						</FormField>
					</div>

					{ /* What the threshold on the left actually does. The
					     support question is always some version of "sending
					     broke and nothing told me" - and the answer is that
					     one send failing is not an outage. */ }
					<div className="p-3 rounded-lg bg-muted/40 text-[13px] text-muted-foreground grid gap-1.5">
						<p className="m-0 font-medium text-foreground">
							{ __( 'When you actually get told', 'modern-mailer-oauth' ) }
						</p>
						<p className="m-0">
							{ __(
								'A failed send is logged immediately, every time, and wp_mail() returns false so the code that sent it knows.',
								'modern-mailer-oauth'
							) }
						</p>
						<p className="m-0">
							{ __(
								'Once that many sends fail in a row, the plugin reports sending as broken: an admin notice appears and the Email delivery check under Tools, Site Health turns red. One success clears it.',
								'modern-mailer-oauth'
							) }
						</p>
					</div>
				</div>
			</Panel>

			{ /* The plugin holds other people's email addresses and answers
			     privacy requests through WordPress's own tools - both of which
			     an administrator would otherwise have no way of knowing. Four
			     lines and three links; the detail lives in the policy text and
			     the README. */ }
			<Panel title={ __( 'Privacy', 'modern-mailer-oauth' ) }>
				<div className="grid gap-3 text-sm">
					<p className="m-0 text-muted-foreground">
						{ __(
							'The log keeps recipients and subjects. A queued message is kept in full until it is sent or discarded. No cookies, no tracking pixels, no IP addresses.',
							'modern-mailer-oauth'
						) }
					</p>

					{ /* Said here, on the screen, rather than only in the readme.
					     An administrator deciding whether to run this is
					     entitled to know it phones home without going looking
					     for the fact. */ }
					<p className="m-0 text-muted-foreground">
						{ __(
							'This plugin sends nothing to its own vendor. No registration, no check-in, no usage figures - the only servers it contacts are the mail providers you configure.',
							'modern-mailer-oauth'
						) }
					</p>

					<div className="flex flex-wrap gap-x-5 gap-y-1">
						<a
							href={ window.mmoa?.privacy?.export }
							className="text-brand-deep no-underline hover:underline"
						>
							{ __( 'Export personal data', 'modern-mailer-oauth' ) }
						</a>
						<a
							href={ window.mmoa?.privacy?.erase }
							className="text-brand-deep no-underline hover:underline"
						>
							{ __( 'Erase personal data', 'modern-mailer-oauth' ) }
						</a>
						<a
							href={ window.mmoa?.privacy?.policy }
							className="text-brand-deep no-underline hover:underline"
						>
							{ __( 'Privacy policy text', 'modern-mailer-oauth' ) }
						</a>
					</div>
				</div>
			</Panel>

			{ /* The wizard, offered permanently rather than only on a fresh
			     install. Changing provider is the same sequence as choosing
			     one for the first time, and somebody doing it a year later has
			     no reason to remember where any of it lives. */ }
			<Panel
				title={ __( 'Setup wizard', 'modern-mailer-oauth' ) }
				description={ __(
					'Walks through choosing a provider, connecting a mailbox, verifying the credentials and sending a test. It edits the primary connection, so nothing already configured is lost by opening it.',
					'modern-mailer-oauth'
				) }
			>
				<Button asChild variant="outline">
					<Link to="/setup">
						<Wand2 />
						{ __( 'Open the setup wizard', 'modern-mailer-oauth' ) }
					</Link>
				</Button>
			</Panel>

			<div className="sticky bottom-0 -mx-6 px-6 py-3 bg-background/80 backdrop-blur border-t border-border">
				<Button
					variant="default"
					busy={ save.isPending }
					onClick={ () => save.mutate() }
				>
					{ __( 'Save settings', 'modern-mailer-oauth' ) }
				</Button>
			</div>
		</div>
	);
};

export default Settings;
