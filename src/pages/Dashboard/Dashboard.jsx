/**
 * Dashboard Page
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const { currencySymbol, restUrl, restNonce } = window.orderPilot || {};

apiFetch.use( apiFetch.createRootURLMiddleware( restUrl ) );
apiFetch.use( apiFetch.createNonceMiddleware( restNonce ) );

export default function Dashboard() {
	const [ stats, setStats ] = useState( null );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		apiFetch( { path: 'order-pilot/v1/dashboard' } )
			.then( ( data ) => setStats( data ) )
			.catch( () => {} )
			.finally( () => setLoading( false ) );
	}, [] );

	return (
		<div>
			<div className="op-page-header">
				<h1>📊 Dashboard</h1>
				<p>Your WooCommerce COD automation overview</p>
			</div>

			{ loading ? (
				<div className="op-loading-screen"><span className="op-spinner" /></div>
			) : (
				<>
					<div className="op-stats-grid">
						<StatCard
							label="Orders (This Month)"
							value={ stats?.total_orders ?? 0 }
							icon="🛒"
							variant="primary"
						/>
						<StatCard
							label="Consignments"
							value={ stats?.total_consignments ?? 0 }
							icon="🚚"
							variant="primary"
						/>
						<StatCard
							label="Delivered"
							value={ stats?.delivered ?? 0 }
							icon="✅"
							variant="success"
						/>
						<StatCard
							label="Delivery Rate"
							value={ `${ stats?.delivery_rate ?? 0 }%` }
							icon="📈"
							variant="success"
						/>
						<StatCard
							label="Couriers Active"
							value={ stats?.connected_couriers ?? 0 }
							icon="🔗"
							variant="warning"
						/>
					</div>

					<div className="op-card">
						<div className="op-card-header">
							<h3 className="op-card-title">Quick Actions</h3>
						</div>
						<div style={ { display: 'flex', gap: '12px', flexWrap: 'wrap' } }>
							<a
								href="?page=order-pilot-couriers"
								className="op-btn op-btn--primary"
							>
								🚚 Manage Couriers
							</a>
							<a
								href="?page=order-pilot-settings"
								className="op-btn op-btn--secondary"
							>
								⚙️ Settings
							</a>
							<a
								href="?page=order-pilot-logs"
								className="op-btn op-btn--secondary"
							>
								📋 View Logs
							</a>
						</div>
					</div>
				</>
			) }
		</div>
	);
}

function StatCard( { label, value, icon, variant = 'primary' } ) {
	return (
		<div className={ `op-stat-card op-stat-card--${ variant }` }>
			<div className="op-stat-card__label">{ label }</div>
			<div className="op-stat-card__value">{ value }</div>
			<div className="op-stat-card__icon">{ icon }</div>
		</div>
	);
}
