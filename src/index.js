/**
 * Order Pilot — React Admin App Entry Point
 *
 * @package OrderPilot
 */

import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './App';
import './styles/admin.css';

// apiFetch middleware is automatically configured via wpApiSettings localization in class-odrplt-admin.php.

const root = document.getElementById( 'order-pilot-root' );

if ( root ) {
	createRoot( root ).render( <App /> );
}
