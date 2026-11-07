// Free's admin api() against both REST address forms: pretty permalinks (/wp-json/cewp/v1/) and plain permalinks (index.php?rest_route=/cewp/v1/).
const fs = require('fs'), vm = require('vm');
const src = fs.readFileSync(require('path').join(__dirname, '../assets/admin.js'), 'utf8');
const start = src.indexOf('async function api('), end = src.indexOf('\n}', start) + 2;
if (start < 0 || end < 2) throw Error('Cannot locate api(); update this adapter explicitly');
const code = src.slice(start, end);
async function urlFor(API, path) {
  const urls = [];
  const sandbox = { API, NONCE: 'N', fetch: async url => { urls.push(String(url)); return { ok: true, text: async () => '{}' }; } };
  vm.createContext(sandbox);
  vm.runInContext(code + '\nthis.api = api;', sandbox);
  await sandbox.api(path);
  return urls[0];
}
(async () => {
  const plain = 'https://example.test/index.php?rest_route=/cewp/v1/', pretty = 'https://example.test/wp-json/cewp/v1/';
  const out = {};
  out['plain permalinks: product search joins its query with &'] = await urlFor(plain, 'products/search?q=tool') === plain + 'products/search&q=tool';
  out['plain permalinks: several parameters keep their separators'] = await urlFor(plain, 'prices?page=2&per_page=25') === plain + 'prices&page=2&per_page=25';
  out['plain permalinks: a path without a query string is unchanged'] = await urlFor(plain, 'roles') === plain + 'roles';
  out['plain permalinks: exactly one question mark'] = (await urlFor(plain, 'products/search?q=a')).split('?').length === 2;
  out['pretty permalinks: query strings are unchanged'] = await urlFor(pretty, 'products/search?q=tool') === pretty + 'products/search?q=tool';
  out['pretty permalinks: a path without a query string is unchanged'] = await urlFor(pretty, 'roles') === pretty + 'roles';
  console.log(JSON.stringify(out));
})().catch(e => { console.error(e); process.exitCode = 1; });
