/**
 * Order Pilot — React Admin App Entry Point
 *
 * @package OrderPilot
 */

import { createRoot } from '@wordpress/element';
import App from './App';
import './styles/admin.css';

const root = document.getElementById( 'order-pilot-root' );

if ( root ) {
	createRoot( root ).render( <App /> );
}
