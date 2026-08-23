/**
 * Logs Page
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function Logs() {
	const [ tab, setTab ]         = useState( 'courier' );
	const [ logs, setLogs ]       = useState( [] );
	const [ loading, setLoading ] = useState( true );

	const fetchLogs = ( type ) => {
		setLoading( true );
		apiFetch( { path: `order-pilot/v1/logs/${ type }?limit=50` } )
			.then( setLogs )
			.finally( () => setLoading( false ) );
	};

	useEffect( () => { fetchLogs( tab ); }, [ tab ] );

	return (
		<div>
			<div className="op-page-header">
				<h1>📋 Logs</h1>
				<p>Courier API and tracking event logs for debugging.</p>
			</div>

			<div className="op-card">
				<div style={ { display: 'flex', gap: '4px', marginBottom: '16px' } }>
					{ [ { key: 'courier', label: '🚚 Courier Logs' }, { key: 'tracking', label: '📊 Tracking Logs' } ].map( ( t ) => (
						<button key={ t.key } onClick={ () => setTab( t.key ) } style={ {
							padding: '8px 14px',
							background: tab === t.key ? 'var(--op-primary-bg)' : 'none',
							border: '1px solid',
							borderColor: tab === t.key ? 'var(--op-primary)' : 'var(--op-border)',
							borderRadius: 'var(--op-radius-sm)',
							color: tab === t.key ? 'var(--op-primary)' : 'var(--op-text-2)',
							fontWeight: tab === t.key ? '600' : '400',
							cursor: 'pointer',
							fontSize: '13px',
						} }>
							{ t.label }
						</button>
					) ) }
				</div>

				{ loading ? (
					<div className="op-loading-screen"><span className="op-spinner" /></div>
				) : logs.length === 0 ? (
					<div className="op-empty">
						<div className="op-empty__icon">📋</div>
						<p className="op-empty__title">No logs yet</p>
						<p className="op-empty__desc">Logs will appear here after courier sends and tracking events.</p>
					</div>
				) : (
					<div className="op-table-wrap">
						<table className="op-table">
							<thead>
								<tr>
									{ tab === 'courier' ? (
										<>
											<th>Order</th>
											<th>Courier</th>
											<th>Action</th>
											<th>Status</th>
											<th>Time</th>
										</>
									) : (
										<>
											<th>Event</th>
											<th>Event ID</th>
											<th>Channel</th>
											<th>Order</th>
											<th>Time</th>
										</>
									) }
								</tr>
							</thead>
							<tbody>
								{ logs.map( ( log ) => (
									<tr key={ log.id }>
										{ tab === 'courier' ? (
											<>
												<td><a href={ `?page=post&action=edit&post=${ log.order_id }` }>#{log.order_id}</a></td>
												<td>{ log.courier }</td>
												<td>{ log.action }</td>
												<td><span className={ `op-badge op-badge--${ log.status === 'success' ? 'delivered' : 'cancelled' }` }>{ log.status }</span></td>
												<td className="op-text-muted op-text-sm">{ log.created_at }</td>
											</>
										) : (
											<>
												<td>{ log.event_name }</td>
												<td className="op-text-sm op-text-muted">{ log.event_id || '—' }</td>
												<td><span className="op-badge op-badge--none">{ log.channel }</span></td>
												<td>{ log.order_id ? `#${ log.order_id }` : '—' }</td>
												<td className="op-text-muted op-text-sm">{ log.created_at }</td>
											</>
										) }
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</div>
		</div>
	);
}
