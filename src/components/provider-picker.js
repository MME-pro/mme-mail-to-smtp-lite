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

			return (
				<button
					key={ provider.slug }
					type="button"
					title={ provider.summary }
					aria-pressed={ active }
					onClick={ () => onSelect( provider.slug ) }
					className={ cn(
						'group relative flex w-[132px] cursor-pointer flex-col items-center gap-2.5 rounded-xl border p-4 text-center transition-all',
						active && 'border-brand bg-brand-subtle ring-1 ring-brand',
						! active && 'border-border bg-card hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-sm'
					) }
				>
					<ProviderLogo slug={ provider.slug } className="size-8" />

					<span className="text-[13px] leading-tight font-medium">
						{ provider.label }
					</span>

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
