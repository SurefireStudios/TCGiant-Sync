// The Listings page's Bulk Actions script, for "Send stock to eBay" (3.40.0),
// run in a browser-like page.
//
// tests/test-send-stock.php pins the script's source; this runs it. The
// harness draws the page's Bulk Actions, its job window and its script from
// admin/views/listings.php itself (php tests/test-send-stock.php
// --listings-page), and this puts them in jsdom with WordPress's own jQuery
// and drives them as a shop does: the option chosen and another, Apply with a
// row ticked, the job's batches answered, and a job refused at its start.
// admin-ajax.php is played: $.post is answered here, and what was posted kept.
//
// Not part of tests/run.php: it needs Node, a jsdom install and a WordPress
// copy, none of which this repository carries, and it installs nothing:
//
//   node tools/check-listings-js.js <jsdom dir> <wordpress dir> [php]
//
// It ends "N passed, M failed" and exits 1 on a failure.
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const [, , jsdomDir, wpDir, phpBin] = process.argv;
if (!jsdomDir || !wpDir) {
  console.error('usage: node tools/check-listings-js.js <jsdom dir> <wordpress dir> [php]');
  process.exit(2);
}
const { JSDOM } = require(path.resolve(jsdomDir));
const root = path.resolve(__dirname, '..');
const jq = fs.readFileSync(path.join(wpDir, 'wp-includes/js/jquery/jquery.js'), 'utf8');
const page = execFileSync(phpBin || 'php', [path.join(root, 'tests', 'test-send-stock.php'), '--listings-page'], { cwd: root, encoding: 'utf8' });
if (page.indexOf('value="bulk_send_stock"') < 0 || page.indexOf('<script>') < 0) {
  console.error('the harness drew no page:\n' + page.slice(0, 2000));
  process.exit(2);
}

