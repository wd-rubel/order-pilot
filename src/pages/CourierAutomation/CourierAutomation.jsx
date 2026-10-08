/**
 * Courier Automation Page
 *
 * Pro-only rule-based courier selection.
 * Supports location, order-amount, and product-category conditions with AND logic.
 */
import { useState, useEffect, useCallback } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";

const OPERATOR_LABELS = {
	is: "is (contains)", is_not: "is not", ">=": ">=", ">": ">", "<=": "<=", "<": "<", "=": "=",
};

const EMPTY_CONDITION = { type: "location", operator: "is", value: "" };
const EMPTY_RULE = { name: "", priority: 99, courier: "", conditions: [{ ...EMPTY_CONDITION }] };

function conditionSummary(c) {
	const opLabel = OPERATOR_LABELS[c.operator] || c.operator;
	const typeLabel = { location: "Location", order_amount: "Amount", product_category: "Category" }[c.type] || c.type;
	return typeLabel + " " + opLabel + ' "' + c.value + '"';
}

function ConditionRow({ cond, idx, condTypes, categories, onChange, onRemove }) {
	const typeDef = condTypes[cond.type] || {};
	const operators = typeDef.operators || {};
	return (
		<div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap", padding: "8px 0", borderBottom: "1px solid var(--odrplt-border, #e2e8f0)" }}>
			{idx > 0 && <span style={{ fontSize: 10, fontWeight: 700, background: "#e0e7ff", color: "#3730a3", padding: "2px 7px", borderRadius: 10, flexShrink: 0 }}>AND</span>}
			<select className="odrplt-input" style={{ flex: "1 1 140px" }} value={cond.type} onChange={(e) => onChange({ ...cond, type: e.target.value, operator: "is", value: "" })}>
				{Object.entries(condTypes).map(([key, def]) => (<option key={key} value={key}>{def.label}</option>))}
			</select>
			<select className="odrplt-input" style={{ flex: "1 1 140px" }} value={cond.operator} onChange={(e) => onChange({ ...cond, operator: e.target.value })}>
				{Object.entries(operators).map(([op, label]) => (<option key={op} value={op}>{label}</option>))}
			</select>
			{typeDef.value_type === "number" && (
				<input type="number" className="odrplt-input" style={{ flex: "1 1 100px" }} placeholder={typeDef.placeholder || "0"} value={cond.value} onChange={(e) => onChange({ ...cond, value: e.target.value })} />
			)}
			{typeDef.value_type === "category_select" && (
				<select className="odrplt-input" style={{ flex: "1 1 150px" }} value={cond.value} onChange={(e) => onChange({ ...cond, value: e.target.value })}>
					<option value="">-- Select Category --</option>
					{categories.map((cat) => (<option key={cat.id} value={cat.slug}>{cat.name}</option>))}
				</select>
			)}
			{(!typeDef.value_type || typeDef.value_type === "text") && (
				<input type="text" className="odrplt-input" style={{ flex: "1 1 140px" }} placeholder={typeDef.placeholder || "e.g. Dhaka"} value={cond.value} onChange={(e) => onChange({ ...cond, value: e.target.value })} />
			)}
			<button className="odrplt-btn odrplt-btn--danger odrplt-btn--sm" onClick={onRemove} title="Remove">x</button>
		</div>
	);
}

function RuleEditor({ rule, connectedCouriers, condTypes, categories, onChange, onSave, onCancel, saving }) {
	const update = (key, val) => onChange({ ...rule, [key]: val });
	const updateCond = (idx, cond) => { const conds = [...rule.conditions]; conds[idx] = cond; update("conditions", conds); };
	const removeCond = (idx) => update("conditions", rule.conditions.filter((_, i) => i !== idx));
	const addCond = () => update("conditions", [...rule.conditions, { ...EMPTY_CONDITION }]);
	return (
		<div style={{ background: "var(--odrplt-surface, #fff)", border: "2px solid var(--odrplt-primary, #6366f1)", borderRadius: 10, padding: "20px 22px", marginBottom: 16 }}>
			<div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12, marginBottom: 16 }}>
				<div className="odrplt-form-group" style={{ margin: 0 }}>
					<label style={{ fontWeight: 600, fontSize: 12, display: "block", marginBottom: 4 }}>Rule Name</label>
					<input type="text" className="odrplt-input" placeholder="e.g. Dhaka Premium" value={rule.name} onChange={(e) => update("name", e.target.value)} />
				</div>
				<div className="odrplt-form-group" style={{ margin: 0 }}>
					<label style={{ fontWeight: 600, fontSize: 12, display: "block", marginBottom: 4 }}>Priority (lower = first)</label>
					<input type="number" min="1" className="odrplt-input" style={{ width: 100 }} value={rule.priority} onChange={(e) => update("priority", parseInt(e.target.value, 10) || 99)} />
				</div>
			</div>
			<div className="odrplt-form-group" style={{ marginBottom: 16 }}>
				<label style={{ fontWeight: 600, fontSize: 12, display: "block", marginBottom: 4 }}>Assign Courier</label>
				<select className="odrplt-input" style={{ maxWidth: 260 }} value={rule.courier} onChange={(e) => update("courier", e.target.value)}>
					<option value="">-- Select Courier --</option>
					{connectedCouriers.map((s) => (<option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>))}
				</select>
			</div>
			<div style={{ marginBottom: 12 }}>
				<label style={{ fontWeight: 600, fontSize: 12, display: "block", marginBottom: 6 }}>Conditions <span style={{ color: "var(--odrplt-text-muted)", fontWeight: 400 }}>(ALL must match)</span></label>
				{rule.conditions.map((cond, idx) => (
					<ConditionRow key={idx} cond={cond} idx={idx} condTypes={condTypes} categories={categories} onChange={(c) => updateCond(idx, c)} onRemove={() => removeCond(idx)} />
				))}
				<button className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm" style={{ marginTop: 8 }} onClick={addCond}>+ Add Condition</button>
				{rule.conditions.length === 0 && (
					<p style={{ margin: "6px 0 0", fontSize: 12, color: "var(--odrplt-warning, #d97706)" }}>Warning: No conditions = catch-all, matches every order.</p>
				)}
			</div>
			<div style={{ display: "flex", gap: 8, marginTop: 8 }}>
				<button className="odrplt-btn odrplt-btn--primary" onClick={onSave} disabled={saving}>{saving ? "Saving..." : "Save Rule"}</button>
				<button className="odrplt-btn odrplt-btn--secondary" onClick={onCancel}>Cancel</button>
			</div>
		</div>
	);
}

