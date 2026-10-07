import * as TabsPrimitive from '@radix-ui/react-tabs';
import { cn } from '../../lib/utils';

function Tabs( { className, ...props } ) {
	return (
		<TabsPrimitive.Root
			data-slot="tabs"
			className={ cn( 'flex flex-col gap-4', className ) }
			{ ...props }
		/>
	);
}

function TabsList( { className, ...props } ) {
	return (
		<TabsPrimitive.List
			data-slot="tabs-list"
			className={ cn(
				'bg-muted text-muted-foreground inline-flex h-10 w-fit items-center justify-center rounded-full p-1',
				className
			) }
			{ ...props }
		/>
	);
}

function TabsTrigger( { className, ...props } ) {
	return (
		<TabsPrimitive.Trigger
			data-slot="tabs-trigger"
			className={ cn(
				"inline-flex h-full flex-1 items-center justify-center gap-1.5 rounded-full border border-transparent px-3.5 py-1 text-sm font-semibold whitespace-nowrap transition-[color,box-shadow,background-color] duration-300 cursor-pointer",
				'data-[state=active]:bg-card data-[state=active]:text-foreground data-[state=active]:shadow-sm dark:data-[state=active]:bg-brand dark:data-[state=active]:text-brand-foreground',
				'focus-visible:ring-ring/40 focus-visible:ring-[3px] outline-none',
				"disabled:pointer-events-none disabled:opacity-50 [&_svg:not([class*='size-'])]:size-4",
				className
			) }
			{ ...props }
		/>
	);
}

function TabsContent( { className, ...props } ) {
	return (
		<TabsPrimitive.Content
			data-slot="tabs-content"
			className={ cn( 'flex-1 outline-none', className ) }
			{ ...props }
		/>
	);
}

export { Tabs, TabsList, TabsTrigger, TabsContent };
