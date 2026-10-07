import { Slot } from '@radix-ui/react-slot';
import { cva } from 'class-variance-authority';
import { Loader2 } from 'lucide-react';
import { cn } from '../../lib/utils';

/**
 * shadcn Button.
 *
 * `asChild` is what makes the OAuth control possible: the Google sign-in has to
 * be a real anchor, because it navigates the browser away rather than calling
 * the API. Radix's Slot merges these props onto whatever child is passed, so an
 * <a> gets the button's appearance without a <button> nested inside it - which
 * would be invalid markup and would break keyboard activation.
 */
const buttonVariants = cva(
	"inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full text-sm font-semibold transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/40 aria-disabled:pointer-events-none aria-disabled:opacity-50 no-underline",
	{
		variants: {
			variant: {
				default:
					'bg-primary text-primary-foreground shadow-sm hover:bg-primary/90 active:scale-[0.97] dark:shadow-[0_10px_30px_-12px_rgb(200_242_109/0.6),inset_0_1px_0_rgb(255_255_255/0.45)]',
				brand:
					'bg-brand text-brand-foreground shadow-[0_10px_30px_-12px_rgb(200_242_109/0.6),inset_0_1px_0_rgb(255_255_255/0.45)] hover:bg-brand/90 active:scale-[0.97]',
				destructive:
					'bg-destructive text-destructive-foreground shadow-sm hover:bg-destructive/90 active:scale-[0.98]',
				outline:
					'border bg-card shadow-xs hover:bg-muted hover:text-accent-foreground dark:border-white/12 dark:bg-white/[0.03] dark:hover:bg-white/[0.07]',
				secondary:
					'bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/70',
				ghost: 'hover:bg-muted hover:text-accent-foreground',
				link: 'text-brand-deep underline-offset-4 hover:underline',
			},
			size: {
				default: 'h-10 px-5 py-2 has-[>svg]:px-4',
				sm: 'h-8 gap-1.5 px-3.5 has-[>svg]:px-3 text-[13px]',
				lg: 'h-11 px-6 has-[>svg]:px-5',
				icon: 'size-10',
			},
		},
		defaultVariants: {
			variant: 'default',
			size: 'default',
		},
	}
);

function Button( {
	className,
	variant,
	size,
	asChild = false,
	busy = false,
	disabled,
	children,
	...props
} ) {
	const Comp = asChild ? Slot : 'button';

	return (
		<Comp
			data-slot="button"
			className={ cn( buttonVariants( { variant, size, className } ) ) }
			// A slotted child may be an anchor, which has no disabled attribute -
			// aria-disabled plus the pointer-events rule above is what stands in
			// for it there.
			{ ...( asChild
				? { 'aria-disabled': disabled || busy || undefined }
				: { type: 'button', disabled: disabled || busy } ) }
			{ ...props }
		>
			{ busy ? (
				<>
					<Loader2 className="animate-spin" />
					{ children }
				</>
			) : (
				children
			) }
		</Comp>
	);
}

export { Button, buttonVariants };