let pass = 0;
let fail = 0;
function check(label, got, want) {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  if (ok) { pass++; } else { fail++; }
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label}${ok ? '' : `\n        got  ${JSON.stringify(got)}\n        want ${JSON.stringify(want)}`}`);
}

const COST = 'Sends each listing the stock WooCommerce has now, in the background, as a stock change would: about one eBay call a listing. At 0, eBay is read first: a listing eBay already shows at 0 is left alone; otherwise, with auto-end on, it is ended.';
const TODAY = 'Send stock to eBay today: 0 of 200.';

function load(answers) {
  const html = `<!doctype html><html><head><script>${jq}</script></head><body>${page}</body></html>`;
  const dom = new JSDOM(html, {
    runScripts: 'outside-only',
    pretendToBeVisual: true,
  });
  const w = dom.window;
  w.posted = [];
  w.confirms = [];
  w.alerts = [];
  w.confirm = (m) => { w.confirms.push(m); return true; };
  w.alert = (m) => { w.alerts.push(m); };
  w.eval(jq);
  // admin-ajax.php, played: each post answered in turn.
  w.jQuery.post = (url, data, cb) => {
    w.posted.push(JSON.parse(JSON.stringify(data)));
    const next = answers.shift();
    if (cb && next) { cb(next); }
    return { fail() {} };
  };
  w.jQuery.fx.off = true;
  w.setTimeout = (fn) => fn();
  // The page's own script, as the browser runs it.
  const script = page.slice(page.indexOf('<script>') + 8, page.lastIndexOf('</script>'));
  w.eval(script);
  return w;
}

// 1. The option: its cost, and today's count on the free plan, said beside
// Apply only while it is chosen.
let w = load([]);
const $ = w.jQuery;
check('the cost is hidden until the option is chosen', $('#tc-send-stock-help').css('display'), 'none');
$('#tc-bulk-action').val('bulk_send_stock').trigger('change');
check('chosen: the cost is shown, the format fields are not', [$('#tc-send-stock-help').css('display') !== 'none', $('#tc-format-fields').css('display')], [true, 'none']);
check("  the cost in words, as the server says it, and today's count (fix round 1)",$('#tc-send-stock-help').text(), COST + ' ' + TODAY);
$('#tc-bulk-action').val('bulk_push').trigger('change');
check('another action chosen: the cost hidden again', $('#tc-send-stock-help').css('display'), 'none');

// 2. Apply with a row ticked: the confirm says the cost; the job posted.
w = load([
  { success: true, data: { job_id: 'job-1', total: 1 } },
  { success: true, data: { status: 'running', processed: 0, total: 1, queued: 1, kept: 0, type: 'bulk_send_stock', errors: [], lines: [] } },
  { success: true, data: { status: 'completed', processed: 1, total: 1, queued: 1, kept: 1, type: 'bulk_send_stock', errors: [], lines: ['Send stock to eBay: 1 push queued.', '1 not sent: not on eBay: #12.'] } },
]);
w.jQuery('#tc-bulk-action').val('bulk_send_stock').trigger('change');
w.jQuery('.tc-listing-cb[value="11"]').prop('checked', true);
w.jQuery('#tc-bulk-apply').trigger('click');
check('Apply: one confirm, naming the action and the count, with the cost', w.confirms, ['Run "Send stock to eBay" on 1 listing(s)? ' + COST]);
check('  the job started as the page\'s other jobs start: its type and the ticked IDs, with the page\'s nonce', [w.posted[0].action, w.posted[0].type, w.posted[0].product_ids, typeof w.posted[0]._ajax_nonce === 'string' && w.posted[0]._ajax_nonce.length > 0], ['tcgiant_job_start', 'bulk_send_stock', [11], true]);
check('  each batch asked for until the job is done', w.posted.slice(1).map((p) => [p.action, p.job_id]), [['tcgiant_job_process', 'job-1'], ['tcgiant_job_process', 'job-1']]);
check('  done: the notice\'s lines in the window', w.jQuery('#tc-job-status').text(), 'Complete! Send stock to eBay: 1 push queued. 1 not sent: not on eBay: #12.');
check('  the title: the action\'s label and the count', w.jQuery('#tc-job-title').text(), 'Send stock to eBay — 1/1');

// While it runs: its own count words.
w = load([
  { success: true, data: { job_id: 'job-2', total: 300 } },
  { success: true, data: { status: 'running', processed: 100, total: 300, queued: 97, kept: 3, type: 'bulk_send_stock', errors: [], lines: [] } },
]);
w.jQuery('#tc-bulk-action').val('bulk_send_stock').trigger('change');
w.jQuery('#tc-select-all-matching').prop('checked', true);
w.jQuery('#tc-bulk-apply').trigger('click');
check('everything matching: the page\'s filters go with it, and the count the page shows', [w.posted[0].select_all, w.confirms[0].indexOf('on 2 listing(s)?') > 0], [1, true]);
check('  while it runs: queued and not sent, in its own words', w.jQuery('#tc-job-status').text(), '97 queued, 3 not sent');

// 3. Refused at its start (sending switched off): the server's words.
w = load([
  { success: false, data: { message: 'Send stock to eBay: nothing was queued, because "Send website stock changes to eBay?" is No in TCGiant Sync, Settings. Set it to Yes to send stock to eBay.' } },
]);
w.jQuery('#tc-bulk-action').val('bulk_send_stock').trigger('change');
w.jQuery('.tc-listing-cb[value="12"]').prop('checked', true);
w.jQuery('#tc-bulk-apply').trigger('click');
check('refused at its start: said in an alert, in the server\'s words, and no batch asked for', [w.alerts, w.posted.length], [['Send stock to eBay: nothing was queued, because "Send website stock changes to eBay?" is No in TCGiant Sync, Settings. Set it to Yes to send stock to eBay.'], 1]);

// 4. Another action's confirm says no cost of this one.
w = load([{ success: false, data: { message: 'x' } }]);
w.jQuery('#tc-bulk-action').val('bulk_push').trigger('change');
w.jQuery('.tc-listing-cb[value="11"]').prop('checked', true);
w.jQuery('#tc-bulk-apply').trigger('click');
check('Push to eBay\'s confirm is as it was', w.confirms, ['Run "Push to eBay" on 1 listing(s)?']);

console.log(`\n  ${pass} passed, ${fail} failed`);
process.exit(fail > 0 ? 1 : 0);
