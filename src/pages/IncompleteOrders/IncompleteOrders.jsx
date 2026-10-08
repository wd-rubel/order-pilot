/**
 * Incomplete Orders (Abandoned Checkout) Page (Pro Feature)
 *
 * Captures checkout information in real-time, displays abandoned carts,
 * and allows store administrators to convert them into native WooCommerce orders.
 *
 * @package OrderPilot
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const { currencySymbol = '৳', isPro = false } = window.orderPilot || {};

const STATUS_CONFIG = {
	all:       { label: 'All',       badgeClass: 'odrplt-badge--none',      color: '#475569', bg: '#f1f5f9' },
	abandoned: { label: 'Abandoned', badgeClass: 'odrplt-badge--pending',   color: '#b27200', bg: '#fff8e6' },
	active:    { label: 'Active',    badgeClass: 'odrplt-badge--picked_up', color: '#0369a1', bg: '#f0f9ff' },
	converted: { label: 'Converted', badgeClass: 'odrplt-badge--delivered', color: '#008a2e', bg: '#e6f7ec' },
	discarded: { label: 'Discarded', badgeClass: 'odrplt-badge--cancelled', color: '#d60000', bg: '#fde8e8' },
	expired:   { label: 'Expired',   badgeClass: 'odrplt-badge--none',      color: '#64748b', bg: '#f8fafc' },
};

export default function IncompleteOrders() {
	const [ items, setItems ]             = useState( [] );
	const [ stats, setStats ]             = useState( { total: 0, active: 0, abandoned: 0, converted: 0, discarded: 0, abandoned_revenue: 0, recovered_revenue: 0 } );
	const [ pagination, setPagination ]   = useState( { current_page: 1, total_pages: 1, total_items: 0, per_page: 15 } );
	const [ loading, setLoading ]         = useState( true );
	const [ notice, setNotice ]           = useState( null );
	const [ activeStatus, setActiveStatus ] = useState( 'all' );
	const [ searchQuery, setSearchQuery ]   = useState( '' );

	// Modals
	const [ selectedItem, setSelectedItem ]       = useState( null );
	const [ detailLoading, setDetailLoading ]     = useState( false );
	const [ convertTarget, setConvertTarget ]     = useState( null );
	const [ converting, setConverting ]           = useState( false );
	const [ discardTarget, setDiscardTarget ]     = useState( null );
	const [ discarding, setDiscarding ]           = useState( false );
	const [ deleteTarget, setDeleteTarget ]       = useState( null );
	const [ deleting, setDeleting ]               = useState( false );

	const fetchOrders = useCallback( ( page = 1, status = activeStatus, search = searchQuery ) => {
		setLoading( true );
		let query = `order-pilot/v1/incomplete-orders?page=${ page }&per_page=15`;
		if ( status && 'all' !== status ) {
			query += `&status=${ encodeURIComponent( status ) }`;
		}
		if ( search && search.trim() ) {
			query += `&search=${ encodeURIComponent( search.trim() ) }`;
		}

		apiFetch( { path: query } )
			.then( ( res ) => {
				setItems( res.items || [] );
				if ( res.pagination ) {
					setPagination( res.pagination );
				}
				if ( res.stats ) {
					setStats( res.stats );
				}
			} )
			.catch( ( err ) => {
				setNotice( { type: 'error', text: err?.message || 'Failed to load incomplete orders.' } );
			} )
			.finally( () => setLoading( false ) );
	}, [ activeStatus, searchQuery ] );

	useEffect( () => {
		fetchOrders( 1, activeStatus, searchQuery );
	}, [ activeStatus ] );

	const handleSearchSubmit = ( e ) => {
		if ( e ) e.preventDefault();
		fetchOrders( 1, activeStatus, searchQuery );
	};

	const handleClearSearch = () => {
		setSearchQuery( '' );
		fetchOrders( 1, activeStatus, '' );
	};

	const handleViewDetails = ( id ) => {
		setDetailLoading( true );
		setSelectedItem( { id } ); // placeholder
		apiFetch( { path: `order-pilot/v1/incomplete-orders/${ id }` } )
			.then( ( res ) => {
				setSelectedItem( res );
			} )
			.catch( ( err ) => {
				setNotice( { type: 'error', text: err?.message || 'Failed to fetch order details.' } );
				setSelectedItem( null );
			} )
			.finally( () => setDetailLoading( false ) );
	};

	const handleConfirmConvert = () => {
		if ( ! convertTarget ) return;
		setConverting( true );
		apiFetch( {
			path: `order-pilot/v1/incomplete-orders/${ convertTarget.id }/convert`,
			method: 'POST',
		} )
			.then( ( res ) => {
				setNotice( {
					type: 'success',
					text: `✓ Converted successfully! Created WooCommerce Order #${ res.order_number }.`,
					link: res.order_url,
					linkText: 'View Order →',
				} );
				setConvertTarget( null );
				if ( selectedItem && selectedItem.id === convertTarget.id ) {
					setSelectedItem( ( prev ) => ( { ...prev, status: 'converted', order_id: res.order_id, order_number: res.order_number, order_url: res.order_url } ) );
				}
				fetchOrders( pagination.current_page, activeStatus, searchQuery );
			} )
			.catch( ( err ) => {
				setNotice( { type: 'error', text: err?.message || 'Conversion failed.' } );
			} )
			.finally( () => setConverting( false ) );
	};

	const handleConfirmDiscard = () => {
		if ( ! discardTarget ) return;
		setDiscarding( true );
		apiFetch( {
			path: `order-pilot/v1/incomplete-orders/${ discardTarget.id }/discard`,
			method: 'POST',
		} )
			.then( () => {
				setNotice( { type: 'success', text: `Incomplete order #${ discardTarget.id } marked as discarded.` } );
				setDiscardTarget( null );
				if ( selectedItem && selectedItem.id === discardTarget.id ) {
					setSelectedItem( ( prev ) => ( { ...prev, status: 'discarded' } ) );
				}
				fetchOrders( pagination.current_page, activeStatus, searchQuery );
			} )
			.catch( ( err ) => {
				setNotice( { type: 'error', text: err?.message || 'Failed to discard record.' } );
			} )
			.finally( () => setDiscarding( false ) );
	};

	const handleConfirmDelete = () => {
		if ( ! deleteTarget ) return;
		setDeleting( true );
		apiFetch( {
			path: `order-pilot/v1/incomplete-orders/${ deleteTarget.id }`,
			method: 'DELETE',
		} )
			.then( () => {
				setNotice( { type: 'success', text: `Record #${ deleteTarget.id } deleted permanently.` } );
				setDeleteTarget( null );
				if ( selectedItem && selectedItem.id === deleteTarget.id ) {
					setSelectedItem( null );
				}
				fetchOrders( pagination.current_page, activeStatus, searchQuery );
			} )
			.catch( ( err ) => {
				setNotice( { type: 'error', text: err?.message || 'Failed to delete record.' } );
			} )
			.finally( () => setDeleting( false ) );
	};

	return (
		<div>
			{ /* Header */ }
			<div className="odrplt-page-header">
				<h1>🛒 Incomplete Orders (Abandoned Checkout)</h1>
				<p>Track customer checkout activity in real time, view abandoned carts, and recover lost sales by converting them into WooCommerce orders.</p>
			</div>

			{ /* Notice Banner */ }
			{ notice && (
				<div className={ `odrplt-notice odrplt-notice--${ notice.type }` } style={ { display: 'flex', alignItems: 'center', gap: 12 } }>
					<span>{ notice.text }</span>
					{ notice.link && (
						<a
							href={ notice.link }
							target="_blank"
							rel="noreferrer"
							style={ { fontWeight: 700, textDecoration: 'underline', color: 'inherit' } }
						>
							{ notice.linkText || 'Open →' }
						</a>
					) }
					<button
						type="button"
						style={ { marginLeft: 'auto', background: 'none', border: 'none', cursor: 'pointer', fontSize: 16 } }
						onClick={ () => setNotice( null ) }
					>
						✕
					</button>
				</div>
			) }

			{ /* Metrics Cards */ }
			<div className="odrplt-stats-grid">
				<div className="odrplt-stat-card odrplt-stat-card--primary">
					<div className="odrplt-stat-card__icon">🛒</div>
					<div className="odrplt-stat-card__label">Total Incomplete</div>
					<div className="odrplt-stat-card__value">{ stats.total.toLocaleString() }</div>
				</div>
				<div className="odrplt-stat-card odrplt-stat-card--warning">
					<div className="odrplt-stat-card__icon">⚠️</div>
					<div className="odrplt-stat-card__label">Abandoned</div>
					<div className="odrplt-stat-card__value">{ stats.abandoned.toLocaleString() }</div>
				</div>
				<div className="odrplt-stat-card odrplt-stat-card--success">
					<div className="odrplt-stat-card__icon">✓</div>
					<div className="odrplt-stat-card__label">Converted to Orders</div>
					<div className="odrplt-stat-card__value">{ stats.converted.toLocaleString() }</div>
				</div>
				<div className="odrplt-stat-card odrplt-stat-card--danger">
					<div className="odrplt-stat-card__icon">💸</div>
					<div className="odrplt-stat-card__label">Lost Cart Value</div>
					<div className="odrplt-stat-card__value" style={ { color: 'var(--odrplt-danger, #ef4444)' } }>
						{ currencySymbol }{ Number( stats.abandoned_revenue ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) }
					</div>
				</div>
				<div className="odrplt-stat-card odrplt-stat-card--success">
					<div className="odrplt-stat-card__icon">💰</div>
					<div className="odrplt-stat-card__label">Recovered Revenue</div>
					<div className="odrplt-stat-card__value" style={ { color: 'var(--odrplt-success, #10b981)' } }>
						{ currencySymbol }{ Number( stats.recovered_revenue ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) }
					</div>
				</div>
			</div>

			{ /* Segmented Status Filter Bar & Search */ }
			<div className="odrplt-card" style={ { padding: '16px 20px', marginBottom: 20 } }>
				<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 16 } }>
					{ /* Status Tabs */ }
					<div style={ { display: 'flex', gap: 6, flexWrap: 'wrap' } }>
						{ [ 'all', 'abandoned', 'active', 'converted', 'discarded' ].map( ( st ) => {
							const cfg   = STATUS_CONFIG[ st ] || { label: st };
							const count = st === 'all' ? stats.total : ( stats[ st ] ?? 0 );
							const isAct = activeStatus === st;
							return (
								<button
									key={ st }
									type="button"
									className={ `odrplt-btn odrplt-btn--sm ${ isAct ? 'odrplt-btn--primary' : 'odrplt-btn--secondary' }` }
									style={ { borderRadius: 20, display: 'inline-flex', alignItems: 'center', gap: 6 } }
									onClick={ () => setActiveStatus( st ) }
								>
									<span>{ cfg.label }</span>
									<span style={ {
										background: isAct ? 'rgba(255,255,255,0.25)' : 'var(--odrplt-surface-alt, #e2e8f0)',
										color: isAct ? '#fff' : 'inherit',
										borderRadius: 10,
										padding: '1px 7px',
										fontSize: 11,
										fontWeight: 700,
									} }>
										{ count }
									</span>
								</button>
							);
						} ) }
					</div>

					{ /* Search Form */ }
					<form onSubmit={ handleSearchSubmit } style={ { display: 'flex', gap: 8, flex: '1 1 300px', maxWidth: 460, justifyContent: 'flex-end' } }>
						<input
							type="text"
							className="odrplt-input"
							placeholder="Search by customer name, phone, or email…"
							value={ searchQuery }
							onChange={ ( e ) => setSearchQuery( e.target.value ) }
						/>
						<button type="submit" className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm">
							🔍 Search
						</button>
						{ searchQuery && (
							<button type="button" className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm" onClick={ handleClearSearch }>
								✕ Clear
							</button>
						) }
						<button
							type="button"
							className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
							onClick={ () => fetchOrders( pagination.current_page, activeStatus, searchQuery ) }
							title="Refresh list"
						>
							🔄
						</button>
					</form>
				</div>
			</div>

			{ /* Orders Table */ }
			<div className="odrplt-card" style={ { padding: 0, overflow: 'hidden' } }>
				{ loading ? (
					<div className="odrplt-loading-screen"><span className="odrplt-spinner" /></div>
				) : items.length === 0 ? (
					<div className="odrplt-empty" style={ { padding: 48, textAlign: 'center' } }>
						<div style={ { fontSize: 44, marginBottom: 12 } }>🛒</div>
						<h3 style={ { margin: '0 0 6px', fontSize: 16 } }>No Incomplete Orders Found</h3>
						<p style={ { margin: 0, color: 'var(--odrplt-text-muted)', fontSize: 13 } }>
							{ searchQuery ? 'No records match your search criteria.' : 'When customers begin filling checkout information and abandon, their carts will appear here.' }
						</p>
					</div>
				) : (
					<>
						<div className="odrplt-table-wrap" style={ { border: 'none', borderRadius: 0, boxShadow: 'none' } }>
							<table className="odrplt-table">
								<thead>
									<tr>
										<th>Customer</th>
										<th>Cart Items</th>
										<th>Cart Total</th>
										<th>Status</th>
										<th>Last Activity</th>
										<th style={ { textAlign: 'right' } }>Actions</th>
									</tr>
								</thead>
								<tbody>
									{ items.map( ( row ) => {
										const stCfg = STATUS_CONFIG[ row.status ] || STATUS_CONFIG.expired;
										return (
											<tr key={ row.id }>
												{ /* Customer */ }
												<td>
													<div style={ { display: 'flex', flexDirection: 'column', gap: 2 } }>
														<div style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
															<strong style={ { fontSize: 13.5 } }>{ row.customer_name }</strong>
															{ row.customer_id > 0 ? (
																<span style={ { background: '#e0f2fe', color: '#0369a1', fontSize: 10, padding: '1px 5px', borderRadius: 4, fontWeight: 700 } }>User</span>
															) : (
																<span style={ { background: '#f1f5f9', color: '#64748b', fontSize: 10, padding: '1px 5px', borderRadius: 4, fontWeight: 600 } }>Guest</span>
															) }
														</div>
														{ row.phone && (
															<a href={ `tel:${ row.phone }` } style={ { fontSize: 12, color: 'var(--odrplt-text-muted)' } }>
																📞 { row.phone }
															</a>
														) }
														{ row.email && (
															<a href={ `mailto:${ row.email }` } style={ { fontSize: 12, color: 'var(--odrplt-text-muted)' } }>
																✉️ { row.email }
															</a>
														) }
													</div>
												</td>

												{ /* Cart Items */ }
												<td>
													<div style={ { display: 'flex', flexDirection: 'column', gap: 4, maxWidth: 280 } }>
														<span style={ {
															background: 'var(--odrplt-bg-secondary, #f8fafc)',
															border: '1px solid var(--odrplt-border)',
															padding: '2px 8px',
															borderRadius: 12,
															fontSize: 11,
															fontWeight: 700,
															width: 'fit-content',
														} }>
															🛍️ { row.items_count } item{ row.items_count !== 1 ? 's' : '' }
														</span>
														<span style={ { fontSize: 12, color: 'var(--odrplt-text-muted)', lineHeight: 1.4 } }>
															{ row.items_summary || 'No items preview' }
														</span>
													</div>
												</td>

												{ /* Total */ }
												<td>
													<strong style={ { fontSize: 14, color: 'var(--odrplt-text)' } }>
														{ currencySymbol }{ Number( row.cart_total ).toFixed( 2 ) }
													</strong>
												</td>

												{ /* Status Badge */ }
												<td>
													<span
														className={ `odrplt-badge ${ stCfg.badgeClass }` }
														style={ {
															background: stCfg.bg,
															color: stCfg.color,
															fontWeight: 700,
															fontSize: 12,
															padding: '3px 10px',
														} }
													>
														{ stCfg.label }
													</span>
													{ row.status === 'converted' && row.order_number && (
														<div style={ { marginTop: 4 } }>
															<a
																href={ row.order_url }
																target="_blank"
																rel="noreferrer"
																style={ { fontSize: 11, color: 'var(--odrplt-primary)', textDecoration: 'underline' } }
															>
																Order #{ row.order_number } ↗
															</a>
														</div>
													) }
												</td>

												{ /* Last Activity */ }
												<td>
													<div style={ { fontSize: 12, color: 'var(--odrplt-text-muted)' } }>
														{ row.updated_at }
													</div>
												</td>

												{ /* Actions */ }
												<td style={ { textAlign: 'right' } }>
													<div style={ { display: 'inline-flex', gap: 6, alignItems: 'center' } }>
														<button
															type="button"
															className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
															onClick={ () => handleViewDetails( row.id ) }
															title="View Details"
														>
															👁️ View
														</button>

														{ row.status !== 'converted' ? (
															<button
																type="button"
																className="odrplt-btn odrplt-btn--primary odrplt-btn--sm"
																onClick={ () => setConvertTarget( row ) }
																style={ { background: '#10b981', borderColor: '#10b981' } }
																title="Convert to WooCommerce Order"
															>
																⚡ Convert to Order
															</button>
														) : (
															<a
																href={ row.order_url }
																target="_blank"
																rel="noreferrer"
																className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
																title="Open WooCommerce Order"
															>
																🔗 WC Order
															</a>
														) }

														{ row.status !== 'converted' && row.status !== 'discarded' && (
															<button
																type="button"
																className="odrplt-btn odrplt-btn--sm"
																style={ { background: '#fef2f2', color: '#ef4444', borderColor: '#fecaca' } }
																onClick={ () => setDiscardTarget( row ) }
																title="Discard this incomplete order"
															>
																✕
															</button>
														) }
													</div>
												</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>

						{ /* Pagination */ }
						<Pagination
							currentPage={ pagination.current_page }
							totalPages={ pagination.total_pages }
							totalItems={ pagination.total_items }
							pageSize={ pagination.per_page }
							onPageChange={ ( newPage ) => fetchOrders( newPage, activeStatus, searchQuery ) }
						/>
					</>
				) }
			</div>

			{ /* ── Details Modal ──────────────────────────────────────────────── */ }
			{ selectedItem && (
				<div className="odrplt-modal-overlay" onClick={ () => setSelectedItem( null ) }>
					<div
						className="odrplt-modal"
						style={ { maxWidth: 680, width: '100%', maxHeight: '90vh' } }
						onClick={ ( e ) => e.stopPropagation() }
					>
						<div className="odrplt-modal-header">
							<div style={ { display: 'flex', alignItems: 'center', gap: 10 } }>
								<h2 className="odrplt-modal-title" style={ { margin: 0 } }>
									Incomplete Order #{ selectedItem.id }
								</h2>
								{ selectedItem.status && (
									<span className={ `odrplt-badge ${ ( STATUS_CONFIG[ selectedItem.status ] || {} ).badgeClass }` }>
										{ ( STATUS_CONFIG[ selectedItem.status ] || {} ).label }
									</span>
								) }
							</div>
							<button className="odrplt-modal-close" onClick={ () => setSelectedItem( null ) }>✕</button>
						</div>

						<div className="odrplt-modal-body" style={ { overflowY: 'auto', padding: '20px 24px' } }>
							{ detailLoading ? (
								<div className="odrplt-loading-screen"><span className="odrplt-spinner" /></div>
							) : (
								<>
									{ /* Converted Order Alert Banner */ }
									{ selectedItem.status === 'converted' && selectedItem.order_id > 0 && (
										<div style={ {
											background: '#ecfdf5',
											border: '1px solid #a7f3d0',
											borderRadius: 8,
											padding: '12px 16px',
											marginBottom: 20,
											display: 'flex',
											justifyContent: 'space-between',
											alignItems: 'center',
										} }>
											<div>
												<strong style={ { color: '#065f46', fontSize: 14 } }>✓ Converted to WooCommerce Order #{ selectedItem.order_number }</strong>
												<p style={ { margin: '2px 0 0', fontSize: 12, color: '#047857' } }>Converted at { selectedItem.converted_at }</p>
											</div>
											<a
												href={ selectedItem.order_url }
												target="_blank"
												rel="noreferrer"
												className="odrplt-btn odrplt-btn--primary odrplt-btn--sm"
											>
												View Order in WooCommerce →
											</a>
										</div>
									) }

									{ /* Customer & Session Grid */ }
									<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 16, marginBottom: 20 } }>
										{ /* Customer Card */ }
										<div style={ { background: 'var(--odrplt-bg-secondary, #f8fafc)', border: '1px solid var(--odrplt-border)', borderRadius: 8, padding: 16 } }>
											<h4 style={ { margin: '0 0 10px', fontSize: 13, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--odrplt-text-muted)' } }>
												👤 Customer Information
											</h4>
											<div style={ { fontSize: 13, display: 'flex', flexDirection: 'column', gap: 6 } }>
												<div><strong>Name:</strong> { selectedItem.customer_name }</div>
												<div><strong>Phone:</strong> { selectedItem.phone ? <a href={ `tel:${ selectedItem.phone }` }>{ selectedItem.phone }</a> : <em>Not provided</em> }</div>
												<div><strong>Email:</strong> { selectedItem.email ? <a href={ `mailto:${ selectedItem.email }` }>{ selectedItem.email }</a> : <em>Not provided</em> }</div>
												<div><strong>Type:</strong> { selectedItem.customer_id > 0 ? 'Registered Customer' : 'Guest Customer' }</div>
											</div>
										</div>

										{ /* Session & Network Card */ }
										<div style={ { background: 'var(--odrplt-bg-secondary, #f8fafc)', border: '1px solid var(--odrplt-border)', borderRadius: 8, padding: 16 } }>
											<h4 style={ { margin: '0 0 10px', fontSize: 13, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--odrplt-text-muted)' } }>
												🌐 Session & Timing
											</h4>
											<div style={ { fontSize: 13, display: 'flex', flexDirection: 'column', gap: 6 } }>
												<div><strong>Created:</strong> { selectedItem.created_at }</div>
												<div><strong>Last Active:</strong> { selectedItem.updated_at }</div>
												<div><strong>IP Address:</strong> { selectedItem.ip_address ? <code>{ selectedItem.ip_address }</code> : <em>—</em> }</div>
												<div style={ { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
													<strong>Session:</strong> <code style={ { fontSize: 11 } }>{ selectedItem.session_key }</code>
												</div>
											</div>
										</div>
									</div>

									{ /* Addresses */ }
									{ selectedItem.billing && ( selectedItem.billing.address_1 || selectedItem.billing.city ) && (
										<div style={ { background: 'var(--odrplt-bg-secondary, #f8fafc)', border: '1px solid var(--odrplt-border)', borderRadius: 8, padding: 16, marginBottom: 20 } }>
											<h4 style={ { margin: '0 0 8px', fontSize: 13, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--odrplt-text-muted)' } }>
												📍 Billing & Shipping Address
											</h4>
											<p style={ { margin: 0, fontSize: 13, lineHeight: 1.5 } }>
												{ [ selectedItem.billing.address_1, selectedItem.billing.city, selectedItem.billing.state, selectedItem.billing.postcode, selectedItem.billing.country ].filter( Boolean ).join( ', ' ) }
											</p>
										</div>
									) }

									{ /* Products Table */ }
									<h4 style={ { margin: '0 0 10px', fontSize: 14, fontWeight: 700 } }>
										Cart Contents ({ selectedItem.items_count } items)
									</h4>
									<div style={ { border: '1px solid var(--odrplt-border)', borderRadius: 8, overflow: 'hidden', marginBottom: 20 } }>
										<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 13 } }>
											<thead>
												<tr style={ { background: 'var(--odrplt-bg-secondary, #f8fafc)', borderBottom: '1px solid var(--odrplt-border)' } }>
													<th style={ { padding: '8px 12px', textAlign: 'left', fontWeight: 600 } }>Product</th>
													<th style={ { padding: '8px 12px', textAlign: 'center', fontWeight: 600 } }>Stock</th>
													<th style={ { padding: '8px 12px', textAlign: 'right', fontWeight: 600 } }>Unit Price</th>
													<th style={ { padding: '8px 12px', textAlign: 'center', fontWeight: 600 } }>Qty</th>
													<th style={ { padding: '8px 12px', textAlign: 'right', fontWeight: 600 } }>Total</th>
												</tr>
											</thead>
											<tbody>
												{ ( selectedItem.cart_items || [] ).map( ( prod, idx ) => (
													<tr key={ prod.key || idx } style={ { borderBottom: '1px solid var(--odrplt-border)' } }>
														<td style={ { padding: '10px 12px', display: 'flex', alignItems: 'center', gap: 10 } }>
															{ prod.image_url ? (
																<img src={ prod.image_url } alt="" style={ { width: 36, height: 36, objectFit: 'cover', borderRadius: 4 } } />
															) : (
																<span style={ { width: 36, height: 36, background: '#f1f5f9', borderRadius: 4, display: 'inline-flex', alignItems: 'center', justifyContent: 'center' } }>📦</span>
															) }
															<div>
																<div style={ { fontWeight: 600 } }>{ prod.name }</div>
																{ prod.sku && <div style={ { fontSize: 11, color: 'var(--odrplt-text-muted)' } }>SKU: { prod.sku }</div> }
															</div>
														</td>
														<td style={ { padding: '10px 12px', textAlign: 'center' } }>
															{ prod.exists === false ? (
																<span style={ { background: '#fde8e8', color: '#d60000', fontSize: 11, padding: '2px 6px', borderRadius: 4, fontWeight: 700 } }>Deleted</span>
															) : prod.in_stock === false ? (
																<span style={ { background: '#fde8e8', color: '#d60000', fontSize: 11, padding: '2px 6px', borderRadius: 4, fontWeight: 700 } }>Out of Stock</span>
															) : (
																<span style={ { background: '#e6f7ec', color: '#008a2e', fontSize: 11, padding: '2px 6px', borderRadius: 4, fontWeight: 700 } }>In Stock</span>
															) }
														</td>
														<td style={ { padding: '10px 12px', textAlign: 'right' } }>
															{ currencySymbol }{ Number( prod.price ).toFixed( 2 ) }
														</td>
														<td style={ { padding: '10px 12px', textAlign: 'center', fontWeight: 600 } }>
															× { prod.quantity }
														</td>
														<td style={ { padding: '10px 12px', textAlign: 'right', fontWeight: 700 } }>
															{ currencySymbol }{ Number( prod.total ).toFixed( 2 ) }
														</td>
													</tr>
												) ) }
											</tbody>
											<tfoot>
												<tr style={ { background: 'var(--odrplt-bg-secondary, #f8fafc)' } }>
													<td colSpan={ 4 } style={ { padding: '10px 12px', textAlign: 'right', fontWeight: 700 } }>Cart Total:</td>
													<td style={ { padding: '10px 12px', textAlign: 'right', fontWeight: 800, fontSize: 15, color: 'var(--odrplt-primary)' } }>
														{ currencySymbol }{ Number( selectedItem.cart_total ).toFixed( 2 ) }
													</td>
												</tr>
											</tfoot>
										</table>
									</div>
								</>
							) }
						</div>

						<div className="odrplt-modal-footer" style={ { display: 'flex', justifyContent: 'space-between' } }>
							<div>
								{ selectedItem.status !== 'converted' && (
									<button
										type="button"
										className="odrplt-btn odrplt-btn--danger odrplt-btn--sm"
										onClick={ () => setDeleteTarget( selectedItem ) }
									>
										🗑 Delete
									</button>
								) }
							</div>
							<div style={ { display: 'flex', gap: 8 } }>
								<button
									type="button"
									className="odrplt-btn odrplt-btn--secondary"
									onClick={ () => setSelectedItem( null ) }
								>
									Close
								</button>
								{ selectedItem.status !== 'converted' && (
									<button
										type="button"
										className="odrplt-btn odrplt-btn--primary"
										style={ { background: '#10b981', borderColor: '#10b981' } }
										onClick={ () => setConvertTarget( selectedItem ) }
									>
										⚡ Convert to WooCommerce Order
									</button>
								) }
							</div>
						</div>
					</div>
				</div>
			) }

			{ /* ── Convert Confirmation Modal ─────────────────────────────────── */ }
			{ convertTarget && (
				<div className="odrplt-modal-overlay" onClick={ () => ! converting && setConvertTarget( null ) }>
					<div className="odrplt-modal" style={ { maxWidth: 480 } } onClick={ ( e ) => e.stopPropagation() }>
						<div className="odrplt-modal-header">
							<h3 className="odrplt-modal-title" style={ { color: '#065f46' } }>
								⚡ Convert to WooCommerce Order
							</h3>
							<button className="odrplt-modal-close" onClick={ () => ! converting && setConvertTarget( null ) }>✕</button>
						</div>
						<div className="odrplt-modal-body">
							<p style={ { fontSize: 13.5, lineHeight: 1.5, margin: '0 0 16px' } }>
								Are you sure you want to convert Incomplete Order #{ convertTarget.id } into a live WooCommerce order?
							</p>

							<div style={ { background: 'var(--odrplt-bg-secondary, #f8fafc)', border: '1px solid var(--odrplt-border)', borderRadius: 8, padding: 14, marginBottom: 16, fontSize: 13 } }>
								<div><strong>Customer:</strong> { convertTarget.customer_name }</div>
								{ convertTarget.phone && <div><strong>Phone:</strong> { convertTarget.phone }</div> }
								{ convertTarget.email && <div><strong>Email:</strong> { convertTarget.email }</div> }
								<div><strong>Items:</strong> { convertTarget.items_count } ({ convertTarget.items_summary })</div>
								<div style={ { marginTop: 4, paddingTop: 6, borderTop: '1px dashed var(--odrplt-border)' } }>
									<strong>Total Amount:</strong> <span style={ { fontSize: 14, fontWeight: 700, color: 'var(--odrplt-primary)' } }>{ currencySymbol }{ Number( convertTarget.cart_total ).toFixed( 2 ) }</span>
								</div>
							</div>

							<div style={ { fontSize: 12, color: 'var(--odrplt-text-muted)' } }>
								ℹ️ The order will be created with status <code>Pending Payment</code> and payment method set to Cash on Delivery (COD). Product stock will be decremented according to your store settings.
							</div>
						</div>
						<div className="odrplt-modal-footer">
							<button
								type="button"
								className="odrplt-btn odrplt-btn--secondary"
								onClick={ () => setConvertTarget( null ) }
								disabled={ converting }
							>
								Cancel
							</button>
							<button
								type="button"
								className="odrplt-btn odrplt-btn--primary"
								style={ { background: '#10b981', borderColor: '#10b981' } }
								onClick={ handleConfirmConvert }
								disabled={ converting }
							>
								{ converting ? 'Creating Order…' : '✓ Confirm & Create Order' }
							</button>
						</div>
					</div>
				</div>
			) }

			{ /* ── Discard Confirmation Modal ─────────────────────────────────── */ }
			{ discardTarget && (
				<div className="odrplt-modal-overlay" onClick={ () => ! discarding && setDiscardTarget( null ) }>
					<div className="odrplt-modal" style={ { maxWidth: 440 } } onClick={ ( e ) => e.stopPropagation() }>
						<div className="odrplt-modal-header">
							<h3 className="odrplt-modal-title">Discard Incomplete Order</h3>
							<button className="odrplt-modal-close" onClick={ () => ! discarding && setDiscardTarget( null ) }>✕</button>
						</div>
						<div className="odrplt-modal-body">
							<p style={ { fontSize: 13.5, margin: 0 } }>
								Mark Incomplete Order #{ discardTarget.id } for <strong>{ discardTarget.customer_name }</strong> as discarded?
							</p>
						</div>
						<div className="odrplt-modal-footer">
							<button type="button" className="odrplt-btn odrplt-btn--secondary" onClick={ () => setDiscardTarget( null ) } disabled={ discarding }>
								Cancel
							</button>
							<button type="button" className="odrplt-btn odrplt-btn--danger" onClick={ handleConfirmDiscard } disabled={ discarding }>
								{ discarding ? 'Discarding…' : 'Discard' }
							</button>
						</div>
					</div>
				</div>
			) }

			{ /* ── Delete Confirmation Modal ──────────────────────────────────── */ }
			{ deleteTarget && (
				<div className="odrplt-modal-overlay" onClick={ () => ! deleting && setDeleteTarget( null ) }>
					<div className="odrplt-modal" style={ { maxWidth: 440 } } onClick={ ( e ) => e.stopPropagation() }>
						<div className="odrplt-modal-header">
							<h3 className="odrplt-modal-title" style={ { color: 'var(--odrplt-danger)' } }>Delete Record</h3>
							<button className="odrplt-modal-close" onClick={ () => ! deleting && setDeleteTarget( null ) }>✕</button>
						</div>
						<div className="odrplt-modal-body">
							<p style={ { fontSize: 13.5, margin: 0 } }>
								Are you sure you want to permanently delete Incomplete Order #{ deleteTarget.id }? This action cannot be undone.
							</p>
						</div>
						<div className="odrplt-modal-footer">
							<button type="button" className="odrplt-btn odrplt-btn--secondary" onClick={ () => setDeleteTarget( null ) } disabled={ deleting }>
								Cancel
							</button>
							<button type="button" className="odrplt-btn odrplt-btn--danger" onClick={ handleConfirmDelete } disabled={ deleting }>
								{ deleting ? 'Deleting…' : 'Delete Permanently' }
							</button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}

/**
 * Shared Pagination Component
 */
function Pagination( { currentPage, totalPages, totalItems, pageSize, onPageChange } ) {
	if ( totalPages <= 1 && totalItems <= pageSize ) {
		return (
			<div style={ { padding: '12px 20px', borderTop: '1px solid var(--odrplt-border)', fontSize: 12.5, color: 'var(--odrplt-text-muted)' } }>
				Showing <strong>{ totalItems }</strong> { totalItems === 1 ? 'entry' : 'entries' }
			</div>
		);
	}

	const startItem = Math.min( ( currentPage - 1 ) * pageSize + 1, totalItems );
	const endItem   = Math.min( currentPage * pageSize, totalItems );

	return (
		<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, padding: '12px 20px', borderTop: '1px solid var(--odrplt-border)' } }>
			<div style={ { fontSize: 13, color: 'var(--odrplt-text-muted)' } }>
				Showing <strong>{ startItem }</strong>–<strong>{ endItem }</strong> of <strong>{ totalItems }</strong>
			</div>
			<div style={ { display: 'flex', gap: 6 } }>
				<button
					type="button"
					className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
					onClick={ () => onPageChange( currentPage - 1 ) }
					disabled={ currentPage <= 1 }
				>
					← Prev
				</button>
				<span style={ { display: 'inline-flex', alignItems: 'center', padding: '0 8px', fontSize: 12.5, fontWeight: 600 } }>
					Page { currentPage } of { totalPages }
				</span>
				<button
					type="button"
					className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
					onClick={ () => onPageChange( currentPage + 1 ) }
					disabled={ currentPage >= totalPages }
				>
					Next →
				</button>
			</div>
		</div>
	);
}
