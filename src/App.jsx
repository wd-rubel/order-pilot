/**
 * Order Pilot — Root App Component
 *
 * Reads the `data-page` attribute from the root div to determine
 * which page to render, acting as a lightweight SPA router.
 *
 * @package OrderPilot
 */

import { useState, useEffect } from '@wordpress/element';
import Layout from './components/Layout/Layout';
import Dashboard from './pages/Dashboard/Dashboard';
import Orders from './pages/Orders/Orders';
import Couriers from './pages/Couriers/Couriers';
import Tracking from './pages/Tracking/Tracking';
import Logs from './pages/Logs/Logs';
import Settings from './pages/Settings/Settings';
import ProGate from './pages/ProGate/ProGate';

const { isPro } = window.orderPilot || {};

/**
 * Route map: page slug → component
 */
const ROUTES = {
	'order-pilot':           Dashboard,
	'order-pilot-orders':    Orders,
	'order-pilot-couriers':  Couriers,
	'order-pilot-tracking':  Tracking,
	'order-pilot-logs':      Logs,
	'order-pilot-settings':  Settings,
	// Pro-gated pages — show ProGate when not licensed.
	'order-pilot-fraud':     isPro ? () => <div>Fraud Checker</div> : ProGate,
	'order-pilot-analytics': isPro ? () => <div>Analytics</div>     : ProGate,
};

export default function App() {
	const [ currentPage, setCurrentPage ] = useState( () => {
		const root = document.getElementById( 'order-pilot-root' );
		return root ? root.getAttribute( 'data-page' ) : 'order-pilot';
	} );

	// Listen to WordPress admin menu clicks (hash-based navigation fallback).
	useEffect( () => {
		const handleNavigation = () => {
			const params = new URLSearchParams( window.location.search );
			const page = params.get( 'page' );
			if ( page && page !== currentPage ) {
				setCurrentPage( page );
			}
		};

		window.addEventListener( 'popstate', handleNavigation );
		return () => window.removeEventListener( 'popstate', handleNavigation );
	}, [ currentPage ] );

	const PageComponent = ROUTES[ currentPage ] || Dashboard;

	return (
		<Layout currentPage={ currentPage }>
			<PageComponent />
		</Layout>
	);
}
