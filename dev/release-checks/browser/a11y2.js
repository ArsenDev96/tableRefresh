// Keyboard order inside the plugin's area, and axe's color-contrast item to review.
const puppeteer = require( 'puppeteer-core' );
const path = require( 'path' );
const BASE = 'http://localhost:8090';
( async () => {
	const browser = await puppeteer.launch( { executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: 'new', userDataDir: path.join( __dirname, 'profile-rc' ) } );
	const page = await browser.newPage();
	await page.setViewport( { width: 1280, height: 900 } );
	await page.goto( BASE + '/wp-login.php', { waitUntil: 'networkidle2' } );
	if ( await page.$( '#user_login' ) ) {
		await page.type( '#user_login', 'admin' );
		await page.type( '#user_pass', 'admin' );
		await Promise.all( [ page.waitForNavigation( { waitUntil: 'networkidle2' } ), page.click( '#wp-submit' ) ] );
	}
	for ( const [ label, url ] of [ [ 'set up', '&action=add' ], [ 'list', '' ], [ 'settings', '&action=edit&table=7' ] ] ) {
		await page.goto( BASE + '/wp-admin/admin.php?page=tablerefresh' + url, { waitUntil: 'networkidle2' } );
		await page.focus( '.tablerefresh a' ); // First link of the plugin's area.
		const order = [];
		for ( let i = 0; i < 40; i++ ) {
			const f = await page.evaluate( () => {
				const e = document.activeElement;
				if ( ! e || ! e.closest( '.tablerefresh' ) ) {
					return null;
				}
				const s = getComputedStyle( e );
				const visible = ( 'none' !== s.outlineStyle && '0px' !== s.outlineWidth ) || ( s.boxShadow && 'none' !== s.boxShadow );
				return ( e.getAttribute( 'name' ) ? e.getAttribute( 'name' ) + ( 'radio' === e.type ? '=' + e.value : '' ) : e.textContent.trim().slice( 0, 24 ) || e.tagName ) + ( visible ? '' : ' [NO VISIBLE FOCUS]' );
			} );
			if ( null === f ) {
				break;
			}
			if ( order[ order.length - 1 ] !== f ) {
				order.push( f );
			}
			await page.keyboard.press( 'Tab' );
		}
		console.log( `keyboard ${ label }: ${ order.join( ' → ' ) }` );
	}
	await page.goto( BASE + '/wp-admin/admin.php?page=tablerefresh&action=add', { waitUntil: 'networkidle2' } );
	await page.addScriptTag( { path: require.resolve( 'axe-core/axe.min.js' ) } );
	const r = await page.evaluate( async () => axe.run( { include: [ '.tablerefresh' ] }, { runOnly: { type: 'rule', values: [ 'color-contrast' ] } } ) );
	for ( const v of r.incomplete ) {
		for ( const n of v.nodes ) {
			console.log( 'contrast to review:', n.html.slice( 0, 120 ), '—', ( n.any[ 0 ] || {} ).message );
		}
	}
	await browser.close();
} )();
