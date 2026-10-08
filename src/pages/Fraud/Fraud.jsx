/**
 * Fraud Checker Page (Pro)
 *
 * Integrated with BDCourier API (Pathao, SteadFast, RedX, PaperFly, CarryBee, CourrierFast, ParcelDex)
 * and WooCommerce order history for comprehensive delivery risk scoring.
 * Also includes Blocked IPs management (IP-level order blocking).
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const { isPro = false } = window.orderPilot || {};

const RISK_BADGES = {
	low:    { label: '🟢 Low Risk',    class: 'odrplt-badge--delivered', color: '#008a2e', bg: '#e6f7ec' },
	medium: { label: '🟡 Medium Risk', class: 'odrplt-badge--pending',   color: '#b27200', bg: '#fff8e6' },
	high:   { label: '🔴 High Risk',   class: 'odrplt-badge--cancelled', color: '#d60000', bg: '#fde8e8' },
};

export default function Fraud() {
	const [ searchPhone, setSearchPhone ]       = useState( '' );
	const [ loading, setLoading ]               = useState( false );
	const [ result, setResult ]                 = useState( null );
	const [ error, setError ]                   = useState( null );
	const [ history, setHistory ]               = useState( [] );
	const [ loadingHistory, setLoadingHistory ] = useState( true );
	const [ historyPage, setHistoryPage ]                   = useState( 1 );
	const [ showDeleteHistoryModal, setShowDeleteHistoryModal ] = useState( false );
	const [ deleteHistoryLoading, setDeleteHistoryLoading ] = useState( false );
	const [ deletingHistoryId, setDeletingHistoryId ]       = useState( null );
	const [ historyNotice, setHistoryNotice ]               = useState( null );
	const [ selectedHistoryLog, setSelectedHistoryLog ]     = useState( null );

	// Blocked IPs state
	const [ blockedIps, setBlockedIps ]             = useState( [] );
	const [ loadingIps, setLoadingIps ]             = useState( true );
	const [ ipSearch, setIpSearch ]                 = useState( '' );
	const [ ipPage, setIpPage ]                     = useState( 1 );
	const [ showDeleteIpsModal, setShowDeleteIpsModal ] = useState( false );
	const [ deleteIpsLoading, setDeleteIpsLoading ] = useState( false );
	const [ newIp, setNewIp ]                       = useState( '' );
	const [ newReason, setNewReason ]               = useState( '' );
	const [ addingIp, setAddingIp ]                 = useState( false );
	const [ ipError, setIpError ]                   = useState( null );
	const [ ipSuccess, setIpSuccess ]               = useState( null );
	const [ removingId, setRemovingId ]             = useState( null );

	const ITEMS_PER_PAGE = 10;

	const fetchHistory = () => {
		setLoadingHistory( true );
		apiFetch( { path: 'order-pilot/v1/fraud/history?limit=100' } )
			.then( ( data ) => {
				setHistory( Array.isArray( data ) ? data : [] );
				setHistoryPage( 1 );
			} )
			.catch( () => setHistory( [] ) )
			.finally( () => setLoadingHistory( false ) );
	};

	const fetchBlockedIps = ( search = '' ) => {
		setLoadingIps( true );
		const qs = search ? `?limit=500&search=${ encodeURIComponent( search ) }` : '?limit=500';
		apiFetch( { path: `order-pilot/v1/fraud/blocked-ips${ qs }` } )
			.then( ( data ) => {
				setBlockedIps( Array.isArray( data ) ? data : [] );
				setIpPage( 1 );
			} )
			.catch( () => setBlockedIps( [] ) )
			.finally( () => setLoadingIps( false ) );
	};

	useEffect( () => {
		fetchHistory();
		if ( isPro ) {
			fetchBlockedIps();
		} else {
			setLoadingIps( false );
		}
	}, [] );

	const handleLookup = ( e, phoneParam ) => {
		if ( e ) e.preventDefault();
		const phone = ( phoneParam || searchPhone ).trim();
		if ( ! phone ) return;

		setLoading( true );
		setError( null );
		setResult( null );

		apiFetch( { path: `order-pilot/v1/fraud/lookup?phone=${ encodeURIComponent( phone ) }` } )
			.then( ( res ) => {
				setResult( res );
				fetchHistory();
			} )
			.catch( ( err ) => {
				setError( err?.message || 'Failed to calculate fraud score for this number.' );
			} )
			.finally( () => setLoading( false ) );
	};

	const handleAddIp = ( e ) => {
		e.preventDefault();
		const trimmed = newIp.trim();
		if ( ! trimmed ) return;

		setAddingIp( true );
		setIpError( null );
		setIpSuccess( null );

		apiFetch( {
			path: 'order-pilot/v1/fraud/blocked-ips',
			method: 'POST',
			data: { ip_address: trimmed, reason: newReason.trim() },
		} )
			.then( ( row ) => {
				setBlockedIps( ( prev ) => [ row, ...prev ] );
				setNewIp( '' );
				setNewReason( '' );
				setIpSuccess( `✅ ${ row.ip_address } has been blocked.` );
			} )
			.catch( ( err ) => {
				setIpError( err?.message || 'Failed to add IP.' );
			} )
			.finally( () => setAddingIp( false ) );
	};

	const handleRemoveIp = ( id ) => {
		setRemovingId( id );
		apiFetch( {
			path: `order-pilot/v1/fraud/blocked-ips/${ id }`,
			method: 'DELETE',
		} )
			.then( () => {
				setBlockedIps( ( prev ) => prev.filter( ( r ) => r.id !== id ) );
			} )
			.catch( ( err ) => {
				setIpError( err?.message || 'Failed to remove IP.' );
			} )
			.finally( () => setRemovingId( null ) );
	};

	const handleConfirmDeleteAllHistory = () => {
		setDeleteHistoryLoading( true );
		apiFetch( {
			path: 'order-pilot/v1/fraud/history',
			method: 'DELETE',
		} )
			.then( ( res ) => {
				setShowDeleteHistoryModal( false );
				setHistory( [] );
				setHistoryPage( 1 );
				setHistoryNotice( {
					type: 'success',
					message: res.message || 'All fraud check logs have been deleted.',
				} );
			} )
			.catch( ( err ) => {
				setShowDeleteHistoryModal( false );
				setHistoryNotice( {
					type: 'error',
					message: err.message || 'Failed to delete fraud check logs.',
				} );
			} )
			.finally( () => setDeleteHistoryLoading( false ) );
	};

	const handleDeleteHistoryItem = ( id ) => {
		setDeletingHistoryId( id );
		apiFetch( {
			path: `order-pilot/v1/fraud/history/${ id }`,
			method: 'DELETE',
		} )
			.then( () => {
				setHistory( ( prev ) => prev.filter( ( item ) => item.id !== id ) );
			} )
			.catch( ( err ) => {
				setHistoryNotice( {
					type: 'error',
					message: err.message || 'Failed to delete fraud check log.',
				} );
			} )
			.finally( () => setDeletingHistoryId( null ) );
	};

	const handleConfirmRemoveAllIps = () => {
		setDeleteIpsLoading( true );
		apiFetch( {
			path: 'order-pilot/v1/fraud/blocked-ips',
			method: 'DELETE',
		} )
			.then( ( res ) => {
				setShowDeleteIpsModal( false );
				setBlockedIps( [] );
				setIpPage( 1 );
				setIpSuccess( res.message || 'All blocked IPs have been removed.' );
			} )
			.catch( ( err ) => {
				setShowDeleteIpsModal( false );
				setIpError( err.message || 'Failed to remove blocked IPs.' );
			} )
			.finally( () => setDeleteIpsLoading( false ) );
	};

	const handleIpSearch = ( e ) => {
		e.preventDefault();
		setIpPage( 1 );
		fetchBlockedIps( ipSearch );
	};

	const filteredIps = ipSearch
		? blockedIps.filter(
			( r ) =>
				r.ip_address.includes( ipSearch ) ||
				( r.reason && r.reason.toLowerCase().includes( ipSearch.toLowerCase() ) )
		  )
		: blockedIps;

	const historyTotalPages = Math.ceil( history.length / ITEMS_PER_PAGE ) || 1;
	const safeHistoryPage   = Math.min( Math.max( 1, historyPage ), historyTotalPages );
	const paginatedHistory  = history.slice( ( safeHistoryPage - 1 ) * ITEMS_PER_PAGE, safeHistoryPage * ITEMS_PER_PAGE );

	const ipTotalPages      = Math.ceil( filteredIps.length / ITEMS_PER_PAGE ) || 1;
	const safeIpPage        = Math.min( Math.max( 1, ipPage ), ipTotalPages );
	const paginatedIps      = filteredIps.slice( ( safeIpPage - 1 ) * ITEMS_PER_PAGE, safeIpPage * ITEMS_PER_PAGE );

	const riskInfo = result ? ( RISK_BADGES[ result.risk_level ] || RISK_BADGES.low ) : null;
	const couriersList = result?.couriers ? Object.values( result.couriers ) : [];
	const reportsList  = result?.reports || [];
	const summary      = result?.summary || {};

	return (
		<div>
			{ /* Page Header */ }
			<div className="odrplt-page-header">
				<h1>🛡️ BDCourier Fraud Checker &amp; Risk Assessment</h1>
				<p>
					Detect fake orders and calculate COD delivery success rates across Pathao, SteadFast, RedX, PaperFly, CarryBee, and more.
				</p>
			</div>

			{ /* Phone Lookup Card */ }
			<div className="odrplt-card" style={ { marginBottom: 20 } }>
				<h3 className="odrplt-card-title" style={ { marginBottom: 12 } }>
					🔍 Check Customer Phone Number
				</h3>
				<form onSubmit={ ( e ) => handleLookup( e ) } style={ { display: 'flex', gap: 8, maxWidth: 500 } }>
					<input
						type="text"
						className="odrplt-input"
						placeholder="Enter 11-digit phone number (e.g. 017XXXXXXXX)"
						value={ searchPhone }
						onChange={ ( e ) => setSearchPhone( e.target.value ) }
						style={ { flex: 1 } }
					/>
					<button
						type="submit"
						className="odrplt-btn odrplt-btn--primary"
						disabled={ loading || ! searchPhone.trim() }
					>
						{ loading ? 'Analyzing…' : 'Check Risk' }
					</button>
				</form>

				{ error && (
					<div className="odrplt-notice odrplt-notice--error" style={ { marginTop: 12 } }>
						{ error }
					</div>
				) }

				{ /* Fraud Check Result Display */ }
				{ result && (
					<div style={ { marginTop: 20, borderTop: '1px solid var(--odrplt-border)', paddingTop: 16 } }>
						
						{ /* Risk Level Banner */ }
						<div style={ {
							display: 'flex',
							justifyContent: 'space-between',
							alignItems: 'center',
							background: riskInfo.bg,
							color: riskInfo.color,
							padding: '16px 20px',
							borderRadius: '8px',
							marginBottom: 16,
							border: `1px solid ${ riskInfo.color }33`,
						} }>
							<div>
								<div style={ { fontSize: 13, opacity: 0.9, textTransform: 'uppercase', letterSpacing: 0.5 } }>
									Delivery Risk Assessment for <strong>{ result.phone }</strong>
								</div>
								<div style={ { fontSize: 22, fontWeight: 800, marginTop: 2 } }>
									{ riskInfo.label }
								</div>
							</div>
							<div style={ { textAlign: 'right' } }>
								<div style={ { fontSize: 12, opacity: 0.9, textTransform: 'uppercase' } }>Fraud Risk Score</div>
								<div style={ { fontSize: 26, fontWeight: 900 } }>{ result.score } / 100</div>
							</div>
						</div>

						{ /* Merchant Fraud Reports Alert (if any) */ }
						{ reportsList.length > 0 && (
							<div style={ {
								background: '#fff0f0',
								border: '1px solid #ffcccc',
								borderRadius: '8px',
								padding: '14px 16px',
								marginBottom: 16,
							} }>
								<div style={ { display: 'flex', alignItems: 'center', gap: 8, color: '#d60000', fontWeight: 700, fontSize: 14, marginBottom: 8 } }>
									<span>⚠️ Warning:</span> { reportsList.length } Merchant Fraud Report{ reportsList.length > 1 ? 's' : '' } Found!
								</div>
								{ reportsList.map( ( rep, idx ) => (
									<div key={ idx } style={ {
										background: '#fff',
										padding: '10px 12px',
										borderRadius: '6px',
										border: '1px solid #ffd6d6',
										marginBottom: 6,
										fontSize: 12,
										display: 'flex',
										justifyContent: 'space-between',
										alignItems: 'center',
									} }>
										<div>
											<strong>{ rep.courierName || 'Courier' }:</strong> { rep.details || rep.name || 'Reported by merchant' }
										</div>
										<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 11 } }>{ rep.created_at }</span>
									</div>
								) ) }
							</div>
						) }

						{ /* All-Courier Summary Stats Banner */ }
						{ summary.total_parcel > 0 && (
							<div style={ {
								display: 'grid',
								gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))',
								gap: 12,
								marginBottom: 16,
							} }>
								<div style={ { background: 'var(--odrplt-bg-secondary, #f8f9fa)', padding: 12, borderRadius: 8, textAlign: 'center' } }>
									<div style={ { fontSize: 11, color: 'var(--odrplt-text-muted)', textTransform: 'uppercase' } }>Total Deliveries</div>
									<div style={ { fontSize: 20, fontWeight: 700 } }>{ summary.total_parcel }</div>
								</div>
								<div style={ { background: '#e6f7ec', color: '#008a2e', padding: 12, borderRadius: 8, textAlign: 'center' } }>
									<div style={ { fontSize: 11, textTransform: 'uppercase' } }>Delivered</div>
									<div style={ { fontSize: 20, fontWeight: 700 } }>{ summary.success_parcel }</div>
								</div>
								<div style={ { background: '#fde8e8', color: '#d60000', padding: 12, borderRadius: 8, textAlign: 'center' } }>
									<div style={ { fontSize: 11, textTransform: 'uppercase' } }>Cancelled / Returned</div>
									<div style={ { fontSize: 20, fontWeight: 700 } }>{ summary.cancelled_parcel }</div>
								</div>
								<div style={ { background: summary.success_ratio >= 75 ? '#e6f7ec' : ( summary.success_ratio >= 50 ? '#fff8e6' : '#fde8e8' ), color: summary.success_ratio >= 75 ? '#008a2e' : ( summary.success_ratio >= 50 ? '#b27200' : '#d60000' ), padding: 12, borderRadius: 8, textAlign: 'center' } }>
									<div style={ { fontSize: 11, textTransform: 'uppercase' } }>Overall Success Rate</div>
									<div style={ { fontSize: 20, fontWeight: 700 } }>{ summary.success_ratio }%</div>
								</div>
							</div>
						) }

						{ /* Individual Couriers Breakdown */ }
						{ couriersList.length > 0 && (
							<div style={ { marginBottom: 16 } }>
								<strong style={ { fontSize: 13, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', display: 'block', marginBottom: 8 } }>
									🚚 Breakdown by Courier Partner
								</strong>
								<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 10 } }>
									{ couriersList.map( ( c ) => (
										<div
											key={ c.slug }
											style={ {
												background: 'var(--odrplt-bg-secondary, #f8f9fa)',
												border: '1px solid var(--odrplt-border)',
												borderRadius: 8,
												padding: '10px 14px',
												display: 'flex',
												flexDirection: 'column',
												gap: 4,
											} }
										>
											<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
												<span style={ { fontWeight: 600, fontSize: 13, display: 'flex', alignItems: 'center', gap: 6 } }>
													{ c.logo && <img src={ c.logo } alt={ c.name } style={ { width: 18, height: 18, objectFit: 'contain' } } /> }
													{ c.name }
												</span>
												<span style={ {
													fontWeight: 700,
													fontSize: 12,
													color: c.total_parcel === 0 ? 'var(--odrplt-text-muted)' : ( c.success_ratio >= 75 ? '#008a2e' : ( c.success_ratio >= 50 ? '#b27200' : '#d60000' ) ),
												} }>
													{ c.total_parcel > 0 ? `${ c.success_ratio }%` : 'No data' }
												</span>
											</div>

											<div style={ { fontSize: 11, color: 'var(--odrplt-text-muted)', display: 'flex', justifyContent: 'space-between', marginTop: 2 } }>
												<span>Total: <strong>{ c.total_parcel }</strong></span>
												<span style={ { color: '#008a2e' } }>Delivered: <strong>{ c.success_parcel }</strong></span>
												<span style={ { color: '#d60000' } }>Cancelled: <strong>{ c.cancelled_parcel }</strong></span>
											</div>
										</div>
									) ) }
								</div>
							</div>
						) }

						{ /* Store History Card */ }
						{ result.history?.woocommerce && (
							<div style={ { background: 'var(--odrplt-bg-secondary, #f8f9fa)', padding: '12px 16px', borderRadius: 8, border: '1px solid var(--odrplt-border)' } }>
								<strong style={ { fontSize: 13, display: 'block', marginBottom: 4 } }>
									🏪 This Store Purchase History
								</strong>
								<div style={ { fontSize: 12, color: 'var(--odrplt-text-muted)', display: 'flex', gap: 16, flexWrap: 'wrap' } }>
									<span>Total Store Orders: <strong>{ result.history.woocommerce.total ?? 0 }</strong></span>
									<span style={ { color: '#008a2e' } }>Delivered: <strong>{ result.history.woocommerce.delivered ?? 0 }</strong></span>
									<span style={ { color: '#d60000' } }>Cancelled: <strong>{ result.history.woocommerce.cancelled ?? 0 }</strong></span>
									<span style={ { color: '#b27200' } }>Returned/Refunded: <strong>{ result.history.woocommerce.returned ?? 0 }</strong></span>
									<span>COD Orders: <strong>{ result.history.woocommerce.cod ?? 0 }</strong></span>
								</div>
							</div>
						) }
					</div>
				) }
			</div>

			{ /* Recent Checks History Table */ }
			<div className="odrplt-card" style={ { padding: 0, overflow: 'hidden', marginBottom: 20 } }>
				<div style={ { padding: '16px 20px', borderBottom: '1px solid var(--odrplt-border)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 } }>
					<div style={ { display: 'flex', alignItems: 'center', gap: 12 } }>
						<h3 className="odrplt-card-title" style={ { margin: 0 } }>
							📋 Recent Fraud Checks Log
						</h3>
						<button
							type="button"
							className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
							onClick={ fetchHistory }
							disabled={ loadingHistory }
						>
							🔄 Refresh
						</button>
					</div>
					<div>
						<button
							type="button"
							className="odrplt-btn odrplt-btn--sm"
							style={ { background: 'var(--odrplt-danger)', color: '#fff', borderColor: 'var(--odrplt-danger)' } }
							onClick={ () => setShowDeleteHistoryModal( true ) }
							disabled={ loadingHistory || history.length === 0 }
							title="Delete all fraud check logs"
						>
							<span style={{ fontSize: '16px' }} className="dashicons dashicons-trash"></span>Delete All
						</button>
					</div>
				</div>

				{ historyNotice && (
					<div
						className={ `odrplt-notice ${ historyNotice.type === 'success' ? 'odrplt-notice--success' : 'odrplt-notice--error' }` }
						style={ { margin: '14px 20px 0' } }
					>
						{ historyNotice.message }
						<button
							style={ { marginLeft: 'auto', background: 'none', border: 'none', cursor: 'pointer' } }
							onClick={ () => setHistoryNotice( null ) }
						>
							✕
						</button>
					</div>
				) }

				{ loadingHistory ? (
					<div className="odrplt-loading-screen"><span className="odrplt-spinner" /></div>
				) : history.length === 0 ? (
					<div className="odrplt-empty" style={ { padding: 36 } }>
						<div className="odrplt-empty__icon">🛡️</div>
						<p className="odrplt-empty__title">No fraud checks recorded yet</p>
						<p className="odrplt-empty__desc">Checks are automatically logged when new orders arrive or when performing a manual phone lookup.</p>
					</div>
				) : (
					<>
						<div style={ { overflowX: 'auto' } }>
						<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 13 } }>
							<thead>
								<tr style={ { background: 'var(--odrplt-bg-secondary)', borderBottom: '1px solid var(--odrplt-border)' } }>
									{ [ 'Time', 'Order', 'Phone Number', 'Risk Level', 'Score', 'Status', 'Action' ].map( ( h ) => (
										<th key={ h } style={ { padding: '10px 14px', textAlign: 'left', fontWeight: 600 } }>{ h }</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ paginatedHistory.map( ( item ) => {
									const risk = RISK_BADGES[ item.risk_level ] || RISK_BADGES.low;
									return (
										<tr key={ item.id } style={ { borderBottom: '1px solid var(--odrplt-border)' } }>
											<td style={ { padding: '10px 14px', fontSize: 12, color: 'var(--odrplt-text-muted)' } }>
												{ item.created_at || item.checked_at }
											</td>
											<td style={ { padding: '10px 14px' } }>
												{ item.order_id && parseInt( item.order_id, 10 ) > 0 ? (
													<a href={ `post.php?post=${ item.order_id }&action=edit` } target="_blank" rel="noreferrer" style={ { fontWeight: 600 } }>
														#{ item.order_id }
													</a>
												) : (
													<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 12 } }>Checkout / Lookup</span>
												) }
											</td>
											<td style={ { padding: '10px 14px', fontWeight: 500 } }>
												<code>{ item.phone }</code>
											</td>
											<td style={ { padding: '10px 14px' } }>
												<span style={ { background: risk.bg, color: risk.color, padding: '3px 8px', borderRadius: 12, fontSize: 11, fontWeight: 700 } }>
													{ risk.label }
												</span>
											</td>
											<td style={ { padding: '10px 14px', fontWeight: 700 } }>
												{ item.score } / 100
											</td>
											<td style={ { padding: '10px 14px' } }>
												{ item.risk_level === 'high' || ( item.data?.action === 'block' ) ? (
													<span className="odrplt-badge odrplt-badge--cancelled" style={ { fontSize: 11 } }>🔴 Blocked</span>
												) : (
													<span className="odrplt-badge odrplt-badge--delivered" style={ { fontSize: 11 } }>🟢 Approved</span>
												) }
											</td>
											<td style={ { padding: '10px 14px' } }>
												<div style={ { display: 'flex', gap: 6, alignItems: 'center' } }>
													<button
														type="button"
														className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
														onClick={ () => setSelectedHistoryLog( item ) }
														title="View fraud check details"
													>
														🔍 View
													</button>
													<button
														type="button"
														className="odrplt-btn odrplt-btn--sm"
														style={ { background: '#fde8e8', color: '#d60000', border: '1px solid #ffcccc' } }
														disabled={ deletingHistoryId === item.id }
														onClick={ () => handleDeleteHistoryItem( item.id ) }
														title="Delete this log record"
													>
														{ deletingHistoryId === item.id ? '…' : <><span style={{ fontSize: '14px', height: '14px', width: '14px' }} className="dashicons dashicons-trash"></span>Delete</> }
													</button>
												</div>
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
					<Pagination
						currentPage={ safeHistoryPage }
						totalPages={ historyTotalPages }
						totalItems={ history.length }
						pageSize={ ITEMS_PER_PAGE }
						onPageChange={ setHistoryPage }
					/>
				</>
				) }
			</div>

			{ /* ── Blocked IPs Management Card ─────────────────────────────────── */ }
			<div className="odrplt-card" style={ { padding: 0, overflow: 'hidden' } }>
				<div style={ { padding: '16px 20px', borderBottom: '1px solid var(--odrplt-border)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 } }>
					<div>
						<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
							<h3 className="odrplt-card-title" style={ { margin: 0 } }>
								🚫 Blocked IPs
							</h3>
							{ ! isPro && <span className="odrplt-pro-badge">PRO</span> }
						</div>
						<p style={ { margin: '4px 0 0', fontSize: 12, color: 'var(--odrplt-text-muted)' } }>
							Orders from blocked IPs are rejected immediately at checkout — no fraud score check is run.
						</p>
					</div>
					{ isPro && (
						<div style={ { display: 'flex', alignItems: 'center', gap: 10 } }>
							<span style={ {
								background: '#fde8e8',
								color: '#d60000',
								borderRadius: 20,
								padding: '3px 10px',
								fontSize: 12,
								fontWeight: 700,
							} }>
								{ blockedIps.length } blocked
							</span>
							<button
								type="button"
								className="odrplt-btn odrplt-btn--sm"
								style={ { background: 'var(--odrplt-danger)', color: '#fff', borderColor: 'var(--odrplt-danger)' } }
								onClick={ () => setShowDeleteIpsModal( true ) }
								disabled={ loadingIps || blockedIps.length === 0 }
								title="Remove all blocked IPs"
							>
								<span style={{ fontSize: '16px' }} className="dashicons dashicons-trash"></span> Remove All
							</button>
						</div>
					) }
				</div>

				{ ! isPro ? (
					<div className="odrplt-pro-gate" style={ { padding: '40px 20px', margin: '20px', background: 'var(--odrplt-bg-secondary)', borderRadius: 8, textAlign: 'center' } }>
						<div className="odrplt-pro-gate__icon" style={ { fontSize: 36, marginBottom: 12 } }>🔒</div>
						<h3 style={ { margin: '0 0 8px', fontSize: 18, fontWeight: 700 } }>IP-Level Order Blocking is a Pro Feature</h3>
						<p style={ { margin: '0 auto 20px', maxWidth: 500, fontSize: 13, color: 'var(--odrplt-text-muted)', lineHeight: 1.5 } }>
							Upgrade to Order Pilot Pro to automatically block abusive customers by IP address, manage custom IP blocklists, and reject fraudulent checkout attempts instantly.
						</p>
						<a
							href="https://arsyntax.com/asxc-product/order-pilot/"
							target="_blank"
							rel="noreferrer"
							className="odrplt-btn odrplt-btn--primary"
							style={ { display: 'inline-flex', alignItems: 'center', gap: 6, fontWeight: 600 } }
						>
							⚡ Upgrade to Pro to Unlock IP Blocking
						</a>
					</div>
				) : (
					<>
						<div style={ { padding: '16px 20px' } }>
							{ /* Add IP Form */ }
							<form onSubmit={ handleAddIp } style={ { display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 } }>
								<input
									type="text"
									className="odrplt-input"
									placeholder="IP address (e.g. 192.168.1.100 or 2001:db8::1)"
									value={ newIp }
									onChange={ ( e ) => setNewIp( e.target.value ) }
									style={ { flex: '1 1 200px', minWidth: 180 } }
								/>
								<input
									type="text"
									className="odrplt-input"
									placeholder="Reason (optional)"
									value={ newReason }
									onChange={ ( e ) => setNewReason( e.target.value ) }
									style={ { flex: '2 1 240px', minWidth: 180 } }
								/>
								<button
									type="submit"
									className="odrplt-btn odrplt-btn--primary"
									disabled={ addingIp || ! newIp.trim() }
								>
									{ addingIp ? 'Blocking…' : '+ Block IP' }
								</button>
							</form>

							{ ipError && (
								<div className="odrplt-notice odrplt-notice--error" style={ { marginBottom: 10 } }>
									{ ipError }
									<button style={ { marginLeft: 'auto', background: 'none', border: 'none', cursor: 'pointer' } } onClick={ () => setIpError( null ) }>✕</button>
								</div>
							) }
							{ ipSuccess && (
								<div className="odrplt-notice odrplt-notice--success" style={ { marginBottom: 10 } }>
									{ ipSuccess }
									<button style={ { marginLeft: 'auto', background: 'none', border: 'none', cursor: 'pointer' } } onClick={ () => setIpSuccess( null ) }>✕</button>
								</div>
							) }

							{ /* Search */ }
							<form onSubmit={ handleIpSearch } style={ { display: 'flex', gap: 8, marginBottom: 14 } }>
								<input
									type="text"
									className="odrplt-input"
									placeholder="Search IP or reason…"
									value={ ipSearch }
									onChange={ ( e ) => { setIpSearch( e.target.value ); setIpPage( 1 ); } }
									style={ { maxWidth: 320 } }
								/>
								<button type="submit" className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm">
									🔍 Search
								</button>
								{ ipSearch && (
									<button
										type="button"
										className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
										onClick={ () => { setIpSearch( '' ); setIpPage( 1 ); fetchBlockedIps(); } }
									>
										✕ Clear
									</button>
								) }
							</form>
						</div>

						{ /* Table */ }
						{ loadingIps ? (
							<div className="odrplt-loading-screen"><span className="odrplt-spinner" /></div>
						) : filteredIps.length === 0 ? (
							<div className="odrplt-empty" style={ { padding: 36 } }>
								<div className="odrplt-empty__icon">🚫</div>
								<p className="odrplt-empty__title">No blocked IPs</p>
								<p className="odrplt-empty__desc">Add an IP address above to block it from placing orders.</p>
							</div>
						) : (
							<>
								<div style={ { overflowX: 'auto' } }>
								<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 13 } }>
									<thead>
										<tr style={ { background: 'var(--odrplt-bg-secondary)', borderBottom: '1px solid var(--odrplt-border)' } }>
											{ [ 'IP Address', 'Reason', 'Source', 'Blocked On', 'Action' ].map( ( h ) => (
												<th key={ h } style={ { padding: '10px 14px', textAlign: 'left', fontWeight: 600 } }>{ h }</th>
											) ) }
										</tr>
									</thead>
									<tbody>
										{ paginatedIps.map( ( row ) => (
											<tr key={ row.id } style={ { borderBottom: '1px solid var(--odrplt-border)' } }>
												<td style={ { padding: '10px 14px', fontWeight: 600 } }>
													<code style={ { background: '#fde8e8', color: '#d60000', padding: '2px 7px', borderRadius: 4 } }>
														{ row.ip_address }
													</code>
												</td>
												<td style={ { padding: '10px 14px', color: 'var(--odrplt-text-muted)', fontSize: 12 } }>
													{ row.reason || <em>—</em> }
												</td>
												<td style={ { padding: '10px 14px' } }>
													{ row.source === 'automatic' ? (
														<span style={ { background: '#fff3cd', color: '#856404', padding: '2px 8px', borderRadius: 12, fontSize: 11, fontWeight: 700 } }>
															🤖 Auto
														</span>
													) : (
														<span style={ { background: '#e6f7ec', color: '#008a2e', padding: '2px 8px', borderRadius: 12, fontSize: 11, fontWeight: 700 } }>
															👤 Manual
														</span>
													) }
												</td>
												<td style={ { padding: '10px 14px', fontSize: 12, color: 'var(--odrplt-text-muted)' } }>
													{ row.created_at }
												</td>
												<td style={ { padding: '10px 14px' } }>
													<button
														className="odrplt-btn odrplt-btn--sm"
														style={ { background: '#fde8e8', color: '#d60000', border: '1px solid #ffcccc' } }
														disabled={ removingId === row.id }
														onClick={ () => handleRemoveIp( row.id ) }
													>
														{ removingId === row.id ? '…' : <><span style={{ fontSize: '14px', height: '14px', width: '14px' }} className="dashicons dashicons-trash"></span>Remove</>}
													</button>
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							</div>
							<Pagination
								currentPage={ safeIpPage }
								totalPages={ ipTotalPages }
								totalItems={ filteredIps.length }
								pageSize={ ITEMS_PER_PAGE }
								onPageChange={ setIpPage }
							/>
						</>
						) }
					</>
				) }
			</div>

			{ /* Delete All Fraud Checks Confirmation Modal */ }
			{ showDeleteHistoryModal && (
				<div
					style={ {
						position: 'fixed',
						inset: 0,
						background: 'rgba(15, 23, 42, 0.72)',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						zIndex: 99999,
						padding: 20,
					} }
				>
					<div
						className="odrplt-card"
						style={ {
							maxWidth: 480,
							width: '100%',
							margin: 0,
							boxShadow: 'var(--odrplt-shadow-xl)',
						} }
					>
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 } }>
							<h3 style={ { margin: 0, fontSize: 16, color: 'var(--odrplt-danger)' } }>
								<span style={{ fontSize: '16px' }} className="dashicons dashicons-trash"></span> Delete All Fraud Checks Log
							</h3>
							<button
								onClick={ () => setShowDeleteHistoryModal( false ) }
								style={ { background: 'none', border: 'none', cursor: 'pointer', fontSize: 20, color: 'var(--odrplt-text-muted)' } }
								disabled={ deleteHistoryLoading }
							>
								&times;
							</button>
						</div>

						<div
							style={ {
								padding: '14px 16px',
								background: 'var(--odrplt-danger-bg)',
								border: '1px solid var(--odrplt-danger-border)',
								borderRadius: 'var(--odrplt-radius-sm)',
								marginBottom: 20,
								fontSize: 13,
								color: 'var(--odrplt-danger-text)',
								lineHeight: 1.6,
							} }
						>
							<strong>⚠️ This action cannot be undone.</strong>
							<br />
							This will permanently delete all{ ' ' }
							<strong>{ history.length.toLocaleString() } fraud check log{ history.length !== 1 ? 's' : '' }</strong>{ ' ' }
							from the database.
						</div>

						<div style={ { display: 'flex', gap: 10, justifyContent: 'flex-end' } }>
							<button
								className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
								onClick={ () => setShowDeleteHistoryModal( false ) }
								disabled={ deleteHistoryLoading }
							>
								Cancel
							</button>
							<button
								className="odrplt-btn odrplt-btn--sm"
								style={ { background: 'var(--odrplt-danger)', color: '#fff', borderColor: 'var(--odrplt-danger)' } }
								onClick={ handleConfirmDeleteAllHistory }
								disabled={ deleteHistoryLoading }
							>
								{ deleteHistoryLoading ? '⏳ Deleting...' : `<span style={{ fontSize: '16px' }} className="dashicons dashicons-trash"></span> Delete ${ history.length.toLocaleString() } Logs` }
							</button>
						</div>
					</div>
				</div>
			) }

			{ /* Remove All Blocked IPs Confirmation Modal */ }
			{ showDeleteIpsModal && isPro && (
				<div
					style={ {
						position: 'fixed',
						inset: 0,
						background: 'rgba(15, 23, 42, 0.72)',
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center',
						zIndex: 99999,
						padding: 20,
					} }
				>
					<div
						className="odrplt-card"
						style={ {
							maxWidth: 480,
							width: '100%',
							margin: 0,
							boxShadow: 'var(--odrplt-shadow-xl)',
						} }
					>
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 } }>
							<h3 style={ { margin: 0, fontSize: 16, color: 'var(--odrplt-danger)' } }>
								<span className="dashicons dashicons-trash"></span> Remove All Blocked IPs
							</h3>
							<button
								onClick={ () => setShowDeleteIpsModal( false ) }
								style={ { background: 'none', border: 'none', cursor: 'pointer', fontSize: 20, color: 'var(--odrplt-text-muted)' } }
								disabled={ deleteIpsLoading }
							>
								&times;
							</button>
						</div>

						<div
							style={ {
								padding: '14px 16px',
								background: 'var(--odrplt-danger-bg)',
								border: '1px solid var(--odrplt-danger-border)',
								borderRadius: 'var(--odrplt-radius-sm)',
								marginBottom: 20,
								fontSize: 13,
								color: 'var(--odrplt-danger-text)',
								lineHeight: 1.6,
							} }
						>
							<strong>⚠️ This action cannot be undone.</strong>
							<br />
							This will remove all{ ' ' }
							<strong>{ blockedIps.length.toLocaleString() } blocked IP{ blockedIps.length !== 1 ? 's' : '' }</strong>{ ' ' }
							from the blocklist. Orders from these IPs will no longer be blocked.
						</div>

						<div style={ { display: 'flex', gap: 10, justifyContent: 'flex-end' } }>
							<button
								className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
								onClick={ () => setShowDeleteIpsModal( false ) }
								disabled={ deleteIpsLoading }
							>
								Cancel
							</button>
							<button
								className="odrplt-btn odrplt-btn--sm"
								style={ { background: 'var(--odrplt-danger)', color: '#fff', borderColor: 'var(--odrplt-danger)' } }
								onClick={ handleConfirmRemoveAllIps }
								disabled={ deleteIpsLoading }
							>
								{ deleteIpsLoading ? '⏳ Removing...' : `Remove ${ blockedIps.length.toLocaleString() } IPs` }
							</button>
						</div>
					</div>
				</div>
			) }

			{ /* Fraud Check Log Details Modal */ }
			{ selectedHistoryLog && ( () => {
				let details = {};
				if ( selectedHistoryLog.data ) {
					if ( typeof selectedHistoryLog.data === 'string' ) {
						try {
							details = JSON.parse( selectedHistoryLog.data );
						} catch ( e ) {
							details = {};
						}
					} else if ( typeof selectedHistoryLog.data === 'object' ) {
						details = selectedHistoryLog.data;
					}
				}

				const risk = RISK_BADGES[ selectedHistoryLog.risk_level ] || RISK_BADGES.low;
				const couriers = details.couriers ? Object.values( details.couriers ) : [];
				const reports  = details.reports || [];
				const summary  = details.summary || {};

				return (
					<div
						style={ {
							position: 'fixed',
							inset: 0,
							background: 'rgba(15, 23, 42, 0.72)',
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'center',
							zIndex: 99999,
							padding: 20,
						} }
						onClick={ ( e ) => {
							if ( e.target === e.currentTarget ) setSelectedHistoryLog( null );
						} }
					>
						<div
							className="odrplt-card"
							style={ {
								maxWidth: 680,
								width: '100%',
								maxHeight: '90vh',
								overflowY: 'auto',
								margin: 0,
								boxShadow: 'var(--odrplt-shadow-xl)',
							} }
						>
							{ /* Modal Header */ }
							<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 16, borderBottom: '1px solid var(--odrplt-border)', paddingBottom: 12 } }>
								<div>
									<h3 style={ { margin: 0, fontSize: 16, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 8 } }>
										<span>🛡️ Fraud Check Details</span>
										<code style={ { fontSize: 13, background: 'var(--odrplt-bg-secondary)', padding: '2px 8px', borderRadius: 4 } }>
											{ selectedHistoryLog.phone }
										</code>
									</h3>
									<div style={ { fontSize: 12, color: 'var(--odrplt-text-muted)', marginTop: 4 } }>
										Recorded on { selectedHistoryLog.created_at || selectedHistoryLog.checked_at }
										{ selectedHistoryLog.order_id && parseInt( selectedHistoryLog.order_id, 10 ) > 0 && (
											<> &bull; WooCommerce Order <a href={ `post.php?post=${ selectedHistoryLog.order_id }&action=edit` } target="_blank" rel="noreferrer" style={ { fontWeight: 600 } }>#{ selectedHistoryLog.order_id }</a></>
										) }
									</div>
								</div>
								<button
									type="button"
									onClick={ () => setSelectedHistoryLog( null ) }
									style={ { background: 'none', border: 'none', cursor: 'pointer', fontSize: 22, lineHeight: 1, color: 'var(--odrplt-text-muted)' } }
									title="Close modal"
								>
									&times;
								</button>
							</div>

							{ /* Risk Assessment Banner */ }
							<div
								style={ {
									display: 'flex',
									justifyContent: 'space-between',
									alignItems: 'center',
									background: risk.bg,
									color: risk.color,
									padding: '14px 18px',
									borderRadius: 8,
									marginBottom: 16,
									border: `1px solid ${ risk.color }33`,
								} }
							>
								<div>
									<div style={ { fontSize: 11, textTransform: 'uppercase', letterSpacing: 0.5, opacity: 0.9 } }>
										Risk Assessment
									</div>
									<div style={ { fontSize: 20, fontWeight: 800, marginTop: 2 } }>
										{ risk.label }
									</div>
								</div>
								<div style={ { textAlign: 'right' } }>
									<div style={ { fontSize: 11, textTransform: 'uppercase', opacity: 0.9 } }>
										Fraud Score
									</div>
									<div style={ { fontSize: 24, fontWeight: 900 } }>
										{ selectedHistoryLog.score } / 100
									</div>
								</div>
							</div>

							{ /* Overall Delivery Summary */ }
							{ ( summary.total_parcel > 0 || details.total_parcel > 0 ) && (
								<div style={ { marginBottom: 16 } }>
									<strong style={ { fontSize: 12, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', display: 'block', marginBottom: 8 } }>
										📦 Cross-Courier Delivery Record
									</strong>
									<div
										style={ {
											display: 'grid',
											gridTemplateColumns: 'repeat(auto-fit, minmax(110px, 1fr))',
											gap: 10,
										} }
									>
										<div style={ { background: 'var(--odrplt-bg-secondary)', padding: '10px', borderRadius: 8, textAlign: 'center' } }>
											<div style={ { fontSize: 11, color: 'var(--odrplt-text-muted)' } }>Total Orders</div>
											<div style={ { fontSize: 18, fontWeight: 700 } }>{ summary.total_parcel ?? details.total_parcel ?? 0 }</div>
										</div>
										<div style={ { background: '#e6f7ec', color: '#008a2e', padding: '10px', borderRadius: 8, textAlign: 'center' } }>
											<div style={ { fontSize: 11 } }>Delivered</div>
											<div style={ { fontSize: 18, fontWeight: 700 } }>{ summary.success_parcel ?? details.success_parcel ?? 0 }</div>
										</div>
										<div style={ { background: '#fde8e8', color: '#d60000', padding: '10px', borderRadius: 8, textAlign: 'center' } }>
											<div style={ { fontSize: 11 } }>Cancelled</div>
											<div style={ { fontSize: 18, fontWeight: 700 } }>{ summary.cancelled_parcel ?? details.cancelled_parcel ?? 0 }</div>
										</div>
										<div
											style={ {
												background: ( summary.success_ratio ?? details.success_ratio ?? 0 ) >= 75 ? '#e6f7ec' : ( ( summary.success_ratio ?? details.success_ratio ?? 0 ) >= 50 ? '#fff8e6' : '#fde8e8' ),
												color: ( summary.success_ratio ?? details.success_ratio ?? 0 ) >= 75 ? '#008a2e' : ( ( summary.success_ratio ?? details.success_ratio ?? 0 ) >= 50 ? '#b27200' : '#d60000' ),
												padding: '10px',
												borderRadius: 8,
												textAlign: 'center',
											} }
										>
											<div style={ { fontSize: 11 } }>Success Rate</div>
											<div style={ { fontSize: 18, fontWeight: 700 } }>{ summary.success_ratio ?? details.success_ratio ?? 0 }%</div>
										</div>
									</div>
								</div>
							) }

							{ /* Merchant Reports (if any) */ }
							{ reports.length > 0 && (
								<div
									style={ {
										background: '#fff0f0',
										border: '1px solid #ffcccc',
										borderRadius: 8,
										padding: '12px 14px',
										marginBottom: 16,
									} }
								>
									<div style={ { color: '#d60000', fontWeight: 700, fontSize: 13, marginBottom: 8 } }>
										⚠️ { reports.length } Merchant Report{ reports.length > 1 ? 's' : '' } Found:
									</div>
									{ reports.map( ( rep, idx ) => (
										<div
											key={ idx }
											style={ {
												background: '#fff',
												padding: '8px 10px',
												borderRadius: 6,
												border: '1px solid #ffd6d6',
												marginBottom: 6,
												fontSize: 12,
												display: 'flex',
												justifyContent: 'space-between',
											} }
										>
											<span><strong>{ rep.courierName || 'Courier' }:</strong> { rep.details || rep.name || 'Reported by merchant' }</span>
											<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 11 } }>{ rep.created_at }</span>
										</div>
									) ) }
								</div>
							) }

							{ /* Courier Breakdown (if any) */ }
							{ couriers.length > 0 && (
								<div style={ { marginBottom: 16 } }>
									<strong style={ { fontSize: 12, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', display: 'block', marginBottom: 8 } }>
										🚚 Courier Partners Breakdown
									</strong>
									<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 8 } }>
										{ couriers.map( ( c ) => (
											<div
												key={ c.slug || c.name }
												style={ {
													background: 'var(--odrplt-bg-secondary)',
													border: '1px solid var(--odrplt-border)',
													borderRadius: 6,
													padding: '8px 12px',
													fontSize: 12,
												} }
											>
												<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 } }>
													<span style={ { fontWeight: 600 } }>{ c.name }</span>
													<span style={ { fontWeight: 700, color: c.total_parcel === 0 ? 'var(--odrplt-text-muted)' : ( c.success_ratio >= 75 ? '#008a2e' : '#d60000' ) } }>
														{ c.total_parcel > 0 ? `${ c.success_ratio }%` : '—' }
													</span>
												</div>
												<div style={ { display: 'flex', justifyContent: 'space-between', color: 'var(--odrplt-text-muted)', fontSize: 11 } }>
													<span>Total: { c.total_parcel }</span>
													<span>Delivered: { c.success_parcel }</span>
													<span>Cancelled: { c.cancelled_parcel }</span>
												</div>
											</div>
										) ) }
									</div>
								</div>
							) }

							{ /* Raw Telemetry Details JSON (collapsible) */ }
							{ selectedHistoryLog.data && (
								<details style={ { marginBottom: 16 } }>
									<summary style={ { fontSize: 12, color: 'var(--odrplt-text-muted)', cursor: 'pointer', fontWeight: 600, userSelect: 'none' } }>
										View Raw Payload JSON
									</summary>
									<pre
										style={ {
											background: '#0f172a',
											color: '#38bdf8',
											padding: 12,
											borderRadius: 6,
											fontSize: 11,
											lineHeight: 1.45,
											maxHeight: 220,
											overflowY: 'auto',
											marginTop: 8,
										} }
									>
										{ JSON.stringify( details, null, 2 ) }
									</pre>
								</details>
							) }

							{ /* Modal Footer */ }
							<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderTop: '1px solid var(--odrplt-border)', paddingTop: 14 } }>
								<button
									type="button"
									className="odrplt-btn odrplt-btn--primary odrplt-btn--sm"
									onClick={ () => {
										const phone = selectedHistoryLog.phone;
										setSelectedHistoryLog( null );
										setSearchPhone( phone );
										handleLookup( null, phone );
										window.scrollTo( { top: 0, behavior: 'smooth' } );
									} }
									title="Re-run live fraud check against BDCourier APIs"
								>
									⚡ Re-check Live
								</button>
								<button
									type="button"
									className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
									onClick={ () => setSelectedHistoryLog( null ) }
								>
									Close
								</button>
							</div>
						</div>
					</div>
				);
			} )() }
		</div>
	);
}

