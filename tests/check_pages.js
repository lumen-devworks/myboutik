// Controle des pages HTML sans navigateur : une erreur de JavaScript bloquerait
// toute la page, un bouton sans fonction ne ferait rien. Sort en erreur si besoin.
// Usage : node tests/check_pages.js
const fs = require('fs'), path = require('path'), vm = require('vm');
const root = path.join(__dirname, '..');
const pages = ['index.html', 'admin.html', 'guide.html', 'dashboard/index.html', 'store/index.html', 'directory/index.html'];
const builtin = new Set(['if', 'alert', 'confirm', 'event', 'window', 'document', 'location', 'history', 'navigator', 'this']);
let errors = 0;
const report = (page, message) => { errors++; console.error('ERREUR ' + page + ' : ' + message); };

for (const page of pages) {
  const html = fs.readFileSync(path.join(root, page), 'utf8');
  const scripts = [...html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)]
    .filter(m => !/src=/.test(m[1]) && !/type=["']application\/(ld\+)?json/.test(m[1]));
  let js = '';
  // Scripts partages du site (ex. install.js) : leurs fonctions comptent aussi pour les boutons.
  for (const m of html.matchAll(/<script\b[^>]*\bsrc=["']([^"':]+\.js)["']/gi)) {
    const file = path.join(root, path.dirname(page), m[1]);
    if (!fs.existsSync(file)) { report(page, 'script introuvable : ' + m[1]); continue; }
    const code = fs.readFileSync(file, 'utf8');
    try { new vm.Script(code, { filename: m[1] }); } catch (e) { report(page, m[1] + ' invalide : ' + e.message); }
    js += code + '\n';
  }
  for (const m of scripts) {
    js += m[2] + '\n';
    try { new vm.Script(m[2], { filename: page }); } catch (e) { report(page, 'JavaScript invalide : ' + e.message); }
  }
  const staticIds = [...html.replace(/<script\b[\s\S]*?<\/script>/gi, '').matchAll(/\sid=["']([^"'$`{]+)["']/g)].map(m => m[1]);
  const duplicates = staticIds.filter((id, i) => staticIds.indexOf(id) !== i);
  if (duplicates.length) report(page, 'identifiants en double : ' + [...new Set(duplicates)].join(', '));

  const handlers = new Set([...html.matchAll(/\bon(?:click|change|input|submit|keyup|keydown|blur|focus)=\\?["']\s*(?:return\s+)?([A-Za-z_$][\w$]*)\(/g)].map(m => m[1]));
  for (const h of handlers) {
    const name = h.replace('$', '\\$');
    if (!builtin.has(h) && !new RegExp('(function\\s+' + name + '\\b|(const|let|var)\\s+' + name + '\\b|window\\.' + name + '\\b)').test(js)) {
      report(page, 'bouton relie a une fonction inexistante : ' + h);
    }
  }
  console.log('OK ' + page);
}
if (errors) { console.error(errors + ' erreur(s)'); process.exit(1); }
