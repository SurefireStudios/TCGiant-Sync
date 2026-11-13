// The bulk edit group's own JavaScript (3.39.0), run in a browser-like page.
//
// tests/test-bulk-edit.php pins the script's source; this runs it. The group is
// drawn by that harness (php tests/test-bulk-edit.php --panel), put in a
// products list built from WordPress's own inline-edit-post.js and jQuery and
// WooCommerce's own quick-edit.js, and driven in jsdom as a shop drives it:
// two bulk edits on one page with no reload - open, set, Cancel, open again -
// and Move to Trash with the group open; what the list's form would send on
// Update is read each time.
//
// Not part of tests/run.php: it needs Node, a jsdom install, a WordPress copy
// and WooCommerce's quick-edit.js, none of which this repository carries, and
// it installs nothing. Point it at what you have:
//
//   node tools/check-bulk-edit-js.js <jsdom dir> <wordpress dir> <dir holding quick-edit.js> [php]
//
// <jsdom dir> is a node_modules/jsdom folder; <wordpress dir> holds wp-admin/
// and wp-includes/; quick-edit.js is WooCommerce's
// client/legacy/js/admin/quick-edit.js (or assets/js/admin/quick-edit.js of a
// built WooCommerce). It ends "N passed, M failed" and exits 1 on a failure.
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const [, , jsdomDir, wpDir, wcDir, phpBin] = process.argv;
if (!jsdomDir || !wpDir || !wcDir) {
  console.error('usage: node tools/check-bulk-edit-js.js <jsdom dir> <wordpress dir> <dir holding quick-edit.js> [php]');
  process.exit(2);
}
const { JSDOM } = require(path.resolve(jsdomDir));
const root = path.resolve(__dirname, '..');

const jq = fs.readFileSync(path.join(wpDir, 'wp-includes/js/jquery/jquery.js'), 'utf8');
const iep = fs.readFileSync(path.join(wpDir, 'wp-admin/js/inline-edit-post.js'), 'utf8');
const wcq = fs.readFileSync(path.join(wcDir, 'quick-edit.js'), 'utf8');
const panel = execFileSync(phpBin || 'php', [path.join(root, 'tests', 'test-bulk-edit.php'), '--panel'], { cwd: root, encoding: 'utf8' });
if (panel.indexOf('id="tcgiant-bulk-edit"') < 0) {
  console.error('the harness drew no group:\n' + panel.slice(0, 2000));
  process.exit(2);
}

const html = `<!doctype html><html><head><script>${jq}</script>
<script>window.wp={i18n:{__:function(s){return s;},_x:function(s){return s;},sprintf:function(f,a){return f.replace('%s',a);}},a11y:{speak:function(){}}};window.ajaxurl='';window.typenow='product';window.woocommerce_admin={i18n_delete_product_notice:''};</script>
</head><body><div id="wpbody">
<form id="posts-filter" method="get" action="edit.php">
<select name="action" id="bulk-action-selector-top"><option value="-1">Bulk actions</option><option value="edit">Edit</option><option value="trash">Move to Trash</option></select>
<input type="submit" id="doaction" class="button action" value="Apply">
<table class="wp-list-table widefat fixed striped posts"><thead><tr><td class="check-column"></td><th>Name</th></tr></thead>
<tbody id="the-list">
<tr id="post-11"><th class="check-column"><input type="checkbox" name="post[]" value="11"></th><td>A<div class="hidden" id="inline_11"><div class="post_title">A</div></div></td></tr>
<tr id="post-12"><th class="check-column"><input type="checkbox" name="post[]" value="12"></th><td>B<div class="hidden" id="inline_12"><div class="post_title">B</div></div></td></tr>
</tbody></table></form>
<form method="get"><table style="display:none"><tbody id="inlineedit">
<tr id="inline-edit" class="inline-edit-row" style="display:none"><td><fieldset></fieldset><button type="button" class="cancel">Cancel</button></td></tr>
<tr id="bulk-edit" class="inline-edit-row inline-edit-row-post bulk-edit-row" style="display:none"><td colspan="2">
<div id="bulk-title-div"><div id="bulk-titles"></div></div>
<fieldset class="inline-edit-col-left"></fieldset>
<fieldset class="inline-edit-col-right"><div id="woocommerce-fields-bulk" class="inline-edit-col"><h4>Product data</h4>
<div class="inline-edit-group"><label class="alignleft"><span class="input-text-wrap"><select class="change_regular_price change_to" name="change_regular_price"><option value="">No change</option><option value="1">Change to:</option></select></span></label><label class="change-input"><input type="text" name="_regular_price" class="text regular_price" value=""></label></div>
${panel}
<input type="hidden" name="woocommerce_bulk_edit" value="1"><input type="hidden" name="woocommerce_quick_edit_nonce" value="n"></div></fieldset>
<div class="submit inline-edit-save"><input type="submit" name="bulk_edit" id="bulk_edit" class="button button-primary" value="Update"><button type="button" class="button cancel">Cancel</button></div>
</td></tr></tbody></table></form>
</div>
<script>${iep}</script><script>${wcq}</script>
</body></html>`;

