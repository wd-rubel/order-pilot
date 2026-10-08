/**
 * Tracking Dashboard Page
 *
 * Unified Event Telemetry & Analytics Hub for:
 * - Facebook / Meta (Browser Pixel & Server-side CAPI with Event Deduplication)
 * - TikTok (Pixel & Events)
 * - Google (Google Analytics 4 / Measurement Protocol)
 * - Event Telemetry Logs (Live real-time event inspector)
 * - Diagnostics & Configuration Checklist
 *
 * @package OrderPilot
 */

import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const { isPro = false } = window.orderPilot || {};

/**
 * Resolves meaningful telemetry status from log record, API response, and HTTP code.
 *
 * @param {Object} log
 * @return {'Sent' | 'Failed' | 'Pending' | 'Skipped'}
 */
const getTelemetryStatus = ( log ) => {
	if ( log.status ) {
		const norm = String( log.status ).trim().toLowerCase();
		if ( norm === 'sent' || norm === 'success' || norm === 'delivered' ) return 'Sent';
		if ( norm === 'failed' || norm === 'error' ) return 'Failed';
		if ( norm === 'pending' || norm === 'queued' ) return 'Pending';
		if ( norm === 'skipped' || norm === 'ignored' ) return 'Skipped';
	}

	let payload = null;
	if ( log.payload ) {
		try {
			payload = typeof log.payload === 'object' ? log.payload : JSON.parse( log.payload );
		} catch ( e ) {
			payload = null;
		}
	}

	const httpCode = log.http_code !== null && log.http_code !== undefined && log.http_code !== ''
		? Number( log.http_code )
		: null;

	// Check for Skipped (not sent due to configuration, conditions, or deduplication)
	if (
		payload?.skipped === true ||
		payload?.status === 'skipped' ||
		payload?.skip_reason ||
		payload?.reason === 'skipped' ||
		httpCode === 304
	) {
		return 'Skipped';
	}

	// Check for Pending (waiting to be processed)
	if (
		payload?.pending === true ||
		payload?.status === 'pending' ||
		httpCode === 202 ||
		httpCode === 102 ||
		( httpCode === null && log.channel !== 'pixel' && ! payload )
	) {
		return 'Pending';
	}

	// Check for Failed (API request failed or error returned)
	if (
		( httpCode !== null && ( httpCode >= 400 || httpCode === 0 ) ) ||
		payload?.error ||
		payload?.errors ||
		payload?.success === false ||
		payload?.status === 'failed' ||
		payload?.status === 'error'
	) {
		return 'Failed';
	}

	// Default to Sent (API request completed successfully or browser pixel dispatched)
	return 'Sent';
};

/**
 * Returns badge configuration (label, style) for telemetry statuses.
 *
 * @param {'Sent' | 'Failed' | 'Pending' | 'Skipped'} status
 * @return {{ label: string, style: Object }}
 */
const getStatusBadgeConfig = ( status ) => {
	switch ( status ) {
		case 'Sent':
			return {
				label: 'Sent',
				style: {
					background: 'var(--odrplt-success-bg, #ecfdf5)',
					color: 'var(--odrplt-success-text, #065f46)',
					border: '1px solid var(--odrplt-success-border, #a7f3d0)',
				},
			};
		case 'Failed':
			return {
				label: 'Failed',
				style: {
					background: 'var(--odrplt-danger-bg, #fef2f2)',
					color: 'var(--odrplt-danger-text, #991b1b)',
					border: '1px solid var(--odrplt-danger-border, #fecaca)',
				},
			};
		case 'Pending':
			return {
				label: 'Pending',
				style: {
					background: 'var(--odrplt-warning-bg, #fffbeb)',
					color: 'var(--odrplt-warning-text, #92400e)',
					border: '1px solid var(--odrplt-warning-border, #fde68a)',
				},
			};
		case 'Skipped':
			return {
				label: 'Skipped',
				style: {
					background: '#f1f5f9',
					color: '#475569',
					border: '1px solid #cbd5e1',
				},
			};
		default:
			return {
				label: status || 'Unknown',
				style: {
					background: '#f1f5f9',
					color: '#64748b',
					border: '1px solid #e2e8f0',
				},
			};
	}
};

/**
 * Extracts detailed telemetry inspect data.
 *
 * @param {Object} log
 * @return {Object}
 */
const getTelemetryInspectDetails = ( log ) => {
	const status = getTelemetryStatus( log );

	let payload = null;
	if ( log.payload ) {
		try {
			payload = typeof log.payload === 'object' ? log.payload : JSON.parse( log.payload );
		} catch ( e ) {
			payload = null;
		}
	}

	const rawHttpCode = log.http_code !== null && log.http_code !== undefined && log.http_code !== ''
		? Number( log.http_code )
		: null;

	// HTTP Status Code text: show actual code (e.g. 200, 400), or 200 for pixel beacon
	let httpCodeText = '200';
	if ( rawHttpCode !== null ) {
		httpCodeText = String( rawHttpCode );
	} else if ( log.channel === 'pixel' ) {
		httpCodeText = '200';
	} else if ( status === 'Skipped' || status === 'Pending' ) {
		httpCodeText = '—';
	}

	// Error details
	let errorDetails = log.error_details || null;
	if ( ! errorDetails && payload ) {
		if ( payload.error?.message ) {
			errorDetails = payload.error.message;
		} else if ( typeof payload.error === 'string' ) {
			errorDetails = payload.error;
		} else if ( payload.errors ) {
			errorDetails = Array.isArray( payload.errors ) ? payload.errors.join( '; ' ) : String( payload.errors );
		} else if ( payload.message && status === 'Failed' ) {
			errorDetails = payload.message;
		}
	}

	// API Response
	let apiResponse = log.api_response || '';
	if ( ! apiResponse ) {
		if ( status === 'Sent' ) {
			apiResponse = 'Success';
		} else if ( status === 'Failed' ) {
			apiResponse = errorDetails ? ('Failed (' + errorDetails + ')') : ( rawHttpCode ? ('Failed (HTTP ' + rawHttpCode + ')') : 'Failed' );
		} else if ( status === 'Pending' ) {
			apiResponse = 'Pending (waiting to be processed)';
		} else if ( status === 'Skipped' ) {
			apiResponse = payload?.skip_reason || payload?.reason || 'Skipped due to configuration or conditions';
		}
	}

	return {
		status,
		httpCodeText,
		apiResponse,
		errorDetails,
	};
};

