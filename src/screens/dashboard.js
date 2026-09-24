import { __, sprintf } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Clock, XCircle, CircleCheck, TriangleAlert, ArrowRight, Wand2 } from 'lucide-react';
import { getDashboard } from '../api/client';
import { Panel, Button, Spinner } from '../components/ui';
import { cn } from '../lib/utils';

/**
 * A supporting figure. Deliberately quieter than the hero - no icon chip, no
 * card of its own, just a hairline and a number.
 */
const Stat = ( { label, value, tone, icon: Icon, help } ) => (
	<div className="border-t border-border pt-4 first:border-t-0 first:pt-0 sm:border-t-0 sm:border-l sm:pt-0 sm:pl-5 sm:first:border-l-0 sm:first:pl-0">
		<span
			className={ cn(
				'block font-display text-[28px] leading-none',
				tone === 'danger' && value > 0 && 'text-danger',
				tone === 'warning' && value > 0 && 'text-warning',
				( ! tone || value === 0 ) && 'text-foreground'
			) }
		>
			{ value }
		</span>
		<span className="mt-1.5 flex items-center gap-1 text-xs text-muted-foreground">
			{ Icon && <Icon className="size-3" /> }
			{ label }
		</span>
		{ help && (
			<span className="mt-1 block text-[11px] leading-snug text-muted-foreground">
				{ help }
			</span>
		) }
	</div>
);

/**
 * The hero: whether sending works, said at display size.
 *
 * This plugin keeps no record of individual messages, so there is no send count
 * to lead with. What an administrator can act on is the current state - working,
 * or failing and why - so that is what gets the largest type on the screen.
 */
const Hero = ( { health, queue } ) => {
	const {
		active,
		failing,
		streak,
		last_error: lastError,
		last_success: lastSuccess,
	} = health;

	let headline;
	let Icon;
	let tone;

	if ( ! active ) {
		headline = __( 'Not sending yet', 'mme-mail-to-smtp' );
		Icon = TriangleAlert;
		tone = 'text-muted-foreground';
	} else if ( failing ) {
		headline = __( 'Sending is failing', 'mme-mail-to-smtp' );
		Icon = XCircle;
		tone = 'text-danger';
	} else {
		headline = __( 'Sending is working', 'mme-mail-to-smtp' );
		Icon = CircleCheck;
		tone = 'text-success';
	}

	let summary;

	if ( ! active ) {
		summary = __(
			'No provider is configured, so WordPress is still using the server mail function.',
			'mme-mail-to-smtp'
		);
	} else if ( failing ) {
		summary = sprintf(
			/* translators: %d: number of consecutive failures. */
			__(
				'%d sends have failed in a row. wp_mail() is returning false, so the code that sent them knows.',
				'mme-mail-to-smtp'
			),
			streak
		);
	} else if ( lastSuccess > 0 ) {
		summary = sprintf(
			/* translators: %s: date and time of the last successful send. */
			__( 'Last message delivered %s.', 'mme-mail-to-smtp' ),
			new Date( lastSuccess * 1000 ).toLocaleString()
		);
	} else {
		summary = __(
			'A provider is configured. Nothing has been sent through it yet.',
			'mme-mail-to-smtp'
		);
	}

	return (
		<Panel className="overflow-hidden">
			<div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,0.85fr)] lg:items-end">
				<div>
					<p className="m-0 text-xs tracking-[0.14em] text-muted-foreground uppercase">
						{ __( 'Delivery', 'mme-mail-to-smtp' ) }
					</p>

					<p className="mt-3 mb-0 flex items-center gap-3">
						<Icon className={ cn( 'size-8 shrink-0', tone ) } />
						<span
							className={ cn(
								'font-display text-[40px] leading-[0.95] tracking-[-0.02em]',
								tone
							) }
						>
							{ headline }
						</span>
					</p>

					<p className="mt-4 mb-0 max-w-[52ch] text-[13px] leading-relaxed text-muted-foreground">
						{ summary }
					</p>

					{ failing && lastError && (
						<p className="mt-3 mb-0 max-w-[52ch] rounded-md border border-danger/30 bg-danger/5 px-3 py-2 text-[13px] leading-relaxed text-danger">
							{ lastError }
						</p>
					) }
				</div>

				<div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
					<Stat
						label={ __( 'Queued for retry', 'mme-mail-to-smtp' ) }
						value={ queue.pending }
						tone="warning"
						icon={ Clock }
						help={ __( 'Retried every five minutes.', 'mme-mail-to-smtp' ) }
					/>
					<Stat
						label={ __( 'Never delivered', 'mme-mail-to-smtp' ) }
						value={ queue.failed }
						tone="danger"
						icon={ XCircle }
						help={ __(
							'Out of attempts. Kept until the discard window passes.',
							'mme-mail-to-smtp'
						) }
					/>
				</div>
			</div>
		</Panel>
	);
};

