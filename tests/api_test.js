// Rejoue les parcours importants sur un vrai serveur PHP et une base d'essai vide.
// Ne jamais lancer contre le site reel : le script refuse toute autre adresse que localhost.
// Usage : API_URL=http://127.0.0.1:8080 ADMIN_PASSWORD=... node tests/api_test.js
const API = process.env.API_URL || 'http://127.0.0.1:8080';
const ADMIN = process.env.ADMIN_PASSWORD || '';
if (!/^http:\/\/(127\.0\.0\.1|localhost)(:|\/|$)/.test(API)) { console.error('Refus : ces tests ecrivent en base, ils ne tournent que sur localhost.'); process.exit(2); }

let failed = 0, passed = 0;
const check = (label, condition, detail) => {
  if (condition) { passed++; console.log('  ok  ' + label); return; }
  failed++;
  const message = label + (detail !== undefined ? ' -> ' + JSON.stringify(detail).slice(0, 300) : '');
  // Sur GitHub, "::error::" rend le message lisible sans se connecter.
  console.error((process.env.GITHUB_ACTIONS ? '::error::' : '  ECHEC  ') + message);
};
const call = async (path, body, token) => {
  const r = await fetch(API + path, {
    method: body ? 'POST' : 'GET',
    headers: Object.assign({ 'Content-Type': 'application/json' }, token ? { Authorization: 'Bearer ' + token } : {}),
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await r.text();
  let json; try { json = JSON.parse(text); } catch (e) { json = { success: false, message: 'Reponse non JSON : ' + text.slice(0, 200) }; }
  return { status: r.status, ...json };
};
const admin = (action, extra) => call('/admin?action=' + action, Object.assign({ password: ADMIN }, extra));

const newSeller = async (label, whatsapp) => {
  const email = label + '@essai.test', password = 'motdepasse-essai';
  const reg = await call('/auth?action=register', { email, password, full_name: 'Vendeur ' + label });
  const token = new URL(reg.data.verify_link_dev_only).searchParams.get('verify');
  await call('/auth?action=verify', { token });
  const login = await call('/auth?action=login', { email, password });
  if (whatsapp) await call('/auth?action=profile_update', { whatsapp_number: whatsapp }, login.data.token);
  const shop = await call('/boutiques?action=create', { name: 'Boutique ' + label, city: 'Abidjan', country: "Côte d'Ivoire" }, login.data.token);
  const product = await call('/products?action=create', { boutique_id: shop.data.id, name: 'Produit ' + label, price: 5000, stock_qty: 50, status: 'active', is_physical: 1 }, login.data.token);
  return { email, password, jwt: login.data.token, userId: login.data.user.id, shop: shop.data, productId: product.data.id };
};
const order = async (s, phone, name) => {
  const r = await call('/shop?action=checkout', { slug: s.shop.slug, customer: { name, phone, address: 'Cocody' }, items: [{ product_id: s.productId, qty: 1 }] });
  const found = await call('/shop?action=orders_lookup', { slug: s.shop.slug, phone, name });
  const list = await call('/orders?action=list&boutique_id=' + s.shop.id, null, s.jwt);
  const ref = found.data && found.data[0] && found.data[0].ref;
  return { ok: r.success, ref, id: (list.data || []).find(o => o.ref === ref)?.id };
};
const setStatus = (s, id, status) => call('/orders?action=update_status', { boutique_id: s.shop.id, id, status }, s.jwt);
const review = (s, ref, phone, extra) => call('/shop?action=review_add', Object.assign({ slug: s.shop.slug, product_id: s.productId, ref, phone, rating: 5, comment: 'Essai' }, extra));
const reviews = async s => (await call('/shop?action=reviews&slug=' + s.shop.slug + '&product_id=' + s.productId)).data || [];

(async () => {
  console.log('Installation');
  const install = await call('/install');
  check('la base se cree', install.success, install);
  check('le serveur repond', (await call('/health')).success);

  console.log('Vendeur, boutique, commande');
  const seller = await newSeller('vendeur', '+225 05 05 05 05 05');
  check('compte, boutique et produit crees', seller.jwt && seller.shop.id && seller.productId);
  check('la boutique publique repond', (await call('/shop?action=boutique&slug=' + seller.shop.slug)).success);
  const customerPhone = '+225 07 11 22 33 44';
  const o1 = await order(seller, customerPhone, 'Awa Kone');
  check('un client commande et retrouve sa commande', o1.ok && o1.ref && o1.id, o1);
  check('un autre nom ne donne pas acces', !(await call('/shop?action=orders_lookup', { slug: seller.shop.slug, phone: customerPhone, name: 'Inconnu Total' })).success);

  console.log('Avis clients');
  check('pas d\'avis sans commande', !(await call('/shop?action=review_add', { slug: seller.shop.slug, product_id: seller.productId, rating: 5, customer_name: 'Faux' })).success);
  const notDelivered = await review(seller, o1.ref, customerPhone);
  check('pas d\'avis avant la livraison', !notDelivered.success, notDelivered);
  await setStatus(seller, o1.id, 'delivered');
  const track = await call('/shop?action=track_order', { slug: seller.shop.slug, ref: o1.ref, phone: customerPhone });
  check('le suivi autorise l\'avis', track.success && track.data.can_review === true, track);
  check('le suivi ne revele pas le numero', track.data && !('customer_phone' in track.data) && !('external_ref' in track.data));
  const firstReview = await review(seller, o1.ref, customerPhone);
  check('l\'acheteur peut donner son avis', firstReview.success, firstReview);
  const published = await reviews(seller);
  check('le nom publie est raccourci', published.length === 1 && published[0].customer_name === 'Awa K.', published);
  check('un deuxieme avis est refuse', (await review(seller, o1.ref, customerPhone)).status === 409);
  check('un mauvais telephone est refuse', !(await review(seller, o1.ref, '0700000000')).success);

  const own = await order(seller, '+225 05 05 05 05 05', 'Vendeur Lui Meme');
  await setStatus(seller, own.id, 'delivered');
  check('pas d\'avis avec le numero du vendeur', (await review(seller, own.ref, '+225 05 05 05 05 05')).status === 403);

  const manual = await call('/orders?action=create', { boutique_id: seller.shop.id, customer_name: 'Ami Fidele', customer_phone: '+225 01 99 88 77 66', items: [{ product_id: seller.productId, qty: 1 }] }, seller.jwt);
  check('commande manuelle creee', manual.success, manual);
  await setStatus(seller, manual.data.id, 'delivered');
  check('pas d\'avis sur une commande manuelle', (await review(seller, manual.data.ref, '+225 01 99 88 77 66')).status === 403);

  console.log('Photos');
  const zlib = require('zlib');
  const crc32 = buf => { let c, r = -1; for (const b of buf) { c = (r ^ b) & 255; for (let k = 0; k < 8; k++) c = c & 1 ? (c >>> 1) ^ 0xedb88320 : c >>> 1; r = (r >>> 8) ^ c; } return (r ^ -1) >>> 0; };
  const chunk = (type, data) => { const t = Buffer.concat([Buffer.from(type), data]); const l = Buffer.alloc(4); l.writeUInt32BE(data.length); const c = Buffer.alloc(4); c.writeUInt32BE(crc32(t)); return Buffer.concat([l, t, c]); };
  const png = size => { // motif peu compressible, comme une vraie photo
    const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(size, 0); ihdr.writeUInt32BE(size, 4); ihdr[8] = 8; ihdr[9] = 2;
    const raw = Buffer.alloc((size * 3 + 1) * size); for (let i = 0; i < raw.length; i++) raw[i] = i % (size * 3 + 1) ? (i * 7919 >> 3) & 255 : 0;
    return 'data:image/png;base64,' + Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]).toString('base64');
  };
  const imageReq = async u => { const r = await fetch(u); const b = Buffer.from(await r.arrayBuffer()); let longest = 0; for (let i = 2; i < b.length - 8 && b[i] === 0xff; i += 2 + b.readUInt16BE(i + 2)) if (b[i + 1] === 0xc0 || b[i + 1] === 0xc2) { longest = Math.max(b.readUInt16BE(i + 5), b.readUInt16BE(i + 7)); break; } return { status: r.status, type: r.headers.get('content-type'), cache: r.headers.get('cache-control'), size: b.length, longest }; };
  const withPhoto = await call('/products?action=create', { boutique_id: seller.shop.id, name: 'Produit photo', price: 1000, stock_qty: 5, status: 'active', is_physical: 1, image_url: png(800) }, seller.jwt);
  check('produit avec photo cree', withPhoto.success, withPhoto);
  await call('/products?action=image_add', { boutique_id: seller.shop.id, product_id: withPhoto.data.id, data: png(600) }, seller.jwt);
  await call('/boutiques?action=update', { id: seller.shop.id, logo_url: png(100) }, seller.jwt);
  const listText = await (await fetch(API + '/shop?action=products&slug=' + seller.shop.slug)).text();
  check('la liste ne contient plus aucune photo', !listText.includes('data:image') && listText.length < 20000, listText.length);
  const listed1 = JSON.parse(listText).data.find(p => p.id === withPhoto.data.id);
  check('la liste donne les adresses des photos', listed1.image_url.includes('/image?') && listed1.thumb_url.includes('product_thumb') && listed1.images.length >= 1 && listed1.images[0].data.includes('gallery'), listed1);
  const full = await imageReq(listed1.images[0].data);
  check('la photo complete s\'affiche', full.status === 200 && full.type.startsWith('image/') && full.size > 1000, full);
  check('le navigateur peut la garder 30 jours', /max-age=2592000/.test(full.cache), full);
  const thumb = await imageReq(listed1.thumb_url), thumbAgain = await imageReq(listed1.thumb_url);
  check('la miniature est une image reduite', thumb.status === 200 && thumb.type === 'image/jpeg' && thumb.longest === 400, thumb);
  check('la miniature reste identique', thumbAgain.size === thumb.size && thumbAgain.type === 'image/jpeg', thumbAgain);
  const detail = await call('/shop?action=product&slug=' + seller.shop.slug + '&id=' + withPhoto.data.id);
  check('la fiche produit ne contient pas de photo', detail.success && !JSON.stringify(detail.data).includes('data:image'), detail.message);
  const shopInfo = await call('/shop?action=boutique&slug=' + seller.shop.slug);
  check('le logo passe par une adresse', shopInfo.data.logo_url.includes('boutique_logo') && (await imageReq(shopInfo.data.logo_url)).status === 200, shopInfo.data.logo_url);
  const ownView = await call('/products?action=get&boutique_id=' + seller.shop.id + '&id=' + withPhoto.data.id, null, seller.jwt);
  check('le vendeur garde ses photos dans le tableau de bord', ownView.success && ownView.data.image_url.startsWith('data:image'), ownView.message);
  await call('/products?action=update', { boutique_id: seller.shop.id, id: withPhoto.data.id, image_url: png(700) }, seller.jwt);
  const changed = JSON.parse(await (await fetch(API + '/shop?action=products&slug=' + seller.shop.slug)).text()).data.find(p => p.id === withPhoto.data.id);
  check('une nouvelle photo change d\'adresse', changed.thumb_url !== listed1.thumb_url, changed.thumb_url);
  check('la nouvelle miniature s\'affiche', (await imageReq(changed.thumb_url)).type === 'image/jpeg');
  const dir = await (await fetch(API + '/directory?action=boutiques')).text();
  check('l\'annuaire ne contient pas de photo', !dir.includes('data:image'), dir.length);

  console.log('Plan du site pour Google');
  const sitemap = async () => (await fetch(API + '/sitemap')).text();
  const shopLoc = 'b=' + seller.shop.slug;
  check('une boutique non publique n\'y figure pas', !(await sitemap()).includes(shopLoc));
  await call('/boutiques?action=update', { id: seller.shop.id, public_listed: 1 }, seller.jwt);
  const listed = await sitemap();
  check('une boutique publique y figure', listed.includes(shopLoc), listed.slice(0, 300));
  check('ses produits y figurent, sans erreur XML', listed.includes(shopLoc + '&amp;p=') && !/&p=/.test(listed), listed.slice(0, 300));
  check('la boutique indique son choix', (await call('/shop?action=boutique&slug=' + seller.shop.slug)).data.public_listed === 1);

  console.log('Commandes terminees');
  check('correction refusee sans motif', !(await setStatus(seller, o1.id, 'cancelled')).success);

  console.log('Administration');
  check('mauvais mot de passe refuse', (await call('/admin?action=clients_list', { password: 'faux' })).status === 403);
  check('liste des clients', (await admin('clients_list')).success);
  const alerts = await admin('suspicious_reviews_list');
  check('liste des avis a verifier', alerts.success && Array.isArray(alerts.data.same_phone), alerts);

  console.log('Suppression de compte');
  const victim = await newSeller('asupprimer');
  const other = await newSeller('autre');
  const victimOrder = await order(victim, '+225 07 44 55 66 77', 'Client Test');
  const victimPhoto = await call('/products?action=create', { boutique_id: victim.shop.id, name: 'Photo supprimee', price: 1000, status: 'active', image_url: png(500) }, victim.jwt);
  const victimThumb = JSON.parse(await (await fetch(API + '/shop?action=products&slug=' + victim.shop.slug)).text()).data.find(p => p.id === victimPhoto.data.id).thumb_url;
  check('photo disponible avant la suppression', (await imageReq(victimThumb)).status === 200);
  check('mot de confirmation exige', !(await call('/auth?action=delete_account', { password: victim.password, confirm: 'non' }, victim.jwt)).success);
  check('mot de passe exige', (await call('/auth?action=delete_account', { password: 'faux', confirm: 'SUPPRIMER' }, victim.jwt)).status === 403);
  check('rien ne change apres un refus', (await call('/shop?action=boutique&slug=' + victim.shop.slug)).success);
  const del = await call('/auth?action=delete_account', { password: victim.password, confirm: 'SUPPRIMER' }, victim.jwt);
  check('la demande est acceptee', del.success, del);
  check('la boutique disparait aussitot', !(await call('/shop?action=boutique&slug=' + victim.shop.slug)).success);
  check('la session est coupee', !(await call('/auth?action=me', null, victim.jwt)).success);
  check('la connexion est refusee', (await call('/auth?action=login', { email: victim.email, password: victim.password })).status === 403);
  check('une boutique ne se reactive pas seule', !(await admin('boutique_set_status', { id: victim.shop.id, status: 'active' })).success);
  const pending = await admin('account_deletion_list');
  check('le compte est dans la liste', pending.success && pending.data.some(a => a.id === victim.userId), pending);
  check('restauration', (await admin('account_delete_cancel', { user_id: victim.userId })).success);
  check('la boutique revient', (await call('/shop?action=boutique&slug=' + victim.shop.slug)).success);
  check('la connexion revient', (await call('/auth?action=login', { email: victim.email, password: victim.password })).success);
  check('raison obligatoire pour l\'administration', !(await admin('account_delete_start', { user_id: victim.userId })).success);
  check('suppression par l\'administration', (await admin('account_delete_start', { user_id: victim.userId, reason: 'Compte de test ou inutilise' })).success);
  const withReason = await admin('account_deletion_list');
  check('la raison est gardee', withReason.data.find(a => a.id === victim.userId)?.deletion_reason === 'Compte de test ou inutilise', withReason);
  check('effacement sans le mot refuse', !(await admin('account_delete_now', { user_id: victim.userId, confirm: 'non' })).success);
  const erased = await admin('account_delete_now', { user_id: victim.userId, confirm: 'SUPPRIMER' });
  check('effacement definitif', erased.success, erased);
  check('la photo n\'est plus servie', (await imageReq(victimThumb)).status === 404);
  check('le compte n\'existe plus', (await call('/auth?action=login', { email: victim.email, password: victim.password })).status === 401);
  check('la commande n\'est plus trouvable', !(await call('/shop?action=track_order', { slug: victim.shop.slug, ref: victimOrder.ref, phone: '+225 07 44 55 66 77' })).success);
  check('les autres boutiques restent intactes', (await call('/shop?action=boutique&slug=' + other.shop.slug)).success && (await call('/shop?action=boutique&slug=' + seller.shop.slug)).success);
  check('les avis des autres restent', (await reviews(seller)).length === 1);
  check('les photos des autres restent', (await imageReq(changed.thumb_url)).status === 200);

  console.log('\n' + passed + ' reussis, ' + failed + ' en echec');
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error((process.env.GITHUB_ACTIONS ? '::error::' : '') + 'Le test s\'est arrete : ' + String(e.stack).replace(/\n/g, ' | ').slice(0, 600)); process.exit(1); });
