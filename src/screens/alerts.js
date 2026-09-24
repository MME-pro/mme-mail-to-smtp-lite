import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Bell, BellOff, Check, AlertTriangle, ExternalLink, Send } from 'lucide-react';
import { getAlerts, saveAlerts, testAlert } from '../api/client';
import { useToast } from '../components/toast';
import { Panel, Button, Badge, Spinner, FormField, inputClass } from '../components/ui';
import { cn } from '../lib/utils';

/**
 * One channel's card: the switch, its fields, and a way to prove it works.
 *
 * The test button sits next to the fields rather than at the bottom of the
 * screen, because it tests this channel and nothing else, and because the
 * answer is only interesting while you are looking at the thing you just typed.
 */
const ChannelCard = ( { channel, draft, onChange, onTest, testing, result } ) => {
	const on = !! draft.enabled;

	return (
		<div
			className={ cn(
				'rounded-lg border transition-colors',
				on ? 'border-brand/40 bg-card' : 'border-border bg-muted/30'
			) }
		>
			<div className="flex items-start gap-3 p-4">
				<button
					type="button"
					role="switch"
					aria-checked={ on }
					aria-label={ sprintf(
						/* translators: %s: the alert channel's name. */
						__( 'Send alerts to %s', 'modern-mailer-oauth' ),
						channel.label
					) }
					onClick={ () => onChange( { ...draft, enabled: ! on } ) }
					className={ cn(
						'mt-0.5 shrink-0 w-9 h-5 rounded-full border-0 cursor-pointer relative transition-colors',
						on ? 'bg-brand' : 'bg-border'
					) }
				>
					<span
						className={ cn(
							'absolute top-0.5 size-4 rounded-full bg-white transition-all',
							on ? 'left-[18px]' : 'left-0.5'
						) }
					/>
				</button>

				<div className="min-w-0 flex-1">
					<div className="flex items-center gap-2 flex-wrap">
						<span className="font-medium text-sm">{ channel.label }</span>

						{ on && ! channel.configured && (
							<Badge variant="warning">
								{ __( 'Needs setting up', 'modern-mailer-oauth' ) }
							</Badge>
						) }

						{ channel.docs && (
							<a
								href={ channel.docs }
								target="_blank"
								rel="noreferrer noopener"
								className="inline-flex items-center gap-1 text-[12px] text-brand-deep no-underline hover:underline"
							>
								{ __( 'How to get this', 'modern-mailer-oauth' ) }
								<ExternalLink size={ 11 } />
							</a>
						) }
					</div>

					<p className="m-0 mt-0.5 text-[13px] text-muted-foreground">{ channel.summary }</p>
				</div>
			</div>

			{ on && (
				<div className="px-4 pb-4 pl-16 grid gap-3">
					{ Object.entries( channel.fields ).map( ( [ key, field ] ) => (
						<FormField
							key={ key }
							label={ field.label }
							help={
								field.secret && field.set
									? __( 'Saved. Leave blank to keep it, or type a new value to replace it.', 'modern-mailer-oauth' )
									: field.help
							}
							htmlFor={ `mmoa-alert-${ channel.slug }-${ key }` }
						>
							{ field.type === 'select' ? (
								<select
									id={ `mmoa-alert-${ channel.slug }-${ key }` }
									className={ inputClass }
									value={ draft[ key ] ?? Object.keys( field.options || {} )[ 0 ] }
									onChange={ ( e ) => onChange( { ...draft, [ key ]: e.target.value } ) }
								>
									{ Object.entries( field.options || {} ).map( ( [ value, label ] ) => (
										<option key={ value } value={ value }>
											{ label }
										</option>
									) ) }
								</select>
							) : (
								<input
									id={ `mmoa-alert-${ channel.slug }-${ key }` }
									type={ field.secret ? 'password' : field.type === 'email' ? 'email' : 'text' }
									autoComplete={ field.secret ? 'new-password' : 'off' }
									placeholder={
										field.secret && field.set
											? '••••••••'
											: field.placeholder || ''
									}
									className={ inputClass }
									value={ draft[ key ] ?? '' }
									onChange={ ( e ) => onChange( { ...draft, [ key ]: e.target.value } ) }
								/>
							) }
						</FormField>
					) ) }

					<div className="flex items-center gap-3 flex-wrap">
						<Button
							variant="secondary"
							busy={ testing }
							onClick={ () => onTest( channel.slug ) }
						>
							<Send size={ 14 } />
							{ __( 'Send test alert', 'modern-mailer-oauth' ) }
						</Button>

						{ result && (
							<span
								className={ cn(
									'inline-flex items-start gap-1.5 text-[12px]',
									result.ok ? 'text-brand-deep' : 'text-danger'
								) }
							>
								{ result.ok ? (
									<Check size={ 13 } className="mt-0.5 shrink-0" />
								) : (
									<AlertTriangle size={ 13 } className="mt-0.5 shrink-0" />
								) }
								{ result.message }
							</span>
						) }
					</div>
				</div>
			) }
		</div>
	);
};

