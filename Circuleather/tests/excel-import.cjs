/*
 * Headless Chrome regression checks for CSV/XLSX imports using the actual PHP form markup.
 * Removes PHP expressions for the browser fixture and substitutes known inventory options.
 * Checks field mapping, decimal/date conversion, SKU ambiguity and recalculated order totals.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');
// Usage: node Circuleather/tests/excel-import.cjs <path-to-xlsx.full.min.js>
// Uses Chrome headlessly with isolated temporary profiles and the actual PHP form markup.
const parserPath = process.argv[2];
if (!parserPath) { console.error('Pass the path to xlsx.full.min.js as the first argument.'); process.exit(1); }
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'circuleather-import-check-'));
const parser = fs.readFileSync(parserPath, 'utf8');
const importer = fs.readFileSync('Circuleather/excel-import.js', 'utf8');
const orderScript = fs.readFileSync('Circuleather/order-form.js', 'utf8');
const options = '<option value="">Select</option><option value="2" data-sku="CL-010" data-unit="kg" data-stock="100" data-price="7.5">CL-010 · Leather · Grade A</option><option value="1" data-sku="CL-01" data-unit="kg" data-stock="100" data-price="12.5">CL-01 · Leather · Grade A</option>';
// Keep the real form structure and importer together so selector and event regressions are visible.
function fixture(type) {
  const source = fs.readFileSync(`Circuleather/pages/${type === 'batch' ? 'batch' : 'new-order'}.php`, 'utf8');
  const start = source.indexOf('    <section class="excel-import-panel"');
  const end = source.indexOf('</form>', start) + 7;
  return source.slice(start, end).replace(/<\?= \$batchOptions \?>/g, options).replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, '');
}
const tests = async function() {
  const results = [];
  const assert = (value, label) => { if (!value) throw Error(label); results.push(label); };
  const panel = document.querySelector('[data-excel-import]');
  const fileInput = panel.querySelector('[data-import-file]');
  const status = panel.querySelector('[data-import-status]');
  const apply = panel.querySelector('[data-import-apply]');
  async function load(file) {
    const dt = new DataTransfer(); dt.items.add(file); fileInput.files = dt.files;
    fileInput.dispatchEvent(new Event('change'));
    for (let i = 0; i < 100 && status.textContent.includes('gelezen...'); i++) await new Promise(r => setTimeout(r, 10));
    assert(!panel.querySelector('[data-import-workspace]').hidden, 'Spreadsheet parsed and preview shown');
  }
  const form = document.querySelector('form');
  if (panel.dataset.excelImport === 'batch') {
    const csv = '\uFEFFMateriaalnaam;SKU;Grade;Kleur;Dikte;Verkoopprijs per kg;Inkoopkosten per kg;Eenheid;Beginvoorraad;Minimale voorraad;Herkomst;Leverancier;Inzamedatum\r\nTest leather;B-123;Grade B;Zwart;2 mm;"€ 1.234,50";"8,25";Kilogram;"25,5";0;Test source;Test supplier;02-10-2026\r\nSecond leather;B-124;A;Bruin;1 mm;15;5;kg;10;2;Other;Other supplier;2026-10-03';
    await load(new File([csv], 'batch.csv', {type:'text/csv'}));
    apply.click();
    const v = name => form.elements.namedItem(name).value;
    assert(v('material_name') === 'Test leather' && v('supplier') === 'Test supplier', 'Dutch batch columns filled the form');
    assert(v('grade') === 'B' && v('unit') === 'kg', 'Grade and unit normalized');
    assert(v('sale_price') === '1234.50' && v('cost_price') === '8.25' && v('stock') === '25.5' && v('minimum_stock') === '0', 'Dutch decimals, currency and zero values imported: ' + JSON.stringify(['sale_price','cost_price','stock','minimum_stock'].map(v)));
    assert(v('arrival_date') === '2026-10-02', 'Dutch date imported');
    const rows = panel.querySelector('[data-import-row]'); rows.value = '3'; rows.dispatchEvent(new Event('change')); apply.click();
    assert(v('material_name') === 'Second leather' && v('sku') === 'B-124', 'Selecting a different row updates the batch');
  } else {
    const headers = ['Klantnaam','E-mailadres','Telefoonnummer','Adres','Postcode','Plaats','SKU','Hoeveelheid'];
    const row = ['Test Client','test@example.com','0612345678','Test Street 1','1234 AB','Amsterdam','CL-01',2.5];
    const sheet = XLSX.utils.aoa_to_sheet([headers,row]); const book = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(book,sheet,'Orders');
    await load(new File([XLSX.write(book,{bookType:'xlsx',type:'array'})], 'order.xlsx'));
    apply.click();
    assert(form.elements.namedItem('client_name').value === 'Test Client' && form.elements.namedItem('phone').value === '0612345678', 'XLSX imported client details and preserved phone zero');
    assert(form.querySelector('.order-batch-select').value === '1', 'Exact SKU preferred over earlier partial match');
    assert(form.querySelector('.order-quantity').value === '2.5', 'XLSX imported quantity');
    assert(document.querySelector('#order-total').textContent.includes('31,25'), 'Order total recalculated from imported quantity and price');
    async function checkSku(sku) {
      const csv = headers.join(';') + '\n' + [...row.slice(0,6),sku,'1'].join(';');
      await load(new File([csv], 'order.csv'));
      const select = form.querySelector('.order-batch-select'); select.value = ''; select.dispatchEvent(new Event('change',{bubbles:true})); apply.click();
      return select.value;
    }
    assert(await checkSku('CL-0') === '' && status.textContent.includes('handmatig'), 'Ambiguous SKU requires manual selection');
    assert(await checkSku('UNKNOWN') === '' && status.textContent.includes('handmatig'), 'Unknown SKU requires manual selection');
    assert(await checkSku('CL-010') === '2', 'Distinct exact SKU selects the correct item');
  }
  document.body.innerHTML = '<pre id="result">PASS: ' + results.join('\nPASS: ') + '</pre>';
};
// Use isolated temporary Chrome profiles so these tests do not affect the user's browser session.
for (const type of ['batch','order']) {
  const html = '<!doctype html><html lang="nl"><meta charset="utf-8"><body>' + fixture(type) + '<script>' + parser + '</script><script>' + importer + '</script>' + (type === 'order' ? '<script>' + orderScript + '</script>' : '') + '<script>(' + tests.toString() + ')().catch(e=>{document.body.innerHTML="<pre>FAIL: "+e.message+"</pre>"})</script>';
  const file = path.join(tmp,type+'.html'); fs.writeFileSync(file,html);
  const result = spawnSync(process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',['--headless','--disable-gpu','--no-first-run','--no-default-browser-check','--user-data-dir='+path.join(tmp,'profile-'+type),'--dump-dom','--virtual-time-budget=8000', 'file:///'+file.replace(/\\/g,'/')],{encoding:'utf8',timeout:30000,maxBuffer:4000000});
  const summary = result.stdout?.match(/<pre[^>]*>([\s\S]*?)<\/pre>/)?.[1];
  console.log(type+': '+(summary || 'Browser did not return test results'));
  if (!summary || summary.includes('FAIL:')) { console.error(result.stderr?.slice(-1500)); process.exitCode=1; }
}