export default function Tracking() {
	const [ activeTab, setActiveTab ]             = useState( 'facebook' );
	const [ stats, setStats ]                     = useState( null );
	const [ settings, setSettings ]               = useState( null );
	const [ logs, setLogs ]                       = useState( [] );
	const [ logsTotal, setLogsTotal ]             = useState( 0 );
	const [ logsPage, setLogsPage ]               = useState( 1 );
	const [ logsTotalPages, setLogsTotalPages ]   = useState( 1 );
	const [ eventFilter, setEventFilter ]         = useState( '' );
	const [ channelFilter, setChannelFilter ]     = useState( '' );
	const [ loadingStats, setLoadingStats ]       = useState( true );
	const [ loadingLogs, setLoadingLogs ]         = useState( false );
	const [ testLoading, setTestLoading ]         = useState( false );
	const [ testNotice, setTestNotice ]           = useState( null );
	const [ selectedLog, setSelectedLog ]         = useState( null );
	const [ showDeleteModal, setShowDeleteModal ] = useState( false );
	const [ deleteCount, setDeleteCount ]         = useState( 0 );
	const [ deleteLoading, setDeleteLoading ]     = useState( false );

	const fetchStats = () => {
		setLoadingStats( true );
		Promise.all( [
			apiFetch( { path: 'order-pilot/v1/tracking/stats' } ).catch( () => null ),
			apiFetch( { path: 'order-pilot/v1/settings' } ).catch( () => null ),
		] )
			.then( ( [ statsData, settingsData ] ) => {
				if ( statsData ) setStats( statsData );
				if ( settingsData ) setSettings( settingsData );
			} )
			.finally( () => setLoadingStats( false ) );
	};

	const fetchLogs = ( page = 1, ev = eventFilter, ch = channelFilter ) => {
		setLoadingLogs( true );
		let query = `/order-pilot/v1/tracking/logs?page=${ page }&per_page=20`;
		if ( ev ) query += `&event_name=${ encodeURIComponent( ev ) }`;
		if ( ch ) query += `&channel=${ encodeURIComponent( ch ) }`;

		apiFetch( { path: query } )
			.then( ( res ) => {
				setLogs( res.logs || [] );
				setLogsTotal( res.total || 0 );
				setLogsPage( res.page || 1 );
				setLogsTotalPages( res.total_pages || 1 );
			} )
			.catch( () => {
				setLogs( [] );
				setLogsTotal( 0 );
			} )
			.finally( () => setLoadingLogs( false ) );
	};

	useEffect( () => {
		fetchStats();
	}, [] );

	useEffect( () => {
		if ( activeTab === 'logs' ) {
			fetchLogs( logsPage, eventFilter, channelFilter );
		}
	}, [ activeTab, logsPage, eventFilter, channelFilter ] );

	const handleSendTestEvent = () => {
		setTestLoading( true );
		setTestNotice( null );
		apiFetch( {
			path: 'order-pilot/v1/tracking/test-event',
			method: 'POST',
		} )
			.then( ( res ) => {
				setTestNotice( {
					type: 'success',
					message: res.message || 'Test Purchase event successfully dispatched to Meta CAPI!',
				} );
				fetchStats();
				if ( activeTab === 'logs' ) fetchLogs( 1 );
			} )
			.catch( ( err ) => {
				setTestNotice( {
					type: 'error',
					message: err.message || 'Failed to dispatch test event to Meta CAPI.',
				} );
			} )
			.finally( () => setTestLoading( false ) );
	};

	const handleOpenDeleteModal = () => {
		// Fetch current count before opening modal so we can show it.
		apiFetch( { path: 'order-pilot/v1/tracking/logs/count' } )
			.then( ( res ) => {
				setDeleteCount( res.count || 0 );
				setShowDeleteModal( true );
			} )
			.catch( () => {
				setDeleteCount( 0 );
				setShowDeleteModal( true );
			} );
	};

	const handleConfirmDelete = () => {
		setDeleteLoading( true );
		apiFetch( {
			path: 'order-pilot/v1/tracking/logs',
			method: 'DELETE',
		} )
			.then( ( res ) => {
				setShowDeleteModal( false );
				setTestNotice( {
					type: 'success',
					message: res.message || 'All tracking event records deleted successfully.',
				} );
				// Refresh everything.
				fetchStats();
				if ( activeTab === 'logs' ) fetchLogs( 1 );
			} )
			.catch( ( err ) => {
				setShowDeleteModal( false );
				setTestNotice( {
					type: 'error',
					message: err.message || 'Failed to delete tracking event records.',
				} );
			} )
			.finally( () => setDeleteLoading( false ) );
	};

	const TABS = [
		{ key: 'facebook',    label: '📘 Facebook / Meta' },
		{ key: 'tiktok',      label: '🎵 TikTok', pro: true },
		{ key: 'google',      label: '📈 Google (GA4)', pro: true },
		{ key: 'logs',        label: '📋 Event Telemetry Log' },
		{ key: 'diagnostics', label: '🔍 Diagnostics & Setup' },
	];

	// Config variables
	const pixelId       = settings?.pixel?.pixel_id || '';
	const capiEnabled   = !! settings?.capi?.enable_capi;
	const capiToken     = settings?.capi?.access_token || '';
	const testCode      = settings?.capi?.test_event_code || settings?.capi?.test_code || '';
	const fbTrigger     = settings?.pixel?.purchase_trigger || 'order_created';

	const tiktokEnabled = !! settings?.tiktok?.enable_tiktok;
	const tiktokPixelId = settings?.tiktok?.pixel_id || '';
	const tiktokTrigger = settings?.tiktok?.purchase_trigger || 'order_created';

	const ga4Enabled    = !! settings?.ga4?.enable_ga4;
	const ga4Id         = settings?.ga4?.measurement_id || '';
	const ga4Trigger    = settings?.ga4?.purchase_trigger || 'order_created';

	const triggerLabels = {
		order_created:     'Order Created (Immediate)',
		payment_completed: 'Payment Completed',
		order_delivered:   'Order Delivered (COD-Aware)',
	};

	return (
		<div>
			{ /* Header */ }
			<div className="odrplt-page-header" style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 16 } }>
				<div>
					<h1>📊 Tracking & Event Telemetry</h1>
					<p>Multi-channel tracking dashboard for Meta Pixel & CAPI, TikTok Pixel, Google Analytics 4, and real-time event logs.</p>
				</div>
				<div>
					<button
						className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
						onClick={ () => { fetchStats(); if ( activeTab === 'logs' ) fetchLogs( logsPage ); } }
						disabled={ loadingStats || loadingLogs }
					>
						🔄 Refresh Data
					</button>
				</div>
			</div>

			{ /* Notice alert */ }
			{ testNotice && (
				<div
					style={ {
						padding: '12px 16px',
						borderRadius: 'var(--odrplt-radius-sm)',
						marginBottom: 20,
						display: 'flex',
						justifyContent: 'space-between',
						alignItems: 'center',
						fontSize: 13,
						background: testNotice.type === 'success' ? 'var(--odrplt-success-bg)' : 'var(--odrplt-danger-bg)',
						border: `1px solid ${ testNotice.type === 'success' ? 'var(--odrplt-success-border)' : 'var(--odrplt-danger-border)' }`,
						color: testNotice.type === 'success' ? 'var(--odrplt-success-text)' : 'var(--odrplt-danger-text)',
					} }
				>
					<div>
						<strong>{ testNotice.type === 'success' ? '✅ Success:' : '⚠️ Error:' }</strong> { testNotice.message }
					</div>
					<button
						onClick={ () => setTestNotice( null ) }
						style={ { background: 'none', border: 'none', cursor: 'pointer', fontSize: 16, color: 'inherit' } }
					>
						&times;
					</button>
				</div>
			) }

			{ /* Modern Segmented Tabs Bar */ }
			<div className="odrplt-tabs-nav">
				{ TABS.map( ( tab ) => (
					<button
						key={ tab.key }
						type="button"
						className={ `odrplt-tab-btn ${ activeTab === tab.key ? 'odrplt-tab-btn--active' : '' }` }
						onClick={ () => setActiveTab( tab.key ) }
					>
						{ tab.label }
						{ tab.pro && ! isPro && <span className="odrplt-pro-badge">PRO</span> }
					</button>
				) ) }
			</div>

			{ /* Loading state for stats */ }
			{ loadingStats && ( activeTab === 'facebook' || activeTab === 'tiktok' || activeTab === 'google' ) ? (
				<div className="odrplt-loading-screen"><span className="odrplt-spinner" /></div>
			) : null }

			{ /* ─── TAB 1: FACEBOOK / META ─── */ }
			{ ! loadingStats && activeTab === 'facebook' && (
				<div>
					{ /* Status Strip */ }
					<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, marginBottom: 24 } }>
						<div className="odrplt-card" style={ { padding: '14px 18px' } }>
							<div style={ { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', marginBottom: 4 } }>
								Meta Pixel (Browser)
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
								<span style={ { width: 9, height: 9, borderRadius: '50%', background: pixelId ? 'var(--odrplt-success)' : 'var(--odrplt-danger)' } } />
								<span style={ { fontWeight: 600, fontSize: 13 } }>
									{ pixelId ? `Active (${ pixelId })` : 'Not Configured' }
								</span>
							</div>
						</div>

						<div className="odrplt-card" style={ { padding: '14px 18px' } }>
							<div style={ { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', marginBottom: 4 } }>
								Conversions API (Server)
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
								<span style={ { width: 9, height: 9, borderRadius: '50%', background: capiEnabled && capiToken ? 'var(--odrplt-success)' : 'var(--odrplt-warning)' } } />
								<span style={ { fontWeight: 600, fontSize: 13 } }>
									{ capiEnabled && capiToken ? 'Active (Graph API v21.0)' : capiEnabled ? 'Token Missing' : 'Disabled' }
								</span>
							</div>
						</div>

						<div className="odrplt-card" style={ { padding: '14px 18px' } }>
							<div style={ { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', marginBottom: 4 } }>
								Deduplication Engine
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
								<span style={ { width: 9, height: 9, borderRadius: '50%', background: 'var(--odrplt-primary)' } } />
								<span style={ { fontWeight: 600, fontSize: 13 } }>
									Shared Event ID ({ stats?.dedup_count ?? 0 } deduplicated)
								</span>
							</div>
						</div>

						<div className="odrplt-card" style={ { padding: '14px 18px' } }>
							<div style={ { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', marginBottom: 4 } }>
								Purchase Trigger
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
								<span style={ { fontWeight: 600, fontSize: 13 } }>
									{ triggerLabels[ fbTrigger ] || fbTrigger }
								</span>
							</div>
						</div>
					</div>

					{ /* Metric Stat Cards */ }
					<div className="odrplt-stats-grid" style={ { marginBottom: 24 } }>
						<div className="odrplt-stat-card odrplt-stat-card--primary">
							<div className="odrplt-stat-card__icon">📘</div>
							<div className="odrplt-stat-card__content">
								<div className="odrplt-stat-card__value">{ ( stats?.total_pixel || 0 ) + ( stats?.total_capi || 0 ) }</div>
								<div className="odrplt-stat-card__label">Total Meta Events (30d)</div>
							</div>
						</div>

						<div className="odrplt-stat-card odrplt-stat-card--info">
							<div className="odrplt-stat-card__icon">🌐</div>
							<div className="odrplt-stat-card__content">
								<div className="odrplt-stat-card__value">{ stats?.total_pixel ?? 0 }</div>
								<div className="odrplt-stat-card__label">Browser Pixel Events</div>
							</div>
						</div>

						<div className="odrplt-stat-card odrplt-stat-card--success">
							<div className="odrplt-stat-card__icon">⚡</div>
							<div className="odrplt-stat-card__content">
								<div className="odrplt-stat-card__value">{ stats?.total_capi ?? 0 }</div>
								<div className="odrplt-stat-card__label">Server CAPI Events</div>
							</div>
						</div>

						<div className="odrplt-stat-card">
							<div className="odrplt-stat-card__icon">🔗</div>
							<div className="odrplt-stat-card__content">
								<div className="odrplt-stat-card__value">{ stats?.dedup_count ?? 0 }</div>
								<div className="odrplt-stat-card__label">Deduplicated Pairs</div>
							</div>
						</div>
					</div>

					{ /* 7-Day Activity & Breakdown */ }
					<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(380px, 1fr))', gap: 20, marginBottom: 24 } }>
						{ /* 7-Day Chart */ }
						<div className="odrplt-card">
							<h3 style={ { margin: '0 0 16px', fontSize: 15, fontWeight: 600 } }>📅 7-Day Meta Event Activity</h3>
							{ ! stats?.daily || stats.daily.length === 0 ? (
								<div style={ { padding: '30px 0', textAlign: 'center', color: 'var(--odrplt-text-muted)', fontSize: 13 } }>
									No Meta events recorded in the last 7 days.
								</div>
							) : (
								<div style={ { display: 'flex', alignItems: 'flex-end', gap: 12, height: 160, paddingTop: 20 } }>
									{ stats.daily.map( ( d ) => {
										const max = Math.max( ...stats.daily.map( ( x ) => ( x.pixel || 0 ) + ( x.capi || 0 ) ), 1 );
										const total = ( d.pixel || 0 ) + ( d.capi || 0 );
										const heightPercent = Math.round( ( total / max ) * 100 );
										return (
											<div key={ d.day } style={ { flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, height: '100%', justifyContent: 'flex-end' } }>
												<div style={ { fontSize: 10, fontWeight: 600, color: 'var(--odrplt-text-muted)' } }>{ total }</div>
												<div style={ { width: '100%', maxWidth: 28, height: `${ Math.max( heightPercent, 8 ) }%`, display: 'flex', flexDirection: 'column', borderRadius: 4, overflow: 'hidden' } }>
													<div style={ { height: `${ ( d.capi / ( total || 1 ) ) * 100 }%`, background: 'var(--odrplt-success)' } } title={ `CAPI: ${ d.capi }` } />
													<div style={ { height: `${ ( d.pixel / ( total || 1 ) ) * 100 }%`, background: 'var(--odrplt-info)' } } title={ `Pixel: ${ d.pixel }` } />
												</div>
												<div style={ { fontSize: 10, color: 'var(--odrplt-text-muted)' } }>{ d.day.slice( 5 ) }</div>
											</div>
										);
									} ) }
								</div>
							) }
							<div style={ { display: 'flex', gap: 16, marginTop: 14, justifyContent: 'center', fontSize: 12 } }>
								<div style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
									<span style={ { width: 10, height: 10, background: 'var(--odrplt-info)', borderRadius: 2 } } /> Pixel (Browser)
								</div>
								<div style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
									<span style={ { width: 10, height: 10, background: 'var(--odrplt-success)', borderRadius: 2 } } /> CAPI (Server)
								</div>
							</div>
						</div>

						{ /* Event Breakdown Table */ }
						<div className="odrplt-card">
							<h3 style={ { margin: '0 0 16px', fontSize: 15, fontWeight: 600 } }>🎯 Meta Event Distribution (30d)</h3>
							{ ! stats?.breakdown || stats.breakdown.filter( ( b ) => ( b.pixel || 0 ) + ( b.capi || 0 ) > 0 ).length === 0 ? (
								<div style={ { padding: '30px 0', textAlign: 'center', color: 'var(--odrplt-text-muted)', fontSize: 13 } }>
									No Meta event records found. Visit your store or click "Send Test CAPI Event" to record telemetry.
								</div>
							) : (
								<table className="odrplt-table">
									<thead>
										<tr>
											<th>Event</th>
											<th style={ { textAlign: 'center' } }>Pixel</th>
											<th style={ { textAlign: 'center' } }>CAPI</th>
											<th style={ { textAlign: 'right' } }>Total</th>
										</tr>
									</thead>
									<tbody>
										{ stats.breakdown
											.filter( ( b ) => ( b.pixel || 0 ) + ( b.capi || 0 ) > 0 )
											.map( ( b ) => (
												<tr key={ b.event }>
													<td style={ { fontWeight: 600 } }>
														<code>{ b.event }</code>
													</td>
													<td style={ { textAlign: 'center' } }>
														<span className="odrplt-badge" style={ { background: 'var(--odrplt-info-bg)', color: 'var(--odrplt-info-text)' } }>
															{ b.pixel || 0 }
														</span>
													</td>
													<td style={ { textAlign: 'center' } }>
														<span className="odrplt-badge" style={ { background: 'var(--odrplt-success-bg)', color: 'var(--odrplt-success-text)' } }>
															{ b.capi || 0 }
														</span>
													</td>
													<td style={ { textAlign: 'right', fontWeight: 600 } }>
														{ ( b.pixel || 0 ) + ( b.capi || 0 ) }
													</td>
												</tr>
											) ) }
									</tbody>
								</table>
							) }
						</div>
					</div>

					{ /* Quick Setup Card */ }
					<div className="odrplt-card" style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 14 } }>
						<div>
							<h4 style={ { margin: '0 0 4px', fontSize: 14 } }>🛠️ Need to update Meta Pixel or Conversions API configuration?</h4>
							<p style={ { margin: 0, fontSize: 13, color: 'var(--odrplt-text-muted)' } }>
								Configure your Pixel ID, Access Token, and purchase triggers directly in Settings.
							</p>
						</div>
						<div style={ { display: 'flex', gap: 10 } }>
							<a href="?page=order-pilot-settings&tab=pixel" className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm">
								Configure Meta Pixel
							</a>
							<a href="?page=order-pilot-settings&tab=capi" className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm">
								Configure Meta CAPI
							</a>
						</div>
					</div>
				</div>
			) }

			{ /* ─── TAB 2: TIKTOK ─── */ }
			{ ! loadingStats && activeTab === 'tiktok' && (
				<div>
					{ /* Status Strip */ }
					<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, marginBottom: 24 } }>
						<div className="odrplt-card" style={ { padding: '14px 18px' } }>
							<div style={ { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', marginBottom: 4 } }>
								TikTok Pixel ID
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
								<span style={ { width: 9, height: 9, borderRadius: '50%', background: tiktokPixelId ? 'var(--odrplt-success)' : 'var(--odrplt-danger)' } } />
								<span style={ { fontWeight: 600, fontSize: 13 } }>
									{ tiktokPixelId ? `Active (${ tiktokPixelId })` : 'Not Configured' }
								</span>
							</div>
						</div>

						<div className="odrplt-card" style={ { padding: '14px 18px' } }>
							<div style={ { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', marginBottom: 4 } }>
								Tracking Status
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
								<span style={ { width: 9, height: 9, borderRadius: '50%', background: tiktokEnabled ? 'var(--odrplt-success)' : 'var(--odrplt-text-subtle)' } } />
								<span style={ { fontWeight: 600, fontSize: 13 } }>
									{ tiktokEnabled ? 'Enabled' : 'Disabled' }
								</span>
							</div>
						</div>

						<div className="odrplt-card" style={ { padding: '14px 18px' } }>
							<div style={ { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', marginBottom: 4 } }>
								Advanced Matching
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
								<span style={ { width: 9, height: 9, borderRadius: '50%', background: 'var(--odrplt-primary)' } } />
								<span style={ { fontWeight: 600, fontSize: 13 } }>
									SHA-256 Customer Match
								</span>
							</div>
						</div>

						<div className="odrplt-card" style={ { padding: '14px 18px' } }>
							<div style={ { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', marginBottom: 4 } }>
								Purchase Trigger
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
								<span style={ { fontWeight: 600, fontSize: 13 } }>
									{ triggerLabels[ tiktokTrigger ] || tiktokTrigger }
								</span>
							</div>
						</div>
					</div>

					{ /* Metric Stat Cards */ }
					{ ( () => {
						const tiktokBreakdown = stats?.breakdown?.filter( ( b ) => ( b.tiktok || 0 ) > 0 ) || [];
						const purchaseCount   = stats?.breakdown?.find( ( b ) => b.event?.toLowerCase() === 'completepayment' || b.event?.toLowerCase() === 'purchase' )?.tiktok || 0;
						const atcCount        = stats?.breakdown?.find( ( b ) => b.event?.toLowerCase() === 'addtocart' )?.tiktok || 0;
						const vcCount         = stats?.breakdown?.find( ( b ) => b.event?.toLowerCase() === 'viewcontent' )?.tiktok || 0;

						return (
							<>
								<div className="odrplt-stats-grid" style={ { marginBottom: 24 } }>
									<div className="odrplt-stat-card odrplt-stat-card--primary">
										<div className="odrplt-stat-card__icon">🎵</div>
										<div className="odrplt-stat-card__content">
											<div className="odrplt-stat-card__value">{ stats?.total_tiktok ?? 0 }</div>
											<div className="odrplt-stat-card__label">Total TikTok Events (30d)</div>
										</div>
									</div>

									<div className="odrplt-stat-card odrplt-stat-card--success">
										<div className="odrplt-stat-card__icon">💰</div>
										<div className="odrplt-stat-card__content">
											<div className="odrplt-stat-card__value">{ purchaseCount }</div>
											<div className="odrplt-stat-card__label">Purchases Tracked</div>
										</div>
									</div>

									<div className="odrplt-stat-card odrplt-stat-card--info">
										<div className="odrplt-stat-card__icon">🛒</div>
										<div className="odrplt-stat-card__content">
											<div className="odrplt-stat-card__value">{ atcCount }</div>
											<div className="odrplt-stat-card__label">AddToCart Events</div>
										</div>
									</div>

									<div className="odrplt-stat-card">
										<div className="odrplt-stat-card__icon">👁️</div>
										<div className="odrplt-stat-card__content">
											<div className="odrplt-stat-card__value">{ vcCount }</div>
											<div className="odrplt-stat-card__label">ViewContent Events</div>
										</div>
									</div>
								</div>

								{ /* 7-Day Activity & Breakdown */ }
								<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(380px, 1fr))', gap: 20, marginBottom: 24 } }>
									{ /* 7-Day Chart */ }
									<div className="odrplt-card">
										<h3 style={ { margin: '0 0 16px', fontSize: 15, fontWeight: 600 } }>📅 7-Day TikTok Activity</h3>
										{ ! stats?.daily || stats.daily.every( ( x ) => ( x.tiktok || 0 ) === 0 ) ? (
											<div style={ { padding: '30px 0', textAlign: 'center', color: 'var(--odrplt-text-muted)', fontSize: 13 } }>
												No TikTok events recorded in the last 7 days.
											</div>
										) : (
											<div style={ { display: 'flex', alignItems: 'flex-end', gap: 12, height: 160, paddingTop: 20 } }>
												{ stats.daily.map( ( d ) => {
													const max = Math.max( ...stats.daily.map( ( x ) => x.tiktok || 0 ), 1 );
													const total = d.tiktok || 0;
													const heightPercent = Math.round( ( total / max ) * 100 );
													return (
														<div key={ d.day } style={ { flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, height: '100%', justifyContent: 'flex-end' } }>
															<div style={ { fontSize: 10, fontWeight: 600, color: 'var(--odrplt-text-muted)' } }>{ total }</div>
															<div style={ { width: '100%', maxWidth: 28, height: `${ Math.max( heightPercent, 8 ) }%`, background: '#fe2c55', borderRadius: 4 } } title={ `TikTok: ${ total }` } />
															<div style={ { fontSize: 10, color: 'var(--odrplt-text-muted)' } }>{ d.day.slice( 5 ) }</div>
														</div>
													);
												} ) }
											</div>
										) }
										<div style={ { display: 'flex', gap: 16, marginTop: 14, justifyContent: 'center', fontSize: 12 } }>
											<div style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
												<span style={ { width: 10, height: 10, background: '#fe2c55', borderRadius: 2 } } /> TikTok Web Events
											</div>
										</div>
									</div>

									{ /* Event Breakdown Table */ }
									<div className="odrplt-card">
										<h3 style={ { margin: '0 0 16px', fontSize: 15, fontWeight: 600 } }>🎯 TikTok Event Distribution (30d)</h3>
										{ tiktokBreakdown.length === 0 ? (
											<div style={ { padding: '30px 0', textAlign: 'center', color: 'var(--odrplt-text-muted)', fontSize: 13 } }>
												No TikTok event telemetry recorded yet. Ensure TikTok tracking is enabled in Settings.
											</div>
										) : (
											<table className="odrplt-table">
												<thead>
													<tr>
														<th>Event Name</th>
														<th style={ { textAlign: 'right' } }>Total Tracked</th>
													</tr>
												</thead>
												<tbody>
													{ tiktokBreakdown.map( ( b ) => (
														<tr key={ b.event }>
															<td style={ { fontWeight: 600 } }>
																<code>{ b.event }</code>
															</td>
															<td style={ { textAlign: 'right' } }>
																<span className="odrplt-badge" style={ { background: 'rgba(254, 44, 85, 0.1)', color: '#fe2c55', border: '1px solid rgba(254, 44, 85, 0.2)' } }>
																	{ b.tiktok || 0 }
																</span>
															</td>
														</tr>
													) ) }
												</tbody>
											</table>
										) }
									</div>
								</div>
							</>
						);
					} )() }

					{ /* Quick Setup Card */ }
					<div className="odrplt-card" style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 14 } }>
						<div>
							<h4 style={ { margin: '0 0 4px', fontSize: 14 } }>🛠️ Manage TikTok Pixel Settings</h4>
							<p style={ { margin: 0, fontSize: 13, color: 'var(--odrplt-text-muted)' } }>
								Configure your TikTok Pixel ID, toggle specific events, or adjust purchase triggers.
							</p>
						</div>
						<a href="?page=order-pilot-settings&tab=tiktok" className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm">
							Open TikTok Settings
						</a>
					</div>
				</div>
			) }

			{ /* ─── TAB 3: GOOGLE (GA4) ─── */ }
			{ ! loadingStats && activeTab === 'google' && (
				<div>
					{ /* Status Strip */ }
					<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, marginBottom: 24 } }>
						<div className="odrplt-card" style={ { padding: '14px 18px' } }>
							<div style={ { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', marginBottom: 4 } }>
								GA4 Measurement ID
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
								<span style={ { width: 9, height: 9, borderRadius: '50%', background: ga4Id ? 'var(--odrplt-success)' : 'var(--odrplt-danger)' } } />
								<span style={ { fontWeight: 600, fontSize: 13 } }>
									{ ga4Id ? `Active (${ ga4Id })` : 'Not Configured' }
								</span>
							</div>
						</div>

						<div className="odrplt-card" style={ { padding: '14px 18px' } }>
							<div style={ { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', marginBottom: 4 } }>
								Tracking Status
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
								<span style={ { width: 9, height: 9, borderRadius: '50%', background: ga4Enabled ? 'var(--odrplt-success)' : 'var(--odrplt-text-subtle)' } } />
								<span style={ { fontWeight: 600, fontSize: 13 } }>
									{ ga4Enabled ? 'Enabled' : 'Disabled' }
								</span>
							</div>
						</div>

						<div className="odrplt-card" style={ { padding: '14px 18px' } }>
							<div style={ { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', marginBottom: 4 } }>
								Analytics Protocol
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
								<span style={ { width: 9, height: 9, borderRadius: '50%', background: '#ea4335' } } />
								<span style={ { fontWeight: 600, fontSize: 13 } }>
									gtag.js & Enhanced Ecommerce
								</span>
							</div>
						</div>

						<div className="odrplt-card" style={ { padding: '14px 18px' } }>
							<div style={ { fontSize: 11, fontWeight: 600, textTransform: 'uppercase', color: 'var(--odrplt-text-muted)', marginBottom: 4 } }>
								Purchase Trigger
							</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
								<span style={ { fontWeight: 600, fontSize: 13 } }>
									{ triggerLabels[ ga4Trigger ] || ga4Trigger }
								</span>
							</div>
						</div>
					</div>

					{ /* Metric Stat Cards */ }
					{ ( () => {
						const ga4Breakdown  = stats?.breakdown?.filter( ( b ) => ( b.ga4 || 0 ) > 0 ) || [];
						const purchaseCount = stats?.breakdown?.find( ( b ) => b.event?.toLowerCase() === 'purchase' )?.ga4 || 0;
						const checkoutCount = stats?.breakdown?.find( ( b ) => b.event?.toLowerCase() === 'begin_checkout' )?.ga4 || 0;
						const viewItemCount = stats?.breakdown?.find( ( b ) => b.event?.toLowerCase() === 'view_item' )?.ga4 || 0;

						return (
							<>
								<div className="odrplt-stats-grid" style={ { marginBottom: 24 } }>
									<div className="odrplt-stat-card odrplt-stat-card--primary">
										<div className="odrplt-stat-card__icon">📈</div>
										<div className="odrplt-stat-card__content">
											<div className="odrplt-stat-card__value">{ stats?.total_ga4 ?? 0 }</div>
											<div className="odrplt-stat-card__label">Total GA4 Events (30d)</div>
										</div>
									</div>

									<div className="odrplt-stat-card odrplt-stat-card--success">
										<div className="odrplt-stat-card__icon">💰</div>
										<div className="odrplt-stat-card__content">
											<div className="odrplt-stat-card__value">{ purchaseCount }</div>
											<div className="odrplt-stat-card__label">Purchases Tracked</div>
										</div>
									</div>

									<div className="odrplt-stat-card odrplt-stat-card--info">
										<div className="odrplt-stat-card__icon">💳</div>
										<div className="odrplt-stat-card__content">
											<div className="odrplt-stat-card__value">{ checkoutCount }</div>
											<div className="odrplt-stat-card__label">Begin Checkout Events</div>
										</div>
									</div>

									<div className="odrplt-stat-card">
										<div className="odrplt-stat-card__icon">🛍️</div>
										<div className="odrplt-stat-card__content">
											<div className="odrplt-stat-card__value">{ viewItemCount }</div>
											<div className="odrplt-stat-card__label">Product Views (view_item)</div>
										</div>
									</div>
								</div>

								{ /* 7-Day Activity & Breakdown */ }
								<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(380px, 1fr))', gap: 20, marginBottom: 24 } }>
									{ /* 7-Day Chart */ }
									<div className="odrplt-card">
										<h3 style={ { margin: '0 0 16px', fontSize: 15, fontWeight: 600 } }>📅 7-Day GA4 Activity</h3>
										{ ! stats?.daily || stats.daily.every( ( x ) => ( x.ga4 || 0 ) === 0 ) ? (
											<div style={ { padding: '30px 0', textAlign: 'center', color: 'var(--odrplt-text-muted)', fontSize: 13 } }>
												No GA4 events recorded in the last 7 days.
											</div>
										) : (
											<div style={ { display: 'flex', alignItems: 'flex-end', gap: 12, height: 160, paddingTop: 20 } }>
												{ stats.daily.map( ( d ) => {
													const max = Math.max( ...stats.daily.map( ( x ) => x.ga4 || 0 ), 1 );
													const total = d.ga4 || 0;
													const heightPercent = Math.round( ( total / max ) * 100 );
													return (
														<div key={ d.day } style={ { flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6, height: '100%', justifyContent: 'flex-end' } }>
															<div style={ { fontSize: 10, fontWeight: 600, color: 'var(--odrplt-text-muted)' } }>{ total }</div>
															<div style={ { width: '100%', maxWidth: 28, height: `${ Math.max( heightPercent, 8 ) }%`, background: '#ea4335', borderRadius: 4 } } title={ `GA4: ${ total }` } />
															<div style={ { fontSize: 10, color: 'var(--odrplt-text-muted)' } }>{ d.day.slice( 5 ) }</div>
														</div>
													);
												} ) }
											</div>
										) }
										<div style={ { display: 'flex', gap: 16, marginTop: 14, justifyContent: 'center', fontSize: 12 } }>
											<div style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
												<span style={ { width: 10, height: 10, background: '#ea4335', borderRadius: 2 } } /> GA4 Enhanced Ecommerce
											</div>
										</div>
									</div>

									{ /* Event Breakdown Table */ }
									<div className="odrplt-card">
										<h3 style={ { margin: '0 0 16px', fontSize: 15, fontWeight: 600 } }>🎯 GA4 Event Distribution (30d)</h3>
										{ ga4Breakdown.length === 0 ? (
											<div style={ { padding: '30px 0', textAlign: 'center', color: 'var(--odrplt-text-muted)', fontSize: 13 } }>
												No Google Analytics event records found. Configure your Measurement ID in Settings.
											</div>
										) : (
											<table className="odrplt-table">
												<thead>
													<tr>
														<th>Event Name</th>
														<th style={ { textAlign: 'right' } }>Total Tracked</th>
													</tr>
												</thead>
												<tbody>
													{ ga4Breakdown.map( ( b ) => (
														<tr key={ b.event }>
															<td style={ { fontWeight: 600 } }>
																<code>{ b.event }</code>
															</td>
															<td style={ { textAlign: 'right' } }>
																<span className="odrplt-badge" style={ { background: 'rgba(234, 67, 53, 0.1)', color: '#ea4335', border: '1px solid rgba(234, 67, 53, 0.2)' } }>
																	{ b.ga4 || 0 }
																</span>
															</td>
														</tr>
													) ) }
												</tbody>
											</table>
										) }
									</div>
								</div>
							</>
						);
					} )() }

					{ /* Quick Setup Card */ }
					<div className="odrplt-card" style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 14 } }>
						<div>
							<h4 style={ { margin: '0 0 4px', fontSize: 14 } }>🛠️ Manage Google Analytics 4 Settings</h4>
							<p style={ { margin: 0, fontSize: 13, color: 'var(--odrplt-text-muted)' } }>
								Configure your Measurement ID (G-XXXXXXXXXX) and select events to track.
							</p>
						</div>
						<a href="?page=order-pilot-settings&tab=ga4" className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm">
							Open GA4 Settings
						</a>
					</div>
				</div>
			) }

			{ /* ─── TAB 4: EVENT TELEMETRY LOGS ─── */ }
			{ activeTab === 'logs' && (
				<div className="odrplt-card">
					<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 12 } }>
						<div style={ { display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' } }>
							{ /* Channel Filter */ }
							<select
								className="odrplt-select"
								style={ { width: 170 } }
								value={ channelFilter }
								onChange={ ( e ) => { setChannelFilter( e.target.value ); setLogsPage( 1 ); } }
							>
								<option value="">🌐 All Channels</option>
								<option value="pixel">📘 Meta Pixel (Browser)</option>
								<option value="capi">⚡ Meta CAPI (Server)</option>
								<option value="tiktok">🎵 TikTok Pixel</option>
								<option value="ga4">📈 Google Analytics 4</option>
							</select>

							{ /* Event Filter */ }
							<select
								className="odrplt-select"
								style={ { width: 180 } }
								value={ eventFilter }
								onChange={ ( e ) => { setEventFilter( e.target.value ); setLogsPage( 1 ); } }
							>
								<option value="">🎯 All Events</option>
								<option value="PageView">PageView</option>
								<option value="ViewContent">ViewContent / view_item</option>
								<option value="AddToCart">AddToCart / add_to_cart</option>
								<option value="InitiateCheckout">InitiateCheckout / begin_checkout</option>
								<option value="Purchase">Purchase / purchase</option>
							</select>
						</div>

						<div style={ { display: 'flex', gap: 14, alignItems: 'center' } }>
							<span style={ { fontSize: 13, color: 'var(--odrplt-text-muted)' } }>
								Total: <strong>{ logsTotal }</strong> records
							</span>
							<button
								type="button"
								className="odrplt-btn odrplt-btn--sm"
								style={ { background: 'var(--odrplt-danger)', color: '#fff', borderColor: 'var(--odrplt-danger)' } }
								onClick={ handleOpenDeleteModal }
								disabled={ loadingStats || loadingLogs }
								title="Delete local tracking event records from OrderPilot database"
							>
								<span style={{ fontSize: '16px' }} className="dashicons dashicons-trash"></span> Delete All Events
							</button>
						</div>
					</div>

					{ loadingLogs ? (
						<div className="odrplt-loading-screen"><span className="odrplt-spinner" /></div>
					) : logs.length === 0 ? (
						<div style={ { padding: '40px 0', textAlign: 'center', color: 'var(--odrplt-text-muted)' } }>
							<div style={ { fontSize: 32, marginBottom: 8 } }>📭</div>
							<p style={ { fontSize: 14, fontWeight: 500 } }>No tracking telemetry logs matching your criteria.</p>
						</div>
					) : (
						<>
							<table className="odrplt-table">
								<thead>
									<tr>
										<th>Time</th>
										<th>Event</th>
										<th>Order</th>
										<th>Channel</th>
										<th>Event ID</th>
										<th>Status</th>
										<th style={ { textAlign: 'right' } }>Action</th>
									</tr>
								</thead>
								<tbody>
									{ logs.map( ( log ) => {
										const isCapi      = log.channel === 'capi';
										const isTiktok    = log.channel === 'tiktok';
										const isGa4       = log.channel === 'ga4';
										const statusBadge = getStatusBadgeConfig( getTelemetryStatus( log ) );

										let channelBadgeBg    = 'var(--odrplt-info-bg)';
										let channelBadgeColor = 'var(--odrplt-info-text)';
										let channelBadgeBorder = 'var(--odrplt-info-border)';
										let channelLabel      = '🌐 Meta Pixel';

										if ( isCapi ) {
											channelBadgeBg    = 'var(--odrplt-success-bg)';
											channelBadgeColor = 'var(--odrplt-success-text)';
											channelBadgeBorder = 'var(--odrplt-success-border)';
											channelLabel      = '⚡ Meta CAPI';
										} else if ( isTiktok ) {
											channelBadgeBg    = 'rgba(254, 44, 85, 0.1)';
											channelBadgeColor = '#fe2c55';
											channelBadgeBorder = 'rgba(254, 44, 85, 0.2)';
											channelLabel      = '🎵 TikTok';
										} else if ( isGa4 ) {
											channelBadgeBg    = 'rgba(234, 67, 53, 0.1)';
											channelBadgeColor = '#ea4335';
											channelBadgeBorder = 'rgba(234, 67, 53, 0.2)';
											channelLabel      = '📈 GA4';
										}

										return (
											<tr key={ log.id }>
												<td style={ { fontSize: 12, color: 'var(--odrplt-text-muted)', whiteSpace: 'nowrap' } }>
													{ log.created_at }
												</td>
												<td>
													<code style={ { fontWeight: 600 } }>{ log.event_name }</code>
												</td>
												<td>
													{ log.order_id ? `#${ log.order_id }` : <span style={ { color: 'var(--odrplt-text-subtle)' } }>—</span> }
												</td>
												<td>
													<span
														className="odrplt-badge"
														style={ {
															background: channelBadgeBg,
															color: channelBadgeColor,
															border: `1px solid ${ channelBadgeBorder }`,
														} }
													>
														{ channelLabel }
													</span>
												</td>
												<td style={ { fontSize: 12 } }>
													{ log.event_id ? (
														<code>{ log.event_id }</code>
													) : (
														<span style={ { color: 'var(--odrplt-text-subtle)' } }>—</span>
													) }
												</td>
												<td>
													<span
														className="odrplt-badge"
														style={ statusBadge.style }
													>
														{ statusBadge.label }
													</span>
												</td>
												<td style={ { textAlign: 'right' } }>
													<button
														className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
														onClick={ () => setSelectedLog( log ) }
													>
														Inspect
													</button>
												</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>

							{ /* Pagination */ }
							{ logsTotalPages > 1 && (
								<div style={ { display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 10, marginTop: 18 } }>
									<button
										className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
										disabled={ logsPage <= 1 }
										onClick={ () => setLogsPage( logsPage - 1 ) }
									>
										◀ Previous
									</button>
									<span style={ { fontSize: 13 } }>
										Page <strong>{ logsPage }</strong> of <strong>{ logsTotalPages }</strong>
									</span>
									<button
										className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
										disabled={ logsPage >= logsTotalPages }
										onClick={ () => setLogsPage( logsPage + 1 ) }
									>
										Next ▶
									</button>
								</div>
							) }
						</>
					) }
				</div>
			) }

			{ /* ─── TAB 5: DIAGNOSTICS & SETUP ─── */ }
			{ activeTab === 'diagnostics' && (
				<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(360px, 1fr))', gap: 20 } }>
					{ /* Meta Checklist */ }
					<div className="odrplt-card">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 } }>
							<h3 style={ { margin: 0, fontSize: 15, fontWeight: 600 } }>📘 Meta Pixel & CAPI Diagnostic</h3>
							<a href="?page=order-pilot-settings&tab=pixel" style={ { fontSize: 12, fontWeight: 600, textDecoration: 'underline' } }>
								Configure ↗
							</a>
						</div>
						<div style={ { display: 'flex', flexDirection: 'column', gap: 14 } }>
							<CheckItem
								done={ !! pixelId }
								title="Meta Pixel Base Code (Browser)"
								desc={ pixelId ? `Configured with Pixel ID: ${ pixelId }` : 'Pixel ID is missing in Settings → Meta Pixel.' }
							/>
							<CheckItem
								done={ capiEnabled && !! capiToken }
								title="Meta Conversions API (Server-Side)"
								desc={ capiEnabled && capiToken ? 'Graph API v21.0 enabled with valid Access Token.' : 'Enable CAPI and provide Access Token in Settings → Conversions API.' }
							/>
							<CheckItem
								done={ true }
								title="Event Deduplication Engine"
								desc="Shared event_id generated on both browser and server payloads for duplicate filtering."
							/>
							<CheckItem
								done={ true }
								title="FBP & FBC First-Party Cookie Capture"
								desc="Captures _fbp and _fbc cookies and stores them in WooCommerce order meta at checkout."
							/>
							<CheckItem
								done={ true }
								title="SHA-256 Advanced Customer Matching"
								desc="Customer email, phone, name, city, and external ID are normalized and hashed."
							/>
							<CheckItem
								done={ !! testCode }
								title="Test Event Code (Sandbox)"
								desc={ testCode ? `Active test code: ${ testCode }` : 'Optional: Add TESTxxxxx from Events Manager to test in sandbox.' }
							/>
						</div>

						<div style={ { marginTop: 16, paddingTop: 14, borderTop: '1px solid var(--odrplt-border)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10 } }>
							<div>
								<div style={ { fontSize: 13, fontWeight: 600 } }>Meta CAPI Connectivity Test</div>
								<div style={ { fontSize: 12, color: 'var(--odrplt-text-muted)' } }>
									Send a test Purchase payload to Meta Graph API to verify server-side tracking.
								</div>
							</div>
							<button
								type="button"
								className="odrplt-btn odrplt-btn--primary odrplt-btn--sm"
								onClick={ handleSendTestEvent }
								disabled={ testLoading || ! capiEnabled || ! capiToken }
								title={ ! capiEnabled || ! capiToken ? 'Enable CAPI with an Access Token in Settings first' : 'Send a test Purchase event to Meta Graph API' }
							>
								{ testLoading ? 'Sending...' : '⚡ Send Test CAPI Event' }
							</button>
						</div>
					</div>

					{ /* TikTok Checklist */ }
					<div className="odrplt-card">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 } }>
							<h3 style={ { margin: 0, fontSize: 15, fontWeight: 600 } }>🎵 TikTok Pixel Diagnostic</h3>
							<a href="?page=order-pilot-settings&tab=tiktok" style={ { fontSize: 12, fontWeight: 600, textDecoration: 'underline' } }>
								Configure ↗
							</a>
						</div>
						<div style={ { display: 'flex', flexDirection: 'column', gap: 14 } }>
							<CheckItem
								done={ !! tiktokPixelId }
								title="TikTok Pixel ID"
								desc={ tiktokPixelId ? `Configured with ID: ${ tiktokPixelId }` : 'TikTok Pixel ID is missing in Settings → TikTok Pixel.' }
							/>
							<CheckItem
								done={ tiktokEnabled }
								title="TikTok Pixel Tracking Enabled"
								desc={ tiktokEnabled ? 'TikTok web events tracking is active.' : 'Enable TikTok tracking in Settings → TikTok Pixel.' }
							/>
							<CheckItem
								done={ true }
								title="Advanced Customer Matching"
								desc="Email and phone numbers are normalized (E.164) and SHA-256 hashed before transmission."
							/>
							<CheckItem
								done={ true }
								title="Ecommerce Event Telemetry"
								desc="Maps ViewContent, AddToCart, InitiateCheckout, and Purchase to TikTok standards."
							/>
							<CheckItem
								done={ true }
								title="COD-Aware Purchase Trigger"
								desc="Optionally delay TikTok Purchase events until successful courier delivery for Cash on Delivery."
							/>
						</div>
					</div>

					{ /* GA4 Checklist */ }
					<div className="odrplt-card">
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 } }>
							<h3 style={ { margin: 0, fontSize: 15, fontWeight: 600 } }>📈 Google Analytics 4 Diagnostic</h3>
							<a href="?page=order-pilot-settings&tab=ga4" style={ { fontSize: 12, fontWeight: 600, textDecoration: 'underline' } }>
								Configure ↗
							</a>
						</div>
						<div style={ { display: 'flex', flexDirection: 'column', gap: 14 } }>
							<CheckItem
								done={ !! ga4Id }
								title="GA4 Measurement ID"
								desc={ ga4Id ? `Configured with ID: ${ ga4Id }` : 'Measurement ID (G-XXXXXXXXXX) is missing in Settings → GA4.' }
							/>
							<CheckItem
								done={ ga4Enabled }
								title="Google Analytics 4 Tracking Enabled"
								desc={ ga4Enabled ? 'GA4 event tracking is active across your store.' : 'Enable GA4 tracking in Settings → GA4.' }
							/>
							<CheckItem
								done={ true }
								title="Enhanced Ecommerce Payload"
								desc="Automatic formatting of items, currency, prices, transaction ID, and value."
							/>
							<CheckItem
								done={ true }
								title="Full Funnel Event Mapping"
								desc="Tracks view_item, add_to_cart, begin_checkout, and purchase."
							/>
						</div>
					</div>
				</div>
			) }

			{ /* ── Inspect Log Modal ── */ }
			{ selectedLog && ( () => {
				const inspectDetails = getTelemetryInspectDetails( selectedLog );
				const badge = getStatusBadgeConfig( inspectDetails.status );

				return (
					<div
						style={ {
							position: 'fixed',
							inset: 0,
							background: 'rgba(15, 23, 42, 0.65)',
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
								maxWidth: 620,
								width: '100%',
								maxHeight: '90vh',
								overflowY: 'auto',
								margin: 0,
								boxShadow: 'var(--odrplt-shadow-xl)',
							} }
						>
							<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 } }>
								<h3 style={ { margin: 0, fontSize: 16 } }>
									🔍 Inspect Telemetry Event
								</h3>
								<button
									onClick={ () => setSelectedLog( null ) }
									style={ { background: 'none', border: 'none', cursor: 'pointer', fontSize: 20 } }
								>
									&times;
								</button>
							</div>

							<div
								style={ {
									display: 'grid',
									gridTemplateColumns: '1fr 1fr',
									gap: '12px 16px',
									fontSize: 13,
									marginBottom: 16,
									background: 'var(--odrplt-bg-subtle, #f8fafc)',
									padding: '14px 16px',
									borderRadius: 8,
									border: '1px solid var(--odrplt-border, #e2e8f0)',
								} }
							>
								<div>
									<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 12, display: 'block', marginBottom: 4 } }>Status</span>
									<span className="odrplt-badge" style={ badge.style }>
										{ badge.label }
									</span>
								</div>
								<div>
									<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 12, display: 'block', marginBottom: 4 } }>HTTP Status Code</span>
									<code style={ { fontWeight: 600, fontSize: 13 } }>{ inspectDetails.httpCodeText }</code>
								</div>
								<div style={ { gridColumn: 'span 2' } }>
									<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 12, display: 'block', marginBottom: 4 } }>API Response</span>
									<span style={ { fontWeight: 600, color: inspectDetails.status === 'Failed' ? 'var(--odrplt-danger-text)' : 'var(--odrplt-text)' } }>
										{ inspectDetails.apiResponse }
									</span>
								</div>
								<div>
									<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 12, display: 'block', marginBottom: 4 } }>Channel</span>
									<span style={ { fontWeight: 600 } }>{ selectedLog.channel?.toUpperCase() }</span>
								</div>
								<div>
									<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 12, display: 'block', marginBottom: 4 } }>Event Name</span>
									<code style={ { fontWeight: 600 } }>{ selectedLog.event_name }</code>
								</div>
								<div>
									<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 12, display: 'block', marginBottom: 4 } }>Event ID</span>
									<code>{ selectedLog.event_id || '—' }</code>
								</div>
								<div>
									<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 12, display: 'block', marginBottom: 4 } }>Order ID</span>
									<span>{ selectedLog.order_id ? ('#' + selectedLog.order_id) : '—' }</span>
								</div>
								<div style={ { gridColumn: 'span 2' } }>
									<span style={ { color: 'var(--odrplt-text-muted)', fontSize: 12, display: 'block', marginBottom: 4 } }>Timestamp</span>
									<span style={ { fontSize: 12, color: 'var(--odrplt-text-muted)' } }>{ selectedLog.created_at }</span>
								</div>
							</div>

							{ inspectDetails.errorDetails && (
								<div
									style={ {
										background: 'var(--odrplt-danger-bg)',
										border: '1px solid var(--odrplt-danger-border)',
										color: 'var(--odrplt-danger-text)',
										padding: '10px 14px',
										borderRadius: 6,
										fontSize: 12,
										marginBottom: 16,
									} }
								>
									<strong>Error Details:</strong> { inspectDetails.errorDetails }
								</div>
							) }

							{ selectedLog.payload && (
								<div>
									<div style={ { fontSize: 12, fontWeight: 600, marginBottom: 6, color: 'var(--odrplt-text-muted)' } }>
										Payload JSON:
									</div>
									<pre
										style={ {
											background: '#0f172a',
											color: '#38bdf8',
											padding: 14,
											borderRadius: 6,
											fontSize: 12,
											lineHeight: 1.5,
											maxHeight: 260,
											overflowY: 'auto',
											margin: 0,
										} }
									>
										{ ( () => {
											try {
												const parsed = typeof selectedLog.payload === 'string' ? JSON.parse( selectedLog.payload ) : selectedLog.payload;
												return JSON.stringify( parsed, null, 2 );
											} catch ( e ) {
												return String( selectedLog.payload );
											}
										} )() }
									</pre>
								</div>
							) }

							<div style={ { display: 'flex', justifyContent: 'flex-end', marginTop: 16 } }>
								<button className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm" onClick={ () => setSelectedLog( null ) }>
									Close
								</button>
							</div>
						</div>
					</div>
				);
			} )() }

			{ /* ── Delete All Events Confirmation Modal ── */ }
			{ showDeleteModal && (
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
						{ /* Modal header */ }
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 } }>
							<h3 style={ { margin: 0, fontSize: 16, color: 'var(--odrplt-danger)' } }>
								<span style={{ fontSize: '16px' }} className="dashicons dashicons-trash"></span> Delete All Tracking Events
							</h3>
							<button
								onClick={ () => setShowDeleteModal( false ) }
								style={ { background: 'none', border: 'none', cursor: 'pointer', fontSize: 20, color: 'var(--odrplt-text-muted)' } }
								disabled={ deleteLoading }
							>
								&times;
							</button>
						</div>

						{ /* Warning message with count */ }
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
							This will permanently delete{ ' ' }
							<strong>{ deleteCount.toLocaleString() } local tracking event{ deleteCount !== 1 ? 's' : '' }</strong>{ ' ' }
							from the OrderPilot database.
							<br />
							<span style={ { fontSize: 12, opacity: 0.85, marginTop: 6, display: 'block' } }>
								No data will be removed from Meta, GA4, or TikTok servers. Only local telemetry records are affected.
							</span>
						</div>

						{ /* Actions */ }
						<div style={ { display: 'flex', gap: 10, justifyContent: 'flex-end' } }>
							<button
								className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
								onClick={ () => setShowDeleteModal( false ) }
								disabled={ deleteLoading }
							>
								Cancel
							</button>
							<button
								className="odrplt-btn odrplt-btn--sm"
								style={ { background: 'var(--odrplt-danger)', color: '#fff', borderColor: 'var(--odrplt-danger)', minWidth: 130 } }
								onClick={ handleConfirmDelete }
								disabled={ deleteLoading }
							>
								{ deleteLoading ? '⏳ Deleting...' : `🗑️ Delete ${ deleteCount.toLocaleString() } Event${ deleteCount !== 1 ? 's' : '' }` }
							</button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}

function CheckItem( { done, title, desc } ) {
	return (
		<div style={ { display: 'flex', gap: 12, alignItems: 'flex-start' } }>
			<span
				style={ {
					fontSize: 16,
					lineHeight: 1,
					marginTop: 2,
					color: done ? 'var(--odrplt-success)' : 'var(--odrplt-danger)',
				} }
			>
				{ done ? '✅' : '❌' }
			</span>
			<div>
				<div style={ { fontWeight: 600, fontSize: 13, color: 'var(--odrplt-text)' } }>{ title }</div>
				<div style={ { fontSize: 12, color: 'var(--odrplt-text-muted)', marginTop: 2 } }>{ desc }</div>
			</div>
		</div>
	);
}