// jsdom has no form submission; the page's own submits are not what is checked.
const virtualConsole = new (require(path.resolve(jsdomDir))).VirtualConsole();
virtualConsole.on('jsdomError', function (e) { if (String(e && e.message).indexOf('requestSubmit') < 0) { console.error(e); } });
const dom = new JSDOM(html, { runScripts: 'dangerously', url: 'https://shop.example/wp-admin/edit.php?post_type=product', virtualConsole });
const w = dom.window;
let pass = 0, fail = 0;
function check(label, got, want) {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label}  got ${JSON.stringify(got)}${ok ? '' : ' want ' + JSON.stringify(want)}`);
}
function $(s) { return w.jQuery(s); }
function open() {
  $('input[name="post[]"]').prop('checked', true);
  $('#bulk-action-selector-top').val('edit');
  $('#doaction')[0].click();
}
function group() {
  const out = {};
  $('#tcgiant-bulk-edit select.tcgiant-bulk-change').each(function () { out[this.name] = this.value; });
  $('#tcgiant-bulk-edit input.text, #tcgiant-bulk-edit textarea').each(function () { if (this.value !== '') out[this.name] = this.value; });
  return out;
}
function nonEmpty(o) { const r = {}; for (const k in o) if (o[k] !== '') r[k] = o[k]; return r; }
function shown() { return $('#tcgiant-bulk-edit .tcgiant-bulk-input').filter(function () { return this.style.display !== 'none'; }).length; }
function sent() {
  // What the list's form sends on Update: its named controls, as a browser
  // sends them, the clicked button included.
  const fd = new w.FormData($('#posts-filter')[0]);
  fd.append('bulk_edit', 'Update');
  const out = {};
  for (const [k, v] of fd.entries()) { if (k.indexOf('tcgiant_bulk_') === 0 && v !== '') out[k] = v; }
  return out;
}

setTimeout(function () {
  console.log('\nTHE GROUP\'S JAVASCRIPT, TWO BULK EDITS ON ONE PAGE');
  check('the script ran once (one change handler on the group)', w.jQuery._data($('#tcgiant-bulk-edit')[0], 'events').change.length, 1);
  open();
  check('first open: the bulk row is in the list form, shown', [$('#posts-filter #bulk-edit').length, $('#bulk-edit').hasClass('inline-editor')], [1, true]);
  check('  every field at No change, no box shown', [nonEmpty(group()), shown()], [{}, 0]);
  $('select[name="tcgiant_bulk_subtitle"]').val('set').trigger('change');
  check('  Set to shows its box', shown(), 1);
  $('input[name="tcgiant_bulk_subtitle_value"]').val('Bulk test');
  $('select[name="tcgiant_bulk_category"]').val('custom').trigger('change');
  $('input[name="tcgiant_bulk_category_value"]').val('31387');
  $('select[name="tcgiant_bulk_condition_note"]').val('set').trigger('change');
  $('textarea[name="tcgiant_bulk_condition_note_value"]').val('Line one\nLine two');
  $('select[name="tcgiant_bulk_listing_type"]').val('Chinese');
  check('  a typed category ID shows its box too', shown(), 3);
  check('  sent on Update: the choices and what is typed', sent(), { tcgiant_bulk_category: 'custom', tcgiant_bulk_category_value: '31387', tcgiant_bulk_listing_type: 'Chinese', tcgiant_bulk_subtitle: 'set', tcgiant_bulk_subtitle_value: 'Bulk test', tcgiant_bulk_condition_note: 'set', tcgiant_bulk_condition_note_value: 'Line one\nLine two' });
  $('select[name="tcgiant_bulk_subtitle"]').val('').trigger('change');
  check('  back to No change: its box hidden, what was typed still sent beside a "" choice (the server ignores it)', [shown(), sent().tcgiant_bulk_subtitle, sent().tcgiant_bulk_subtitle_value], [2, undefined, 'Bulk test']);
  $('#bulk-edit .cancel')[0].click();
  check('Cancel: the bulk row back in the hidden table', [$('#inlineedit #bulk-edit').length, $('#posts-filter #bulk-edit').length], [1, 0]);
  check('  sent by the list form now: nothing of the group', sent(), {});
  open();
  check('second open, no reload: every field at No change again, every box and text area empty and hidden', [nonEmpty(group()), shown()], [{}, 0]);
  check('  sent on Update: nothing of the group', sent(), {});
  $('select[name="tcgiant_bulk_brand"]').val('set').trigger('change');
  $('input[name="tcgiant_bulk_brand_value"]').val('Acme');
  $('#bulk-action-selector-top').val('trash');
  $('#doaction')[0].click();
  check('Move to Trash clicked with the group open: the row reverted, the group reset', [$('#posts-filter #bulk-edit').length, nonEmpty(group())], [0, {}]);
  console.log(`\n  ${pass} passed, ${fail} failed\n`);
  process.exit(fail ? 1 : 0);
}, 50);
