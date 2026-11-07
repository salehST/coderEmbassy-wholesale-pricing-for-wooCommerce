// Button hover styles in assets/admin.css. `.dpp-btn:hover` sets a light background; a primary button has white text,
// so its hover rule must set its own background or the label turns white on a near-white fill.
const fs = require('fs'), path = require('path');
const css = fs.readFileSync(path.join(__dirname, '../assets/admin.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
const rules = [];
css.replace(/([^{}]+)\{([^{}]*)\}/g, (m, sel, body) => { rules.push({ sel: sel.trim().split(/\s*,\s*/), body }); return m; });
const decl = (body, prop) => { const m = body.match(new RegExp('(?:^|;)\\s*' + prop + '\\s*:\\s*([^;]+)', 'i')); return m ? m[1].trim() : ''; };
const forSelector = test => rules.filter(r => r.sel.some(test));
const out = {};
const light = forSelector(s => /(^|\s)\.dpp-btn-primary:hover$/.test(s) && !/data-theme/.test(s));
const dark = forSelector(s => /data-theme="dark"\]\s+\.dpp-btn-primary:hover$/.test(s));
out['light: primary hover keeps a gradient background'] = light.length > 0 && light.every(r => /gradient/.test(decl(r.body, 'background')));
out['dark: primary hover keeps a gradient background'] = dark.length > 0 && dark.every(r => /gradient/.test(decl(r.body, 'background')));
out['primary hover text stays white'] = light.concat(dark).every(r => /^(#fff|#ffffff)$/i.test(decl(r.body, 'color')));
out['danger hover still has its own background'] = forSelector(s => /\.dpp-btn-danger:hover$/.test(s)).every(r => decl(r.body, 'background') !== '');
console.log(JSON.stringify(out));
if (Object.values(out).some(v => !v)) process.exitCode = 1;
