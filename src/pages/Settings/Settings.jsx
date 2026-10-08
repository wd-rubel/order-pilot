/**
 * Settings Page
 *
 * Settings for General, Couriers, Fraud Checker, Meta Pixel, and Conversions API.
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import CourierAutomation from '../CourierAutomation/CourierAutomation';

const { isPro = false } = window.orderPilot || {};

export default function Settings() {
	// Initialize tab from URL query param if present (e.g. ?page=order-pilot-settings&tab=couriers)
	const [tab, setTab] = useState(() => {
		const params = new URLSearchParams(window.location.search);
		return params.get('tab') || 'general';
	});

	const [settings, setSettings] = useState(null);
	const [loading, setLoading] = useState(true);
	const [saving, setSaving] = useState(false);
	const [notice, setNotice] = useState(null);

	useEffect(() => {
		apiFetch({ path: 'order-pilot/v1/settings' })
			.then((data) => { setSettings(data); })
			.finally(() => setLoading(false));
	}, []);

	const handleSave = () => {
		setSaving(true);
		setNotice(null);
		apiFetch({
			path: 'order-pilot/v1/settings',
			method: 'POST',
			data: { tab, [tab]: settings[tab] },
		})
			.then(() => setNotice({ type: 'success', text: '✓ Settings saved successfully.' }))
			.catch((err) => setNotice({ type: 'error', text: err.message || 'Failed to save settings.' }))
			.finally(() => setSaving(false));
	};

	const TABS = [
		{ key: 'general', label: '⚙️ General' },
		{ key: 'couriers', label: '🚚 Couriers' },
		{ key: 'fraud', label: '🛡️ Fraud Checker' },
		{ key: 'pixel', label: '📊 Meta Pixel' },
		{ key: 'capi', label: '🖥️ Conversions API', pro: true },
		{ key: 'ga4', label: '📈 Google Analytics 4', pro: true },
		{ key: 'tiktok', label: '🎵 TikTok Pixel', pro: true },
		{ key: 'license', label: '🔑 License' },
	];

	return (
		<div>
			<div className="odrplt-page-header">
				<h1>⚙️ Settings</h1>
				<p>Configure Order Pilot couriers, fraud detection, and analytics tracking.</p>
			</div>

			{notice && (
				<div className={`odrplt-notice odrplt-notice--${notice.type}`}>
					<span>{notice.text}</span>
					<button
						style={{ marginLeft: 'auto', background: 'none', border: 'none', cursor: 'pointer', fontSize: 14 }}
						onClick={() => setNotice(null)}
					>✕</button>
				</div>
			)}

			{ /* Modern Segmented Tab Bar */}
			<div className="odrplt-tabs-nav">
				{TABS.map((t) => (
					<button
						key={t.key}
						type="button"
						className={`odrplt-tab-btn ${tab === t.key ? 'odrplt-tab-btn--active' : ''}`}
						onClick={() => setTab(t.key)}
					>
						{t.label}
						{t.pro && !isPro && <span className="odrplt-pro-badge">PRO</span>}
					</button>
				))}
			</div>

			<div className="odrplt-card">
				{loading ? (
					<div className="odrplt-loading-screen"><span className="odrplt-spinner" /></div>
				) : (
					<>
						{tab === 'general' && (
							<GeneralSettings
								settings={settings?.general || {}}
								setSettings={(v) => setSettings({ ...settings, general: v })}
							/>
						)}

						{tab === 'couriers' && (
							<CouriersSettings
								settings={settings?.couriers || { enable_courier: settings?.general?.enable_courier ?? true }}
								setSettings={(v) => {
									const updated = typeof v === 'function' ? v(settings?.couriers || {}) : v;
									setSettings({
										...settings,
										couriers: updated,
										general: { ...settings?.general, enable_courier: updated.enable_courier },
									});
								}}
								onSave={handleSave}
								saving={saving}
							/>
						)}

						{tab === 'fraud' && (
							<FraudSettings
								settings={settings?.fraud || {}}
								setSettings={(v) => setSettings({ ...settings, fraud: v })}
							/>
						)}

						{tab === 'pixel' && (
							<PixelSettings
								settings={settings?.pixel || {}}
								setSettings={(v) => setSettings({ ...settings, pixel: v })}
							/>
						)}
						{tab === 'capi' && (
							isPro ? (
								<CapiSettings
									settings={settings?.capi || {}}
									setSettings={(v) => setSettings({ ...settings, capi: v })}
								/>
							) : (
								<ProGateTab
									title="Meta Conversions API (CAPI)"
									desc="Server-side event tracking with 100% deduplication and COD delivery tracking."
								/>
							)
						)}

						{tab === 'ga4' && (
							isPro ? (
								<GA4Settings
									settings={settings?.ga4 || {}}
									setSettings={(v) => setSettings({ ...settings, ga4: v })}
								/>
							) : (
								<ProGateTab
									title="Google Analytics 4 (GA4)"
									desc="Track eCommerce events (view_item, add_to_cart, begin_checkout, purchase) directly to GA4 with COD-aware purchase tracking."
								/>
							)
						)}

						{tab === 'tiktok' && (
							isPro ? (
								<TikTokSettings
									settings={settings?.tiktok || {}}
									setSettings={(v) => setSettings({ ...settings, tiktok: v })}
								/>
							) : (
								<ProGateTab
									title="TikTok Pixel"
									desc="Track TikTok events (ViewContent, AddToCart, InitiateCheckout, Purchase) with COD-aware purchase tracking."
								/>
							)
						)}

						{tab === 'license' && (
							<LicenseSettings />
						)}

						{(tab === 'general' || tab === 'couriers' || tab === 'pixel' || tab === 'fraud' || ((tab === 'capi' || tab === 'ga4' || tab === 'tiktok') && isPro)) && (
							<div style={{ marginTop: '28px', borderTop: '1px solid var(--odrplt-border)', paddingTop: '18px' }}>
								<button className="odrplt-btn odrplt-btn--primary" onClick={handleSave} disabled={saving}>
									{saving ? 'Saving…' : '💾 Save Settings'}
								</button>
							</div>
						)}
					</>
				)}
			</div>
		</div>
	);
}