/**
 * Generate pagination page numbers with smart ellipsis.
 */
function getPageNumbers( current, total ) {
	if ( total <= 7 ) {
		return Array.from( { length: total }, ( _, i ) => i + 1 );
	}
	if ( current <= 4 ) {
		return [ 1, 2, 3, 4, 5, '...', total ];
	}
	if ( current >= total - 3 ) {
		return [ 1, '...', total - 4, total - 3, total - 2, total - 1, total ];
	}
	return [ 1, '...', current - 1, current, current + 1, '...', total ];
}

/**
 * Shared Pagination Component with Previous, Next, and Numeric buttons.
 */
function Pagination( { currentPage, totalPages, totalItems, pageSize, onPageChange } ) {
	if ( totalPages <= 1 && totalItems <= pageSize ) {
		return (
			<div
				style={ {
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
					padding: '12px 20px',
					borderTop: '1px solid var(--odrplt-border)',
					background: 'var(--odrplt-surface)',
					fontSize: 12.5,
					color: 'var(--odrplt-text-muted)',
				} }
			>
				<span>
					Showing <strong>{ totalItems }</strong> { totalItems === 1 ? 'entry' : 'entries' }
				</span>
			</div>
		);
	}

	const pages = getPageNumbers( currentPage, totalPages );
	const startItem = Math.min( ( currentPage - 1 ) * pageSize + 1, totalItems );
	const endItem   = Math.min( currentPage * pageSize, totalItems );

	return (
		<div
			style={ {
				display: 'flex',
				justifyContent: 'space-between',
				alignItems: 'center',
				flexWrap: 'wrap',
				gap: 12,
				padding: '12px 20px',
				borderTop: '1px solid var(--odrplt-border)',
				background: 'var(--odrplt-surface)',
			} }
		>
			<div style={ { fontSize: 13, color: 'var(--odrplt-text-muted)' } }>
				Showing <strong>{ startItem }</strong>–<strong>{ endItem }</strong> of{ ' ' }
				<strong>{ totalItems }</strong> { totalItems === 1 ? 'entry' : 'entries' }
			</div>

			<div style={ { display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' } }>
				{ /* Previous Button */ }
				<button
					type="button"
					className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
					onClick={ () => onPageChange( Math.max( 1, currentPage - 1 ) ) }
					disabled={ currentPage <= 1 }
					style={ { padding: '5px 10px', fontSize: 12 } }
					title="Previous page"
				>
					◀ Prev
				</button>

				{ /* Numeric Buttons */ }
				{ pages.map( ( p, idx ) => {
					if ( p === '...' ) {
						return (
							<span
								key={ `ellipsis-${ idx }` }
								style={ {
									padding: '4px 6px',
									color: 'var(--odrplt-text-muted)',
									fontSize: 13,
									userSelect: 'none',
								} }
							>
								…
							</span>
						);
					}
					const isActive = p === currentPage;
					return (
						<button
							key={ p }
							type="button"
							className={ `odrplt-btn odrplt-btn--sm ${ isActive ? 'odrplt-btn--primary' : 'odrplt-btn--secondary' }` }
							onClick={ () => onPageChange( p ) }
							style={ {
								minWidth: 32,
								height: 30,
								padding: '0 8px',
								fontSize: 12,
								fontWeight: isActive ? 700 : 500,
							} }
							aria-current={ isActive ? 'page' : undefined }
						>
							{ p }
						</button>
					);
				} ) }

				{ /* Next Button */ }
				<button
					type="button"
					className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
					onClick={ () => onPageChange( Math.min( totalPages, currentPage + 1 ) ) }
					disabled={ currentPage >= totalPages }
					style={ { padding: '5px 10px', fontSize: 12 } }
					title="Next page"
				>
					Next ▶
				</button>
			</div>
		</div>
	);
}