/**
 * The way back into the wizard.
 *
 * Shown while nothing is configured, which is the only time it is the most
 * useful thing on the screen - a site that is sending has no use for a banner
 * about setting up sending. It is a card rather than a notice because it is an
 * offer, not a warning: the state it describes is what a fresh install looks
 * like, not a fault.
 */
const SetupCallout = () => (
	<Panel className="border-brand/35 bg-brand-subtle/40">
		<div className="flex flex-wrap items-center gap-x-6 gap-y-4">
			<span className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl border border-brand/30 bg-card text-brand-deep">
				<Wand2 className="size-4" />
			</span>

			<div className="min-w-0 flex-1">
				<p className="m-0 font-display text-[17px] leading-none tracking-[-0.01em]">
					{ __( 'Nothing is sending yet', 'mme-mail-to-smtp' ) }
				</p>
				<p className="mt-2 mb-0 max-w-[62ch] text-[13px] leading-relaxed text-muted-foreground">
					{ __(
						'WordPress is still using the server mail function. The wizard connects a mailbox, checks the credentials against the provider and sends one real message, in that order.',
						'mme-mail-to-smtp'
					) }
				</p>
			</div>

			<Button asChild variant="brand" size="lg" className="shrink-0">
				<Link to="/setup">
					{ __( 'Run the setup wizard', 'mme-mail-to-smtp' ) }
					<ArrowRight />
				</Link>
			</Button>
		</div>
	</Panel>
);

const Dashboard = () => {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'dashboard' ],
		queryFn: getDashboard,
	} );

	// Already in the cache: the shell fetches it before any screen renders, so
	// this reads the answer rather than asking again.
	const { data: bootstrap } = useQuery( { queryKey: [ 'bootstrap' ] } );

	if ( isLoading ) {
		return <Spinner />;
	}

	const { queue, health } = data;

	return (
		<div className="stagger grid gap-6">
			{ bootstrap?.setup?.recommended && <SetupCallout /> }

			<Hero health={ health } queue={ queue } />

			<Panel
				title={ __( 'Where to look next', 'mme-mail-to-smtp' ) }
				description={ __(
					'This plugin keeps no copy of what it sent. A failure is reported the moment it happens, through wp_mail() and the checks below.',
					'mme-mail-to-smtp'
				) }
			>
				<ul className="m-0 grid list-none gap-3 p-0 text-[13px]">
					<li className="flex flex-wrap items-baseline gap-x-2">
						<Link
							to="/connections"
							className="text-brand-deep no-underline hover:underline"
						>
							{ __( 'Connections', 'mme-mail-to-smtp' ) }
						</Link>
						<span className="text-muted-foreground">
							{ __(
								'send a test message and confirm the credentials still work.',
								'mme-mail-to-smtp'
							) }
						</span>
					</li>
					<li className="flex flex-wrap items-baseline gap-x-2">
						<span className="font-medium">
							{ __( 'Tools, Site Health', 'mme-mail-to-smtp' ) }
						</span>
						<span className="text-muted-foreground">
							{ __(
								'the Email delivery check reports whether sending is working, and names the last error.',
								'mme-mail-to-smtp'
							) }
						</span>
					</li>
					<li className="flex flex-wrap items-baseline gap-x-2">
						<Link
							to="/settings"
							className="text-brand-deep no-underline hover:underline"
						>
							{ __( 'Settings', 'mme-mail-to-smtp' ) }
						</Link>
						<span className="text-muted-foreground">
							{ __(
								'how many failures in a row count as an outage, and how long the retry queue holds a message.',
								'mme-mail-to-smtp'
							) }
						</span>
					</li>
				</ul>
			</Panel>
		</div>
	);
};

export default Dashboard;