function ProGateTab({ title, desc }) {
	return (
		<div className="odrplt-pro-gate">
			<div className="odrplt-pro-gate__icon">🔒</div>
			<h2 className="odrplt-pro-gate__title">{title}</h2>
			<p className="odrplt-pro-gate__desc">{desc}</p>
			<a href="https://arsyntax.com/asxc-product/order-pilot/" target="_blank" rel="noreferrer" className="odrplt-btn odrplt-btn--primary">
				Upgrade to Pro
			</a>
		</div>
	);
}

function LicenseSettings() {
	const [licenseData, setLicenseData] = useState(null);
	const [licenseKey, setLicenseKey] = useState('');
	const [loading, setLoading] = useState(true);
	const [busy, setBusy] = useState(false);
	const [notice, setNotice] = useState(null);

	const fetchLicense = () => {
		setLoading(true);
		apiFetch({ path: 'order-pilot/v1/license' })
			.then((data) => setLicenseData(data))
			.catch(() => setLicenseData(null))
			.finally(() => setLoading(false));
	};

	useEffect(() => { fetchLicense(); }, []);

	const showNotice = (type, text) => {
		setNotice({ type, text });
		setTimeout(() => setNotice(null), 6000);
	};

	const handleActivate = () => {
		if (!licenseKey.trim()) return;
		setBusy(true);
		apiFetch({
			path: 'order-pilot/v1/license/activate',
			method: 'POST',
			data: { license_key: licenseKey.trim() },
		})
			.then((res) => {
				if (res.success) {
					showNotice('success', '✓ ' + (res.message || 'License activated successfully.'));
					setLicenseKey('');
					fetchLicense();
				} else {
					showNotice('error', '✕ ' + (res.message || 'Activation failed.'));
				}
			})
			.catch((err) => showNotice('error', '✕ ' + (err.message || 'Activation failed.')))
			.finally(() => setBusy(false));
	};

	const handleDeactivate = () => {
		if (!confirm('Deactivate this license? This will remove it from this site.')) return;
		setBusy(true);
		apiFetch({
			path: 'order-pilot/v1/license/deactivate',
			method: 'POST',
			data: {},
		})
			.then((res) => {
				if (res.success) {
					showNotice('success', '✓ ' + (res.message || 'License deactivated.'));
					fetchLicense();
				} else {
					showNotice('error', '✕ ' + (res.message || 'Deactivation failed.'));
				}
			})
			.catch((err) => showNotice('error', '✕ ' + (err.message || 'Deactivation failed.')))
			.finally(() => setBusy(false));
	};

	const handleCheck = () => {
		setBusy(true);
		apiFetch({
			path: 'order-pilot/v1/license/check',
			method: 'POST',
			data: {},
		})
			.then((res) => {
				if (res.success) {
					showNotice('success', '✓ License verified successfully.');
					fetchLicense();
				} else {
					showNotice('error', '✕ ' + (res.message || 'Verification failed.'));
				}
			})
			.catch((err) => showNotice('error', '✕ ' + (err.message || 'Verification failed.')))
			.finally(() => setBusy(false));
	};

	const isActive    = licenseData?.status === 'active';
	const isExpired   = licenseData?.status === 'expired';
	const statusLabel = isActive ? 'Active' : isExpired ? 'Expired' : 'Inactive';
	const statusColor = isActive ? '#22c55e' : isExpired ? '#f59e0b' : '#ef4444';

	const expiresLabel = licenseData?.expires_at
		? licenseData.expires_at === 'lifetime'
			? 'Lifetime'
			: new Date(licenseData.expires_at).toLocaleDateString()
		: '—';

	const lastCheckedLabel = licenseData?.last_checked
		? new Date(licenseData.last_checked * 1000).toLocaleString()
		: '—';

	if (loading) {
		return <div className="odrplt-loading-screen"><span className="odrplt-spinner" /></div>;
	}

	return (
		<div>
			<h2 style={{ marginTop: 0, marginBottom: 6, fontSize: 18, fontWeight: 600 }}>🔑 License Management</h2>
			<p style={{ margin: '0 0 20px', color: 'var(--odrplt-text-muted)', fontSize: 14 }}>
				Activate your Order Pilot Pro license key to unlock all premium features.
			</p>

			{notice && (
				<div className={`odrplt-notice odrplt-notice--${notice.type}`} style={{ marginBottom: 16 }}>
					<span>{notice.text}</span>
					<button
						style={{ marginLeft: 'auto', background: 'none', border: 'none', cursor: 'pointer', fontSize: 14 }}
						onClick={() => setNotice(null)}
					>✕</button>
				</div>
			)}

			{/* Status Card */}
			{licenseData && (
				<div style={{
					background: 'var(--odrplt-surface)',
					border: '1px solid var(--odrplt-border)',
					borderRadius: 10,
					padding: '16px 20px',
					marginBottom: 24,
				}}>
					<div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 14 }}>
						<span style={{
							display: 'inline-flex',
							alignItems: 'center',
							gap: 6,
							background: statusColor + '22',
							color: statusColor,
							borderRadius: 20,
							padding: '4px 12px',
							fontSize: 13,
							fontWeight: 600,
						}}>
							<span style={{ width: 7, height: 7, borderRadius: '50%', background: statusColor, display: 'inline-block' }} />
							{statusLabel}
						</span>
						{licenseData.product_name && (
							<span style={{ fontSize: 14, color: 'var(--odrplt-text-muted)' }}>{licenseData.product_name}</span>
						)}
					</div>

					<div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '10px 24px' }}>
						{licenseData.license_key && (
							<div>
								<div style={{ fontSize: 11, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--odrplt-text-muted)', marginBottom: 2 }}>License Key</div>
								<div style={{ fontFamily: 'monospace', fontSize: 13 }}>{licenseData.license_key}</div>
							</div>
						)}
						<div>
							<div style={{ fontSize: 11, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--odrplt-text-muted)', marginBottom: 2 }}>Expires</div>
							<div style={{ fontSize: 13 }}>{expiresLabel}</div>
						</div>
						{licenseData.activation_limit > 0 && (
							<div>
								<div style={{ fontSize: 11, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--odrplt-text-muted)', marginBottom: 2 }}>Activations</div>
								<div style={{ fontSize: 13 }}>{licenseData.activation_count} / {licenseData.activation_limit}</div>
							</div>
						)}
						<div>
							<div style={{ fontSize: 11, fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--odrplt-text-muted)', marginBottom: 2 }}>Last Verified</div>
							<div style={{ fontSize: 13 }}>{lastCheckedLabel}</div>
						</div>
					</div>

					{isActive && (
						<div style={{ marginTop: 14, display: 'flex', gap: 8 }}>
							<button
								className="odrplt-btn odrplt-btn--secondary"
								style={{ fontSize: 13 }}
								onClick={handleCheck}
								disabled={busy}
							>
								{busy ? '⏳ Checking…' : '🔄 Verify License'}
							</button>
							<button
								className="odrplt-btn odrplt-btn--danger"
								style={{ fontSize: 13 }}
								onClick={handleDeactivate}
								disabled={busy}
							>
								{busy ? '⏳ Deactivating…' : '🔓 Deactivate'}
							</button>
						</div>
					)}
				</div>
			)}

			{/* Activate Form */}
			{!isActive && (
				<div style={{
					background: 'var(--odrplt-surface)',
					border: '1px solid var(--odrplt-border)',
					borderRadius: 10,
					padding: '20px',
				}}>
					<h3 style={{ margin: '0 0 12px', fontSize: 15, fontWeight: 600 }}>Activate License</h3>
					<p style={{ margin: '0 0 14px', fontSize: 13, color: 'var(--odrplt-text-muted)' }}>
						Enter your license key from{' '}
						<a href="https://arsyntax.com/asxc-product/order-pilot/" target="_blank" rel="noreferrer">arsyntax.com</a>.
					</p>
					<div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
						<input
							type="text"
							className="odrplt-input"
							style={{ flex: '1 1 260px', fontFamily: 'monospace' }}
							placeholder="XXXX-XXXX-XXXX-XXXX"
							value={licenseKey}
							onChange={(e) => setLicenseKey(e.target.value)}
							onKeyDown={(e) => e.key === 'Enter' && handleActivate()}
							disabled={busy}
						/>
						<button
							className="odrplt-btn odrplt-btn--primary"
							onClick={handleActivate}
							disabled={busy || !licenseKey.trim()}
						>
							{busy ? '⏳ Activating…' : '🔑 Activate License'}
						</button>
					</div>
				</div>
			)}
		</div>
	);
}

