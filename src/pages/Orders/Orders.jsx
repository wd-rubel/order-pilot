/**
 * Orders Page
 *
 * Full WooCommerce orders list with:
 *  - Courier status column
 *  - Fraud Risk assessment badge column
 *  - "Send to Courier" modal (one-click)
 *  - "Sync Status" live refresh per order
 *  - Filters: WC status, Courier delivery status, Courier partner, Search
 *  - Pagination
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const { currencySymbol = '৳', adminUrl = '' } = window.orderPilot || {};

// ─── Status helpers ───────────────────────────────────────────────────────────

const WC_STATUS_LABELS = {
	'pending':    'Pending Payment',
	'processing': 'Processing',
	'on-hold':    'On Hold',
	'completed':  'Completed',
	'cancelled':  'Cancelled',
	'refunded':   'Refunded',
	'failed':     'Failed',
};

const COURIER_STATUS_LABELS = {
	pending:         'Pending',
	picked_up:       'Picked Up',
	in_transit:      'In Transit',
	delivered:       'Delivered',
	cancelled:       'Cancelled',
	returned:        'Returned',
	failed_delivery: 'Failed Delivery',
	unknown:         'Unknown',
};

const COURIER_STATUS_BADGE = {
	pending:         'odrplt-badge--none',
	picked_up:       'odrplt-badge--in-transit',
	in_transit:      'odrplt-badge--in-transit',
	delivered:       'odrplt-badge--delivered',
	cancelled:       'odrplt-badge--cancelled',
	returned:        'odrplt-badge--cancelled',
	failed_delivery: 'odrplt-badge--cancelled',
	unknown:         'odrplt-badge--none',
};

const FRAUD_RISK_BADGES = {
	low:    { label: '🟢 Low',    bg: '#e6f7ec', color: '#008a2e' },
	medium: { label: '🟡 Medium', bg: '#fff8e6', color: '#b27200' },
	high:   { label: '🔴 High',   bg: '#fde8e8', color: '#d60000' },
};

// ─── Sub-components ───────────────────────────────────────────────────────────

/**
 * Courier selection + send modal.
 */
function SendModal( { order, couriers, onClose, onSent } ) {
	const [ selectedCourier, setSelectedCourier ] = useState( couriers[0]?.slug ?? '' );
	const [ sending, setSending ]                 = useState( false );
	const [ error, setError ]                     = useState( null );

	const handleSend = () => {
		if ( ! selectedCourier ) return;
		setSending( true );
		setError( null );
		apiFetch( {
			path: `order-pilot/v1/orders/${ order.id }/send`,
			method: 'POST',
			data:   { courier_slug: selectedCourier },
		} )
			.then( ( res ) => {
				onSent( order.id, res );
			} )
			.catch( ( err ) => {
				setError( err?.message || 'Failed to send order.' );
			} )
			.finally( () => setSending( false ) );
	};

	return (
		<div className="odrplt-modal-overlay" onClick={ onClose }>
			<div className="odrplt-modal" style={ { maxWidth: 440 } } onClick={ ( e ) => e.stopPropagation() }>
				<div className="odrplt-modal-header">
					<h2 className="odrplt-modal-title">
						🚚 Send Order #{ order.number } to Courier
					</h2>
					<button className="odrplt-modal-close" onClick={ onClose }>✕</button>
				</div>
				<div className="odrplt-modal-body">
					<p style={ { marginBottom: 12, color: 'var(--odrplt-text-muted)', fontSize: 13 } }>
						<strong>Customer:</strong> { order.customer_name } ({ order.customer_phone || 'No phone' })<br />
						<strong>Amount:</strong> { currencySymbol }{ Number( order.total ).toFixed(2) }
					</p>

					{ couriers.length === 0 ? (
						<div className="odrplt-notice odrplt-notice--error">
							No connected courier available. Please connect a courier in the Couriers tab first.
						</div>
					) : (
						<div className="odrplt-form-group">
							<label>Select Courier Partner</label>
							<select
								className="odrplt-select"
								value={ selectedCourier }
								onChange={ ( e ) => setSelectedCourier( e.target.value ) }
							>
								{ couriers.map( ( c ) => (
									<option key={ c.slug } value={ c.slug }>
										{ c.name } { c.environment ? `(${ c.environment.toUpperCase() })` : '' }
									</option>
								) ) }
							</select>
						</div>
					) }

					{ error && (
						<div className="odrplt-notice odrplt-notice--error" style={ { marginTop: 8 } }>
							{ error }
						</div>
					) }
				</div>
				<div className="odrplt-modal-footer">
					<button className="odrplt-btn odrplt-btn--secondary" onClick={ onClose }>Cancel</button>
					<button
						className="odrplt-btn odrplt-btn--primary"
						onClick={ handleSend }
						disabled={ sending || couriers.length === 0 }
					>
						{ sending ? 'Sending to Courier…' : '🚚 Send to Courier' }
					</button>
				</div>
			</div>
		</div>
	);
}

