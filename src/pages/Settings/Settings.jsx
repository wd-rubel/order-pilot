/**
 * Settings Page
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function Settings() {
	const [ tab, setTab ]           = useState( 'general' );
	const [ settings, setSettings ] = useState( null );
	const [ loading, setLoading ]   = useState( true );
	const [ saving, setSaving ]     = useState( false );
	const [ notice, setNotice ]     = useState( null );

	useEffect( () => {
		apiFetch( { path: 'order-pilot/v1/settings' } )
			.then( ( data ) => { setSettings( data ); } )
			.finally( () => setLoading( false ) );
	}, [] );

	const handleSave = () => {
		setSaving( true );
		apiFetch( {
			path: 'order-pilot/v1/settings',
			method: 'POST',
			data: { tab, [ tab ]: settings[ tab ] },
		} )
			.then( () => setNotice( { type: 'success', text: 'Settings saved.' } ) )
			.catch( ( err ) => setNotice( { type: 'error', text: err.message } ) )
			.finally( () => setSaving( false ) );
	};

	const TABS = [
		{ key: 'general',  label: '⚙️ General' },
		{ key: 'pixel',    label: '📊 Meta Pixel' },
		{ key: 'capi',     label: '🖥️ Conversions API', pro: true },
		{ key: 'fraud',    label: '🛡️ Fraud', pro: true },
	];

	return (
		<div>
			<div className="op-page-header">
				<h1>⚙️ Settings</h1>
				<p>Configure Order Pilot to match your store's workflow.</p>
			</div>

			{ notice && (
				<div className={ `op-notice op-notice--${ notice.type }` }>
					{ notice.text }
					<button
						style={ { marginLeft: 'auto', background: 'none', border: 'none', cursor: 'pointer' } }
						onClick={ () => setNotice( null ) }
					>✕</button>
				</div>
			) }

			<div className="op-card">
				{ /* Tab bar */ }
				<div style={ { display: 'flex', gap: '4px', marginBottom: '20px', borderBottom: '1px solid var(--op-border)', paddingBottom: '0' } }>
					{ TABS.map( ( t ) => (
						<button
							key={ t.key }
							onClick={ () => setTab( t.key ) }
							style={ {
								padding: '10px 16px',
								background: 'none',
								border: 'none',
								borderBottom: tab === t.key ? '2px solid var(--op-primary)' : '2px solid transparent',
								color: tab === t.key ? 'var(--op-primary)' : 'var(--op-text-2)',
								fontWeight: tab === t.key ? '600' : '400',
								cursor: 'pointer',
								fontSize: '13px',
								whiteSpace: 'nowrap',
								marginBottom: '-1px',
							} }
						>
							{ t.label }
							{ t.pro && <span className="op-pro-badge">PRO</span> }
						</button>
					) ) }
				</div>

				{ loading ? (
					<div className="op-loading-screen"><span className="op-spinner" /></div>
				) : (
					<>
						{ tab === 'general' && <GeneralSettings settings={ settings?.general || {} } setSettings={ ( v ) => setSettings( { ...settings, general: v } ) } /> }
						{ tab === 'pixel' && <PixelSettings settings={ settings?.pixel || {} } setSettings={ ( v ) => setSettings( { ...settings, pixel: v } ) } /> }
						{ ( tab === 'capi' || tab === 'fraud' ) && (
							<div className="op-pro-gate">
								<div className="op-pro-gate__icon">🔒</div>
								<h2 className="op-pro-gate__title">Pro Feature</h2>
								<p className="op-pro-gate__desc">Upgrade to Order Pilot Pro to access these settings.</p>
							</div>
						) }

						{ ( tab === 'general' || tab === 'pixel' ) && (
							<div style={ { marginTop: '24px' } }>
								<button className="op-btn op-btn--primary" onClick={ handleSave } disabled={ saving }>
									{ saving ? 'Saving…' : 'Save Settings' }
								</button>
							</div>
						) }
					</>
				) }
			</div>
		</div>
	);
}

function GeneralSettings( { settings, setSettings } ) {
	const update = ( key, val ) => setSettings( { ...settings, [ key ]: val } );
	return (
		<div>
			<div className="op-form-group">
				<label>Log Retention (days)</label>
				<input
					type="number"
					className="op-input"
					style={ { maxWidth: 120 } }
					value={ settings.log_retention || 30 }
					onChange={ ( e ) => update( 'log_retention', parseInt( e.target.value ) ) }
				/>
				<p className="op-form-help">Courier and tracking logs older than this many days will be deleted automatically.</p>
			</div>
			<div className="op-form-group">
				<label>
					<input
						type="checkbox"
						checked={ !! settings.enable_courier }
						onChange={ ( e ) => update( 'enable_courier', e.target.checked ) }
						style={ { marginRight: 8 } }
					/>
					Enable Courier Features
				</label>
			</div>
			<div className="op-form-group">
				<label>
					<input
						type="checkbox"
						checked={ !! settings.enable_pixel }
						onChange={ ( e ) => update( 'enable_pixel', e.target.checked ) }
						style={ { marginRight: 8 } }
					/>
					Enable Meta Pixel Tracking
				</label>
			</div>
		</div>
	);
}

function PixelSettings( { settings, setSettings } ) {
	const update = ( key, val ) => setSettings( { ...settings, [ key ]: val } );
	const updateEvent = ( key, val ) => setSettings( { ...settings, events: { ...settings.events, [ key ]: val } } );

	const EVENTS = [
		{ key: 'page_view',          label: 'PageView' },
		{ key: 'view_content',       label: 'ViewContent' },
		{ key: 'add_to_cart',        label: 'AddToCart' },
		{ key: 'initiate_checkout',  label: 'InitiateCheckout' },
		{ key: 'purchase',           label: 'Purchase' },
	];

	return (
		<div>
			<div className="op-form-group">
				<label>Facebook / Meta Pixel ID</label>
				<input
					type="text"
					className="op-input"
					style={ { maxWidth: 300 } }
					value={ settings.pixel_id || '' }
					onChange={ ( e ) => update( 'pixel_id', e.target.value ) }
					placeholder="1234567890"
				/>
			</div>

			<div className="op-form-group">
				<label>Purchase Event Trigger</label>
				<select
					className="op-input op-select"
					style={ { maxWidth: 250 } }
					value={ settings.purchase_trigger || 'order_created' }
					onChange={ ( e ) => update( 'purchase_trigger', e.target.value ) }
				>
					<option value="order_created">Order Created (default)</option>
					<option value="payment_completed" disabled>Payment Completed (Pro)</option>
					<option value="order_delivered" disabled>Order Delivered — COD (Pro)</option>
				</select>
				<p className="op-form-help">COD-aware Purchase tracking (on delivery) requires Pro.</p>
			</div>

			<div className="op-form-group">
				<label style={ { marginBottom: 10 } }>Events to Track</label>
				{ EVENTS.map( ( ev ) => (
					<label key={ ev.key } style={ { display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 } }>
						<input
							type="checkbox"
							checked={ !! ( settings.events || {} )[ ev.key ] }
							onChange={ ( e ) => updateEvent( ev.key, e.target.checked ) }
						/>
						{ ev.label }
					</label>
				) ) }
			</div>
		</div>
	);
}