function GeneralSettings({ settings, setSettings }) {
	const update = (key, val) => setSettings({ ...settings, [key]: val });
	return (
		<div>
			<div className="odrplt-form-group">
				<label>Log Retention (days)</label>
				<input
					type="number"
					className="odrplt-input"
					style={{ maxWidth: 140 }}
					value={settings.log_retention || 30}
					onChange={(e) => update('log_retention', parseInt(e.target.value))}
				/>
				<p className="odrplt-form-help">Courier and tracking logs older than this many days will be deleted automatically.</p>
			</div>
		</div>
	);
}

/**
 * Couriers Settings Component (Inside Settings -> Couriers Tab)
 */
function CouriersSettings({ settings = {}, setSettings, onSave, saving }) {
	const update = (key, val) => setSettings && setSettings({ ...settings, [key]: val });
	const [couriers, setCouriers] = useState([]);
	const [loading, setLoading] = useState(true);
	const [selected, setSelected] = useState(null);
	const [credentials, setCreds] = useState({});
	const [modalSaving, setModalSaving] = useState(false);
	const [notice, setNotice] = useState(null);

	const selectedCourier = couriers.find((c) => c.slug === selected);

	const loadCouriers = () => {
		setLoading(true);
		apiFetch({ path: 'order-pilot/v1/couriers' })
			.then(setCouriers)
			.catch(() => { })
			.finally(() => setLoading(false));
	};

	useEffect(() => {
		loadCouriers();
	}, []);

	const openModal = (courier) => {
		setSelected(courier.slug);
		const defaults = {};
		Object.entries(courier.fields || {}).forEach(([key, field]) => {
			if (field.type === 'select' && field.default) {
				defaults[key] = field.default;
			}
		});
		setCreds({ ...defaults, ...(courier.credentials || {}) });
		setNotice(null);
	};

	const handleSaveCredentials = () => {
		const hasValues = Object.values(credentials).some((v) => v && String(v).trim() !== '');
		if (!hasValues) {
			setNotice({ type: 'error', text: 'Please enter your API credentials before saving.' });
			return;
		}

		setModalSaving(true);
		apiFetch({
			path: `order-pilot/v1/couriers/${selected}/credentials`,
			method: 'POST',
			data: credentials,
		})
			.then(() => {
				setNotice({ type: 'success', text: '✓ Courier credentials saved and connected successfully.' });
				setSelected(null);
				setCreds({});
				loadCouriers();
			})
			.catch((err) => {
				setNotice({
					type: 'error',
					text: err?.message || 'Could not save credentials. Please check your inputs.',
				});
			})
			.finally(() => setModalSaving(false));
	};

	const handleDisconnect = (slug) => {
		if (!window.confirm('Disconnect this courier? Your saved credentials will be kept.')) {
			return;
		}
		apiFetch({
			path: `order-pilot/v1/couriers/${slug}/disconnect`,
			method: 'DELETE',
		})
			.then(() => {
				setNotice({ type: 'success', text: 'Courier disconnected.' });
				loadCouriers();
			})
			.catch((err) => {
				setNotice({ type: 'error', text: err?.message || 'Could not disconnect courier.' });
			});
	};

	const closeModal = () => {
		setSelected(null);
		setCreds({});
	};

	return (
		<div>
			{/* Courier Integration Enable/Disable */}
			<div className="odrplt-form-group" style={{ marginBottom: 24, paddingBottom: 18, borderBottom: '1px solid var(--odrplt-border)' }}>
				<div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
					<label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', fontWeight: 600, fontSize: 14 }}>
						<input
							type="checkbox"
							className="odrplt-checkbox"
							checked={settings.enable_courier !== false}
							onChange={(e) => update('enable_courier', e.target.checked)}
						/>
						<span>Enable Courier Integration Features</span>
					</label>
				</div>
				<p className="odrplt-form-help" style={{ margin: '6px 0 0 28px' }}>
					Enable order dispatching, consignment creation, and tracking integration with Bangladesh couriers.
				</p>
			</div>

			<div style={{ marginBottom: 20 }}>
				<h3 style={{ fontSize: 16, fontWeight: 700, margin: '0 0 4px', color: 'var(--odrplt-text)' }}>
					🚚 Courier Partners & Credentials
				</h3>
				<p style={{ fontSize: 13, color: 'var(--odrplt-text-muted)', margin: 0 }}>
					Connect and configure your Bangladesh courier accounts to enable one-click order dispatching and live status syncing.
					{!isPro && (
						<span style={{ marginLeft: 8, color: '#b27200', fontWeight: 600 }}>
							(Free plan: up to 2 active couriers)
						</span>
					)}
				</p>
			</div>

			{notice && (
				<div className={`odrplt-notice odrplt-notice--${notice.type}`} style={{ marginBottom: 16 }}>
					<span>{notice.text}</span>
					<button
						style={{ marginLeft: 'auto', background: 'none', border: 'none', cursor: 'pointer' }}
						onClick={() => setNotice(null)}
					>✕</button>
				</div>
			)}

			{loading ? (
				<div className="odrplt-loading-screen"><span className="odrplt-spinner" /></div>
			) : (
				<div style={{ display: 'grid', gap: '16px', gridTemplateColumns: 'repeat(auto-fill, minmax(300px, 1fr))' }}>
					{couriers.map((courier) => (
						<div
							key={courier.slug}
							className="odrplt-card"
							style={{
								margin: 0,
								background: courier.connected ? 'var(--odrplt-surface)' : 'var(--odrplt-surface-alt)',
								border: courier.connected ? '1.5px solid var(--odrplt-primary-border)' : '1px solid var(--odrplt-border)',
								borderRadius: 'var(--odrplt-radius)',
								padding: '18px 20px',
							}}
						>
							<div className="odrplt-card-header" style={{ marginBottom: 12, paddingBottom: 12 }}>
								<h3 className="odrplt-card-title">{courier.name}</h3>
								<div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
									{courier.connected && courier.environment && (
										<span
											className={`odrplt-badge ${courier.environment === 'sandbox' ? 'odrplt-badge--pending' : 'odrplt-badge--in-transit'}`}
											style={{ fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.5 }}
										>
											{courier.environment}
										</span>
									)}
									{courier.connected
										? <span className="odrplt-badge odrplt-badge--delivered">✓ Connected</span>
										: <span className="odrplt-badge odrplt-badge--none">Not Connected</span>
									}
								</div>
							</div>

							{courier.configured && !courier.connected && (
								<p style={{ fontSize: 12, color: 'var(--odrplt-text-muted)', marginBottom: 10 }}>
									Credentials saved — click "Connect" to activate.
								</p>
							)}

							<div className="odrplt-flex odrplt-gap-2" style={{ marginTop: 14 }}>
								{courier.connected ? (
									<>
										<button
											type="button"
											className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm"
											onClick={() => openModal(courier)}
										>
											🔑 Edit Credentials
										</button>
										<button
											type="button"
											className="odrplt-btn odrplt-btn--danger odrplt-btn--sm"
											onClick={() => handleDisconnect(courier.slug)}
										>
											Disconnect
										</button>
									</>
								) : (
									<button
										type="button"
										className="odrplt-btn odrplt-btn--primary odrplt-btn--sm"
										onClick={() => openModal(courier)}
									>
										+ Connect {courier.name}
									</button>
								)}
							</div>
						</div>
					))}
				</div>
			)}

			{ /* Modal */}
			{selected && selectedCourier && (
				<div className="odrplt-modal-overlay" onClick={closeModal}>
					<div className="odrplt-modal" onClick={(e) => e.stopPropagation()}>
						<div className="odrplt-modal-header">
							<h2 className="odrplt-modal-title">
								{selectedCourier.name} — {selectedCourier.connected ? 'Edit Credentials' : 'Connect Courier'}
							</h2>
							<button className="odrplt-modal-close" onClick={closeModal}>✕</button>
						</div>

						<div className="odrplt-modal-body">
							{!selectedCourier.connected && (
								<p style={{ marginBottom: 16, color: 'var(--odrplt-text-muted)', fontSize: 13 }}>
									Enter your {selectedCourier.name} API credentials below. Your courier will be connected automatically after saving.
								</p>
							)}

							{Object.entries(selectedCourier.fields || {}).map(([key, field]) => (
								<div key={key} className="odrplt-form-group">
									<label>
										{field.label}
										{field.required && <span style={{ color: 'var(--odrplt-danger)', marginLeft: 3 }}>*</span>}
									</label>

									{field.type === 'select' ? (
										<select
											className="odrplt-select"
											value={credentials[key] ?? field.default ?? ''}
											onChange={(e) => setCreds({ ...credentials, [key]: e.target.value })}
										>
											{Object.entries(field.options || {}).map(([val, label]) => (
												<option key={val} value={val}>{label}</option>
											))}
										</select>
									) : (
										<input
											type={field.type === 'password' ? 'password' : field.type === 'email' ? 'email' : 'text'}
											className="odrplt-input"
											value={credentials[key] || ''}
											onChange={(e) => setCreds({ ...credentials, [key]: e.target.value })}
											placeholder={field.placeholder || field.label}
											autoComplete={field.type === 'password' ? 'new-password' : 'off'}
										/>
									)}

									{field.help && (
										<p className="odrplt-form-help">
											{field.help}
										</p>
									)}
								</div>
							))}
						</div>

						<div className="odrplt-modal-footer">
							<button type="button" className="odrplt-btn odrplt-btn--secondary" onClick={closeModal}>
								Cancel
							</button>
							<button
								type="button"
								className="odrplt-btn odrplt-btn--primary"
								onClick={handleSaveCredentials}
								disabled={modalSaving}
							>
								{modalSaving ? 'Saving…' : (selectedCourier.connected ? '💾 Save Credentials' : '🔗 Save & Connect')}
							</button>
						</div>
					</div>
				</div>
			)}

			{ /* ── Section 2: Automatic Courier Selection (Pro) ── */}
			<div style={{ marginTop: 40, paddingTop: 28, borderTop: '2px solid var(--odrplt-border, #e2e8f0)' }}>
				<div style={{ marginBottom: 20 }}>
					<div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
						<h3 style={{ fontSize: 16, fontWeight: 700, margin: 0, color: 'var(--odrplt-text)' }}>
							⚡ Automatic Courier Selection & Rules
						</h3>
						{!isPro && <span className="odrplt-pro-badge">PRO</span>}
					</div>
					<p style={{ fontSize: 13, color: 'var(--odrplt-text-muted)', margin: 0 }}>
						Automatically assign incoming orders to the most suitable courier based on customer location, order value, or product category, with automatic retry and fallback.
					</p>
				</div>

				{isPro ? (
					<CourierAutomation connectedCouriersList={couriers.filter((c) => c.connected).map((c) => c.slug)} />
				) : (
					<ProGateTab
						title="Automatic Courier Selection & Rules"
						desc="Set priority-based rules to auto-dispatch orders to Pathao, Steadfast, RedX, etc. by location, order value, or product category."
					/>
				)}
			</div>
		</div>
	);
}


