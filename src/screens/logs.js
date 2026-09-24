import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { Inbox, RotateCw, Undo2, Trash2, Search, X, ChevronLeft, ChevronRight } from 'lucide-react';
import { getLogs, getQueue, queueAction } from '../api/client';
import LogDetail from '../components/log-detail';
import { useToast } from '../components/toast';
import { Panel, Button, Badge, Spinner, EmptyState, inputClass } from '../components/ui';

/**
 * The queue sits above the log on purpose.
 *
 * The log is history; the queue is mail that has not arrived yet. An admin
 * opening this screen during an incident needs the second thing first, and
 * should not have to scroll past a hundred successful sends to find it.
 */
const QueueSection = () => {
	const toast = useToast();
	const queryClient = useQueryClient();

	const { data, isLoading } = useQuery( { queryKey: [ 'queue' ], queryFn: getQueue } );

	const act = useMutation( {
		mutationFn: queueAction,
		onSuccess: ( result ) => {
			toast( result.message );
			queryClient.invalidateQueries( { queryKey: [ 'queue' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'logs' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	if ( isLoading ) {
		return <Spinner />;
	}

	const { stats, entries, enabled } = data;
	const empty = stats.pending === 0 && stats.failed === 0;

	return (
		<Panel
			title={ __( 'Retry queue', 'modern-mailer-oauth' ) }
			description={
				enabled
					? __(
							'Failed for a temporary reason, waiting to be retried.',
							'modern-mailer-oauth'
					  )
					: __(
							'Switched off. A send that fails for a temporary reason is reported and discarded rather than retried.',
							'modern-mailer-oauth'
					  )
			}
			actions={
				! empty && (
					<>
						<Button busy={ act.isPending } onClick={ () => act.mutate( 'drain' ) }>
							<RotateCw size={ 14 } />
							{ __( 'Retry now', 'modern-mailer-oauth' ) }
						</Button>
						{ stats.failed > 0 && (
							<Button
								busy={ act.isPending }
								onClick={ () => act.mutate( 'requeue' ) }
							>
								<Undo2 size={ 14 } />
								{ __( 'Return abandoned', 'modern-mailer-oauth' ) }
							</Button>
						) }
						<Button
							variant="danger"
							busy={ act.isPending }
							onClick={ () => {
								// eslint-disable-next-line no-alert
								if (
									window.confirm(
										__(
											'This permanently deletes every queued message, including any still waiting to be delivered. Continue?',
											'modern-mailer-oauth'
										)
									)
								) {
									act.mutate( 'purge' );
								}
							} }
						>
							<Trash2 size={ 14 } />
							{ __( 'Discard all', 'modern-mailer-oauth' ) }
						</Button>
					</>
				)
			}
		>
			{ empty ? (
				<EmptyState
					icon={ Inbox }
					title={ __( 'Nothing waiting', 'modern-mailer-oauth' ) }
				>
					{ __(
						'Every message has been delivered or reported.',
						'modern-mailer-oauth'
					) }
				</EmptyState>
			) : (
				<>
					{ stats.failed > 0 && (
						<p className="flex items-start gap-2 p-3 mb-4 rounded-lg bg-danger-subtle text-[13px] text-foreground m-0">
							<span className="font-semibold text-danger">
								{ sprintf(
									/* translators: %d: number of abandoned messages. */
									__( '%d never delivered.', 'modern-mailer-oauth' ),
									stats.failed
								) }
							</span>
							{ __(
								'These ran out of retries. Fix the cause, then return them to the queue.',
								'modern-mailer-oauth'
							) }
						</p>
					) }

					<Table
						columns={ [
							__( 'Queued', 'modern-mailer-oauth' ),
							__( 'To', 'modern-mailer-oauth' ),
							__( 'Subject', 'modern-mailer-oauth' ),
							__( 'Tries', 'modern-mailer-oauth' ),
							__( 'Next attempt', 'modern-mailer-oauth' ),
							__( 'Last error', 'modern-mailer-oauth' ),
						] }
						rows={ entries.map( ( row ) => [
							<span key="d" className="font-mono text-muted-foreground">
								{ row.created_at.slice( 5, 16 ) }
							</span>,
							row.recipients,
							row.subject || __( '(no subject)', 'modern-mailer-oauth' ),
							<span key="a" className="tabular-nums">
								{ row.attempts }
							</span>,
							row.status === 'failed' ? (
								<Badge key="s" variant="danger">
									{ __( 'Abandoned', 'modern-mailer-oauth' ) }
								</Badge>
							) : (
								<span key="s" className="font-mono text-muted-foreground">
									{ row.next.slice( 5, 16 ) }
								</span>
							),
							<span key="e" className="text-danger">
								{ row.error }
							</span>,
						] ) }
					/>
				</>
			) }
		</Panel>
	);
};

const Table = ( { columns, rows, onRowClick } ) => (
	<div className="overflow-x-auto -mx-5 px-5">
		<table className="w-full border-collapse text-[13px]">
			<thead>
				<tr>
					{ columns.map( ( c ) => (
						<th
							key={ c }
							className="text-left font-medium text-muted-foreground text-[12px] uppercase tracking-wide pb-2 border-b border-border whitespace-nowrap pr-4"
						>
							{ c }
						</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( cells, i ) => (
					<tr
						key={ i }
						// A row is only interactive where the caller gave it
						// something to do, so the queue table below is
						// unaffected by any of this.
						{ ...( onRowClick
							? {
									onClick: () => onRowClick( i ),
									onKeyDown: ( e ) => {
										if ( 'Enter' === e.key || ' ' === e.key ) {
											e.preventDefault();
											onRowClick( i );
										}
									},
									tabIndex: 0,
									role: 'button',
									className:
										'border-b border-border last:border-0 cursor-pointer transition-colors hover:bg-muted/50 focus-visible:outline-none focus-visible:bg-muted/50',
							  }
							: { className: 'border-b border-border last:border-0' } ) }
					>
						{ cells.map( ( cell, j ) => (
							<td
								key={ j }
								className="py-2.5 pr-4 align-top text-foreground max-w-[280px] truncate"
							>
								{ cell }
							</td>
						) ) }
					</tr>
				) ) }
			</tbody>
		</table>
	</div>
);

const PAGE_SIZES = [ 10, 25, 50, 100 ];

/**
 * The log's footer: how many rows, which page, and how to get to another one.
 *
 * The count is the part people actually use. "Showing 1-25 of 3,140" answers
 * the question an admin opens this screen with - how much mail is there - and
 * it is the one number a client-side list could never show honestly, because
 * it only ever knew about the rows it had already fetched.
 */
const Pagination = ( { page, pages, total, perPage, onPage, onPerPage, busy } ) => {
	// The jump box is its own state so typing "12" does not fire a request at
	// "1". It resyncs whenever the page changes underneath it - including when
	// the server clamps a request for page 400 down to the last page there is.
	const [ jump, setJump ] = useState( String( page ) );

	useEffect( () => setJump( String( page ) ), [ page ] );

	const first = total === 0 ? 0 : ( page - 1 ) * perPage + 1;
	const last = Math.min( page * perPage, total );

	const go = () => {
		const wanted = parseInt( jump, 10 );

		if ( Number.isNaN( wanted ) ) {
			setJump( String( page ) );
			return;
		}

		onPage( Math.max( 1, Math.min( wanted, pages ) ) );
	};

	return (
		<div className="flex flex-wrap items-center justify-between gap-3 pt-3 mt-1 border-t border-border">
			<div className="flex items-center gap-3">
				<label
					htmlFor="mmoa-log-per-page"
					className="flex items-center gap-1.5 text-[12px] text-muted-foreground whitespace-nowrap"
				>
					{ __( 'Rows per page', 'modern-mailer-oauth' ) }
					<select
						id="mmoa-log-per-page"
						value={ perPage }
						onChange={ ( e ) => onPerPage( Number( e.target.value ) ) }
						className={ `${ inputClass } h-7 w-[64px] py-0 text-[12px]` }
					>
						{ PAGE_SIZES.map( ( size ) => (
							<option key={ size } value={ size }>
								{ size }
							</option>
						) ) }
					</select>
				</label>

				<span
					className="text-[12px] text-muted-foreground tabular-nums"
					aria-live="polite"
				>
					{ sprintf(
						/* translators: 1: first row on this page, 2: last row, 3: total rows. */
						__( 'Showing %1$d-%2$d of %3$d', 'modern-mailer-oauth' ),
						first,
						last,
						total
					) }
				</span>
			</div>

			<nav
				className="flex items-center gap-1.5"
				aria-label={ __( 'Send log pages', 'modern-mailer-oauth' ) }
			>
				<Button
					variant="secondary"
					disabled={ page <= 1 || busy }
					onClick={ () => onPage( page - 1 ) }
					aria-label={ __( 'Previous page', 'modern-mailer-oauth' ) }
				>
					<ChevronLeft size={ 14 } />
					{ __( 'Previous', 'modern-mailer-oauth' ) }
				</Button>

				<div className="flex items-center gap-1.5 px-1 text-[12px] text-muted-foreground whitespace-nowrap">
					<input
						id="mmoa-log-page"
						type="number"
						min="1"
						max={ pages }
						value={ jump }
						onChange={ ( e ) => setJump( e.target.value ) }
						onBlur={ go }
						onKeyDown={ ( e ) => e.key === 'Enter' && go() }
						aria-label={ __( 'Jump to page', 'modern-mailer-oauth' ) }
						className={ `${ inputClass } h-7 w-14 py-0 text-center text-[12px] tabular-nums` }
					/>
					{ sprintf(
						/* translators: %d: the number of pages in the log. */
						__( 'of %d', 'modern-mailer-oauth' ),
						pages
					) }
				</div>

				<Button
					variant="secondary"
					disabled={ page >= pages || busy }
					onClick={ () => onPage( page + 1 ) }
					aria-label={ __( 'Next page', 'modern-mailer-oauth' ) }
				>
					{ __( 'Next', 'modern-mailer-oauth' ) }
					<ChevronRight size={ 14 } />
				</Button>
			</nav>
		</div>
	);
};

const LogSection = () => {
	const [ filter, setFilter ] = useState( 'all' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ perPage, setPerPage ] = useState( 25 );

	// Typing is not a query. The box updates on every keystroke; the server
	// hears about it once the typing stops.
	const [ needle, setNeedle ] = useState( '' );

	useEffect( () => {
		const timer = setTimeout( () => setNeedle( search.trim() ), 300 );

		return () => clearTimeout( timer );
	}, [ search ] );

	// Narrowing the results moves the floor under the reader, so any change to
	// what is being asked for starts again at the first page. Without this,
	// searching from page 9 lands on page 9 of two results - an empty table
	// that looks like "nothing matches".
	useEffect( () => setPage( 1 ), [ needle, filter, perPage ] );

	// The log id whose report is open, or null. Held here rather than inside
	// the modal so the modal is unmounted when nothing is open, and its query
	// is never fired for an entry nobody asked about.
	const [ detail, setDetail ] = useState( null );

	const { data, isLoading, isFetching } = useQuery( {
		queryKey: [ 'logs', page, perPage, filter, needle ],
		queryFn: () =>
			getLogs( {
				page,
				perPage,
				status: filter === 'all' ? '' : filter,
				search: needle,
			} ),

		// Hold the page that is on screen while the next one loads. Without
		// this every page turn blanks the table to a spinner, which on a fast
		// connection reads as a flicker and on a slow one as a broken screen.
		placeholderData: keepPreviousData,
	} );

	if ( isLoading ) {
		return <Spinner />;
	}

	const rows = data.entries;

	if ( ! data.enabled ) {
		return (
			<Panel title={ __( 'Send log', 'modern-mailer-oauth' ) }>
				<EmptyState
					icon={ Inbox }
					title={ __( 'Logging is switched off', 'modern-mailer-oauth' ) }
				>
					{ __( 'Turn it on under Settings.', 'modern-mailer-oauth' ) }
				</EmptyState>
			</Panel>
		);
	}

	return (
		<Panel
			title={ __( 'Send log', 'modern-mailer-oauth' ) }
			description={ __(
				'Envelope details and errors only - message bodies are never stored here.',
				'modern-mailer-oauth'
			) }
			actions={
				<div className="flex items-center gap-2">
					<div className="relative">
						<Search
							size={ 14 }
							className="absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground/60 pointer-events-none"
						/>
						<input
							type="search"
							value={ search }
							onChange={ ( e ) => setSearch( e.target.value ) }
							placeholder={ __( 'Search', 'modern-mailer-oauth' ) }
							className={ `${ inputClass } pl-8 w-48` }
						/>
					</div>
					<div className="inline-flex p-0.5 bg-muted rounded-lg">
						{ [
							[ 'all', __( 'All', 'modern-mailer-oauth' ) ],
							[ 'sent', __( 'Sent', 'modern-mailer-oauth' ) ],
							[ 'failed', __( 'Failed', 'modern-mailer-oauth' ) ],
						].map( ( [ key, label ] ) => (
							<button
								key={ key }
								type="button"
								onClick={ () => setFilter( key ) }
								className={ `px-2.5 h-7 rounded-md text-[12px] font-medium border-0 cursor-pointer ${
									filter === key
										? 'bg-card text-foreground shadow-sm'
										: 'bg-transparent text-muted-foreground'
								}` }
							>
								{ label }
							</button>
						) ) }
					</div>
				</div>
			}
		>
			<div className={ isFetching ? 'opacity-60 transition-opacity' : 'transition-opacity' }>
			{ rows.length === 0 ? (
				<EmptyState
					icon={ search || filter !== 'all' ? X : Inbox }
					title={
						search || filter !== 'all'
							? __( 'Nothing matches', 'modern-mailer-oauth' )
							: __( 'Nothing recorded yet', 'modern-mailer-oauth' )
					}
				>
					{ search || filter !== 'all'
						? __( 'Try a different search or filter.', 'modern-mailer-oauth' )
						: __(
								'Once WordPress sends its first message it will appear here.',
								'modern-mailer-oauth'
						  ) }
				</EmptyState>
			) : (
				<Table
					columns={ [
						__( 'When', 'modern-mailer-oauth' ),
						__( 'Result', 'modern-mailer-oauth' ),
						__( 'Connection', 'modern-mailer-oauth' ),
						__( 'To', 'modern-mailer-oauth' ),
						__( 'Subject', 'modern-mailer-oauth' ),
					] }
					rows={ rows.map( ( row ) => [
						<span key="d" className="font-mono text-muted-foreground whitespace-nowrap">
							{ row.created_at.slice( 5, 16 ) }
						</span>,
						row.status === 'sent' ? (
							<Badge key="s" variant="success">
								{ __( 'Sent', 'modern-mailer-oauth' ) }
							</Badge>
						) : (
							<span key="s" title={ row.error }>
								<Badge variant="danger">{ __( 'Failed', 'modern-mailer-oauth' ) }</Badge>
							</span>
						),
						<span key="p" className="text-muted-foreground">
							{ row.provider }
						</span>,
						row.recipients,
						row.status === 'sent' ? (
							row.subject
						) : (
							<span key="x">
								<span className="block truncate">{ row.subject }</span>
								<span className="block text-[12px] text-danger truncate">
									{ row.error }
								</span>
							</span>
						),
					] ) }
					onRowClick={ ( i ) => setDetail( rows[ i ].id ) }
				/>
			) }
			</div>

			{ data.total > 0 && (
				<Pagination
					page={ data.page }
					pages={ data.pages }
					total={ data.total }
					perPage={ data.per_page }
					onPage={ setPage }
					onPerPage={ setPerPage }
					busy={ isFetching }
				/>
			) }

			<LogDetail id={ detail } onClose={ () => setDetail( null ) } />
		</Panel>
	);
};

const Logs = () => (
	<div className="grid gap-5">
		<QueueSection />
		<LogSection />
	</div>
);

export default Logs;
