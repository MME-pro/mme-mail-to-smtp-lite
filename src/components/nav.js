import { __ } from '@wordpress/i18n';
import { NavLink, useLocation } from 'react-router-dom';
import {
	LayoutDashboard,
	Plug,
	Settings2,
	Puzzle,
	TriangleAlert,
	CircleCheck,
	Clock,
	Sun,
	Moon,
} from 'lucide-react';
import { cn } from '../lib/utils';
import { useTheme } from '../lib/use-theme';
import extensions from '../lib/extensions';
import Guilloche from './guilloche';

/*
 * Settings stays last. An add-on's screens go between Connections and
 * Settings rather than after it, because Settings reads as the end of the row
 * - anything past it looks like an afterthought, and on a site with the
 * add-on installed these are not.
 *
 * An add-on may bring its own icon; the puzzle piece is what it gets if it
 * does not, and is deliberately plain. This plugin does not style somebody
 * else's screen, it only gives it a place to be.
 */
const TABS = [
	{ to: '/dashboard', label: __( 'Dashboard', 'mme-mail-to-smtp' ), icon: LayoutDashboard },
	{ to: '/connections', label: __( 'Connections', 'mme-mail-to-smtp' ), icon: Plug },
	...extensions.map( ( { path, label, icon } ) => ( {
		to: path,
		label,
		icon: icon || Puzzle,
	} ) ),
	{ to: '/settings', label: __( 'Settings', 'mme-mail-to-smtp' ), icon: Settings2 },
];

/**
 * One line that says whether mail is arriving.
 *
 * In the header rather than on the dashboard, deliberately. The failure this
 * plugin exists to prevent is email quietly not being delivered, so its state
 * belongs on every screen - not on one an admin has to think to visit.
 */
const statusOf = ( health, queue ) => {
	if ( ! health ) {
		return null;
	}

	if ( ! health.active ) {
		return {
			tone: 'warning',
			icon: TriangleAlert,
			text: __( 'No provider configured', 'mme-mail-to-smtp' ),
			detail: __( 'WordPress is using the server mail function.', 'mme-mail-to-smtp' ),
		};
	}

	if ( health.failing ) {
		return {
			tone: 'danger',
			icon: TriangleAlert,
			text: __( 'Not delivering', 'mme-mail-to-smtp' ),
			detail: health.last_error || '',
		};
	}

	if ( queue?.failed > 0 ) {
		return {
			tone: 'danger',
			icon: TriangleAlert,
			text: __( 'Mail was lost', 'mme-mail-to-smtp' ),
			detail: __( 'Some messages exhausted every retry.', 'mme-mail-to-smtp' ),
		};
	}

	if ( queue?.pending > 0 ) {
		return {
			tone: 'warning',
			icon: Clock,
			text: __( 'Queued for retry', 'mme-mail-to-smtp' ),
			detail: __( 'Nothing is lost, but sending is not healthy.', 'mme-mail-to-smtp' ),
		};
	}

	return {
		tone: 'success',
		icon: CircleCheck,
		text: __( 'Sending normally', 'mme-mail-to-smtp' ),
		detail: '',
	};
};

/*
 * Status on the ink band needs its own values. The semantic tokens are tuned
 * for contrast against a white sheet; on near-black they sit far too dark.
 */
const TONE = {
	success: 'text-[#8fe3ad]',
	warning: 'text-[#fdc85a]',
	danger: 'text-[#ff9a8f]',
};

const DOT = {
	success: 'bg-[#47cd89]',
	warning: 'bg-[#fdb022]',
	danger: 'bg-[#f97066]',
};

/**
 * The tab row. Pills, the active one lit - the same control as the website's
 * header, so the plugin and the site read as one product.
 */
const Tabs = () => {
	const { pathname } = useLocation();
	const active = Math.max(
		0,
		TABS.findIndex( ( tab ) => pathname.startsWith( tab.to ) )
	);

	return (
		<div
			className="relative -mb-px flex gap-1 overflow-x-auto ink-scroll"
		>
			{ TABS.map( ( { to, label, icon: Icon }, i ) => (
				<NavLink
					key={ to }
					to={ to }
					className={ cn(
						'group relative mb-3 inline-flex shrink-0 items-center gap-2 rounded-full px-4 py-2',
						'text-[13px] font-semibold no-underline',
						'transition-colors duration-300',
						i === active
							? 'bg-white/[0.08] text-ink-foreground shadow-[inset_0_1px_0_rgb(255_255_255/0.08)]'
							: 'text-ink-muted hover:bg-white/[0.04] hover:text-ink-foreground'
					) }
				>
					<Icon
						className={ cn(
							'size-4 transition-transform duration-200',
							i === active
								? 'scale-105 text-brand'
								: 'opacity-70 group-hover:opacity-100'
						) }
					/>
					{ label }
				</NavLink>
			) ) }
		</div>
	);
};