function FraudSettings({ settings, setSettings }) {
	const update = (key, val) => setSettings({ ...settings, [key]: val });

	return (
		<div>
			<div className="odrplt-form-group">
				<label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', fontWeight: 600 }}>
					<input
						type="checkbox"
						className="odrplt-checkbox"
						checked={!!settings.enable_fraud}
						onChange={(e) => update('enable_fraud', e.target.checked)}
					/>
					<span>Enable Fraud Checker & Delivery Risk Assessment</span>
				</label>
			</div>

			<div className="odrplt-form-group">
				<label>BDCourier API Key</label>
				<input
					type="password"
					className="odrplt-input"
					style={{ maxWidth: 480 }}
					value={settings.api_key || ''}
					onChange={(e) => update('api_key', e.target.value)}
					placeholder="Enter your BDCourier API Key"
				/>
				<p className="odrplt-form-help">
					Get your API key from your <a href="https://bdcourier.com" target="_blank" rel="noreferrer" style={{ textDecoration: 'underline' }}>BDCourier Merchant Account</a>. Powers cross-courier delivery analytics across Pathao, SteadFast, RedX, PaperFly, CarryBee, CourrierFast, and ParcelDex.
				</p>
			</div>

			<div className="odrplt-form-group">
				<label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', fontWeight: 500 }}>
					<input
						type="checkbox"
						className="odrplt-checkbox"
						checked={settings.auto_check !== false}
						onChange={(e) => update('auto_check', e.target.checked)}
					/>
					<span>Automatically check fraud risk on checkout & new orders</span>
				</label>
				<p className="odrplt-form-help">Runs background fraud assessment as customer inputs their phone number and before order submission.</p>
			</div>

			<div className="odrplt-form-group">
				<label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: isPro ? 'pointer' : 'default', fontWeight: 500, opacity: isPro ? 1 : 0.75 }}>
					<input
						type="checkbox"
						className="odrplt-checkbox"
						disabled={!isPro}
						checked={isPro && !!settings.auto_block_ip}
						onChange={(e) => update('auto_block_ip', e.target.checked)}
					/>
					<span>🚫 Automatically block IP address when fraud score threshold is reached</span>
					{!isPro && <span className="odrplt-pro-badge">PRO</span>}
				</label>
				<p className="odrplt-form-help">
					When a customer's fraud score meets or exceeds the threshold, their IP address is automatically added to the Blocked IPs list. Future orders from that IP will be blocked immediately without running a fraud score check.
					{!isPro && <span style={{ display: 'block', color: 'var(--odrplt-primary, #6366f1)', marginTop: 4 }}>★ IP-level auto-blocking is an Order Pilot Pro feature.</span>}
				</p>
			</div>

			{ /* Single Auto-Block Threshold */}
			<div className="odrplt-form-group" style={{ background: 'var(--odrplt-surface-alt, #f8f9fa)', padding: '16px 18px', borderRadius: '8px', border: '1px solid var(--odrplt-border, #e2e8f0)', maxWidth: 520 }}>
				<label style={{ fontWeight: 600, fontSize: 13, marginBottom: 4, display: 'block', color: 'var(--odrplt-danger, #d60000)' }}>
					🔴 Auto-Block Fraud Score Threshold (≥ %)
				</label>
				<div style={{ display: 'flex', alignItems: 'center', gap: 6, margin: '8px 0' }}>
					<input
						type="number"
						min="1"
						max="100"
						className="odrplt-input"
						style={{ width: 100 }}
						value={settings.block_score_threshold ?? 80}
						onChange={(e) => update('block_score_threshold', e.target.value === '' ? '' : Math.max(1, Math.min(100, parseInt(e.target.value, 10) || 0)))}
					/>
					<span style={{ fontWeight: 600, color: 'var(--odrplt-text-muted)' }}>%</span>
				</div>
				<p className="odrplt-form-help" style={{ margin: 0 }}>
					If a customer's fraud risk score reaches or exceeds this threshold (e.g. 80%), the order is automatically blocked at checkout. Otherwise, the order is approved.
				</p>
			</div>

			{ /* Blocked Order Message Template */}
			<div className="odrplt-form-group" style={{ maxWidth: 580 }}>
				<label style={{ fontWeight: 600, fontSize: 13, display: 'block', marginBottom: 6 }}>
					💬 Blocked Order Message
				</label>
				<textarea
					className="odrplt-input"
					rows={3}
					style={{ width: '100%', resize: 'vertical', fontFamily: 'inherit', lineHeight: 1.6 }}
					value={settings.block_message || ''}
					onChange={(e) => update('block_message', e.target.value)}
					placeholder="Sorry, we’re unable to process your order at this time. Please contact our support team for assistance."
				/>
				<p className="odrplt-form-help" style={{ marginBottom: 6 }}>
					This message is shown to customers at checkout when their order is blocked due to high fraud risk or a blocked IP address.
				</p>
				{settings.block_message && (
					<div style={{ background: '#fff3cd', border: '1px solid #ffc107', borderRadius: 6, padding: '8px 12px', fontSize: 12, color: '#856404' }}>
						<strong>Preview:</strong> {settings.block_message}
					</div>
				)}
			</div>
		</div>
	);
}