function RuleCard({ rule, idx, total, condTypes, categories, onEdit, onDelete, onMove, saving }) {
	return (
		<div style={{ background: "var(--odrplt-surface, #fff)", border: "1px solid var(--odrplt-border, #e2e8f0)", borderRadius: 10, padding: "14px 18px", marginBottom: 10, display: "flex", alignItems: "flex-start", gap: 14 }}>
			<div style={{ textAlign: "center", flexShrink: 0 }}>
				<div style={{ background: "var(--odrplt-primary, #6366f1)", color: "#fff", borderRadius: 8, width: 36, height: 36, display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700, fontSize: 14 }}>{rule.priority}</div>
				<div style={{ display: "flex", flexDirection: "column", gap: 2, marginTop: 4 }}>
					<button disabled={idx === 0} className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm" style={{ padding: "2px 6px", fontSize: 10 }} onClick={() => onMove(idx, -1)}>UP</button>
					<button disabled={idx === total - 1} className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm" style={{ padding: "2px 6px", fontSize: 10 }} onClick={() => onMove(idx, 1)}>DN</button>
				</div>
			</div>
			<div style={{ flex: 1, minWidth: 0 }}>
				<div style={{ fontWeight: 700, fontSize: 14, marginBottom: 6 }}>{rule.name || "Unnamed Rule"}</div>
				<div style={{ display: "flex", flexWrap: "wrap", gap: 6, marginBottom: 8 }}>
					{(rule.conditions || []).length === 0
						? <span style={{ fontSize: 12, color: "var(--odrplt-warning, #d97706)" }}>Catch-all (no conditions)</span>
						: rule.conditions.map((c, i) => (
							<span key={i} style={{ background: "#f0f9ff", border: "1px solid #bae6fd", color: "#0369a1", borderRadius: 20, padding: "2px 10px", fontSize: 12, fontWeight: 500 }}>
								{i > 0 && <strong style={{ color: "#7c3aed", marginRight: 4 }}>AND </strong>}
								{conditionSummary(c)}
							</span>
						))
					}
				</div>
				<span style={{ background: "#dcfce7", color: "#166534", borderRadius: 20, padding: "3px 10px", fontSize: 12, fontWeight: 700 }}>
					{rule.courier ? rule.courier.charAt(0).toUpperCase() + rule.courier.slice(1) : "(No courier set)"}
				</span>
			</div>
			<div style={{ display: "flex", gap: 6, flexShrink: 0 }}>
				<button className="odrplt-btn odrplt-btn--secondary odrplt-btn--sm" onClick={() => onEdit(rule)}>Edit</button>
				<button className="odrplt-btn odrplt-btn--danger odrplt-btn--sm" onClick={() => onDelete(rule.id)} disabled={saving}>Del</button>
			</div>
		</div>
	);
}

