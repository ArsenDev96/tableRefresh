// Full TableRefresh workflow in a clean WordPress site (dev/rc-env.sh, http://localhost:8090),
// through the browser like an administrator. WP-CLI is used only to read the true database state.
const puppeteer = require( 'puppeteer-core' );
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );
const fs = require( 'fs' );

const BASE = 'http://localhost:8090';
const OUT = path.join( __dirname, 'rc' );
const SHEET = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit?usp=sharing';
const SHEET_CHANGED = 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/gviz/tq?tqx=out:csv&tq=' + encodeURIComponent( 'select A, B, C limit 5' );
fs.mkdirSync( OUT, { recursive: true } );

const results = [];
const check = ( ok, label, detail = '' ) => {
	results.push( ( ok ? 'PASS ' : 'FAIL ' ) + label + ( detail ? ' — ' + detail : '' ) );
	console.log( results[ results.length - 1 ] );
};
const wp = ( code ) => execFileSync( 'C:/Program Files/Git/bin/bash.exe', [ '-c', 'cd /e/tableRefresh && sh dev/rc-env.sh wp eval "$0" 2>/dev/null | tail -1', code ], { encoding: 'utf8' } ).trim();
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

( async () => {
	const browser = await puppeteer.launch( { executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', headless: 'new', userDataDir: path.join( __dirname, 'profile-rc' ) } );
	const page = await browser.newPage();
	const problems = [];
	page.on( 'pageerror', ( e ) => problems.push( 'pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => { if ( 'error' === m.type() ) problems.push( 'console: ' + m.text() + ' @ ' + page.url() ); } );
	await page.setViewport( { width: 1280, height: 900 } );
	const go = async ( url ) => page.goto( BASE + url, { waitUntil: 'networkidle2' } );
	const shot = async ( name ) => page.screenshot( { path: path.join( OUT, name + '.png' ), fullPage: true } );
	const text = async () => page.$eval( '#wpbody-content', ( e ) => e.innerText );
	const submitAndWait = async ( selector ) => Promise.all( [ page.waitForNavigation( { waitUntil: 'networkidle2', timeout: 90000 } ), page.click( selector ) ] );
	const trPage = '/wp-admin/tools.php?page=tablerefresh';

	// Log in.
	await go( '/wp-login.php' );
	if ( await page.$( '#user_login' ) ) {
		await page.type( '#user_login', 'admin' );
		await page.type( '#user_pass', 'admin' );
		await submitAndWait( '#wp-submit' );
	}

	// 1. Install TableRefresh from the ZIP through Plugins > Add New > Upload Plugin.
	await go( '/wp-admin/plugin-install.php?tab=upload' );
	const upload = await page.$( '#pluginzip' );
	await upload.uploadFile( path.join( __dirname, 'tablerefresh.zip' ) );
	await submitAndWait( '#install-plugin-submit' );
	check( ( await text() ).includes( 'Plugin installed successfully' ), 'install from ZIP upload' );
	await Promise.all( [ page.waitForNavigation( { waitUntil: 'networkidle2' } ), page.click( 'a.button-primary[href*="action=activate"]' ) ] );
	check( ( await text() ).includes( 'Plugin activated' ), 'activate' );
	check( await page.$( 'a[href*="tools.php?page=tablerefresh"]' ) !== null, 'plugin row "Set up" link' );

	// 2. Create a table in TablePress, and change one of its options in the TablePress editor.
	await go( '/wp-admin/admin.php?page=tablepress_add' );
	await page.type( 'input[name="table[name]"]', 'Class roster' );
	await page.$eval( 'input[name="table[rows]"]', ( e ) => { e.value = ''; } );
	await page.type( 'input[name="table[rows]"]', '2' );
	await page.$eval( 'input[name="table[columns]"]', ( e ) => { e.value = ''; } );
	await page.type( 'input[name="table[columns]"]', '2' );
	await submitAndWait( '#wpbody form button[type=submit]' );
	const tableId = new URL( page.url() ).searchParams.get( 'table_id' );
	check( !! tableId, 'TablePress table created', 'ID ' + tableId );
	await sleep( 1500 );
	await page.click( 'label[for="inspector-checkbox-control-2"]' ); // An option that is off by default.
	await page.evaluate( () => [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => 'Save Changes' === b.textContent.trim() ).click() );
	await sleep( 3000 );
	const optionsBefore = wp( `echo get_post_meta( TableRefresh\\TablePress_Adapter::get_post_id( '${ tableId }' ), '_tablepress_table_options', true );` );
	const pageId = execFileSync( 'C:\\Program Files\\Git\\bin\\bash.exe', [ '-c', `cd /e/tableRefresh && sh dev/rc-env.sh wp post create --post_type=page --post_status=publish --post_title=Roster --post_content='[table id=${ tableId } /]' --porcelain 2>/dev/null | tail -1` ], { encoding: 'utf8' } ).trim();

	// 3. Connect the public Google Sheet and run the first sync.
	await go( trPage );
	await shot( '01-empty-state' );
	check( ( await text() ).includes( 'Get started' ), 'empty state with steps' );
	await go( trPage + '&action=add' );
	await page.select( '#tablerefresh-table', tableId );
	await page.type( '#tablerefresh-url', SHEET );
	await page.click( 'input[name="frequency"][value="daily"]' );
	await page.click( 'input[name="confirm_replace"]' );
	await shot( '02-set-up-filled' );
	await submitAndWait( '#submit' );
	let body = await text();
	check( /Class roster: The table was updated: 31 rows, 6 columns/.test( body ), 'first sync from Google Sheets', body.split( '\n' ).find( ( l ) => l.includes( 'Class roster:' ) ) );
	await shot( '03-list-after-setup' );
	const postId = wp( `echo TableRefresh\\TablePress_Adapter::get_post_id( '${ tableId }' );` );

	// The page with the shortcode shows the synchronized data.
	await go( '/?page_id=' + pageId );
	check( ( await page.content() ).includes( 'Student Name' ), 'shortcode page shows Google data' );

	// 4. The spreadsheet changes (another live answer of the same sheet), then Sync now.
	await go( trPage + '&action=edit&table=' + postId );
	await page.$eval( '#tablerefresh-url', ( e ) => { e.value = ''; } );
	await page.type( '#tablerefresh-url', SHEET_CHANGED );
	await page.click( 'input[name="frequency"][value="hourly"]' );
	await submitAndWait( '#submit' );
	check( ( await text() ).includes( 'Settings saved.' ), 'settings saved (hourly)' );
	check( ( await text() ).includes( 'Every hour' ) && /Next sync in about (60 mins|1 hour)/.test( await text() ), 'automatic sync hourly with next run' );
	await submitAndWait( '.tablerefresh-sync-form button' );
	body = await text();
	check( /The table was updated: \d+ rows, 3 columns/.test( body ), 'Sync now after the sheet changed', body.split( '\n' ).find( ( l ) => l.includes( 'Class roster:' ) ) );
	await submitAndWait( '.tablerefresh-sync-form button' );
	check( ( await text() ).includes( 'The source has not changed' ), 'Sync now without changes' );

	// 5. Error messages.
	for ( const [ url, expected, label ] of [
		[ 'https://docs.google.com/spreadsheets/d/1TableRefreshNoSuchSheet0000000000000000000/edit', /not public|Nothing was found|refused access/, 'missing/private Google Sheet' ],
		[ 'https://wordpress.org/tablerefresh-no-such-file.csv', /Nothing was found at the source address \(HTTP 404\)/, 'missing CSV (404)' ],
		[ 'https://127.0.0.1/secret.csv', /not allowed|blocked|private|reserved/i, 'private address refused (SSRF)' ],
	] ) {
		await go( trPage + '&action=edit&table=' + postId );
		await page.$eval( '#tablerefresh-url', ( e ) => { e.value = ''; } );
		await page.type( '#tablerefresh-url', url );
		await submitAndWait( '#submit' );
		await submitAndWait( '.tablerefresh-sync-form button' );
		body = await text();
		const notice = body.split( '\n' ).find( ( l ) => l.includes( 'Class roster:' ) ) || '';
		check( expected.test( notice ), 'error message: ' + label, notice );
	}
	await shot( '04-history-with-errors' );
	await go( trPage + '&action=edit&table=' + postId );
	await page.$eval( '#tablerefresh-url', ( e ) => { e.value = ''; } );
	await page.type( '#tablerefresh-url', SHEET );
	await submitAndWait( '#submit' );
	await submitAndWait( '.tablerefresh-sync-form button' );
	check( ( await text() ).includes( 'The table was updated: 31 rows, 6 columns' ), 'back to the full sheet' );

	// 6. A manual edit in the TablePress editor.
	await go( `/wp-admin/admin.php?page=tablepress&action=edit&table_id=${ tableId }` );
	await sleep( 2000 );
	await page.click( 'td[data-x="0"][data-y="1"]', { clickCount: 2 } );
	const editorInput = await page.waitForSelector( 'td.editor textarea' );
	await editorInput.evaluate( ( e ) => { e.value = ''; } );
	await editorInput.type( 'EDITED IN TABLEPRESS' );
	await page.click( 'td[data-x="1"][data-y="2"]' ); // Leaves the cell (Enter adds a line break in TablePress).
	await page.evaluate( () => [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => 'Save Changes' === b.textContent.trim() ).click() );
	await sleep( 3000 );
	const cellValue = wp( `echo TablePress::$model_table->load( '${ tableId }' )['data'][1][0];` );
	check( 'EDITED IN TABLEPRESS' === cellValue, 'edit saved in the TablePress editor', cellValue );

	// The administrator's own editor session does not block "Sync now"; the edit is detected.
	await go( trPage + '&action=edit&table=' + postId );
	await submitAndWait( '.tablerefresh-sync-form button' );
	body = await text();
	check( body.includes( 'This table was changed in TablePress' ), 'conflict shown with the two choices' );
	check( 'EDITED IN TABLEPRESS' === wp( `echo TablePress::$model_table->load( '${ tableId }' )['data'][1][0];` ), 'manual edit not overwritten' );
	await shot( '05-conflict' );
	await go( trPage );
	check( ( await text() ).includes( 'Review changes' ), 'list offers "Review changes"' );
	await go( '/wp-admin/' );
	check( ( await text() ).includes( 'needs your decision' ), 'dashboard notice' );

	// 7. Keep the changes: automatic sync is turned off.
	await go( trPage + '&action=edit&table=' + postId );
	await submitAndWait( 'form:has(input[value="tablerefresh_keep"]) button' );
	body = await text();
	check( body.includes( 'are kept, and automatic sync is turned off' ), 'keep changes' );
	check( body.includes( 'Off (only "Sync now")' ), 'status off' );
	check( '0' === wp( `echo (int) TableRefresh\\Scheduler::count_events( ${ postId } );` ), 'no scheduled event after keep' );

	// 8. Resume automatic sync; the edit still needs a decision; replace it.
	await page.click( 'input[name="enabled"]' );
	await submitAndWait( '#submit' );
	check( '1' === wp( `echo (int) TableRefresh\\Scheduler::count_events( ${ postId } );` ), 'resume: one scheduled event' );
	await submitAndWait( '.tablerefresh-sync-form button' );
	check( ( await text() ).includes( 'This table was changed in TablePress' ), 'Sync now still stops at the edit' );
	// Without confirmation the browser does not submit (required checkbox; the server also refuses, see AdminScreenTest).
	const urlBefore = page.url();
	await page.click( 'form:has(input[value="tablerefresh_replace"]) button' );
	await sleep( 1500 );
	const invalid = await page.$eval( 'form:has(input[value="tablerefresh_replace"]) input[name="confirm_replace"]', ( e ) => ! e.checkValidity() && '' !== e.validationMessage );
	check( invalid && page.url() === urlBefore, 'replace needs the confirmation checkbox' );
	await page.click( 'form:has(input[value="tablerefresh_replace"]) input[name="confirm_replace"]' );
	await submitAndWait( 'form:has(input[value="tablerefresh_replace"]) button' );
	check( ( await text() ).includes( 'The table was updated: 31 rows, 6 columns' ), 'replace with source data' );
	check( 'Alexandra' === wp( `echo TablePress::$model_table->load( '${ tableId }' )['data'][1][0];` ), 'edit replaced by source data' );

	// 9. Pause and disable.
	await page.click( 'input[name="enabled"]' );
	await submitAndWait( '#submit' );
	check( '0' === wp( `echo (int) TableRefresh\\Scheduler::count_events( ${ postId } );` ) && ( await text() ).includes( 'Off (only "Sync now")' ), 'disable automatic sync' );
	await page.click( 'input[name="enabled"]' );
	await submitAndWait( '#submit' );
	check( '1' === wp( `echo (int) TableRefresh\\Scheduler::count_events( ${ postId } );` ), 'enable again' );
	await shot( '06-settings-history' );

	// 10. TablePress settings, ID, and shortcode are unchanged.
	const optionsAfter = wp( `echo get_post_meta( TableRefresh\\TablePress_Adapter::get_post_id( '${ tableId }' ), '_tablepress_table_options', true );` );
	check( optionsBefore === optionsAfter, 'table options unchanged', optionsAfter.slice( 0, 80 ) );
	check( tableId === wp( `echo TableRefresh\\TablePress_Adapter::get_table_id( ${ postId } );` ), 'table ID unchanged' );
	await go( '/?page_id=' + pageId );
	check( ( await page.content() ).includes( 'Alexandra' ), 'shortcode still renders the table' );

	// 11. Phone.
	await page.setViewport( { width: 390, height: 844, isMobile: true } );
	await go( trPage );
	await shot( '07-list-phone' );
	await go( trPage + '&action=edit&table=' + postId );
	await shot( '08-settings-phone' );
	const overflow = await page.evaluate( () => document.documentElement.scrollWidth - window.innerWidth );
	check( overflow <= 0, 'no horizontal scrolling on a phone', 'overflow ' + overflow + ' px' );
	await page.setViewport( { width: 1280, height: 900 } );

	await go( trPage );
	await shot( '09-list' );

	check( 0 === problems.length, 'no JavaScript errors', problems.join( ' | ' ) );
	const log = wp( `echo file_exists( WP_CONTENT_DIR . '/debug.log' ) ? str_replace( "\\n", ' / ', file_get_contents( WP_CONTENT_DIR . '/debug.log' ) ) : 'empty';` );
	check( 'empty' === log, 'no PHP notices in debug.log', log.slice( 0, 300 ) );
	fs.writeFileSync( path.join( OUT, 'results.txt' ), results.join( '\n' ) + '\n' );
	await browser.close();
} )().catch( ( e ) => { console.error( 'ABORTED:', e.message ); process.exit( 1 ); } );