function PixelSettings({ settings, setSettings }) {
	const update = (key, val) => setSettings({ ...settings, [key]: val });
	const updateEvent = (key, val) => setSettings({ ...settings, events: { ...settings.events, [key]: val } });

	const EVENTS = [
		{ key: 'page_view', label: 'PageView' },
		{ key: 'view_content', label: 'ViewContent' },
		{ key: 'add_to_cart', label: 'AddToCart' },
		{ key: 'initiate_checkout', label: 'InitiateCheckout' },
		{ key: 'purchase', label: 'Purchase' },
	];

	return (
		<div>
			<div className="odrplt-form-group">
				<label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', fontWeight: 600 }}>
					<input
						type="checkbox"
						className="odrplt-checkbox"
						checked={!!settings.enable_pixel}
						onChange={(e) => update('enable_pixel', e.target.checked)}
					/>
					<span>Enable Meta Pixel Tracking</span>
				</label>
				<p className="odrplt-form-help" style={{ margin: '4px 0 0 28px' }}>
					Inject the Meta Pixel base code and track browser-side events.
				</p>
			</div>

			<div className="odrplt-form-group">
				<label>Facebook / Meta Pixel ID</label>
				<input
					type="text"
					className="odrplt-input"
					style={{ maxWidth: 360 }}
					value={settings.pixel_id || ''}
					onChange={(e) => update('pixel_id', e.target.value)}
					placeholder="e.g. 123456789012345"
				/>
			</div>

			<div className="odrplt-form-group">
				<label>Purchase Event Trigger</label>
				<select
					className="odrplt-select"
					style={{ maxWidth: 320 }}
					value={settings.purchase_trigger || 'order_created'}
					onChange={(e) => update('purchase_trigger', e.target.value)}
				>
					<option value="order_created">Order Created (default)</option>
					<option value="payment_completed">Payment Completed</option>
					<option value="order_delivered">Order Delivered (COD-Aware)</option>
				</select>
			</div>

			<div className="odrplt-form-group">
				<label style={{ marginBottom: 12 }}>Events to Track</label>
				<div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10, maxWidth: 500 }}>
					{EVENTS.map((ev) => (
						<label key={ev.key} style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', fontSize: 13 }}>
							<input
								type="checkbox"
								className="odrplt-checkbox"
								checked={!!(settings.events || {})[ev.key]}
								onChange={(e) => updateEvent(ev.key, e.target.checked)}
							/>
							<span>{ev.label}</span>
						</label>
					))}
				</div>
			</div>
		</div>
	);
}