/**
 * A single order row.
 */
function OrderRow( { order, couriers, onSent, onSynced } ) {
	const [ syncing, setSyncing ]       = useState( false );
	const [ localOrder, setLocalOrder ] = useState( order );

	// Keep localOrder in sync with parent updates (e.g. after list refetch)
	useEffect( () => {
		setLocalOrder( order );
	}, [ order ] );

	// Sync live status from courier API.
	const handleSync = () => {
		setSyncing( true );
		apiFetch( {
			path: `order-pilot/v1/orders/${ localOrder.id }/sync-status`,
			method: 'POST',
		} )
			.then( ( res ) => {
				setLocalOrder( ( prev ) => ( { ...prev, courier_status: res.status } ) );
				if ( onSynced ) onSynced( order.id, res.status );
			} )
			.catch( () => {} )
			.finally( () => setSyncing( false ) );
	};

	const courierStatus  = localOrder.courier_status;
	const badgeClass     = COURIER_STATUS_BADGE[ courierStatus ] ?? 'odrplt-badge--none';
	const courierLabel   = COURIER_STATUS_LABELS[ courierStatus ] ?? courierStatus ?? '—';
	const fraudRiskInfo  = localOrder.fraud_risk ? ( FRAUD_RISK_BADGES[ localOrder.fraud_risk ] || FRAUD_RISK_BADGES.low ) : null;

	return (
		<tr>
			<td>
				<a
					href={ localOrder.edit_url }
					target="_blank"
					rel="noreferrer"
					style={ { fontWeight: 600 } }
				>
					#{ localOrder.number }
				</a>
			</td>
			<td style={ { fontSize: 12, color: 'var(--odrplt-text-muted)', whiteSpace: 'nowrap' } }>{ localOrder.date }</td>
			<td>
				<div style={ { fontWeight: 500 } }>{ localOrder.customer_name }</div>
				<div style={ { fontSize: 12, color: 'var(--odrplt-text-muted)' } }>{ localOrder.customer_phone }</div>
			</td>
			<td>
				<span className={ `odrplt-badge odrplt-badge--wc-${ localOrder.status }` }>
					{ WC_STATUS_LABELS[ localOrder.status ] ?? localOrder.status }
				</span>
			</td>
			<td>
				{ fraudRiskInfo ? (
					<span
						style={ {
							background: fraudRiskInfo.bg,
							color: fraudRiskInfo.color,
							padding: '3px 8px',
							borderRadius: 12,
							fontSize: 11,
							fontWeight: 700,
							display: 'inline-block',
							whiteSpace: 'nowrap',
						} }
						title={ `Fraud Score: ${ localOrder.fraud_score }/100` }
					>
						{ fraudRiskInfo.label } ({ localOrder.fraud_score })
					</span>
				) : (
					<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 12 } }>—</span>
				) }
			</td>
			<td style={ { fontWeight: 600 } }>
				{ currencySymbol }{ Number( localOrder.total ).toFixed(2) }
			</td>
			<td>
				{ localOrder.courier ? (
					<div>
						<span style={ { fontSize: 12, fontWeight: 600, textTransform: 'capitalize' } }>
							{ localOrder.courier }
						</span>
						<div>
							<code style={ { fontSize: 11 } }>{ localOrder.consignment_id || localOrder.tracking_id }</code>
						</div>
					</div>
				) : (
					<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 12 } }>—</span>
				) }
			</td>
			<td>
				{ courierStatus ? (
					<span className={ `odrplt-badge ${ badgeClass }` }>{ courierLabel }</span>
				) : (
					<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 12 } }>—</span>
				) }
			</td>
			<td>
				<div className="odrplt-flex odrplt-gap-2">
					{ localOrder.courier ? (
						<button
							className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
							onClick={ handleSync }
							disabled={ syncing }
							title="Sync live courier delivery status"
						>
							{ syncing ? '⏳' : '🔄 Sync' }
						</button>
					) : null }
					<button
						className="odrplt-btn odrplt-btn--primary odrplt-btn--sm"
						onClick={ () => onSent( localOrder ) }
					>
						{ localOrder.courier ? '↩ Re-send' : '+ Send' }
					</button>
				</div>
			</td>
		</tr>
	);
}