export default function CourierAutomation({ connectedCouriersList = [] }) {
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(true);
	const [saving, setSaving] = useState(false);
	const [notice, setNotice] = useState(null);
	const [editingRule, setEditingRule] = useState(null);
	const [isNew, setIsNew] = useState(false);
	const [autoSelect, setAutoSelect] = useState(false);
	const [autoSend, setAutoSend] = useState(false);
	const [defaultCourier, setDefault] = useState("");
	const [settingsDirty, setSettingsDirty] = useState(false);

	const notify = (type, text) => { setNotice({ type, text }); setTimeout(() => setNotice(null), 4000); };

	useEffect(() => {
		apiFetch({ path: "order-pilot/v1/courier-rules" })
			.then((res) => { setData(res); setAutoSelect(!!res.auto_select); setAutoSend(!!res.auto_send); setDefault(res.default_courier || ""); })
			.catch(() => notify("error", "Failed to load automation data."))
			.finally(() => setLoading(false));
	}, []);

	const couriers = useCallback(() => {
		if (!data) return [];
		const fromRules = (data.rules || []).map((r) => r.courier).filter(Boolean);
		const fromWindow = Object.keys(window.orderPilot?.connectedCouriers || {});
		return [...new Set([...connectedCouriersList, ...fromWindow, ...fromRules])];
	}, [data, connectedCouriersList]);

	const saveSettings = () => {
		setSaving(true);
		apiFetch({ path: "order-pilot/v1/courier-rules/automation-settings", method: "POST", data: { auto_select: autoSelect, auto_send: autoSend, default_courier: defaultCourier } })
			.then(() => { notify("success", "Automation settings saved."); setSettingsDirty(false); })
			.catch((e) => notify("error", e.message || "Save failed."))
			.finally(() => setSaving(false));
	};

	const saveRule = () => {
		if (!editingRule?.name) return notify("error", "Rule name is required.");
		if (!editingRule?.courier) return notify("error", "Please select a courier.");
		setSaving(true);
		const path = isNew ? "order-pilot/v1/courier-rules" : "order-pilot/v1/courier-rules/" + editingRule.id;
		const method = isNew ? "POST" : "PUT";
		apiFetch({ path, method, data: editingRule })
			.then((res) => {
				setData((prev) => {
					const rules = prev.rules ? [...prev.rules] : [];
					if (isNew) { rules.push(res); } else { const i = rules.findIndex((r) => r.id === editingRule.id); if (i > -1) rules[i] = editingRule; }
					rules.sort((a, b) => (a.priority || 99) - (b.priority || 99));
					return { ...prev, rules };
				});
				setEditingRule(null);
				notify("success", isNew ? "Rule created." : "Rule updated.");
			})
			.catch((e) => notify("error", e.message || "Save failed."))
			.finally(() => setSaving(false));
	};

	const deleteRule = (id) => {
		if (!window.confirm("Delete this rule?")) return;
		setSaving(true);
		apiFetch({ path: "order-pilot/v1/courier-rules/" + id, method: "DELETE" })
			.then(() => { setData((prev) => ({ ...prev, rules: prev.rules.filter((r) => r.id !== id) })); notify("success", "Rule deleted."); })
			.catch((e) => notify("error", e.message || "Delete failed."))
			.finally(() => setSaving(false));
	};

	const moveRule = (idx, dir) => {
		const rules = [...(data.rules || [])];
		const swap = idx + dir;
		if (swap < 0 || swap >= rules.length) return;
		[rules[idx], rules[swap]] = [rules[swap], rules[idx]];
		const reordered = rules.map((r, i) => ({ ...r, priority: i + 1 }));
		setData((prev) => ({ ...prev, rules: reordered }));
		apiFetch({ path: "order-pilot/v1/courier-rules/reorder", method: "POST", data: { order: reordered.map((r) => r.id) } }).catch(() => notify("error", "Failed to save order."));
	};

	if (loading) return <div style={{ padding: 40, textAlign: "center", color: "var(--odrplt-text-muted)" }}>Loading Courier Automation...</div>;

	const rules = (data?.rules || []).sort((a, b) => (a.priority || 99) - (b.priority || 99));
	const condTypes = data?.condition_types || {};
	const categories = data?.categories || [];
	const courierList = couriers();

	return (
		<div style={{ maxWidth: 860, padding: "0 4px" }}>
			{notice && <div className={"odrplt-notice odrplt-notice--" + notice.type} style={{ marginBottom: 20 }}>{notice.text}</div>}

			<div style={{ background: "var(--odrplt-surface, #fff)", border: "1px solid var(--odrplt-border, #e2e8f0)", borderRadius: 12, padding: "22px 24px", marginBottom: 28 }}>
				<h2 style={{ margin: "0 0 18px", fontSize: 16, fontWeight: 700 }}>Automation Settings</h2>
				<div className="odrplt-form-group">
					<label style={{ display: "flex", alignItems: "center", gap: 10, cursor: "pointer", fontWeight: 600 }}>
						<input type="checkbox" className="odrplt-checkbox" checked={autoSelect} onChange={(e) => { setAutoSelect(e.target.checked); setSettingsDirty(true); }} />
						<span>Enable Automatic Courier Selection</span>
					</label>
					<p className="odrplt-form-help">When enabled, rules below are evaluated for each new order to auto-assign a courier.</p>
				</div>
				{autoSelect && (
					<div className="odrplt-form-group" style={{ paddingLeft: 28 }}>
						<label style={{ display: "flex", alignItems: "center", gap: 10, cursor: "pointer", fontWeight: 500 }}>
							<input type="checkbox" className="odrplt-checkbox" checked={autoSend} onChange={(e) => { setAutoSend(e.target.checked); setSettingsDirty(true); }} />
							<span>Enable Automatic Sending</span>
							<span style={{ background: "#fef9c3", color: "#854d0e", fontSize: 11, padding: "1px 6px", borderRadius: 6 }}>OFF by default</span>
						</label>
						<p className="odrplt-form-help">When ON, the order is immediately dispatched after rule evaluation. If the courier fails with a retryable error, the next connected courier is tried automatically.</p>
					</div>
				)}
				<div className="odrplt-form-group">
					<label style={{ fontWeight: 600, fontSize: 13, display: "block", marginBottom: 6 }}>Default Courier (fallback if no rule matches)</label>
					<select className="odrplt-input" style={{ maxWidth: 260 }} value={defaultCourier} onChange={(e) => { setDefault(e.target.value); setSettingsDirty(true); }}>
						<option value="">-- No default --</option>
						{courierList.map((s) => (<option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>))}
					</select>
				</div>
				{settingsDirty && <button className="odrplt-btn odrplt-btn--primary" onClick={saveSettings} disabled={saving}>{saving ? "Saving..." : "Save Settings"}</button>}
			</div>

			<div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 14 }}>
				<h2 style={{ margin: 0, fontSize: 16, fontWeight: 700 }}>Courier Rules ({rules.length})</h2>
				<button className="odrplt-btn odrplt-btn--primary" onClick={() => { setEditingRule({ ...EMPTY_RULE, priority: rules.length + 1 }); setIsNew(true); }} disabled={!!editingRule}>+ Add Rule</button>
			</div>

			{editingRule && isNew && (
				<RuleEditor rule={editingRule} connectedCouriers={courierList} condTypes={condTypes} categories={categories} onChange={setEditingRule} onSave={saveRule} onCancel={() => setEditingRule(null)} saving={saving} />
			)}

			{rules.length === 0 && !editingRule && (
				<div style={{ textAlign: "center", padding: "40px 20px", color: "var(--odrplt-text-muted)", border: "2px dashed var(--odrplt-border, #e2e8f0)", borderRadius: 12 }}>
					<p style={{ fontWeight: 600 }}>No rules yet. Click "+ Add Rule" to get started.</p>
					<p style={{ fontSize: 13 }}>Example: Dhaka + Order &gt; 2000 BDT &rarr; Pathao | Outside Dhaka &rarr; RedX</p>
				</div>
			)}

			{rules.map((rule, idx) =>
				editingRule && !isNew && editingRule.id === rule.id
					? <RuleEditor key={rule.id} rule={editingRule} connectedCouriers={courierList} condTypes={condTypes} categories={categories} onChange={setEditingRule} onSave={saveRule} onCancel={() => setEditingRule(null)} saving={saving} />
					: <RuleCard key={rule.id} rule={rule} idx={idx} total={rules.length} condTypes={condTypes} categories={categories} onEdit={(r) => { setEditingRule({ ...r }); setIsNew(false); }} onDelete={deleteRule} onMove={moveRule} saving={saving} />
			)}

			{rules.length > 0 && (
				<div style={{ background: "#f0fdf4", border: "1px solid #86efac", borderRadius: 10, padding: "14px 18px", marginTop: 20, fontSize: 13 }}>
					<strong>Evaluation Order:</strong> Rules are tested Priority 1 to {rules[rules.length - 1]?.priority}. First match wins.
					{defaultCourier ? " If no rule matches, " + defaultCourier + " is used." : " If no rule matches and no default is set, the order must be sent manually."}
				</div>
			)}
		</div>
	);
}