function CapiSettings({ settings, setSettings }) {
	const update = (key, val) => setSettings({ ...settings, [key]: val });

	return (
		<div>
			<div className="odrplt-form-group">
				<label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', fontWeight: 600 }}>
					<input
						type="checkbox"
						className="odrplt-checkbox"
						checked={!!settings.enable_capi}
						onChange={(e) => update('enable_capi', e.target.checked)}
					/>
					<span>Enable Meta Conversions API (CAPI)</span>
				</label>
			</div>

			<div className="odrplt-form-group">
				<label>System User Access Token</label>
				<input
					type="password"
					className="odrplt-input"
					style={{ maxWidth: 500 }}
					value={settings.access_token || ''}
					onChange={(e) => update('access_token', e.target.value)}
					placeholder="EAAG..."
				/>
				<p className="odrplt-form-help">Generated in Meta Business Events Manager.</p>
			</div>

			<div className="odrplt-form-group">
				<label>Test Event Code (Optional)</label>
				<input
					type="text"
					className="odrplt-input"
					style={{ maxWidth: 220 }}
					value={settings.test_code || ''}
					onChange={(e) => update('test_code', e.target.value)}
					placeholder="TEST12345"
				/>
			</div>
		</div>
	);
}

function GA4Settings({ settings, setSettings }) {
	const update = (key, val) => setSettings({ ...settings, [key]: val });
	const updateEvent = (key, val) => setSettings({ ...settings, events: { ...settings.events, [key]: val } });

	const EVENTS = [
		{ key: 'view_item', label: 'view_item (Product View)' },
		{ key: 'add_to_cart', label: 'add_to_cart' },
		{ key: 'begin_checkout', label: 'begin_checkout' },
		{ key: 'purchase', label: 'purchase' },
	];

	return (
		<div>
			<div className="odrplt-form-group">
				<label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', fontWeight: 600 }}>
					<input
						type="checkbox"
						className="odrplt-checkbox"
						checked={!!settings.enable_ga4}
						onChange={(e) => update('enable_ga4', e.target.checked)}
					/>
					<span>Enable Google Analytics 4 (GA4) Tracking</span>
				</label>
			</div>

			<div className="odrplt-form-group">
				<label>GA4 Measurement ID</label>
				<input
					type="text"
					className="odrplt-input"
					style={{ maxWidth: 360 }}
					value={settings.measurement_id || ''}
					onChange={(e) => update('measurement_id', e.target.value)}
					placeholder="e.g. G-XXXXXXXXXX"
				/>
				<p className="odrplt-form-help">Found in Google Analytics: Admin &gt; Data Streams &gt; Web stream details.</p>
			</div>

			<div className="odrplt-form-group">
				<label>Purchase Event Trigger (for COD / Online Orders)</label>
				<select
					className="odrplt-select"
					style={{ maxWidth: 320 }}
					value={settings.purchase_trigger || 'order_created'}
					onChange={(e) => update('purchase_trigger', e.target.value)}
				>
					<option value="order_created">Order Created (Thank-you Page)</option>
					<option value="payment_completed">Payment Completed</option>
					<option value="order_delivered">Order Delivered (COD-Aware)</option>
				</select>
				<p className="odrplt-form-help">
					For Cash on Delivery (COD), select 'Order Delivered' to prevent firing purchase events for returned/cancelled shipments.
				</p>
			</div>

			<div className="odrplt-form-group">
				<label style={{ marginBottom: 12 }}>Events to Track</label>
				<div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10, maxWidth: 500 }}>
					{EVENTS.map((ev) => (
						<label key={ev.key} style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', fontSize: 13 }}>
							<input
								type="checkbox"
								className="odrplt-checkbox"
								checked={settings.events ? !!settings.events[ev.key] : true}
								onChange={(e) => updateEvent(ev.key, e.target.checked)}
							/>
							<span>{ev.label}</span>
						</label>
					))}
				</div>
			</div>
		</div>
	);
}

