import { __ } from '@wordpress/i18n';
import { Check } from 'lucide-react';
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
 */
const ProviderPicker = ( { providers, selected, onSelect, className } ) => (
	<div className={ cn( 'flex flex-wrap gap-2.5', className ) }>
		{ providers.map( ( provider ) => {
			const active = selected === provider.slug;

			// Shown, but not yet choosable. Never applied to whatever this
			// connection already uses: a site sending through one of these
			// must be able to open its settings, and greying out the tile it
			// is standing on would read as the connection being broken.
			const soon = !! provider.coming_soon && ! active;

			return (
				<button
					key={ provider.slug }
					type="button"
					title={ soon ? __( 'Coming soon', 'mme-mail-to-smtp' ) : provider.summary }
					aria-pressed={ active }
					aria-disabled={ soon }
					disabled={ soon }
					onClick={ () => ! soon && onSelect( provider.slug ) }
					className={ cn(
						'group relative flex w-[132px] flex-col items-center gap-2.5 rounded-xl border p-4 text-center transition-all',
						soon && 'cursor-not-allowed border-border bg-muted/40',
						! soon && 'cursor-pointer',
						active && 'border-brand bg-brand-subtle ring-1 ring-brand',
						! active && ! soon && 'border-border bg-card hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-sm'
					) }
				>
					<ProviderLogo
						slug={ provider.slug }
						className={ cn( 'size-8', soon && 'opacity-40 grayscale' ) }
					/>

					<span
						className={ cn(
							'text-[13px] leading-tight font-medium',
							soon && 'text-muted-foreground'
						) }
					>
						{ provider.label }
					</span>

					{ /* In the corner rather than along the bottom edge, which is
					     where this used to sit. Out of the flow it had to be -
					     in the flow it made the coming-soon tiles taller, and
					     because the row stretches them all to match, every
					     finished tile grew a band of empty space under its name
					     for a label it does not have. But pinned to the bottom
					     of a tile this short it landed on the provider's own
					     name: there is no gap between them to sit in.

					     The corner has one, and it is already the slot the
					     selected tick uses - which costs nothing, because a
					     tile cannot be both selected and unavailable.

					     Shortened to fit that corner. The whole phrase is still
					     the tooltip, and still what a screen reader is given. */ }
					{ soon && (
						<>
							<span className="absolute top-2 right-2 rounded-full border border-border bg-muted px-1.5 py-px text-[9px] leading-[1.4] font-medium tracking-wide text-muted-foreground uppercase">
								{ __( 'Soon', 'mme-mail-to-smtp' ) }
							</span>
							<span className="sr-only">
								{ __( 'Coming soon', 'mme-mail-to-smtp' ) }
							</span>
						</>
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

export default ProviderPicker;
