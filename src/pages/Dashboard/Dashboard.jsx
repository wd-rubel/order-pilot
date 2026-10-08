/**
 * Dashboard Page
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const { currencySymbol } = window.orderPilot || {};

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
			<div className="odrplt-page-header">
				<h1>📊 Dashboard</h1>
				<p>Your WooCommerce COD automation overview</p>
			</div>

			{ loading ? (
				<div className="odrplt-loading-screen"><span className="odrplt-spinner" /></div>
			) : (
				<>
					<div className="odrplt-stats-grid">
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

					<div className="odrplt-card">
						<div className="odrplt-card-header">
							<h3 className="odrplt-card-title">Quick Actions</h3>
						</div>
						<div style={ { display: 'flex', gap: '12px', flexWrap: 'wrap' } }>
							<a
								href="?page=order-pilot-settings&tab=couriers"
								className="odrplt-btn odrplt-btn--primary"
							>
								🚚 Manage Couriers
							</a>
							<a
								href="?page=order-pilot-settings"
								className="odrplt-btn odrplt-btn--secondary"
							>
								⚙️ Settings
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
		<div className={ `odrplt-stat-card odrplt-stat-card--${ variant }` }>
			<div className="odrplt-stat-card__label">{ label }</div>
			<div className="odrplt-stat-card__value">{ value }</div>
			<div className="odrplt-stat-card__icon">{ icon }</div>
		</div>
	);
}