function TikTokSettings({ settings, setSettings }) {
	const update = (key, val) => setSettings({ ...settings, [key]: val });
	const updateEvent = (key, val) => setSettings({ ...settings, events: { ...settings.events, [key]: val } });

	const EVENTS = [
		{ key: 'view_content', label: 'ViewContent' },
		{ key: 'add_to_cart', label: 'AddToCart' },
		{ key: 'initiate_checkout', label: 'InitiateCheckout' },
		{ key: 'purchase', label: 'Purchase' },
	];

	return (
		<div>
			<div className="odrplt-form-group">
				<label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer', fontWeight: 600 }}>
					<input
						type="checkbox"
						className="odrplt-checkbox"
						checked={!!settings.enable_tiktok}
						onChange={(e) => update('enable_tiktok', e.target.checked)}
					/>
					<span>Enable TikTok Pixel Tracking</span>
				</label>
			</div>

			<div className="odrplt-form-group">
				<label>TikTok Pixel ID</label>
				<input
					type="text"
					className="odrplt-input"
					style={{ maxWidth: 360 }}
					value={settings.pixel_id || ''}
					onChange={(e) => update('pixel_id', e.target.value)}
					placeholder="e.g. C1234567890ABCDEF"
				/>
				<p className="odrplt-form-help">Found in TikTok Ads Manager: Assets &gt; Events &gt; Web Events.</p>
			</div>

			<div className="odrplt-form-group">
				<label>Purchase Event Trigger (for COD / Online Orders)</label>
				<select
					className="odrplt-select"
					style={{ maxWidth: 320 }}
					value={settings.purchase_trigger || 'order_created'}
					onChange={(e) => update('purchase_trigger', e.target.value)}
				>
					<option value="order_created">Order Created (Thank-you Page)</option>
					<option value="payment_completed">Payment Completed</option>
					<option value="order_delivered">Order Delivered (COD-Aware)</option>
				</select>
				<p className="odrplt-form-help">
					For Cash on Delivery (COD), select 'Order Delivered' to fire purchases only after successful delivery.
				</p>
			</div>

			<div className="odrplt-form-group">
				<label style={{ marginBottom: 12 }}>Events to Track</label>
				<div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10, maxWidth: 500 }}>
					{EVENTS.map((ev) => (
						<label key={ev.key} style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', fontSize: 13 }}>
							<input
								type="checkbox"
								className="odrplt-checkbox"
								checked={settings.events ? !!settings.events[ev.key] : true}
								onChange={(e) => updateEvent(ev.key, e.target.checked)}
							/>
							<span>{ev.label}</span>
						</label>
					))}
				</div>
			</div>
		</div>
	);
}