// ─── Main Component ───────────────────────────────────────────────────────────

export default function Orders() {
	const [ orders, setOrders ]               = useState( [] );
	const [ couriers, setCouriers ]           = useState( [] );
	const [ loading, setLoading ]             = useState( true );
	const [ sending, setSendingOrder ]        = useState( null ); // order object being sent
	const [ notice, setNotice ]               = useState( null );

	// Filters.
	const [ filterStatus, setFilterStatus ]               = useState( '' );
	const [ filterCourier, setFilterCourier ]             = useState( '' );
	const [ filterCourierStatus, setFilterCourierStatus ] = useState( '' );
	const [ search, setSearch ]                           = useState( '' );
	const [ searchInput, setSearchInput ]                 = useState( '' );

	// Pagination.
	const [ page, setPage ]             = useState( 1 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ total, setTotal ]           = useState( 0 );
	const PER_PAGE = 20;

	// Load connected couriers once.
	useEffect( () => {
		apiFetch( { path: 'order-pilot/v1/couriers' } )
			.then( ( data ) => setCouriers( Array.isArray( data ) ? data.filter( ( c ) => c.connected ) : [] ) )
			.catch( () => {} );
	}, [] );

	const loadOrders = useCallback( () => {
		setLoading( true );
		const params = new URLSearchParams( {
			page:           page,
			per_page:       PER_PAGE,
			status:         filterStatus,
			courier:        filterCourier,
			courier_status: filterCourierStatus,
			search:         search,
		} );
		apiFetch( { path: `order-pilot/v1/orders?${ params }` } )
			.then( ( res ) => {
				setOrders( res.orders || [] );
				setTotalPages( res.total_pages || 1 );
				setTotal( res.total || 0 );
			} )
			.catch( () => setOrders( [] ) )
			.finally( () => setLoading( false ) );
	}, [ page, filterStatus, filterCourier, filterCourierStatus, search ] );

	useEffect( () => {
		loadOrders();
	}, [ loadOrders ] );

	const handleSent = ( order ) => {
		setSendingOrder( order );
	};

	const handleSendSuccess = ( orderId, result ) => {
		setSendingOrder( null );
		setNotice( {
			type: 'success',
			text: `✓ Order #${ orderId } sent successfully! Tracking/Consignment: ${ result.consignment_id || result.tracking_id || '—' }`,
		} );
		loadOrders();
	};

	const handleSearch = ( e ) => {
		e.preventDefault();
		setSearch( searchInput );
		setPage( 1 );
	};

	const handleClearFilters = () => {
		setFilterStatus( '' );
		setFilterCourier( '' );
		setFilterCourierStatus( '' );
		setSearch( '' );
		setSearchInput( '' );
		setPage( 1 );
	};

	return (
		<div>
			{ /* Header */ }
			<div className="odrplt-page-header">
				<h1>📦 Orders</h1>
				<p>Manage WooCommerce orders, track shipments, assess fraud risk, and dispatch to couriers in one click.</p>
			</div>

			{ /* Notice */ }
			{ notice && (
				<div className={ `odrplt-notice odrplt-notice--${ notice.type }` }>
					{ notice.text }
					<button
						style={ { marginLeft: 'auto', background: 'none', border: 'none', cursor: 'pointer' } }
						onClick={ () => setNotice( null ) }
					>✕</button>
				</div>
			) }

			{ /* Filters bar */ }
			<div className="odrplt-card" style={ { marginBottom: 16 } }>
				<div className="odrplt-flex odrplt-gap-2" style={ { flexWrap: 'wrap', alignItems: 'center' } }>

					{ /* Search */ }
					<form onSubmit={ handleSearch } style={ { display: 'flex', gap: 6 } }>
						<input
							type="search"
							className="odrplt-input"
							style={ { minWidth: 180 } }
							placeholder="Search name, phone, order #…"
							value={ searchInput }
							onChange={ ( e ) => setSearchInput( e.target.value ) }
						/>
						<button className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm" type="submit">
							🔍 Search
						</button>
					</form>

					{ /* WC Status filter */ }
					<select
						className="odrplt-select"
						style={ { minWidth: 160 } }
						value={ filterStatus }
						onChange={ ( e ) => { setFilterStatus( e.target.value ); setPage( 1 ); } }
					>
						<option value="">All WC Statuses</option>
						{ Object.entries( WC_STATUS_LABELS ).map( ( [ slug, label ] ) => (
							<option key={ slug } value={ slug }>{ label }</option>
						) ) }
					</select>

					{ /* Courier partner filter */ }
					<select
						className="odrplt-select"
						style={ { minWidth: 150 } }
						value={ filterCourier }
						onChange={ ( e ) => { setFilterCourier( e.target.value ); setPage( 1 ); } }
					>
						<option value="">All Couriers</option>
						{ couriers.map( ( c ) => (
							<option key={ c.slug } value={ c.slug }>{ c.name }</option>
						) ) }
					</select>

					{ /* Delivery status filter */ }
					<select
						className="odrplt-select"
						style={ { minWidth: 170 } }
						value={ filterCourierStatus }
						onChange={ ( e ) => { setFilterCourierStatus( e.target.value ); setPage( 1 ); } }
					>
						<option value="">All Delivery Statuses</option>
						{ Object.entries( COURIER_STATUS_LABELS ).map( ( [ slug, label ] ) => (
							<option key={ slug } value={ slug }>{ label }</option>
						) ) }
					</select>

					{ ( filterStatus || filterCourier || filterCourierStatus || search ) && (
						<button
							className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
							onClick={ handleClearFilters }
						>
							✕ Reset
						</button>
					) }

					{ /* Total count */ }
					<span style={ { marginLeft: 'auto', color: 'var(--odrplt-text-muted)', fontSize: 13, fontWeight: 500 } }>
						{ total } order{ total !== 1 ? 's' : '' }
					</span>
				</div>
			</div>

			{ /* Orders table */ }
			<div className="odrplt-card" style={ { padding: 0, overflow: 'hidden' } }>
				{ loading ? (
					<div className="odrplt-loading-screen"><span className="odrplt-spinner" /></div>
				) : orders.length === 0 ? (
					<div className="odrplt-empty" style={ { padding: 48 } }>
						<div className="odrplt-empty__icon">📭</div>
						<p className="odrplt-empty__title">No orders found</p>
						<p className="odrplt-empty__desc">Try adjusting your filters or search terms.</p>
					</div>
				) : (
					<div style={ { overflowX: 'auto' } }>
						<table style={ {
							width: '100%',
							borderCollapse: 'collapse',
							fontSize: 13,
						} }>
							<thead>
								<tr style={ {
									background: 'var(--odrplt-bg-secondary)',
									borderBottom: '1px solid var(--odrplt-border)',
								} }>
									{ [ 'Order', 'Date', 'Customer', 'WC Status', 'Fraud Risk', 'Total', 'Courier', 'Delivery Status', 'Actions' ].map( ( h ) => (
										<th key={ h } style={ { padding: '10px 12px', textAlign: 'left', fontWeight: 600, whiteSpace: 'nowrap' } }>
											{ h }
										</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ orders.map( ( order ) => (
									<OrderRow
										key={ order.id }
										order={ order }
										couriers={ couriers }
										onSent={ ( o ) => handleSent( o ) }
										onSynced={ () => {} }
									/>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</div>

			{ /* Pagination */ }
			{ totalPages > 1 && (
				<div className="odrplt-flex odrplt-gap-2" style={ { marginTop: 16, justifyContent: 'center' } }>
					<button
						className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
						onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
						disabled={ page <= 1 }
					>
						← Prev
					</button>
					<span style={ { lineHeight: '32px', fontSize: 13 } }>
						Page { page } of { totalPages }
					</span>
					<button
						className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
						onClick={ () => setPage( ( p ) => Math.min( totalPages, p + 1 ) ) }
						disabled={ page >= totalPages }
					>
						Next →
					</button>
				</div>
			) }

			{ /* Send Modal */ }
			{ sending && (
				<SendModal
					order={ sending }
					couriers={ couriers }
					onClose={ () => setSendingOrder( null ) }
					onSent={ handleSendSuccess }
				/>
			) }
		</div>
	);
}
