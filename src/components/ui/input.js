import { cn } from '../../lib/utils';

function Input( { className, type = 'text', ...props } ) {
	return (
		<input
			type={ type }
			data-slot="input"
			className={ cn(
				'border-input placeholder:text-muted-foreground/70 flex h-10 w-full min-w-0 rounded-xl border bg-field px-3.5 py-1 text-sm shadow-xs transition-[color,box-shadow,border-color] outline-none',
				'focus-visible:border-ring focus-visible:ring-ring/40 focus-visible:ring-[3px]',
				'disabled:cursor-not-allowed disabled:opacity-60 disabled:bg-muted',
				'aria-invalid:border-danger aria-invalid:ring-danger/20',
				className
			) }
			{ ...props }
		/>
	);
}

function Textarea( { className, ...props } ) {
	return (
		<textarea
			data-slot="textarea"
			className={ cn(
				'border-input placeholder:text-muted-foreground/70 flex min-h-16 w-full rounded-xl border bg-field px-3.5 py-2.5 text-sm shadow-xs transition-[color,box-shadow,border-color] outline-none',
				'focus-visible:border-ring focus-visible:ring-ring/40 focus-visible:ring-[3px]',
				'disabled:cursor-not-allowed disabled:opacity-60 disabled:bg-muted',
				className
			) }
			{ ...props }
		/>
	);
}

export { Input, Textarea };
