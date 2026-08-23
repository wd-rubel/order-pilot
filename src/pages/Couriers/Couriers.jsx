/**
 * Couriers Page
 * Manage courier connections and credentials.
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const { isPro, restUrl, restNonce } = window.orderPilot || {};

export default function Couriers() {
	const [ couriers, setCouriers ]   = useState( [] );
	const [ loading, setLoading ]     = useState( true );
	const [ selected, setSelected ]   = useState( null );
	const [ credentials, setCreds ]   = useState( {} );
	const [ saving, setSaving ]       = useState( false );
	const [ notice, setNotice ]       = useState( null );

	useEffect( () => {
		loadCouriers();
	}, [] );

	const loadCouriers = () => {
		setLoading( true );
		apiFetch( { path: 'order-pilot/v1/couriers' } )
			.then( setCouriers )
			.finally( () => setLoading( false ) );
	};

	const handleConnect = ( slug ) => {
		apiFetch( {
			path: `order-pilot/v1/couriers/${ slug }/connect`,
			method: 'POST',
		} )
			.then( () => { setNotice( { type: 'success', text: 'Courier connected.' } ); loadCouriers(); } )
			.catch( ( err ) => setNotice( { type: 'error', text: err.message } ) );
	};

	const handleDisconnect = ( slug ) => {
		apiFetch( {
			path: `order-pilot/v1/couriers/${ slug }/disconnect`,
			method: 'DELETE',
		} )
			.then( () => { setNotice( { type: 'success', text: 'Courier disconnected.' } ); loadCouriers(); } );
	};

	const handleSaveCredentials = () => {
		setSaving( true );
		apiFetch( {
			path: `order-pilot/v1/couriers/${ selected }/credentials`,
			method: 'POST',
			data: credentials,
		} )
			.then( () => {
				setNotice( { type: 'success', text: 'Credentials saved.' } );
				setSelected( null );
				setCreds( {} );
				loadCouriers();
			} )
			.catch( ( err ) => setNotice( { type: 'error', text: err.message } ) )
			.finally( () => setSaving( false ) );
	};

	const selectedCourier = couriers.find( ( c ) => c.slug === selected );

	return (
		<div>
			<div className="op-page-header">
				<h1>🚚 Couriers</h1>
				<p>Connect and manage your courier services. { ! isPro && 'Free plan: up to 2 active couriers.' }</p>
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

			{ loading ? (
				<div className="op-loading-screen"><span className="op-spinner" /></div>
			) : (
				<div style={ { display: 'grid', gap: '16px', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))' } }>
					{ couriers.map( ( courier ) => (
						<div key={ courier.slug } className="op-card">
							<div className="op-card-header">
								<h3 className="op-card-title">{ courier.name }</h3>
								{ courier.connected
									? <span className="op-badge op-badge--delivered">Connected</span>
									: <span className="op-badge op-badge--none">Not Connected</span>
								}
							</div>

							<div className="op-flex op-gap-2">
								{ courier.connected ? (
									<>
										<button
											className="op-btn op-btn--secondary op-btn--sm"
											onClick={ () => { setSelected( courier.slug ); setCreds( {} ); } }
										>
											🔑 Credentials
										</button>
										<button
											className="op-btn op-btn--danger op-btn--sm"
											onClick={ () => handleDisconnect( courier.slug ) }
										>
											Disconnect
										</button>
									</>
								) : (
									<button
										className="op-btn op-btn--primary op-btn--sm"
										onClick={ () => { setSelected( courier.slug ); setCreds( {} ); } }
									>
										+ Connect
									</button>
								) }
							</div>
						</div>
					) ) }
				</div>
			) }

			{ /* Credentials Modal */ }
			{ selected && selectedCourier && (
				<div className="op-modal-overlay" onClick={ () => setSelected( null ) }>
					<div className="op-modal" onClick={ ( e ) => e.stopPropagation() }>
						<div className="op-modal-header">
							<h2 className="op-modal-title">{ selectedCourier.name } — Credentials</h2>
							<button className="op-modal-close" onClick={ () => setSelected( null ) }>✕</button>
						</div>
						<div className="op-modal-body">
							{ Object.entries( selectedCourier.fields || {} ).map( ( [ key, field ] ) => (
								<div key={ key } className="op-form-group">
									<label>
										{ field.label }
										{ field.required && <span style={ { color: 'var(--op-danger)', marginLeft: 3 } }>*</span> }
									</label>
									<input
										type={ field.type === 'password' ? 'password' : 'text' }
										className="op-input"
										value={ credentials[ key ] || '' }
										onChange={ ( e ) => setCreds( { ...credentials, [ key ]: e.target.value } ) }
										placeholder={ field.label }
									/>
								</div>
							) ) }
						</div>
						<div className="op-modal-footer">
							<button className="op-btn op-btn--secondary" onClick={ () => setSelected( null ) }>
								Cancel
							</button>
							<button
								className="op-btn op-btn--primary"
								onClick={ handleSaveCredentials }
								disabled={ saving }
							>
								{ saving ? 'Saving…' : 'Save & Connect' }
							</button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}