const ThemeToggle = () => {
	const { theme, toggle } = useTheme();
	const dark = theme === 'dark';

	return (
		<button
			type="button"
			onClick={ toggle }
			aria-pressed={ dark }
			aria-label={
				dark
					? __( 'Switch to light', 'mme-mail-to-smtp' )
					: __( 'Switch to dark', 'mme-mail-to-smtp' )
			}
			className={ cn(
				'inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-full',
				'border border-ink-line bg-transparent text-ink-muted',
				'transition-colors duration-200 hover:border-brand/50 hover:text-brand'
			) }
		>
			{ dark ? <Moon className="size-4" /> : <Sun className="size-4" /> }
		</button>
	);
};

/**
 * The header band.
 *
 * `focused` is what the setup wizard puts it in. A wizard with the full tab row
 * above it is a wizard offering five ways out of itself on every step, and the
 * sending status belongs to a site that is already configured - during setup it
 * says "no provider configured" about the very thing being configured, which
 * reads as an error rather than as a starting point.
 *
 * The band itself stays, wordmark and all. Stripping the chrome entirely would
 * make the wizard look like a different piece of software from the one it is
 * setting up.
 */
const Nav = ( { health, queue, focused = false } ) => {
	const status = focused ? null : statusOf( health, queue );
	const StatusIcon = status?.icon;

	return (
		<header className="relative overflow-hidden bg-ink text-ink-foreground">
			<div aria-hidden="true" className="ink-glow pointer-events-none absolute inset-0" />
			<div aria-hidden="true" className="ink-grid pointer-events-none absolute inset-0" />
			{ /* THE SIGNATURE. Engine-turned rosette in brass, pinned right so
			     it reads as a watermark on the band rather than a halo behind
			     the wordmark. */ }
			<Guilloche
				id="mmoa-guilloche-header"
				className="pointer-events-none absolute -top-[420px] -right-[180px] h-[900px] w-[900px] text-brand opacity-[0.08]"
			/>

			{ /* The join between ink and paper, and the only rule in the chrome. */ }
			<div
				aria-hidden="true"
				className="absolute inset-x-0 bottom-0 h-px bg-linear-to-r from-transparent via-brand/45 to-transparent"
			/>

			<div className="relative px-6 pt-6 sm:px-8">
				<div className="flex flex-wrap items-start gap-x-6 gap-y-3">
					<div className="flex min-w-0 items-center gap-3.5">
						<span
							aria-hidden="true"
							className="grid size-11 shrink-0 place-items-center rounded-[14px] bg-[linear-gradient(140deg,#8fd39a_0%,#15803d_55%,#0c4f25_100%)] shadow-[inset_0_1px_0_rgb(255_255_255/0.35),0_10px_24px_-10px_rgb(21_128_61/0.9)]"
						>
							<svg width="22" height="22" viewBox="0 0 32 32" fill="none">
								<path d="M7 23V9.5l9 8 9-8V23" stroke="#fff" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
							</svg>
						</span>
						<div className="min-w-0">
							<h1 className="font-display text-[22px] leading-none text-ink-foreground">
								{ __( 'MME-Mail to SMTP', 'mme-mail-to-smtp' ) }
							</h1>
							<p className="mt-2 mb-0 flex items-center gap-2 text-xs text-ink-muted">
								{ focused
									? __( 'Guided setup', 'mme-mail-to-smtp' )
									: __( 'Authenticated delivery', 'mme-mail-to-smtp' ) }
								<span className="rounded-full border border-white/10 bg-white/[0.05] px-2 py-0.5 font-semibold text-ink-foreground/80">
									v{ window.mmoa?.version }
								</span>
							</p>
						</div>
					</div>

					<div className="ml-auto flex items-start gap-4">
						{ status && (
							<div className="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.04] py-2 pr-4 pl-3 text-left shadow-[inset_0_1px_0_rgb(255_255_255/0.06)] backdrop-blur">
								<span className="relative grid size-7 shrink-0 place-items-center">
									{ status.tone === 'success' && (
										<span className={ cn( 'pulse-ring absolute inset-1 rounded-full', DOT[ status.tone ] ) } />
									) }
									<StatusIcon className={ cn( 'relative size-4', TONE[ status.tone ] ) } />
								</span>
								<div className="min-w-0">
									<p
										className={ cn(
											'm-0 text-[13px] leading-tight font-semibold',
											TONE[ status.tone ]
										) }
									>
										{ status.text }
									</p>
									{ status.detail && (
										<p className="m-0 max-w-[42ch] truncate text-xs text-ink-muted">
											{ status.detail }
										</p>
									) }
								</div>
							</div>
						) }

						<ThemeToggle />
					</div>
				</div>

				{ /* The band keeps its height in focused mode rather than
				     collapsing to the wordmark, so moving in and out of the
				     wizard does not shift the whole page up and down. */ }
				<div className={ focused ? 'h-6' : 'mt-7' }>{ ! focused && <Tabs /> }</div>
			</div>
		</header>
	);
};

export default Nav;