const Alerts = () => {
	const toast = useToast();
	const queryClient = useQueryClient();

	const { data, isLoading } = useQuery( { queryKey: [ 'alerts' ], queryFn: getAlerts } );

	const [ draft, setDraft ] = useState( null );
	const [ results, setResults ] = useState( {} );

	// Seeded from the server once, then owned locally until saved. Re-seeding
	// on every refetch would throw away whatever the administrator is halfway
	// through typing.
	useEffect( () => {
		if ( data && ! draft ) {
			setDraft( {
				enabled: data.enabled,
				when: data.when,
				quiet: data.quiet,
				channels: Object.fromEntries(
					Object.entries( data.channels ).map( ( [ slug, channel ] ) => [
						slug,
						{
							enabled: channel.enabled,
							...Object.fromEntries(
								Object.entries( channel.fields ).map( ( [ key, field ] ) => [
									key,
									field.secret ? '' : field.value,
								] )
							),
						},
					] )
				),
			} );
		}
	}, [ data, draft ] );

	const save = useMutation( {
		mutationFn: saveAlerts,
		onSuccess: () => {
			toast( __( 'Alerts saved.', 'modern-mailer-oauth' ) );
			setDraft( null );
			queryClient.invalidateQueries( { queryKey: [ 'alerts' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	const test = useMutation( {
		mutationFn: testAlert,
		onMutate: ( channel ) => setResults( ( r ) => ( { ...r, [ channel ]: null } ) ),
		onSuccess: ( response, channel ) =>
			setResults( ( r ) => ( { ...r, [ channel ]: response } ) ),
		onError: ( error, channel ) =>
			setResults( ( r ) => ( { ...r, [ channel ]: { ok: false, message: error.message } } ) ),
	} );

	if ( isLoading || ! draft ) {
		return <Spinner />;
	}

	const setChannel = ( slug, next ) =>
		setDraft( { ...draft, channels: { ...draft.channels, [ slug ]: next } } );

	const live = Object.entries( draft.channels ).filter( ( [ , c ] ) => c.enabled ).length;

	return (
		<div className="grid gap-5">
			<Panel
				title={ __( 'Failure alerts', 'modern-mailer-oauth' ) }
				description={ __(
					'Who hears about it when a message does not go out. Sent over the channels below, never through this plugin - an alert that travels down the path that just failed is not an alert.',
					'modern-mailer-oauth'
				) }
				actions={
					<Button busy={ save.isPending } onClick={ () => save.mutate( draft ) }>
						{ __( 'Save alerts', 'modern-mailer-oauth' ) }
					</Button>
				}
			>
				<div className="grid gap-4">
					<button
						type="button"
						onClick={ () => setDraft( { ...draft, enabled: ! draft.enabled } ) }
						className={ cn(
							'flex items-center gap-3 p-3 rounded-lg border text-left cursor-pointer transition-colors',
							draft.enabled
								? 'border-brand/40 bg-brand-subtle'
								: 'border-border bg-muted/30'
						) }
					>
						{ draft.enabled ? (
							<Bell size={ 18 } className="text-brand-deep shrink-0" />
						) : (
							<BellOff size={ 18 } className="text-muted-foreground shrink-0" />
						) }

						<span className="min-w-0">
							<span className="block text-sm font-medium">
								{ draft.enabled
									? __( 'Alerts are on', 'modern-mailer-oauth' )
									: __( 'Alerts are off', 'modern-mailer-oauth' ) }
							</span>
							<span className="block text-[13px] text-muted-foreground">
								{ draft.enabled
									? sprintf(
											/* translators: %d: number of channels switched on. */
											_n(
												'%d channel switched on below.',
												'%d channels switched on below.',
												live,
												'modern-mailer-oauth'
											),
											live
									  )
									: __(
											'Nothing will be sent, whatever the channels below say.',
											'modern-mailer-oauth'
									  ) }
							</span>
						</span>
					</button>

					<div className="grid gap-4 sm:grid-cols-2">
						<FormField
							label={ __( 'Alert me', 'modern-mailer-oauth' ) }
							help={
								draft.when === 'every'
									? __(
											'One alert per failed message. Right for a site that sends a few important emails a day; on a shop this is a message per order.',
											'modern-mailer-oauth'
									  )
									: __(
											'One alert when the failure count reaches the threshold on the Settings screen, and one more when sending recovers. Two messages per outage instead of hundreds.',
											'modern-mailer-oauth'
									  )
							}
							htmlFor="mmoa-alert-when"
						>
							<select
								id="mmoa-alert-when"
								className={ inputClass }
								value={ draft.when }
								onChange={ ( e ) => setDraft( { ...draft, when: e.target.value } ) }
							>
								<option value="threshold">
									{ __( 'When sending starts failing', 'modern-mailer-oauth' ) }
								</option>
								<option value="every">
									{ __( 'On every failed message', 'modern-mailer-oauth' ) }
								</option>
							</select>
						</FormField>

						<FormField
							label={ __( 'Quiet period (minutes)', 'modern-mailer-oauth' ) }
							help={ __(
								'Repeats of the same error are held back for this long, per channel. Set to 0 to send every one.',
								'modern-mailer-oauth'
							) }
							htmlFor="mmoa-alert-quiet"
						>
							<input
								id="mmoa-alert-quiet"
								type="number"
								min="0"
								max="1440"
								className={ inputClass }
								value={ draft.quiet }
								onChange={ ( e ) => setDraft( { ...draft, quiet: e.target.value } ) }
							/>
						</FormField>
					</div>

					{ /* Said plainly rather than buried in the readme. The two
					     fields that leave the site are the two an administrator
					     would most want to be asked about first. */ }
					<p className="m-0 text-[13px] text-muted-foreground">
						{ __(
							'An alert names the failed recipient, the subject, the time, the error and which connection was used. Message bodies and attachments are never included, and neither are your provider credentials.',
							'modern-mailer-oauth'
						) }
					</p>
				</div>
			</Panel>

			<Panel
				title={ __( 'Channels', 'modern-mailer-oauth' ) }
				description={ __(
					'Switch on as many as you like. Test each one - a channel that has never been tested is a channel you are trusting on faith.',
					'modern-mailer-oauth'
				) }
			>
				<div className="grid gap-3">
					{ Object.values( data.channels ).map( ( channel ) => (
						<ChannelCard
							key={ channel.slug }
							channel={ channel }
							draft={ draft.channels[ channel.slug ] || {} }
							onChange={ ( next ) => setChannel( channel.slug, next ) }
							onTest={ ( slug ) => test.mutate( slug ) }
							testing={ test.isPending && test.variables === channel.slug }
							result={ results[ channel.slug ] }
						/>
					) ) }
				</div>
			</Panel>
		</div>
	);
};

export default Alerts;
