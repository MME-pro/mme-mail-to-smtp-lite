import { __ } from '@wordpress/i18n';
import { Check, Lock } from 'lucide-react';
import { cn } from '../lib/utils';
import ProviderLogo from './provider-logo';

/**
 * The provider chooser.
 *
 * One wrapping row rather than grouped sections. With a dozen providers the
 * grouping added three headings and three grids to scan before you could find
 * the one you already knew you wanted; a single field of marks is faster to
 * search, and the logo does the identifying that a heading used to.
 *
 * Shared by the connections screen and the setup wizard, which ask the same
 * question and so should not be able to answer it differently.
 *
 * `locked` are the connections only Pro provides, shown where they would sit
 * so they can be found. Choosing one does not select it - there is nothing
 * behind it here - it calls `onLocked`, which explains where it comes from.
 * They go above "Other SMTP" for the same reason every named service does:
 * the generic fallback belongs at the end.
 */
const ProviderPicker = ( { providers, selected, onSelect, locked = [], onLocked, className } ) => {
	const tiles = providers.map( ( provider ) => ( { ...provider, isLocked: false } ) );
	const extra = locked.map( ( tile ) => ( { ...tile, isLocked: true } ) );
	const at = tiles.findIndex( ( tile ) => 'smtp' === tile.slug );

	tiles.splice( -1 === at ? tiles.length : at, 0, ...extra );

	return (
		<div className={ cn( 'flex flex-wrap gap-2.5', className ) }>
			{ tiles.map( ( provider ) => {
				const active = ! provider.isLocked && selected === provider.slug;

				return (
					<button
						key={ provider.slug }
						type="button"
						title={ provider.summary }
						aria-pressed={ provider.isLocked ? undefined : active }
						onClick={ () =>
							provider.isLocked ? onLocked?.( provider ) : onSelect( provider.slug )
						}
						className={ cn(
							'group relative flex w-[132px] cursor-pointer flex-col items-center gap-2.5 rounded-xl border p-4 text-center transition-all',
							active && 'border-brand bg-brand-subtle ring-1 ring-brand',
							! active && 'border-border bg-card hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-sm'
						) }
					>
						<ProviderLogo
							slug={ provider.slug }
							className={ cn( 'size-8', provider.isLocked && 'opacity-60 grayscale-[35%]' ) }
						/>

						<span
							className={ cn(
								'text-[13px] leading-tight font-medium',
								provider.isLocked && 'text-muted-foreground'
							) }
						>
							{ provider.label }
						</span>

						{ provider.isLocked && (
							<span className="absolute top-2 right-2 inline-flex items-center gap-1 rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
								<Lock className="size-2.5" strokeWidth={ 2.5 } />
								{ __( 'Pro', 'mme-mail-to-smtp' ) }
							</span>
						) }

						{ active && (
							<span className="absolute top-2 right-2 inline-flex size-4 items-center justify-center rounded-full bg-brand text-brand-foreground">
								<Check className="size-2.5" strokeWidth={ 3 } />
							</span>
						) }
					</button>
				);
			} ) }
		</div>
	);
};

export default ProviderPicker;
