/**
 * Layout Component
 */
import './Layout.css';

export default function Layout( { children, currentPage } ) {
	return (
		<div className="odrplt-layout">
			<main className="odrplt-content">
				{ children }
			</main>
		</div>
	);
}
