import { useEffect } from '@wordpress/element';
import { HashRouter, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { getBootstrap } from './api/client';
import Nav from './components/nav';
import Footer from './components/footer';
import { Spinner } from './components/ui';
import { ToastProvider, useToast } from './components/toast';
import Dashboard from './screens/dashboard';
import Connections from './screens/connections';
import Logs from './screens/logs';
import Routing from './screens/routing';
import Settings from './screens/settings';
import Setup from './screens/setup';

const queryClient = new QueryClient( {
	defaultOptions: {
		queries: {
			refetchOnWindowFocus: false,
			retry: 1,
			staleTime: 10_000,
		},
	},
} );

/**
 * Surface the result of a flow that left the site and came back.
 *
 * The Google handshake is a full page navigation, so the app is remounted with
 * no memory of it. The server puts the outcome in the query string; this shows
 * it once and then strips it, so a refresh does not repeat a stale message.
 */
const useRedirectMessage = () => {
	const toast = useToast();

	useEffect( () => {
		const params = new URLSearchParams( window.location.search );
		const message = params.get( 'mmoa_msg' );

		if ( ! message ) {
			return;
		}

		toast( decodeURIComponent( message ), params.get( 'mmoa_status' ) === 'error' ? 'bad' : 'ok' );

		params.delete( 'mmoa_msg' );
		params.delete( 'mmoa_status' );

		window.history.replaceState(
			{},
			'',
			`${ window.location.pathname }?${ params.toString() }${ window.location.hash }`
		);
	}, [ toast ] );
};

const Shell = () => {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'bootstrap' ],
		queryFn: getBootstrap,
	} );

	useRedirectMessage();

	// The wizard is a sequence rather than a destination, so it gets the band
	// without the tab row: five ways out of a five-step flow is not navigation,
	// it is an invitation to abandon it halfway.
	const { pathname } = useLocation();
	const focused = pathname.startsWith( '/setup' );

	return (
		<div id="mmoa-app">
			<Nav health={ data?.health } queue={ data?.queue } focused={ focused } />

			{ /* Full width on purpose. The header band already spans the whole admin
				     area, so capping the content left a growing empty gutter on wide
				     screens - while the log table and the provider grid were exactly
				     the things that wanted the room. Line length is still held where
				     it actually matters: on the paragraphs, per component. */ }
				<main
					className={
						focused
							? 'w-full flex-1 px-6 py-10 sm:px-8'
							: 'w-full flex-1 px-6 py-8 sm:px-8'
					}
				>
				{ isLoading ? (
					<Spinner />
				) : (
					<Routes>
						<Route path="/setup" element={ <Setup /> } />
						<Route path="/dashboard" element={ <Dashboard /> } />
						<Route path="/connections" element={ <Connections /> } />
						<Route path="/routing" element={ <Routing /> } />
						<Route path="/logs" element={ <Logs /> } />
						<Route path="/settings" element={ <Settings /> } />
						<Route
							path="*"
							element={ <Navigate to="/dashboard" replace /> }
						/>
					</Routes>
				) }
			</main>

			<Footer />
		</div>
	);
};

const App = () => (
	<QueryClientProvider client={ queryClient }>
		<ToastProvider>
			<HashRouter>
				<Shell />
			</HashRouter>
		</ToastProvider>
	</QueryClientProvider>
);

export default App;
