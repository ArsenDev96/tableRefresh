// WordPress.org screenshots (screenshot-1 … 5) on a fresh site with TableRefresh and TablePress
// active and no tables yet, through the browser like an administrator.
//
//   node store-shots.js <output dir>
//
// TR_BASE: site address (default http://localhost:8090, see dev/rc-env.sh); TR_WP: WP-CLI command
// for that site, run from the repository root (default "sh dev/rc-env.sh wp"). WP-CLI only runs the
// scheduled sync event (as WP-Cron would) and reads IDs.
const puppeteer = require( 'puppeteer-core' );
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );
const REPO = path.resolve( __dirname, '../../..' ).replace( /\\/g, '/' );
const fs = require( 'fs' );

const BASE = process.env.TR_BASE || 'http://localhost:8090';
const WP = process.env.TR_WP || 'sh dev/rc-env.sh wp';
const OUT = process.argv[ 2 ];
const SHEET = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit?usp=sharing';
const SHEET_CHANGED = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/gviz/tq?tqx=out:csv&tq=' + encodeURIComponent( 'select A, B, C limit 5' );
if ( ! OUT ) {
	console.error( 'usage: node store-shots.js <output dir>' );
	process.exit( 1 );
}
fs.mkdirSync( OUT, { recursive: true } );

const wp = ( code ) => execFileSync( 'C:/Program Files/Git/bin/bash.exe', [ '-c', 'cd "' + REPO + '" && ' + WP + ' eval "$0" 2>/dev/null | tail -1', code ], { encoding: 'utf8' } ).trim();
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

( async () => {
	const browser = await puppeteer.launch( { executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', headless: 'new', userDataDir: path.join( __dirname, 'profile-shots' ) } );
	const page = await browser.newPage();
	const problems = [];
	page.on( 'pageerror', ( e ) => problems.push( 'pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => { if ( 'error' === m.type() ) problems.push( 'console: ' + m.text() + ' @ ' + page.url() ); } );
	await page.setViewport( { width: 1280, height: 900 } );
	const go = async ( url ) => page.goto( BASE + url, { waitUntil: 'networkidle2' } );
	const shot = async ( n ) => {
		await page.screenshot( { path: path.join( OUT, `screenshot-${ n }.png` ), fullPage: true } );
		console.log( 'saved screenshot-' + n );
	};
	const text = async () => page.$eval( '#wpbody-content', ( e ) => e.innerText );
	const submitAndWait = async ( selector ) => Promise.all( [ page.waitForNavigation( { waitUntil: 'networkidle2', timeout: 90000 } ), page.click( selector ) ] );
	const expect = async ( needle, step ) => {
		if ( ! ( await text() ).includes( needle ) ) {
			throw new Error( `${ step }: "${ needle }" not found` );
		}
	};
	const trPage = '/wp-admin/tools.php?page=tablerefresh';

	await go( '/wp-login.php' );
	if ( await page.$( '#user_login' ) ) {
		await page.type( '#user_login', 'admin' );
		await page.type( '#user_pass', 'admin' );
		await submitAndWait( '#wp-submit' );
	}

	// A TablePress table to fill. Created through TablePress's model, not its "Add" screen: that screen
	// opens the new table in the editor, and an open editor rightly holds automatic syncs for 15 minutes.
	const tableId = wp( "wp_set_current_user( 1 ); $t = TablePress::$model_table->get_table_template(); $t['name'] = 'Class roster'; $t['data'] = array( array( '' ) ); $t['visibility'] = array( 'rows' => array( 1 ), 'columns' => array( 1 ) ); echo TablePress::$model_table->add( $t );" );
	const postId = wp( `echo TableRefresh\\TablePress_Adapter::get_post_id( '${ tableId }' );` );

	// 2: setting up the table.
	await go( trPage + '&action=add' );
	await page.select( '#tablerefresh-table', tableId );
	await page.type( '#tablerefresh-url', SHEET );
	await page.click( 'input[name="frequency"][value="hourly"]' );
	await page.click( 'input[name="confirm_replace"]' );
	await page.evaluate( () => document.activeElement.blur() );
	await shot( 2 );
	await submitAndWait( '#submit' );
	await expect( 'The table was updated: 31 rows, 6 columns', 'setup' );

	// Automatic syncs: the sheet answers with other data, then with the full sheet again.
	const setSource = async ( url ) => {
		await go( trPage + '&action=edit&table=' + postId );
		await page.$eval( '#tablerefresh-url', ( e ) => { e.value = ''; } );
		await page.type( '#tablerefresh-url', url );
		await submitAndWait( '#submit' );
		await expect( 'Settings saved.', 'save source' );
	};
	const scheduledSync = () => wp( `do_action( TableRefresh\\Scheduler::HOOK, ${ postId } ); echo TableRefresh\\Sync_Log::last( ${ postId } )['outcome'];` );
	await setSource( SHEET_CHANGED );
	if ( 'updated' !== scheduledSync() ) {
		throw new Error( 'first scheduled sync did not update' );
	}
	await setSource( SHEET );
	if ( 'updated' !== scheduledSync() ) {
		throw new Error( 'second scheduled sync did not update' );
	}
	// 1: the list after the automatic sync; 5: the same on a phone.
	await go( trPage );
	await shot( 1 );
	await page.setViewport( { width: 390, height: 844, isMobile: true } );
	await go( trPage );
	await shot( 5 );
	await page.setViewport( { width: 1280, height: 900 } );

	// 3: settings and history, after a "Sync now" without changes.
	await go( trPage + '&action=edit&table=' + postId );
	await submitAndWait( '.tablerefresh-sync-form button' );
	await expect( 'The source has not changed', 'Sync now' );
	await go( trPage + '&action=edit&table=' + postId );
	await shot( 3 );

	// 4: someone edits the table in TablePress; the next automatic sync stops and asks.
	await go( `/wp-admin/admin.php?page=tablepress&action=edit&table_id=${ tableId }` );
	await sleep( 2000 );
	await page.click( 'td[data-x="5"][data-y="3"]', { clickCount: 2 } );
	const editorInput = await page.waitForSelector( 'td.editor textarea' );
	await editorInput.evaluate( ( e ) => { e.value = ''; } );
	await editorInput.type( 'Chess Club' );
	await page.click( 'td[data-x="1"][data-y="2"]' );
	await page.evaluate( () => [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => 'Save Changes' === b.textContent.trim() ).click() );
	await sleep( 3000 );
	if ( 'conflict' !== scheduledSync() ) {
		throw new Error( 'the edit was not detected' );
	}
	await go( trPage + '&action=edit&table=' + postId );
	await expect( 'This table was changed in TablePress', 'conflict' );
	await shot( 4 );

	console.log( problems.length ? 'problems: ' + problems.join( ' | ' ) : 'no JavaScript errors' );
	await browser.close();
} )().catch( ( e ) => { console.error( 'ABORTED:', e.message ); process.exit( 1 ); } );
