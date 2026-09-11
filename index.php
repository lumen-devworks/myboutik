<?php
// ============================================================
// MYBOUTIK - Backend complet PostgreSQL
// Plateforme multi-boutiques : creation de boutique, catalogue,
// vitrine publique, commandes paiement a la livraison (COD),
// livraisons, clients, finances, analytique, marketing.
// Meme style que ROM_MONEY/APPLICATION/index.php (routeur module/action,
// helpers ok()/fail()/db()/q(), JWT maison, aucune valeur par defaut
// codee en dur pour les secrets).
// ============================================================

define('DB_HOST',     getenv('DB_HOST')     ?: 'localhost');
define('DB_NAME',     getenv('DB_NAME')     ?: 'myboutik_db');
define('DB_USER',     getenv('DB_USER')     ?: 'postgres');
define('DB_PASS',     getenv('DB_PASS')     ?: '');
define('DB_PORT',     getenv('DB_PORT')     ?: '5432');
// Neon (et la plupart des Postgres serverless) exigent une connexion
// chiffree et refusent toute tentative en clair. Render Postgres et un
// Postgres local l'acceptent aussi sans probleme, donc ce reglage est sans
// danger dans tous les cas - mais il reste desactive par defaut (chaine
// vide) pour ne rien changer au comportement existant tant qu'il n'est pas
// explicitement demande.
define('DB_SSLMODE',  getenv('DB_SSLMODE')  ?: '');
define('JWT_SECRET',  getenv('JWT_SECRET')  ?: null);
// Cle dediee pour route_install() (creation/mise a jour des tables), separee
// de JWT_SECRET par souci de coherence avec ROM_MONEY. Si INSTALL_KEY n'est
// pas configuree, on retombe sur JWT_SECRET.
define('INSTALL_KEY', getenv('INSTALL_KEY') ?: JWT_SECRET);
// Cle pour declencher les taches planifiees (relances paniers abandonnes,
// alertes de stock) - aucun worker en arriere-plan sur cet hebergement, donc
// un service de cron externe (cron-job.org, etc.) appelle /cron?key=...&
// action=... a intervalle regulier. Repli sur INSTALL_KEY, meme logique.
define('CRON_KEY', getenv('CRON_KEY') ?: INSTALL_KEY);
// Aucune valeur de repli codee en dur pour ce secret : un secret visible
// dans le code source n'est plus un secret. Si JWT_SECRET n'est pas
// configuree sur l'hebergeur, l'app s'arrete plutot que de tourner avec
// un secret compromis/devinable.
if (!JWT_SECRET) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>'Configuration serveur incomplete: JWT_SECRET non defini.'], JSON_UNESCAPED_UNICODE);
    exit;
}
define('JWT_EXPIRY', 43200); // 12h
// IMPORTANT: par defaut (variable absente) on retombe sur 'production'
// (sur, ferme), jamais 'development' (permissif, ouvre le CORS a tout le
// web et affiche les erreurs BDD brutes). 'development' ne doit s'activer
// que par un choix EXPLICITE.
define('APP_ENV',   getenv('APP_ENV')   ?: 'production');
define('APP_DEBUG', APP_ENV === 'development');
// Mot de passe du panneau admin (validation manuelle des demandes
// d'abonnement - voir route_admin()). Optionnel : contrairement a
// JWT_SECRET, son absence ne bloque pas le demarrage de l'app, seules les
// routes /admin repondent "non configure" tant qu'il n'est pas defini.
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: null);

// Envoi d'email transactionnel (Brevo, https://app.brevo.com/settings/keys/api)
// - optionnel : en son absence, send_email() se contente de journaliser
// comme avant (voir plus bas), rien ne casse.
define('BREVO_API_KEY',     getenv('BREVO_API_KEY')     ?: null);
define('BREVO_SENDER_EMAIL',getenv('BREVO_SENDER_EMAIL')?: null);
define('BREVO_SENDER_NAME', getenv('BREVO_SENDER_NAME') ?: 'MYBOUTIK');
// Origine du frontend statique (GitHub Pages) - utilisee pour construire les
// liens de redirection dans les pages d'apercu (voir route_preview()) et
// pour le CTA de l'annuaire. Configurable par env var pour ne pas casser au
// prochain demenagement d'hebergement (deja arrive une fois cette session).
define('FRONTEND_BASE_URL', rtrim(getenv('FRONTEND_BASE_URL') ?: 'https://lumen-devworks.github.io/myboutik', '/'));
// CORS restreint : seules les origines listees ici peuvent appeler l'API
// directement depuis un navigateur. A completer avec le(s) domaine(s) ou
// sont hebergees index.html / dashboard / store une fois deployees.
$ALLOWED_ORIGINS = [
    'https://lumen-devworks.github.io',
    'https://myboutik-ci.netlify.app',
];
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($requestOrigin, $ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: $requestOrigin");
} elseif (APP_ENV === 'development') {
    header("Access-Control-Allow-Origin: *"); // confort en developpement local uniquement
}
header("Vary: Origin");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Lang");
header("Content-Type: application/json; charset=utf-8");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
header("Permissions-Policy: geolocation=(), camera=(), microphone=()");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ============================================================
// TRADUCTION DES MESSAGES SERVEUR (FR par defaut, EN a la demande)
// ============================================================
// Le frontend envoie l'entete X-Lang: en quand l'utilisateur a choisi
// l'anglais (voir Access-Control-Allow-Headers ci-dessus). t() traduit les
// messages STATIQUES via une correspondance exacte francais->anglais.
// Les quelques messages construits dynamiquement (ex: erreurs SQL brutes,
// limites avec un nombre insere en cours de phrase) ne peuvent pas etre
// traduits par une simple table de correspondance puisque le texte final
// varie a chaque appel - ils restent en francais meme en mode EN.
function current_lang() {
    $l = strtolower($_SERVER['HTTP_X_LANG'] ?? '');
    return $l === 'en' ? 'en' : 'fr';
}
function t($msg) {
    if (current_lang() !== 'en') return $msg;
    return EN_DICT[$msg] ?? $msg;
}
const EN_DICT = [
    '5 images maximum par produit' => '5 images maximum per product',
    'Action inconnue' => 'Unknown action',
    'Action non autorisee pour votre role' => 'Action not allowed for your role',
    'Action reservee au proprietaire ou a un administrateur de la boutique' => 'Action reserved for the shop owner or an administrator',
    'Adresse email invalide' => 'Invalid email address',
    'Avis envoye. Merci pour votre retour !' => 'Feedback sent. Thank you for your input!',
    'Marque comme repondu' => 'Marked as replied',
    'Ajoutez au moins un produit' => 'Add at least one product',
    'Aucun lien de feuille configure' => 'No sheet link configured',
    'Aucune commande trouvee avec ces informations' => 'No order found with this information',
    'Aucune information a capturer' => 'No information to capture',
    'Avis introuvable' => 'Review not found',
    'Avis mis a jour' => 'Review updated',
    'Avis supprime' => 'Review deleted',
    'Boutique creee' => 'Shop created',
    'Boutique introuvable' => 'Shop not found',
    'Boutique suspendue' => 'Shop suspended',
    'Boutique reactivee' => 'Shop reactivated',
    'La ville de la boutique est requise' => 'The shop city is required',
    'Le pays de la boutique est requis' => 'The shop country is required',
    'Boutique mise a jour' => 'Shop updated',
    'Categorie creee' => 'Category created',
    'Categorie introuvable' => 'Category not found',
    'Categorie mise a jour' => 'Category updated',
    'Categorie supprimee' => 'Category deleted',
    'Ce code existe deja pour cette boutique' => 'This code already exists for this shop',
    'Ce membre est deja invite sur cette boutique' => 'This member is already invited to this shop',
    'Cette invitation est destinee a une autre adresse email' => 'This invitation is for a different email address',
    'Cette livraison ne vous est pas assignee' => 'This delivery is not assigned to you',
    'Choisissez deux comptes differents' => 'Choose two different accounts',
    'Client introuvable' => 'Customer not found',
    'Code promo cree' => 'Promo code created',
    'Code promo introuvable' => 'Promo code not found',
    'Code promo invalide, expire, ou reserve a un autre client' => 'Promo code invalid, expired, or reserved for another customer',
    'Code promo mis a jour' => 'Promo code updated',
    'Code promo supprime' => 'Promo code deleted',
    'Code supprime' => 'Code deleted',
    'Commande assignee' => 'Order assigned',
    'Commande creee' => 'Order created',
    'Commande enregistree' => 'Order recorded',
    'Commande fournisseur creee' => 'Supplier order created',
    'Commande fournisseur introuvable' => 'Supplier order not found',
    'Commande introuvable' => 'Order not found',
    'Compte cree' => 'Account created',
    'Compte cree. Verifiez votre email pour activer votre compte.' => 'Account created. Check your email to activate your account.',
    'Compte introuvable' => 'Account not found',
    'Compte suspendu ou bloque' => 'Account suspended or blocked',
    'Connecte' => 'Logged in',
    'Contact ajoute' => 'Contact added',
    'Contact introuvable' => 'Contact not found',
    'Contact mis a jour' => 'Contact updated',
    'Contact supprime' => 'Contact deleted',
    'Demande de retrait envoyee. Vous serez paye(e) apres verification.' => 'Withdrawal request sent. You will be paid after verification.',
    'Demande deja en attente de verification' => 'Request already pending verification',
    'Demande enregistree. Envoyez le montant via Orange Money, Wave ou Djomo au +225 07 78 79 83 19 (MYBOUTIK) - votre plan sera active des verification du paiement par l\'equipe MYBOUTIK (generalement sous 24h).' => 'Request recorded. Send the amount via Orange Money, Wave or Djomo to +225 07 78 79 83 19 (MYBOUTIK) - your plan will be activated once the MYBOUTIK team verifies the payment (usually within 24h).',
    'Demande introuvable' => 'Request not found',
    'Demande rejetee' => 'Request rejected',
    'Depense enregistree' => 'Expense recorded',
    'Depense introuvable' => 'Expense not found',
    'Depense publicitaire enregistree' => 'Ad expense recorded',
    'Email ou mot de passe incorrect' => 'Incorrect email or password',
    'Email verifie. Vous pouvez vous connecter.' => 'Email verified. You can now log in.',
    'Fournisseur ajoute' => 'Supplier added',
    'Fournisseur introuvable' => 'Supplier not found',
    'Fournisseur mis a jour' => 'Supplier updated',
    'Fournisseur supprime' => 'Supplier deleted',
    'Frais enregistre' => 'Fee recorded',
    'Image ajoutee' => 'Image added',
    'Image introuvable' => 'Image not found',
    'Image manquante' => 'Missing image',
    'Image principale mise a jour' => 'Main image updated',
    'Image supprimee' => 'Image deleted',
    'Impossible de recuperer la feuille (verifiez que le lien est bien publie en CSV et accessible publiquement)' => 'Unable to retrieve the sheet (check that the link is published as CSV and publicly accessible)',
    'Inscription confirmee' => 'Subscription confirmed',
    'Installation terminee ! Toutes les tables ont ete creees.' => 'Installation complete! All tables have been created.',
    'Introuvable' => 'Not found',
    'Invitation acceptee' => 'Invitation accepted',
    'Invitation envoyee' => 'Invitation sent',
    'Invitation invalide ou deja utilisee' => 'Invalid or already used invitation',
    'L\'abonnement de cette boutique a expire. Le proprietaire doit se reabonner (menu Abonnement) pour continuer a l\'utiliser.' => 'This shop\'s subscription has expired. The owner must renew (Subscription menu) to keep using it.',
    'La feuille est vide (juste l\'entete ou aucune ligne)' => 'The sheet is empty (only the header or no rows)',
    'La valeur doit etre superieure a 0' => 'The value must be greater than 0',
    'Le code est requis' => 'The code is required',
    'Le message ne peut pas etre vide' => 'The message cannot be empty',
    'Le mot de passe doit contenir au moins 6 caracteres' => 'The password must be at least 6 characters',
    'Le nom de la boutique est requis' => 'The shop name is required',
    'Le nom de la categorie est requis' => 'The category name is required',
    'Le nom du compte est requis' => 'The account name is required',
    'Le nom du contact est requis' => 'The contact name is required',
    'Le nom du fournisseur est requis' => 'The supplier name is required',
    'Le nom du livreur est requis' => 'The delivery person\'s name is required',
    'Le nom du produit est requis' => 'The product name is required',
    'Le nom du role est requis' => 'The role name is required',
    'Le nouveau mot de passe doit contenir au moins 6 caracteres' => 'The new password must be at least 6 characters',
    'Le paiement a la livraison n\'est pas active pour cette boutique' => 'Cash on delivery is not enabled for this shop',
    'Le panier est vide' => 'The cart is empty',
    'Libelle et montant requis' => 'Label and amount required',
    'Lien de verification invalide ou deja utilise' => 'Invalid or already used verification link',
    'Livreur ajoute' => 'Delivery person added',
    'Livreur desactive' => 'Delivery person deactivated',
    'Livreur introuvable' => 'Delivery person not found',
    'Livreur mis a jour' => 'Delivery person updated',
    'Marque comme lu' => 'Marked as read',
    'Membre retire' => 'Member removed',
    'Merci pour votre avis !' => 'Thank you for your review!',
    'Message envoye' => 'Message sent',
    'Mis a jour' => 'Updated',
    'Module inconnu' => 'Unknown module',
    'Montant invalide' => 'Invalid amount',
    'Mot de passe incorrect' => 'Incorrect password',
    'Mouvement enregistre' => 'Transaction recorded',
    'Nom et telephone du client requis' => 'Customer name and phone required',
    'Nom et telephone requis' => 'Name and phone required',
    'Non autorise' => 'Not authorized',
    'Note invalide (1 a 5)' => 'Invalid rating (1 to 5)',
    'Numero Mobile Money requis' => 'Mobile Money number required',
    'Un email est requis pour recevoir un produit numerique' => 'An email is required to receive a digital product',
    'Numero de commande et telephone requis' => 'Order number and phone required',
    'Panneau admin non configure (variable ADMIN_PASSWORD absente)' => 'Admin panel not configured (ADMIN_PASSWORD variable missing)',
    'Parametres enregistres' => 'Settings saved',
    'Plan active' => 'Plan activated',
    'Plan invalide' => 'Invalid plan',
    'Produit cree' => 'Product created',
    'Produit introuvable' => 'Product not found',
    'Produit mis a jour' => 'Product updated',
    'Produit supprime' => 'Product deleted',
    'Profil mis a jour' => 'Profile updated',
    'Promotion creee' => 'Promotion created',
    'Promotion introuvable' => 'Promotion not found',
    'Promotion mise a jour' => 'Promotion updated',
    'Promotion supprimee' => 'Promotion deleted',
    'Reglages enregistres' => 'Settings saved',
    'Retrait introuvable' => 'Withdrawal not found',
    'Retrait marque paye' => 'Withdrawal marked as paid',
    'Role cree' => 'Role created',
    'Role invalide' => 'Invalid role',
    'Selectionnez au moins un produit' => 'Select at least one product',
    'Si un compte existe avec cet email, un nouveau lien vient d\'etre envoye.' => 'If an account exists with this email, a new link has just been sent.',
    'Statut invalide' => 'Invalid status',
    'Statut mis a jour' => 'Status updated',
    'Stock ajuste' => 'Stock adjusted',
    'Token invalide ou expire' => 'Invalid or expired token',
    'Token manquant' => 'Missing token',
    'Transfert effectue' => 'Transfer completed',
    'Transfert impossible' => 'Transfer not possible',
    'Trop de requetes depuis cette adresse. Reessayez dans quelques instants.' => 'Too many requests from this address. Please try again shortly.',
    'Type invalide' => 'Invalid type',
    'Un compte existe deja avec cet email' => 'An account already exists with this email',
    'Un pourcentage ne peut pas depasser 100' => 'A percentage cannot exceed 100',
    'Veuillez verifier votre email avant de vous connecter' => 'Please verify your email before logging in',
    'Votre nom est requis' => 'Your name is required',
    'Votre telephone est requis' => 'Your phone number is required',
    'Votre role n\'a pas acces a cette section' => 'Your role does not have access to this section',
    'boutique_id manquant' => 'boutique_id missing',
    'OK' => 'OK',
];

// ============================================================
// HELPERS GENERIQUES
// ============================================================

function ok($data = null, $msg = 'OK', $code = 200) {
    http_response_code($code);
    echo json_encode(['success'=>true,'message'=>t($msg),'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}
function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success'=>false,'message'=>t($msg)], JSON_UNESCAPED_UNICODE);
    exit;
}
function log_and_fail($e, $userMsg, $code = 500) {
    error_log('[MYBOUTIK] '.$userMsg.' :: '.$e->getMessage());
    fail(APP_DEBUG ? $e->getMessage() : $userMsg, $code);
}
function body() {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}
// Lit un parametre depuis le corps JSON en priorite, sinon la query string.
function bg($key, $default=null) {
    $b = body();
    if (array_key_exists($key, $b)) return $b[$key];
    return $_GET[$key] ?? $default;
}
function b64e($d) { return rtrim(strtr(base64_encode($d),'+/','-_'),'='); }
function b64d($d) { return base64_decode(strtr($d,'-_','+/').str_repeat('=',(3+strlen($d))%4)); }
function jwt_make($payload) {
    $h = b64e(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload['iat'] = time(); $payload['exp'] = time()+JWT_EXPIRY;
    $b = b64e(json_encode($payload));
    return "$h.$b.".b64e(hash_hmac('sha256',"$h.$b",JWT_SECRET,true));
}
function jwt_check($token) {
    $p = explode('.',$token);
    if(count($p)!==3) return null;
    if(!hash_equals(b64e(hash_hmac('sha256',"$p[0].$p[1]",JWT_SECRET,true)),$p[2])) return null;
    $pl = json_decode(b64d($p[1]),true);
    return ($pl && $pl['exp']>time()) ? $pl : null;
}
// Authentification du proprietaire de boutique(s) (table users). Seule
// identite de cette app (pas d'admin, pas de role separe pour l'instant).
function owner_auth() {
    $h = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? (function_exists("getallheaders") ? (getallheaders()["Authorization"] ?? "") : "") ?? "";
    if(!str_starts_with($h,'Bearer ')) fail('Token manquant',401);
    $pl = jwt_check(substr($h,7));
    if(!$pl || ($pl['typ']??'')!=='owner') fail('Token invalide ou expire',401);
    $status = q("SELECT status FROM users WHERE id=?",[$pl['sub']])->fetchColumn();
    if($status === false) fail('Compte introuvable',401);
    if($status !== 'active') fail('Compte suspendu ou bloque', 403);
    return $pl;
}
function uid() { return bin2hex(random_bytes(8)); }
function order_ref() { return 'CMD-'.strtoupper(date('ymd')).'-'.strtoupper(substr(uniqid(),-6)); }
// Normalise un numero de telephone pour comparaison : ne garde que les
// chiffres et se limite aux 8 derniers, pour que "07 78 79 83 19",
// "0778798319" et "+225 07 78 79 83 19" soient reconnus comme le meme
// numero peu importe le format saisi a la commande vs au suivi.
function phone_key($phone) {
    $digits = preg_replace('/\D/', '', (string)$phone);
    return substr($digits, -8);
}

function slugify($text) {
    $text = trim((string)$text);
    $translit = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$text);
    if ($translit !== false && $translit !== '') $text = $translit;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/','-',$text);
    $text = trim($text,'-');
    if ($text === '') $text = 'boutique';
    return substr($text,0,60);
}
function unique_boutique_slug($base) {
    $slug = $base; $i = 1;
    while (q("SELECT 1 FROM boutiques WHERE slug=?",[$slug])->fetch()) {
        $i++; $slug = $base.'-'.$i;
    }
    return $slug;
}

function db(): PDO {
    static $pdo = null;
    if(!$pdo) {
        try {
            $dsn = "pgsql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME;
            if (DB_SSLMODE !== '') $dsn .= ";sslmode=".DB_SSLMODE;
            $pdo = new PDO(
                $dsn,
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>true]
            );
        } catch(PDOException $e) {
            error_log('[MYBOUTIK] Erreur serveur :: BDD: '.$e->getMessage());
            fail(APP_DEBUG ? 'BDD: '.$e->getMessage() : 'Erreur serveur', 500);
        }
    }
    return $pdo;
}
function q($sql, $params=[]) {
    $s = db()->prepare($sql);
    $s->execute($params);
    return $s;
}

function rate_limit_check($bucket, $maxRequests, $windowSeconds) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (mt_rand(1, 100) === 1) {
            q("DELETE FROM rate_limit_hits WHERE created_at < NOW() - INTERVAL '1 hour'");
        }
        $row = q("SELECT COUNT(*) c FROM rate_limit_hits
                  WHERE bucket=? AND ip_address=?
                  AND created_at > NOW() - (?::text || ' seconds')::interval",
                  [$bucket, $ip, $windowSeconds])->fetch();
        if ($row && (int)$row['c'] >= $maxRequests) {
            fail('Trop de requetes depuis cette adresse. Reessayez dans quelques instants.', 429);
        }
        q("INSERT INTO rate_limit_hits (bucket, ip_address) VALUES (?,?)", [$bucket, $ip]);
    } catch (PDOException $e) { /* table pas encore prete : on laisse passer */ }
}

// $actorUserId absent (commande passee depuis la vitrine publique, par un
// client) -> actor_email reste NULL, affiche comme "Client" dans Historique.
function log_activity($boutiqueId, $message, $actorUserId = null) {
    try {
        $actorEmail = $actorUserId ? (q("SELECT email FROM users WHERE id=?", [$actorUserId])->fetchColumn() ?: null) : null;
        q("INSERT INTO activity_log (boutique_id, message, actor_email) VALUES (?,?,?)", [$boutiqueId, $message, $actorEmail]);
    } catch (PDOException $e) { /* jamais bloquant */ }
}

function period_clause($period, $col='created_at') {
    switch ($period) {
        case '7d':  return "$col >= NOW() - INTERVAL '7 days'";
        case '90d': return "$col >= NOW() - INTERVAL '90 days'";
        case 'all': return "1=1";
        case '30d':
        default:    return "$col >= NOW() - INTERVAL '30 days'";
    }
}

// Statuts consideres comme "argent encaisse" (validee ou livree), par
// opposition a pending (pas encore traitee)/refusee/annulee.
const ENCAISSE_STATUSES = "('processing','shipped','delivered')";

function require_boutique_owned($boutiqueId, $userId) {
    if (!$boutiqueId) fail('boutique_id manquant', 400);
    $row = q("SELECT * FROM boutiques WHERE id=? AND owner_user_id=?", [$boutiqueId, $userId])->fetch();
    if ($row) { $row['_member_role'] = 'owner'; assert_owner_plan_active($row['owner_user_id']); return $row; }
    // Pas proprietaire : autorise si membre actif de l'equipe de cette
    // boutique (voir route_team()). Meme fonction reutilisee partout plutot
    // que de retoucher chaque module un par un.
    $member = q("SELECT role FROM boutique_members WHERE boutique_id=? AND user_id=? AND status='active'", [$boutiqueId, $userId])->fetch();
    if ($member) {
        $row = q("SELECT * FROM boutiques WHERE id=?", [$boutiqueId])->fetch();
        if ($row) { $row['_member_role'] = $member['role']; assert_owner_plan_active($row['owner_user_id']); return $row; }
    }
    fail('Boutique introuvable', 404);
}
// Bloque l'acces a une boutique si l'abonnement de son PROPRIETAIRE (pas du
// membre d'equipe qui y accede eventuellement) est expire - jamais applique
// aux actions hors boutique (billing, profil...) pour que le proprietaire
// puisse toujours se reabonner lui-meme sans etre bloque de partout.
function assert_owner_plan_active($ownerUserId) {
    $validUntil = q("SELECT plan_valid_until FROM users WHERE id=?", [$ownerUserId])->fetchColumn();
    if ($validUntil !== null && $validUntil !== false && strtotime($validUntil) < time()) {
        fail('L\'abonnement de cette boutique a expire. Le proprietaire doit se reabonner (menu Abonnement) pour continuer a l\'utiliser.', 402);
    }
}
// Reserve les actions sensibles (parametres, gestion d'equipe) au
// proprietaire et aux membres au role 'admin' - les autres roles (manager,
// livreur, closeuse, comptable) gardent acces au reste de la boutique.
function require_boutique_admin($boutiqueId, $userId) {
    $row = require_boutique_owned($boutiqueId, $userId);
    if (!in_array($row['_member_role'], ['owner','admin'], true)) {
        fail('Action reservee au proprietaire ou a un administrateur de la boutique', 403);
    }
    return $row;
}

// Acces par module pour chaque role non-admin (owner/admin passent toujours,
// voir require_module_access). Correspond a la description des roles
// affichee a l'invitation : Manager = tout sauf equipe/parametres/abonnement
// (deja geres a part par require_boutique_admin) ; Livreur ne voit que les
// livraisons (et seulement celles qui lui sont assignees, voir
// deliveries_list()) ; Closeuse = commandes/clients/stock ; Comptable =
// finance/analytique uniquement.
const ROLE_MODULE_ACCESS = [
    'manager'   => ['orders','deliveries','customers','contacts','finance','products','analytics','marketing'],
    'closeuse'  => ['orders','customers','products'],
    'comptable' => ['finance','analytics'],
    'livreur'   => ['deliveries'],
];
// A appeler juste apres require_boutique_owned() dans chaque route_* module
// boutique-scope (voir les routeurs plus bas) - owner/admin ne sont jamais
// restreints, les autres roles doivent figurer dans ROLE_MODULE_ACCESS pour
// ce module precis.
function require_module_access($boutiqueRow, $module) {
    $role = $boutiqueRow['_member_role'] ?? 'owner';
    if (in_array($role, ['owner','admin'], true)) return;
    $allowed = ROLE_MODULE_ACCESS[$role] ?? [];
    if (!in_array($module, $allowed, true)) {
        fail('Votre role n\'a pas acces a cette section', 403);
    }
}
// Interdit une action a des roles precis meme s'ils ont acces au module en
// general (ex: un Livreur peut consulter les livraisons mais ne doit pas
// pouvoir en reassigner une a quelqu'un d'autre).
function deny_roles($boutiqueRow, $roles) {
    if (in_array($boutiqueRow['_member_role'] ?? 'owner', $roles, true)) {
        fail('Action non autorisee pour votre role', 403);
    }
}

// Plans d'abonnement (limite de boutiques par compte). Aucune passerelle de
// paiement automatique branchee : un choix de plan cree une demande
// (subscription_requests) verifiee manuellement par l'operateur de
// MYBOUTIK via /admin (voir route_admin()) avant d'etre activee.
const PLANS = [
    'starter' => ['name'=>'Starter', 'price'=>7000,  'boutique_limit'=>1,  'promo_limit'=>1],
    'pro'     => ['name'=>'Pro',     'price'=>12000, 'boutique_limit'=>3,  'promo_limit'=>5],
    'premium' => ['name'=>'Premium', 'price'=>20000, 'boutique_limit'=>10, 'promo_limit'=>999],
];
// Une commission n'est "disponible" au retrait qu'apres un delai de
// validation (le temps qu'un paiement Mobile Money litigieux soit
// eventuellement annule) - avant cela elle reste "en attente de validation"
// tout en etant deja comptee dans le total gagne. Definies ici (avant le
// routeur plus bas) et non pres de billing_affiliate_info() : un `const`
// top-niveau s'execute a sa position dans le fichier (contrairement a une
// fonction, jamais hoiste) - le declarer apres le routeur le rendait
// "undefined" au moment ou une requete /billing l'utilisait.
const REFERRAL_VALIDATION_DAYS = 7;
const REFERRAL_MIN_PAYOUT = 5000;
const TEAM_ROLES = ['admin','manager','livreur','closeuse','comptable'];

// Point unique d'envoi d'email. Journalise toujours (utile pour deboguer
// meme quand Brevo est branche), puis envoie reellement via l'API Brevo si
// BREVO_API_KEY/BREVO_SENDER_EMAIL sont configures - sinon se comporte comme
// avant (log seul). Ne remonte jamais d'erreur a l'appelant : une commande
// ou une inscription ne doit jamais echouer a cause d'un email qui n'est
// pas parti.
function send_email($to, $subject, $body) {
    error_log('[MYBOUTIK] Email a envoyer -> '.$to.' | Sujet: '.$subject."\n".$body);
    if (!BREVO_API_KEY || !BREVO_SENDER_EMAIL || !$to) return;
    try {
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: '.BREVO_API_KEY],
            CURLOPT_POSTFIELDS => json_encode([
                'sender' => ['email' => BREVO_SENDER_EMAIL, 'name' => BREVO_SENDER_NAME],
                'to' => [['email' => $to]],
                'subject' => $subject,
                'textContent' => $body,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 300) error_log('[MYBOUTIK] Brevo erreur HTTP '.$code.': '.$res);
        curl_close($ch);
    } catch (Throwable $e) {
        error_log('[MYBOUTIK] Brevo exception: '.$e->getMessage());
    }
}

// Paliers de grade (gamification), calcules sur le cumul "vie" des revenus
// encaisses de la boutique. Le cumul ne redescend jamais (meme principe que
// l'ecran "Mon grade" : chaque palier est acquis pour de bon).
const GRADES = [
    ['name'=>'Bronze','min'=>1000000],
    ['name'=>'Argent','min'=>5000000],
    ['name'=>'Or','min'=>10000000],
    ['name'=>'Platine','min'=>20000000],
    ['name'=>'Diamant','min'=>40000000],
    ['name'=>'Emeraude','min'=>80000000],
    ['name'=>'Rubis','min'=>100000000],
    ['name'=>'Maitre','min'=>200000000],
    ['name'=>'Grand Maitre','min'=>300000000],
    ['name'=>'Legende','min'=>500000000],
    ['name'=>'Titan','min'=>1000000000],
];
function compute_grade($totalEncaisse) {
    $current = null; $next = GRADES[0];
    foreach (GRADES as $i => $g) {
        if ($totalEncaisse >= $g['min']) { $current = $g; $next = GRADES[$i+1] ?? null; }
    }
    $prevMin = $current['min'] ?? 0;
    $nextMin = $next['min'] ?? null;
    $progress = $nextMin ? max(0,min(100, (($totalEncaisse-$prevMin)/($nextMin-$prevMin))*100)) : 100;
    return [
        'current_name'  => $current['name'] ?? null,
        'next_name'     => $next['name'] ?? null,
        'total'         => (float)$totalEncaisse,
        'next_min'      => $nextMin,
        'remaining'     => $nextMin ? max(0,$nextMin-$totalEncaisse) : 0,
        'progress_pct'  => round($progress,1),
        'all'           => GRADES,
    ];
}

// ============================================================
// ROUTEUR
// ============================================================
$uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$parts  = explode('/', $uri);
$module = $parts[1] ?? ($parts[0] ?? '');
$action = $_GET['action'] ?? '';

try {
    switch ($module) {
        case 'install':   route_install(); break;
        case 'auth':      route_auth($action); break;
        case 'boutiques': route_boutiques($action); break;
        case 'products':  route_products($action); break;
        case 'shop':      route_shop($action); break;
        case 'orders':    route_orders($action); break;
        case 'deliveries':route_deliveries($action); break;
        case 'customers': route_customers($action); break;
        case 'contacts':  route_contacts($action); break;
        case 'finance':   route_finance($action); break;
        case 'analytics': route_analytics($action); break;
        case 'marketing': route_marketing($action); break;
        case 'team':      route_team($action); break;
        case 'billing':   route_billing($action); break;
        case 'feedback':  route_feedback($action); break;
        case 'admin':     route_admin($action); break;
        case 'directory': route_directory($action); break;
        case 'preview':   route_preview($action); break;
        case 'image':     route_image($action); break;
        case 'integrations': route_integrations($action); break;
        case 'cron':      route_cron($action); break;
        case 'health':    ok(['status'=>'up','time'=>date('c')]); break;
        default: fail('Module inconnu', 404);
    }
} catch (PDOException $e) {
    log_and_fail($e, 'Erreur base de donnees');
} catch (Throwable $e) {
    log_and_fail($e, 'Erreur serveur');
}

// ============================================================
// INSTALL — creation des tables
// ============================================================
function route_install() {
    $key = $_GET['key'] ?? '';
    if (APP_ENV !== 'development' && $key !== INSTALL_KEY) fail('Non autorise', 403);

    $sqls = [
    "CREATE TABLE IF NOT EXISTS users (
        id VARCHAR(36) PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(150),
        email_verified_at TIMESTAMP,
        verification_token VARCHAR(64),
        verification_sent_at TIMESTAMP,
        status VARCHAR(20) DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    // Plan/abonnement de la personne (pas de la boutique - un compte avec
    // plusieurs boutiques n'a qu'un seul plan, voir PLANS plus bas).
    // plan_status='pending' le temps qu'un paiement declare soit verifie
    // manuellement (voir subscription_requests) - aucune passerelle de
    // paiement automatique n'est branchee pour l'instant.
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS plan VARCHAR(20) DEFAULT 'starter'",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS plan_status VARCHAR(20) DEFAULT 'active'",
    // Date jusqu'a laquelle l'acces aux boutiques reste actif - un nouveau
    // compte demarre avec 30 jours gratuits sur Starter ; chaque demande
    // d'abonnement approuvee (n'importe quel plan, Starter inclus a 7000
    // FCFA) prolonge de 30 jours (voir admin_subscription_approve()). Passe
    // cette date, require_boutique_owned() bloque l'acces aux boutiques
    // (mais jamais a la page Abonnement elle-meme, pour pouvoir repayer).
    // DEFAULT applique aussi aux comptes deja existants au moment de cette
    // migration : ils repartent avec 30 jours a partir d'aujourd'hui, pas
    // bloques retroactivement sur leur ancienne date d'inscription.
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS plan_valid_until TIMESTAMP DEFAULT (NOW() + INTERVAL '30 days')",
    // Affiliation : code personnel a partager (?ref=CODE), et la personne
    // qui a recrute ce compte (s'il y en a une). La commission (10% du prix
    // du plan) n'est calculee qu'a l'approbation manuelle d'un abonnement -
    // voir admin_subscription_approve() et referral_commissions ci-dessous.
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS referral_code VARCHAR(20) UNIQUE",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS referred_by VARCHAR(36)",
    "CREATE TABLE IF NOT EXISTS referral_commissions (
        id VARCHAR(36) PRIMARY KEY,
        referrer_user_id VARCHAR(36) NOT NULL,
        referred_user_id VARCHAR(36) NOT NULL,
        subscription_request_id VARCHAR(36),
        plan VARCHAR(20),
        amount DECIMAL(14,2) NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        paid_at TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_refcomm_referrer ON referral_commissions(referrer_user_id)",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS referral_clicks INT DEFAULT 0",
    // Un retrait couvre toujours la totalite du solde "disponible" au
    // moment de la demande (les commissions couvertes passent en
    // status='requested' pour ne pas etre comptees deux fois dans une
    // demande suivante) - verifie manuellement puis marque paye via
    // admin.html, comme les demandes d'abonnement.
    "CREATE TABLE IF NOT EXISTS referral_payouts (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        method VARCHAR(30),
        phone VARCHAR(30),
        status VARCHAR(20) DEFAULT 'requested',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        paid_at TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_refpayouts_user ON referral_payouts(user_id)",
    "CREATE TABLE IF NOT EXISTS subscription_requests (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36) NOT NULL,
        plan VARCHAR(20) NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_subreq_user ON subscription_requests(user_id)",
    "CREATE INDEX IF NOT EXISTS idx_subreq_status ON subscription_requests(status)",
    // Avis sur la plateforme MYBOUTIK elle-meme - soit d'un marchand connecte
    // (user_id renseigne), soit d'un client acheteur anonyme identifie par
    // son telephone/nom saisis a la volee (user_id NULL, customer_* renseignes) -
    // (pas les avis produits/boutique laisses par les clients - voir
    // product_reviews plus bas). L'admin repond par email quand elle est
    // connue (bouton "Repondre" cote panneau admin, voir admin.html), sinon
    // doit recontacter via le telephone fourni - replied_at sert juste a
    // cocher que c'est traite, aucun envoi automatique n'est fait.
    "CREATE TABLE IF NOT EXISTS platform_feedback (
        id VARCHAR(36) PRIMARY KEY,
        user_id VARCHAR(36),
        customer_name VARCHAR(150),
        customer_phone VARCHAR(30),
        customer_email VARCHAR(150),
        rating SMALLINT NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        replied_at TIMESTAMP
    )",
    "ALTER TABLE platform_feedback ALTER COLUMN user_id DROP NOT NULL",
    "ALTER TABLE platform_feedback ADD COLUMN IF NOT EXISTS customer_name VARCHAR(150)",
    "ALTER TABLE platform_feedback ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(30)",
    "ALTER TABLE platform_feedback ADD COLUMN IF NOT EXISTS customer_email VARCHAR(150)",
    "CREATE INDEX IF NOT EXISTS idx_platform_feedback_created ON platform_feedback(created_at DESC)",
    "CREATE TABLE IF NOT EXISTS boutiques (
        id VARCHAR(36) PRIMARY KEY,
        owner_user_id VARCHAR(36) NOT NULL,
        slug VARCHAR(80) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        currency VARCHAR(10) DEFAULT 'XOF',
        cod_enabled SMALLINT DEFAULT 1,
        status VARCHAR(20) DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_boutiques_owner ON boutiques(owner_user_id)",
    // Ajoutee apres le premier lancement - frais de livraison applique
    // automatiquement au panier du client sur la vitrine (voir shop_boutique()
    // et store/index.html). ALTER...IF NOT EXISTS : sans danger a rejouer
    // sur une base qui a deja ete installee.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS default_delivery_fee DECIMAL(14,2) DEFAULT 0",
    // Frais d'expedition par defaut - meme principe que default_delivery_fee
    // mais pour le mode "Expedition" (voir products.shipping_fee et
    // shop_checkout()) : un client choisit l'un ou l'autre au panier, jamais
    // les deux a la fois.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS default_shipping_fee DECIMAL(14,2) DEFAULT 0",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS description TEXT",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS logo_url TEXT",
    // Opt-in explicite (jamais automatique) pour apparaitre dans l'annuaire
    // public MYBOUTIK (voir route_directory()) - certains marchands vendent
    // en clientele privee et ne veulent pas etre exposes publiquement a
    // cote d'autres boutiques.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS public_listed SMALLINT DEFAULT 0",
    // Categorie/ville optionnelles - uniquement utilisees pour filtrer dans
    // l'annuaire public (route_directory()) ; sans objet pour une boutique
    // qui n'y est pas listee.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS category VARCHAR(60)",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS city VARCHAR(80)",
    // Pays obligatoire a la creation (voir boutiques_create()) - l'annuaire
    // etant public et multi-pays, un acheteur doit pouvoir filtrer pour ne
    // pas commander par erreur chez une boutique trop loin pour etre livre.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS country VARCHAR(80)",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS notify_order_email SMALLINT DEFAULT 1",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS notify_email VARCHAR(190)",
    // notify_whatsapp_enabled/number : colonnes conservees pour compatibilite
    // (evite une migration DROP COLUMN) mais plus utilisees par l'appli -
    // le canal WhatsApp a ete retire, seul l'email reste propose.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS notify_whatsapp_enabled SMALLINT DEFAULT 0",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS notify_whatsapp_number VARCHAR(30)",
    // Import de commandes depuis une feuille Google Sheets publiee en CSV
    // (voir route_integrations()). Pas de synchronisation automatique en
    // arriere-plan (aucun worker planifie sur cet hebergement) : le bouton
    // "Importer maintenant" appelle la meme route a la demande.
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS sheet_url TEXT",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS sheet_sync_enabled SMALLINT DEFAULT 0",
    // Alerte de stock bas envoyee au marchand (email/WhatsApp selon ses
    // reglages de notification) via /cron?action=stock_alerts - au plus une
    // fois par jour par boutique (voir last_stock_alert_at).
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS stock_alert_enabled SMALLINT DEFAULT 1",
    "ALTER TABLE boutiques ADD COLUMN IF NOT EXISTS last_stock_alert_at TIMESTAMP",
    // Equipe : une boutique peut etre geree par plusieurs comptes MYBOUTIK
    // distincts (le proprietaire + des membres invites par email). status
    // reste 'pending' (avec un invite_token) tant que la personne invitee
    // n'a pas un compte MYBOUTIK avec cette meme adresse et n'a pas
    // confirme l'invitation - a ce moment user_id est rempli et status
    // passe a 'active'.
    "CREATE TABLE IF NOT EXISTS boutique_members (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        user_id VARCHAR(36),
        email VARCHAR(190) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'manager',
        invite_token VARCHAR(64),
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_boutiquemembers_boutique ON boutique_members(boutique_id)",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_boutiquemembers_boutique_email ON boutique_members(boutique_id, email)",
    // Lie un membre au role 'livreur' a une fiche livreur precise (voir
    // delivery_persons) - lui permet de ne voir que les livraisons qui lui
    // sont assignees plutot que toutes celles de la boutique.
    "ALTER TABLE boutique_members ADD COLUMN IF NOT EXISTS delivery_person_id VARCHAR(36)",
    "CREATE TABLE IF NOT EXISTS products (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(200) NOT NULL,
        description TEXT,
        price DECIMAL(14,2) NOT NULL DEFAULT 0,
        cost_price DECIMAL(14,2),
        stock_qty INT DEFAULT 0,
        image_url TEXT,
        status VARCHAR(20) DEFAULT 'draft',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_products_boutique ON products(boutique_id)",
    // Champs ajoutes apres le premier lancement (fiche produit complete :
    // prix barre, reference interne, suivi de stock, produit
    // physique/service, frais de livraison propre au produit qui prend le
    // pas sur boutiques.default_delivery_fee quand il est renseigne).
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS compare_at_price DECIMAL(14,2)",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS sku VARCHAR(60)",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS barcode VARCHAR(60)",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS slug VARCHAR(100)",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS track_inventory SMALLINT DEFAULT 1",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS allow_backorder SMALLINT DEFAULT 0",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS is_physical SMALLINT DEFAULT 1",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS delivery_fee DECIMAL(14,2)",
    // Frais d'expedition propre au produit - meme principe que delivery_fee
    // (prend le pas sur boutiques.default_shipping_fee quand renseigne),
    // mais pour le mode "Expedition" choisi par le client au panier plutot
    // que "Livraison".
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS shipping_fee DECIMAL(14,2)",
    // Independant de is_physical : un produit peut etre physique ET
    // numerique a la fois (ex: une boite livree qui contient aussi un code
    // d'activation envoye par email), l'un n'exclut pas l'autre. is_physical
    // controle uniquement les frais de livraison (voir shop_checkout()) ;
    // is_digital controle uniquement l'envoi automatique du contenu
    // numerique (voir maybe_send_digital_delivery()).
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS is_digital SMALLINT DEFAULT 0",
    // Produit numerique (is_physical=0) : contenu envoye automatiquement par
    // email au client quand la commande passe au statut "livree". Deux
    // sources cumulables : digital_delivery_content (meme contenu partage
    // pour toutes les ventes - lien, instructions...) et un pool de codes a
    // usage unique dans product_digital_codes (licences, codes cadeaux...),
    // un code different pioche a chaque vente. Les deux peuvent etre
    // renseignes ensemble (ex: meme lien de telechargement pour tous + une
    // cle de licence differente par acheteur) - voir maybe_send_digital_delivery().
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS digital_delivery_content TEXT",
    // digital_delivery_mode : colonne conservee pour compatibilite (evite une
    // migration DROP COLUMN) mais plus utilisee - les deux sources ci-dessus
    // se cumulent desormais au lieu d'etre un choix exclusif.
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS digital_delivery_mode VARCHAR(20) DEFAULT 'link'",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS digital_delivery_sent_at TIMESTAMP",
    "CREATE TABLE IF NOT EXISTS product_digital_codes (
        id VARCHAR(36) PRIMARY KEY,
        product_id VARCHAR(36) NOT NULL,
        code TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'available',
        used_by_order_id VARCHAR(36),
        used_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT NOW()
    )",
    "CREATE INDEX IF NOT EXISTS idx_digital_codes_product ON product_digital_codes(product_id, status)",
    // Definition des options (Taille, Couleur...) ayant servi a generer les
    // variantes - stockee telle quelle (JSON) pour pouvoir rouvrir le
    // generateur avec les memes lignes plutot que de les reconstruire a
    // partir des noms de variantes deja crees.
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS options_json TEXT",
    // Une commande n'est plus jamais bloquee par manque de stock (au
    // marchand de gerer un stock insuffisant une fois la commande vue,
    // pas a l'acheteur de s'en rendre compte au moment de payer) - a la
    // place, le marchand definit lui-meme un seuil pour etre alerte quand
    // le stock d'un produit devient bas.
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS low_stock_threshold INT DEFAULT 5",
    // Produits associes choisis a la main par le marchand pour l'upsell sur
    // la fiche produit de la vitrine (liste d'ids JSON, resolue a l'affichage
    // - voir shop_product()). Pas de suggestion automatique par categorie :
    // aucune notion de categorie n'existe encore dans le modele.
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS related_product_ids TEXT",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_products_boutique_slug ON products(boutique_id, slug) WHERE slug IS NOT NULL AND slug <> ''",
    // Categories de produits, pour organiser/filtrer le catalogue une fois
    // qu'il grandit (menu Produits + filtre sur la vitrine publique).
    "CREATE TABLE IF NOT EXISTS product_categories (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_productcategories_boutique ON product_categories(boutique_id)",
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS category_id VARCHAR(36)",
    "CREATE INDEX IF NOT EXISTS idx_products_category ON products(category_id)",
    // Galerie de photos (plusieurs images par produit). products.image_url
    // reste en place et reflete toujours l'image marquee is_primary=1 ici -
    // tout le code existant qui lit deja image_url (liste produits, vitrine,
    // panier) continue de fonctionner sans modification.
    "CREATE TABLE IF NOT EXISTS product_images (
        id VARCHAR(36) PRIMARY KEY,
        product_id VARCHAR(36) NOT NULL,
        boutique_id VARCHAR(36) NOT NULL,
        data TEXT NOT NULL,
        position INT DEFAULT 0,
        is_primary SMALLINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_productimages_product ON product_images(product_id)",
    "CREATE TABLE IF NOT EXISTS product_variants (
        id VARCHAR(36) PRIMARY KEY,
        product_id VARCHAR(36) NOT NULL,
        name VARCHAR(100) NOT NULL,
        price DECIMAL(14,2),
        stock_qty INT DEFAULT 0
    )",
    "CREATE INDEX IF NOT EXISTS idx_variants_product ON product_variants(product_id)",
    "CREATE TABLE IF NOT EXISTS suppliers (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(30),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_suppliers_boutique ON suppliers(boutique_id)",
    "CREATE TABLE IF NOT EXISTS supplier_orders (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        supplier_id VARCHAR(36) NOT NULL,
        product_id VARCHAR(36),
        qty INT NOT NULL,
        unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_supplierorders_boutique ON supplier_orders(boutique_id)",
    "CREATE TABLE IF NOT EXISTS customers (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(150),
        phone VARCHAR(30),
        email VARCHAR(190),
        address TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_boutique_phone ON customers(boutique_id, phone)",
    "CREATE TABLE IF NOT EXISTS orders (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        customer_id VARCHAR(36),
        ref VARCHAR(30) UNIQUE,
        status VARCHAR(20) DEFAULT 'pending',
        payment_method VARCHAR(20) DEFAULT 'cod',
        subtotal DECIMAL(14,2) DEFAULT 0,
        delivery_fee_charged DECIMAL(14,2) DEFAULT 0,
        total DECIMAL(14,2) DEFAULT 0,
        customer_name VARCHAR(150),
        customer_phone VARCHAR(30),
        customer_address TEXT,
        utm_source VARCHAR(100),
        utm_campaign VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        delivered_at TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_orders_boutique ON orders(boutique_id)",
    "CREATE INDEX IF NOT EXISTS idx_orders_boutique_created ON orders(boutique_id, created_at)",
    // Reference externe (id_commande de la feuille Google Sheets importee) -
    // sert uniquement a ne jamais importer deux fois la meme ligne.
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS external_ref VARCHAR(64)",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_boutique_external_ref ON orders(boutique_id, external_ref) WHERE external_ref IS NOT NULL AND external_ref <> ''",
    // Code promo applique a la commande (reduction deja deduite dans total -
    // subtotal/delivery_fee_charged restent les montants bruts, discount_amount
    // est ce qui a ete retire).
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS promo_code VARCHAR(40)",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(14,2) DEFAULT 0",
    // 'delivery' ou 'shipping' - mode choisi par le client au panier quand la
    // commande contient un produit physique (voir shop_checkout()) ;
    // delivery_fee_charged porte le montant reellement facture dans les
    // deux cas, cette colonne dit juste lequel des deux tarifs s'applique.
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_method VARCHAR(20) DEFAULT 'delivery'",
    "CREATE TABLE IF NOT EXISTS order_items (
        id VARCHAR(36) PRIMARY KEY,
        order_id VARCHAR(36) NOT NULL,
        product_id VARCHAR(36),
        product_name VARCHAR(200),
        variant_id VARCHAR(36),
        unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
        unit_cost DECIMAL(14,2) DEFAULT 0,
        qty INT NOT NULL DEFAULT 1
    )",
    "CREATE INDEX IF NOT EXISTS idx_items_order ON order_items(order_id)",
    "CREATE TABLE IF NOT EXISTS abandoned_carts (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        session_id VARCHAR(64),
        phone VARCHAR(30),
        email VARCHAR(190),
        cart_snapshot TEXT,
        total DECIMAL(14,2) DEFAULT 0,
        converted SMALLINT DEFAULT 0,
        captured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_abandoned_boutique ON abandoned_carts(boutique_id)",
    "CREATE TABLE IF NOT EXISTS abandoned_settings (
        boutique_id VARCHAR(36) PRIMARY KEY,
        capture_enabled SMALLINT DEFAULT 1,
        timeout_minutes INT DEFAULT 15,
        email_alert SMALLINT DEFAULT 0
    )",
    // Relance automatique du CLIENT (par opposition a email_alert ci-dessus,
    // qui prevenait le marchand) - envoyee une seule fois par panier via
    // /cron?action=abandoned_reminders (voir abandoned_carts.reminded_at).
    "ALTER TABLE abandoned_settings ADD COLUMN IF NOT EXISTS customer_reminder_enabled SMALLINT DEFAULT 0",
    "ALTER TABLE abandoned_carts ADD COLUMN IF NOT EXISTS reminded_at TIMESTAMP",
    "CREATE TABLE IF NOT EXISTS delivery_persons (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(30),
        active SMALLINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_delivpersons_boutique ON delivery_persons(boutique_id)",
    "ALTER TABLE delivery_persons ADD COLUMN IF NOT EXISTS email VARCHAR(190)",
    "ALTER TABLE delivery_persons ADD COLUMN IF NOT EXISTS vehicle_type VARCHAR(20)",
    "ALTER TABLE delivery_persons ADD COLUMN IF NOT EXISTS plate_number VARCHAR(30)",
    "ALTER TABLE delivery_persons ADD COLUMN IF NOT EXISTS photo_url TEXT",
    "ALTER TABLE delivery_persons ADD COLUMN IF NOT EXISTS notes TEXT",
    "CREATE TABLE IF NOT EXISTS delivery_assignments (
        id VARCHAR(36) PRIMARY KEY,
        order_id VARCHAR(36) NOT NULL UNIQUE,
        boutique_id VARCHAR(36) NOT NULL,
        delivery_person_id VARCHAR(36),
        status VARCHAR(20) DEFAULT 'to_assign',
        assigned_at TIMESTAMP,
        delivered_at TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_delivassign_boutique ON delivery_assignments(boutique_id)",
    "CREATE TABLE IF NOT EXISTS contact_roles (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(100) NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS contacts (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        role_id VARCHAR(36),
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(30),
        email VARCHAR(190),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_contacts_boutique ON contacts(boutique_id)",
    "CREATE TABLE IF NOT EXISTS accounts (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(100) NOT NULL,
        type VARCHAR(20) DEFAULT 'caisse',
        balance DECIMAL(14,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_accounts_boutique ON accounts(boutique_id)",
    "CREATE TABLE IF NOT EXISTS account_transactions (
        id VARCHAR(36) PRIMARY KEY,
        account_id VARCHAR(36) NOT NULL,
        boutique_id VARCHAR(36) NOT NULL,
        type VARCHAR(10) NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        note TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_accounttx_account ON account_transactions(account_id)",
    "CREATE TABLE IF NOT EXISTS delivery_fees_paid (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        order_id VARCHAR(36),
        amount DECIMAL(14,2) NOT NULL,
        note TEXT,
        account_id VARCHAR(36),
        paid_at DATE DEFAULT CURRENT_DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_deliveryfees_boutique ON delivery_fees_paid(boutique_id)",
    // Livreur a qui ce frais a ete verse - permet de retrouver qui a ete
    // paye pour quelle livraison en cas de litige/malentendu (order_id
    // existait deja mais n'etait jamais rempli par le formulaire du
    // tableau de bord, voir finance_delivery_fee_create()).
    "ALTER TABLE delivery_fees_paid ADD COLUMN IF NOT EXISTS delivery_person_id VARCHAR(36)",
    "CREATE TABLE IF NOT EXISTS expenses (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        label VARCHAR(200) NOT NULL,
        category VARCHAR(60),
        amount DECIMAL(14,2) NOT NULL,
        account_id VARCHAR(36),
        expense_date DATE DEFAULT CURRENT_DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_expenses_boutique ON expenses(boutique_id)",
    "CREATE TABLE IF NOT EXISTS ad_expenses (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        campaign_name VARCHAR(150),
        product_id VARCHAR(36),
        amount DECIMAL(14,2) NOT NULL,
        spend_date DATE DEFAULT CURRENT_DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_adexpenses_boutique ON ad_expenses(boutique_id)",
    // Plusieurs produits peuvent partager une meme depense publicitaire
    // (ex: un carrousel Facebook qui met en avant 3 articles a la fois).
    // ad_expenses.product_id reste pour compat (une seule ligne = un seul
    // produit dans l'ancien systeme) mais n'est plus la source de verite :
    // l'INSERT ci-dessous copie une bonne fois les anciennes lignes dans
    // cette table, qui est ensuite la seule utilisee pour calculer le ROAS
    // par produit (voir finance_ads()).
    "CREATE TABLE IF NOT EXISTS ad_expense_products (
        ad_expense_id VARCHAR(36) NOT NULL,
        product_id VARCHAR(36) NOT NULL,
        PRIMARY KEY (ad_expense_id, product_id)
    )",
    "CREATE INDEX IF NOT EXISTS idx_adexpenseproducts_product ON ad_expense_products(product_id)",
    "INSERT INTO ad_expense_products (ad_expense_id, product_id)
     SELECT id, product_id FROM ad_expenses WHERE product_id IS NOT NULL
     ON CONFLICT DO NOTHING",
    // Codes de reduction utilisables au panier de la vitrine (type
    // percent = %, amount = montant fixe retire du sous-total). L'unicite du
    // code par boutique ignore la casse (index sur UPPER(code)) pour eviter
    // qu'un client tape "PROMO10" alors que le marchand a saisi "promo10".
    "CREATE TABLE IF NOT EXISTS promo_codes (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        code VARCHAR(40) NOT NULL,
        type VARCHAR(10) NOT NULL DEFAULT 'percent',
        value DECIMAL(14,2) NOT NULL,
        max_uses INT,
        used_count INT DEFAULT 0,
        expires_at TIMESTAMP,
        active SMALLINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_promocodes_boutique_code ON promo_codes(boutique_id, UPPER(code))",
    "CREATE INDEX IF NOT EXISTS idx_promocodes_boutique ON promo_codes(boutique_id)",
    // Ciblage par client (optionnel) : un code SANS aucune ligne ici reste
    // ouvert a tous (comportement d'origine). Des qu'au moins un numero est
    // associe, le code devient exclusif a ces numeros - voir
    // find_active_promo(), qui refuse toute autre commande meme si le
    // client a obtenu le code par un tiers.
    "CREATE TABLE IF NOT EXISTS promo_code_customers (
        promo_code_id VARCHAR(36) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        PRIMARY KEY (promo_code_id, phone)
    )",
    "CREATE INDEX IF NOT EXISTS idx_promocodecustomers_promo ON promo_code_customers(promo_code_id)",
    // Avis clients sur une fiche produit - modere par le marchand
    // (status pending/approved/rejected) avant d'apparaitre sur la vitrine,
    // pour eviter le spam/les faux avis visibles immediatement.
    "CREATE TABLE IF NOT EXISTS product_reviews (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        product_id VARCHAR(36) NOT NULL,
        customer_name VARCHAR(150) NOT NULL,
        rating INT NOT NULL,
        comment TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_reviews_product ON product_reviews(product_id)",
    "CREATE INDEX IF NOT EXISTS idx_reviews_boutique ON product_reviews(boutique_id)",
    // Promotion automatiquement visible sur les fiches produits ciblees
    // (contrairement aux codes promo, aucune saisie du client - le prix
    // barre/reduit s'affiche tout seul, voir effective_unit_price()).
    "CREATE TABLE IF NOT EXISTS product_promotions (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        type VARCHAR(10) NOT NULL DEFAULT 'percent',
        value DECIMAL(14,2) NOT NULL,
        starts_at TIMESTAMP,
        expires_at TIMESTAMP,
        active SMALLINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_productpromotions_boutique ON product_promotions(boutique_id)",
    "CREATE TABLE IF NOT EXISTS product_promotion_items (
        promotion_id VARCHAR(36) NOT NULL,
        product_id VARCHAR(36) NOT NULL,
        PRIMARY KEY (promotion_id, product_id)
    )",
    "CREATE INDEX IF NOT EXISTS idx_productpromotionitems_product ON product_promotion_items(product_id)",
    "CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        email VARCHAR(190) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_newsletter_boutique_email ON newsletter_subscribers(boutique_id, email)",
    "CREATE TABLE IF NOT EXISTS contact_messages (
        id VARCHAR(36) PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        name VARCHAR(150),
        email VARCHAR(190),
        message TEXT,
        is_read SMALLINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_contactmsg_boutique ON contact_messages(boutique_id)",
    "CREATE TABLE IF NOT EXISTS visits (
        id SERIAL PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        session_id VARCHAR(64),
        path VARCHAR(255),
        referrer VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX IF NOT EXISTS idx_visits_boutique_created ON visits(boutique_id, created_at)",
    "CREATE TABLE IF NOT EXISTS activity_log (
        id SERIAL PRIMARY KEY,
        boutique_id VARCHAR(36) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    // Auteur de l'action, pour la page Historique - absent (NULL) pour les
    // actions declenchees par un client sur la vitrine publique (personne
    // connectee dans ce cas), rempli pour toute action venant du tableau de
    // bord (voir log_activity()).
    "ALTER TABLE activity_log ADD COLUMN IF NOT EXISTS actor_email VARCHAR(190)",
    "CREATE INDEX IF NOT EXISTS idx_activity_boutique_created ON activity_log(boutique_id, created_at)",
    "CREATE TABLE IF NOT EXISTS rate_limit_hits (
        id SERIAL PRIMARY KEY,
        bucket VARCHAR(50),
        ip_address VARCHAR(64),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    ];

    $created = [];
    foreach ($sqls as $sql) {
        try {
            db()->exec($sql);
            preg_match('/TABLE IF NOT EXISTS (\w+)/', $sql, $m);
            if (!empty($m[1])) $created[] = $m[1];
        } catch (Exception $e) {
            fail('Erreur SQL: '.$e->getMessage(), 500);
        }
    }

    ok(['tables_created'=>$created], 'Installation terminee ! Toutes les tables ont ete creees.');
}

// ============================================================
// AUTH — inscription, verification email, connexion
// ============================================================
function route_auth($action) {
    switch ($action) {
        case 'register': auth_register(); break;
        case 'verify':   auth_verify(); break;
        case 'resend':   auth_resend(); break;
        case 'login':    auth_login(); break;
        case 'me':       auth_me(); break;
        case 'profile_update': auth_profile_update(); break;
        case 'track_ref_click': auth_track_ref_click(); break;
        default: fail('Action inconnue', 404);
    }
}

function generate_unique_referral_code() {
    do { $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 7)); }
    while (q("SELECT 1 FROM users WHERE referral_code=?", [$code])->fetch());
    return $code;
}

// Public (aucune authentification) : compte un clic sur un lien d'affiliation
// avant meme une eventuelle inscription, pour que la page Affiliation montre
// un vrai taux de conversion (clics -> inscriptions -> abonnes payants).
function auth_track_ref_click() {
    rate_limit_check('ref_click', 60, 300);
    $code = trim(bg('code', ''));
    if ($code === '') ok(null);
    q("UPDATE users SET referral_clicks = referral_clicks + 1 WHERE referral_code=?", [$code]);
    ok(null);
}

function auth_register() {
    rate_limit_check('auth_register', 10, 300);
    $b = body();
    $email = strtolower(trim($b['email'] ?? ''));
    $password = (string)($b['password'] ?? '');
    $fullName = trim($b['full_name'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse email invalide');
    if (strlen($password) < 6) fail('Le mot de passe doit contenir au moins 6 caracteres');

    $exists = q("SELECT id FROM users WHERE email=?", [$email])->fetch();
    if ($exists) fail('Un compte existe deja avec cet email', 409);

    $id = uid();
    $token = bin2hex(random_bytes(24));
    $myReferralCode = generate_unique_referral_code();
    $referredBy = null;
    $refCode = trim($b['ref'] ?? '');
    if ($refCode !== '') {
        $referrer = q("SELECT id FROM users WHERE referral_code=?", [$refCode])->fetch();
        if ($referrer) $referredBy = $referrer['id'];
    }
    q("INSERT INTO users (id,email,password_hash,full_name,verification_token,verification_sent_at,referral_code,referred_by)
       VALUES (?,?,?,?,?,NOW(),?,?)",
      [$id, $email, password_hash($password, PASSWORD_DEFAULT), $fullName, $token, $myReferralCode, $referredBy]);

    $verifyLink = auth_verify_link($token, $b['page_url'] ?? '');
    send_email($email, 'Verifiez votre email MYBOUTIK', "Cliquez sur ce lien pour activer votre compte :\n".$verifyLink);

    $data = ['email' => $email];
    if (APP_ENV === 'development') $data['verify_link_dev_only'] = $verifyLink;
    ok($data, 'Compte cree. Verifiez votre email pour activer votre compte.', 201);
}

function auth_verify_link($token, $pageUrl = '') {
    // Construit un lien pointant vers la page d'accueil (index.html), qui
    // lit ?verify=TOKEN au chargement pour appeler auth?action=verify.
    // $pageUrl vient du frontend (window.location.href sans la query string)
    // et inclut le sous-dossier eventuel (ex: /myboutik/ sur GitHub Pages) -
    // l'en-tete Origin seul (repli si $pageUrl est absent/invalide) ne
    // contient jamais ce sous-dossier, ce qui produirait un lien casse des
    // que le site n'est pas a la racine du domaine.
    $pageUrl = trim($pageUrl);
    if ($pageUrl !== '' && preg_match('#^https?://#i', $pageUrl)) {
        $base = rtrim(strtok($pageUrl, '?#'), '/');
    } else {
        $base = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
    }
    // Pas de "/" ajoute ici : $base contient deja le chemin complet envoye
    // par le frontend, fichier inclus (ex: ".../myboutik/index.html"). En
    // ajouter un produisait "index.html/?verify=..." -> 404 sur GitHub Pages
    // (qui ne reecrit pas les URLs comme un serveur PHP classique).
    return $base.'?verify='.$token;
}

function auth_verify() {
    $token = bg('token');
    if (!$token) fail('Token manquant');
    $user = q("SELECT id FROM users WHERE verification_token=?", [$token])->fetch();
    if (!$user) fail('Lien de verification invalide ou deja utilise', 404);
    q("UPDATE users SET email_verified_at=NOW(), verification_token=NULL WHERE id=?", [$user['id']]);
    ok(null, 'Email verifie. Vous pouvez vous connecter.');
}

function auth_resend() {
    rate_limit_check('auth_resend', 5, 300);
    $email = strtolower(trim(bg('email','')));
    $user = q("SELECT id,email_verified_at FROM users WHERE email=?", [$email])->fetch();
    // Reponse volontairement identique que l'email existe ou non, pour ne
    // pas laisser deviner quels emails sont deja inscrits.
    if ($user && !$user['email_verified_at']) {
        $token = bin2hex(random_bytes(24));
        q("UPDATE users SET verification_token=?, verification_sent_at=NOW() WHERE id=?", [$token, $user['id']]);
        send_email($email, 'Verifiez votre email MYBOUTIK', "Cliquez sur ce lien pour activer votre compte :\n".auth_verify_link($token, bg('page_url','')));
    }
    ok(null, 'Si un compte existe avec cet email, un nouveau lien vient d\'etre envoye.');
}

function auth_login() {
    rate_limit_check('auth_login', 20, 300);
    $b = body();
    $email = strtolower(trim($b['email'] ?? ''));
    $password = (string)($b['password'] ?? '');
    $user = q("SELECT * FROM users WHERE email=?", [$email])->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) fail('Email ou mot de passe incorrect', 401);
    if ($user['status'] !== 'active') fail('Compte suspendu ou bloque', 403);
    if (!$user['email_verified_at']) fail('Veuillez verifier votre email avant de vous connecter', 403);

    $token = jwt_make(['sub'=>$user['id'], 'typ'=>'owner']);
    ok(['token'=>$token, 'user'=>[
        'id'=>$user['id'], 'email'=>$user['email'], 'full_name'=>$user['full_name'],
    ]], 'Connecte');
}

function auth_me() {
    $pl = owner_auth();
    $user = q("SELECT id,email,full_name,plan,plan_status,created_at FROM users WHERE id=?", [$pl['sub']])->fetch();
    if (!$user) fail('Compte introuvable', 404);
    ok($user);
}

function auth_profile_update() {
    $pl = owner_auth();
    $b = body();
    $user = q("SELECT * FROM users WHERE id=?", [$pl['sub']])->fetch();
    $fullName = trim($b['full_name'] ?? $user['full_name']);
    q("UPDATE users SET full_name=? WHERE id=?", [$fullName, $user['id']]);
    if (!empty($b['new_password'])) {
        if (strlen($b['new_password']) < 6) fail('Le nouveau mot de passe doit contenir au moins 6 caracteres');
        q("UPDATE users SET password_hash=? WHERE id=?", [password_hash($b['new_password'], PASSWORD_DEFAULT), $user['id']]);
    }
    ok(null, 'Profil mis a jour');
}

// ============================================================
// BOUTIQUES — creation et gestion multi-boutiques d'un proprietaire
// ============================================================
function route_boutiques($action) {
    $pl = owner_auth();
    switch ($action) {
        case 'list':   boutiques_list($pl); break;
        case 'create': boutiques_create($pl); break;
        case 'update': boutiques_update($pl); break;
        case 'get':    boutiques_get($pl); break;
        case 'grade':  boutiques_grade($pl); break;
        case 'export_backup': boutiques_export_backup($pl); break;
        default: fail('Action inconnue', 404);
    }
}

// Sauvegarde complete d'une boutique en JSON - reservee au proprietaire/admin
// (donnees clients/commandes/finances sensibles, voir require_boutique_admin).
// Les photos (base64) sont volontairement exclues pour garder le fichier
// leger et rapide a generer ; tout le reste (catalogue, clients, commandes,
// finances, marketing) y est.
function boutiques_export_backup($pl) {
    $bt = require_boutique_admin($_GET['boutique_id'] ?? '', $pl['sub']);
    $bid = $bt['id'];
    $data = [
        'exported_at' => date('c'),
        'boutique' => q("SELECT id,slug,name,description,currency,cod_enabled,default_delivery_fee,default_shipping_fee,status,created_at FROM boutiques WHERE id=?", [$bid])->fetch(),
        'products' => q("SELECT id,name,description,price,compare_at_price,cost_price,stock_qty,status,sku,barcode,slug,track_inventory,is_physical,is_digital,delivery_fee,shipping_fee,low_stock_threshold,category_id,created_at FROM products WHERE boutique_id=?", [$bid])->fetchAll(),
        'product_variants' => q("SELECT v.* FROM product_variants v JOIN products p ON p.id=v.product_id WHERE p.boutique_id=?", [$bid])->fetchAll(),
        'product_categories' => q("SELECT * FROM product_categories WHERE boutique_id=?", [$bid])->fetchAll(),
        'customers' => q("SELECT * FROM customers WHERE boutique_id=?", [$bid])->fetchAll(),
        'orders' => q("SELECT * FROM orders WHERE boutique_id=?", [$bid])->fetchAll(),
        'order_items' => q("SELECT oi.* FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.boutique_id=?", [$bid])->fetchAll(),
        'contacts' => q("SELECT * FROM contacts WHERE boutique_id=?", [$bid])->fetchAll(),
        'contact_roles' => q("SELECT * FROM contact_roles WHERE boutique_id=?", [$bid])->fetchAll(),
        'delivery_persons' => q("SELECT id,name,phone,email,vehicle_type,plate_number,active,notes,created_at FROM delivery_persons WHERE boutique_id=?", [$bid])->fetchAll(),
        'delivery_assignments' => q("SELECT * FROM delivery_assignments WHERE boutique_id=?", [$bid])->fetchAll(),
        'delivery_fees_paid' => q("SELECT * FROM delivery_fees_paid WHERE boutique_id=?", [$bid])->fetchAll(),
        'accounts' => q("SELECT * FROM accounts WHERE boutique_id=?", [$bid])->fetchAll(),
        'account_transactions' => q("SELECT * FROM account_transactions WHERE boutique_id=?", [$bid])->fetchAll(),
        'expenses' => q("SELECT * FROM expenses WHERE boutique_id=?", [$bid])->fetchAll(),
        'ad_expenses' => q("SELECT * FROM ad_expenses WHERE boutique_id=?", [$bid])->fetchAll(),
        'suppliers' => q("SELECT * FROM suppliers WHERE boutique_id=?", [$bid])->fetchAll(),
        'supplier_orders' => q("SELECT * FROM supplier_orders WHERE boutique_id=?", [$bid])->fetchAll(),
        'promo_codes' => q("SELECT * FROM promo_codes WHERE boutique_id=?", [$bid])->fetchAll(),
        'product_reviews' => q("SELECT * FROM product_reviews WHERE boutique_id=?", [$bid])->fetchAll(),
        'newsletter_subscribers' => q("SELECT * FROM newsletter_subscribers WHERE boutique_id=?", [$bid])->fetchAll(),
        'contact_messages' => q("SELECT * FROM contact_messages WHERE boutique_id=?", [$bid])->fetchAll(),
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="sauvegarde-'.$bt['slug'].'-'.date('Y-m-d').'.json"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function boutiques_list($pl) {
    // Les boutiques dont l'utilisateur est proprietaire ET celles ou il a
    // ete ajoute comme membre d'equipe actif (voir require_boutique_owned).
    $rows = q("SELECT b.*, 'owner' AS my_role FROM boutiques b WHERE b.owner_user_id=?
               UNION
               SELECT b.*, bm.role AS my_role FROM boutiques b
               JOIN boutique_members bm ON bm.boutique_id=b.id
               WHERE bm.user_id=? AND bm.status='active'
               ORDER BY created_at ASC", [$pl['sub'], $pl['sub']])->fetchAll();
    foreach ($rows as &$r) {
        $r['stats'] = boutique_quick_stats($r['id']);
    }
    ok($rows);
}

function boutique_quick_stats($boutiqueId) {
    $cmd = (int)q("SELECT COUNT(*) c FROM orders WHERE boutique_id=?", [$boutiqueId])->fetch()['c'];
    $enAttente = (int)q("SELECT COUNT(*) c FROM orders WHERE boutique_id=? AND status='pending'", [$boutiqueId])->fetch()['c'];
    $ca = (float)q("SELECT COALESCE(SUM(total),0) s FROM orders WHERE boutique_id=? AND status IN ".ENCAISSE_STATUSES, [$boutiqueId])->fetch()['s'];
    return ['commandes'=>$cmd, 'en_attente'=>$enAttente, 'ca_encaisse'=>$ca];
}

function boutiques_create($pl) {
    $b = body();
    $name = trim($b['name'] ?? '');
    $city = trim($b['city'] ?? '');
    $country = trim($b['country'] ?? '');
    if ($name === '') fail('Le nom de la boutique est requis');
    if ($city === '') fail('La ville de la boutique est requise');
    if ($country === '') fail('Le pays de la boutique est requis');
    $user = q("SELECT plan FROM users WHERE id=?", [$pl['sub']])->fetch();
    $limit = PLANS[$user['plan']]['boutique_limit'] ?? 1;
    $count = (int)q("SELECT COUNT(*) c FROM boutiques WHERE owner_user_id=?", [$pl['sub']])->fetch()['c'];
    if ($count >= $limit) {
        fail('Limite de '.$limit.' boutique(s) atteinte pour votre plan '.PLANS[$user['plan']]['name'].'. Passez a un plan superieur pour en creer davantage.', 403);
    }
    $slug = unique_boutique_slug(slugify($name));
    $id = uid();
    q("INSERT INTO boutiques (id,owner_user_id,slug,name,city,country) VALUES (?,?,?,?,?,?)", [$id, $pl['sub'], $slug, $name, $city, $country]);
    // Compte caisse par defaut, pour que le Livre de Compte ne soit pas vide
    // des la creation (l'utilisateur peut le renommer/en ajouter d'autres).
    q("INSERT INTO accounts (id,boutique_id,name,type) VALUES (?,?,?,?)", [uid(), $id, 'Caisse', 'caisse']);
    q("INSERT INTO abandoned_settings (boutique_id) VALUES (?)", [$id]);
    log_activity($id, 'Boutique creee', $pl['sub']);
    $row = q("SELECT * FROM boutiques WHERE id=?", [$id])->fetch();
    ok($row, 'Boutique creee', 201);
}

function boutiques_update($pl) {
    $b = body();
    $id = $b['id'] ?? '';
    $row = require_boutique_admin($id, $pl['sub']);
    $name = trim($b['name'] ?? $row['name']);
    $codEnabled = isset($b['cod_enabled']) ? (int)!!$b['cod_enabled'] : $row['cod_enabled'];
    $currency = trim($b['currency'] ?? $row['currency']);
    $deliveryFee = isset($b['default_delivery_fee']) && $b['default_delivery_fee'] !== ''
        ? max(0, (float)$b['default_delivery_fee']) : $row['default_delivery_fee'];
    $shippingFee = isset($b['default_shipping_fee']) && $b['default_shipping_fee'] !== ''
        ? max(0, (float)$b['default_shipping_fee']) : $row['default_shipping_fee'];
    $description = trim($b['description'] ?? $row['description']);
    $logoUrl = trim($b['logo_url'] ?? $row['logo_url']);
    $notifyOrderEmail = isset($b['notify_order_email']) ? (int)!!$b['notify_order_email'] : $row['notify_order_email'];
    $notifyEmail = trim($b['notify_email'] ?? $row['notify_email']);
    $stockAlertEnabled = isset($b['stock_alert_enabled']) ? (int)!!$b['stock_alert_enabled'] : $row['stock_alert_enabled'];
    $publicListed = isset($b['public_listed']) ? (int)!!$b['public_listed'] : $row['public_listed'];
    $category = trim($b['category'] ?? $row['category']);
    $city = trim($b['city'] ?? $row['city']);
    $country = trim($b['country'] ?? $row['country']);
    q("UPDATE boutiques SET name=?, cod_enabled=?, currency=?, default_delivery_fee=?, default_shipping_fee=?, description=?, logo_url=?,
       notify_order_email=?, notify_email=?, stock_alert_enabled=?, public_listed=?, category=?, city=?, country=? WHERE id=?",
      [$name, $codEnabled, $currency, $deliveryFee, $shippingFee, $description, $logoUrl, $notifyOrderEmail, $notifyEmail,
       $stockAlertEnabled, $publicListed, $category, $city, $country, $id]);
    ok(q("SELECT * FROM boutiques WHERE id=?", [$id])->fetch(), 'Boutique mise a jour');
}

function boutiques_get($pl) {
    $row = require_boutique_owned($_GET['id'] ?? '', $pl['sub']);
    $row['stats'] = boutique_quick_stats($row['id']);
    ok($row);
}

function boutiques_grade($pl) {
    $row = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $total = (float)q("SELECT COALESCE(SUM(total),0) s FROM orders WHERE boutique_id=? AND status IN ".ENCAISSE_STATUSES, [$row['id']])->fetch()['s'];
    ok(compute_grade($total));
}

// ============================================================
// PRODUITS — catalogue, variantes, stock, fournisseurs
// ============================================================
function route_products($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'products');
    switch ($action) {
        case 'list':   products_list($pl); break;
        case 'get':    products_get($pl); break;
        case 'create': products_create($pl); break;
        case 'update': products_update($pl); break;
        case 'delete': products_delete($pl); break;
        case 'stock_adjust': products_stock_adjust($pl); break;
        case 'image_add':        product_image_add($pl); break;
        case 'image_delete':     product_image_delete($pl); break;
        case 'image_set_primary':product_image_set_primary($pl); break;
        case 'suppliers_list':   suppliers_list($pl); break;
        case 'supplier_create':  supplier_create($pl); break;
        case 'supplier_update':  supplier_update($pl); break;
        case 'supplier_delete':  supplier_delete($pl); break;
        case 'supplier_orders_list':  supplier_orders_list($pl); break;
        case 'supplier_order_create': supplier_order_create($pl); break;
        case 'supplier_order_update_status': supplier_order_update_status($pl); break;
        case 'categories_list':  categories_list($pl); break;
        case 'category_create':  category_create($pl); break;
        case 'category_update':  category_update($pl); break;
        case 'category_delete':  category_delete($pl); break;
        case 'digital_codes_list':   digital_codes_list($pl); break;
        case 'digital_codes_add':    digital_codes_add($pl); break;
        case 'digital_codes_delete': digital_codes_delete($pl); break;
        default: fail('Action inconnue', 404);
    }
}

function categories_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id=c.id) AS product_count
          FROM product_categories c WHERE c.boutique_id=? ORDER BY c.name", [$bt['id']])->fetchAll());
}
function category_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom de la categorie est requis');
    $id = uid();
    q("INSERT INTO product_categories (id,boutique_id,name) VALUES (?,?,?)", [$id, $bt['id'], $name]);
    ok(q("SELECT * FROM product_categories WHERE id=?", [$id])->fetch(), 'Categorie creee', 201);
}
function category_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM product_categories WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Categorie introuvable', 404);
    return $row;
}
function category_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $cat = category_owned($b['id'] ?? '', $bt['id']);
    $name = trim($b['name'] ?? $cat['name']);
    if ($name === '') fail('Le nom de la categorie est requis');
    q("UPDATE product_categories SET name=? WHERE id=?", [$name, $cat['id']]);
    ok(q("SELECT * FROM product_categories WHERE id=?", [$cat['id']])->fetch(), 'Categorie mise a jour');
}
function category_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $cat = category_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE products SET category_id=NULL WHERE category_id=?", [$cat['id']]);
    q("DELETE FROM product_categories WHERE id=?", [$cat['id']]);
    ok(null, 'Categorie supprimee');
}

function products_list($pl) {
    $row = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    // image_url contient la photo en base64 (potentiellement plusieurs
    // centaines de Ko) - jamais affichee dans ce tableau, donc jamais
    // transferee ici (elle alourdissait chaque page qui liste les produits,
    // meme celles qui ne montrent qu'un menu deroulant sans photo). Elle
    // reste disponible via products_get() pour la fiche d'un seul produit.
    $rows = q("SELECT p.id,p.boutique_id,p.name,p.description,p.price,p.compare_at_price,p.cost_price,p.stock_qty,p.status,
               p.sku,p.barcode,p.slug,p.track_inventory,p.allow_backorder,p.is_physical,p.is_digital,p.delivery_fee,p.shipping_fee,p.low_stock_threshold,
               p.options_json,p.created_at,p.category_id, c.name AS category_name,
               (p.image_url IS NOT NULL AND p.image_url<>'') AS has_image
               FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
               WHERE p.boutique_id=? ORDER BY p.created_at DESC", [$row['id']])->fetchAll();
    foreach ($rows as &$p) {
        $p['variants'] = q("SELECT * FROM product_variants WHERE product_id=? ORDER BY name", [$p['id']])->fetchAll();
    }
    ok($rows);
}

function product_owned($id, $boutiqueId) {
    $p = q("SELECT * FROM products WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$p) fail('Produit introuvable', 404);
    return $p;
}

function products_get($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($_GET['id'] ?? '', $bt['id']);
    $p['variants'] = q("SELECT * FROM product_variants WHERE product_id=? ORDER BY name", [$p['id']])->fetchAll();
    // Contrairement a products_list() (qui ne renvoie pas les images pour
    // eviter d'alourdir la liste de tous les produits), la fiche d'un seul
    // produit inclut la galerie complete (donnees base64 incluses).
    $p['images'] = q("SELECT * FROM product_images WHERE product_id=? ORDER BY position", [$p['id']])->fetchAll();
    ok($p);
}

function unique_product_slug($boutiqueId, $base, $excludeId = null) {
    $slug = $base; $i = 1;
    while (true) {
        $sql = "SELECT 1 FROM products WHERE boutique_id=? AND slug=?";
        $params = [$boutiqueId, $slug];
        if ($excludeId) { $sql .= " AND id<>?"; $params[] = $excludeId; }
        if (!q($sql, $params)->fetch()) return $slug;
        $i++; $slug = $base.'-'.$i;
    }
}

// Ne garde que des ids qui appartiennent bien a cette boutique (jamais le
// produit lui-meme) - un id invalide/etranger est silencieusement ignore
// plutot que de faire echouer toute la sauvegarde du produit.
function related_product_ids_json($boutiqueId, $ids, $excludeId) {
    $ids = array_values(array_unique(array_filter((array)$ids)));
    if (!$ids) return null;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $valid = q("SELECT id FROM products WHERE boutique_id=? AND id IN ($placeholders)", array_merge([$boutiqueId], $ids))
             ->fetchAll(PDO::FETCH_COLUMN);
    $valid = array_values(array_filter($valid, fn($id) => $id !== $excludeId));
    return $valid ? json_encode(array_slice($valid, 0, 8), JSON_UNESCAPED_UNICODE) : null;
}

function products_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom du produit est requis');
    $id = uid();
    $slugInput = trim($b['slug'] ?? '');
    $slug = unique_product_slug($bt['id'], slugify($slugInput !== '' ? $slugInput : $name));
    $optionsJson = trim($b['options_json'] ?? '');
    if ($optionsJson !== '' && json_decode($optionsJson) === null) $optionsJson = '';
    $relatedJson = related_product_ids_json($bt['id'], $b['related_product_ids'] ?? [], null);
    $categoryId = !empty($b['category_id']) ? category_owned($b['category_id'], $bt['id'])['id'] : null;
    q("INSERT INTO products (id,boutique_id,name,description,price,compare_at_price,cost_price,stock_qty,
       image_url,status,sku,barcode,slug,track_inventory,allow_backorder,is_physical,is_digital,delivery_fee,shipping_fee,digital_delivery_content,options_json,low_stock_threshold,related_product_ids,category_id)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [$id, $bt['id'], $name, trim($b['description'] ?? ''), (float)($b['price'] ?? 0),
       isset($b['compare_at_price']) && $b['compare_at_price'] !== '' ? (float)$b['compare_at_price'] : null,
       isset($b['cost_price']) && $b['cost_price'] !== '' ? (float)$b['cost_price'] : null,
       (int)($b['stock_qty'] ?? 0), trim($b['image_url'] ?? ''), $b['status'] ?? 'draft',
       trim($b['sku'] ?? ''), trim($b['barcode'] ?? ''), $slug,
       (int)!!($b['track_inventory'] ?? 1), (int)!!($b['allow_backorder'] ?? 0), (int)!!($b['is_physical'] ?? 1),
       (int)!!($b['is_digital'] ?? 0),
       isset($b['delivery_fee']) && $b['delivery_fee'] !== '' ? (float)$b['delivery_fee'] : null,
       isset($b['shipping_fee']) && $b['shipping_fee'] !== '' ? (float)$b['shipping_fee'] : null,
       trim($b['digital_delivery_content'] ?? '') !== '' ? trim($b['digital_delivery_content']) : null,
       $optionsJson !== '' ? $optionsJson : null,
       (int)($b['low_stock_threshold'] ?? 5), $relatedJson, $categoryId]);
    foreach (($b['variants'] ?? []) as $v) {
        if (trim($v['name'] ?? '') === '') continue;
        q("INSERT INTO product_variants (id,product_id,name,price,stock_qty) VALUES (?,?,?,?,?)",
          [uid(), $id, trim($v['name']), isset($v['price']) && $v['price']!=='' ? (float)$v['price'] : null, (int)($v['stock_qty'] ?? 0)]);
    }
    // Garde products.image_url et la galerie product_images en synchro des
    // la creation, pour que la photo posee au meme moment que le produit
    // apparaisse bien comme premiere/principale une fois qu'on rouvre la
    // fiche pour en ajouter d'autres.
    $imageUrl = trim($b['image_url'] ?? '');
    if ($imageUrl !== '') {
        q("INSERT INTO product_images (id,product_id,boutique_id,data,position,is_primary) VALUES (?,?,?,?,0,1)",
          [uid(), $id, $bt['id'], $imageUrl]);
    }
    // Photos supplementaires choisies avant meme la creation du produit (le
    // formulaire n'a plus besoin d'un aller-retour enregistrer/rouvrir pour
    // en ajouter plusieurs) - meme plafond de 5 que product_image_add().
    $extraImages = array_slice(array_filter((array)($b['extra_images'] ?? []), fn($d) => trim((string)$d) !== ''), 0, 4);
    foreach ($extraImages as $i => $data) {
        q("INSERT INTO product_images (id,product_id,boutique_id,data,position,is_primary) VALUES (?,?,?,?,?,0)",
          [uid(), $id, $bt['id'], $data, $i + 1]);
    }
    // Codes numeriques colles directement a la creation - meme logique que
    // digital_codes_add(), sans le controle de doublons contre l'existant
    // puisqu'il n'y a encore aucun code pour ce produit tout neuf.
    $codesRaw = (string)($b['codes'] ?? '');
    $codeLines = array_values(array_unique(array_filter(array_map('trim', explode("\n", $codesRaw)))));
    foreach ($codeLines as $code) {
        q("INSERT INTO product_digital_codes (id,product_id,code) VALUES (?,?,?)", [uid(), $id, $code]);
    }
    log_activity($bt['id'], 'Produit ajoute: '.$name, $pl['sub']);
    ok(q("SELECT * FROM products WHERE id=?", [$id])->fetch(), 'Produit cree', 201);
}

function products_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($b['id'] ?? '', $bt['id']);
    $name = trim($b['name'] ?? $p['name']);
    $slugInput = trim($b['slug'] ?? '');
    $slug = $slugInput !== ''
        ? unique_product_slug($bt['id'], slugify($slugInput), $p['id'])
        : ($p['slug'] ?: unique_product_slug($bt['id'], slugify($name), $p['id']));
    $optionsJson = null;
    if (array_key_exists('options_json', $b)) {
        $optionsJson = trim($b['options_json'] ?? '');
        if ($optionsJson !== '' && json_decode($optionsJson) === null) $optionsJson = '';
        $optionsJson = $optionsJson !== '' ? $optionsJson : null;
    } else {
        $optionsJson = $p['options_json'];
    }
    $relatedJson = array_key_exists('related_product_ids', $b)
        ? related_product_ids_json($bt['id'], $b['related_product_ids'], $p['id'])
        : $p['related_product_ids'];
    $categoryId = $p['category_id'];
    if (array_key_exists('category_id', $b)) {
        $categoryId = !empty($b['category_id']) ? category_owned($b['category_id'], $bt['id'])['id'] : null;
    }
    q("UPDATE products SET name=?, description=?, price=?, compare_at_price=?, cost_price=?, stock_qty=?,
       image_url=?, status=?, sku=?, barcode=?, slug=?, track_inventory=?, allow_backorder=?, is_physical=?, is_digital=?, delivery_fee=?, shipping_fee=?, digital_delivery_content=?, options_json=?, low_stock_threshold=?, related_product_ids=?, category_id=?
       WHERE id=?",
      [$name, trim($b['description'] ?? $p['description']), (float)($b['price'] ?? $p['price']),
       isset($b['compare_at_price']) && $b['compare_at_price'] !== '' ? (float)$b['compare_at_price'] : $p['compare_at_price'],
       isset($b['cost_price']) && $b['cost_price'] !== '' ? (float)$b['cost_price'] : $p['cost_price'],
       (int)($b['stock_qty'] ?? $p['stock_qty']), trim($b['image_url'] ?? $p['image_url']),
       $b['status'] ?? $p['status'], trim($b['sku'] ?? $p['sku']), trim($b['barcode'] ?? $p['barcode']), $slug,
       isset($b['track_inventory']) ? (int)!!$b['track_inventory'] : $p['track_inventory'],
       isset($b['allow_backorder']) ? (int)!!$b['allow_backorder'] : $p['allow_backorder'],
       isset($b['is_physical']) ? (int)!!$b['is_physical'] : $p['is_physical'],
       isset($b['is_digital']) ? (int)!!$b['is_digital'] : $p['is_digital'],
       isset($b['delivery_fee']) && $b['delivery_fee'] !== '' ? (float)$b['delivery_fee'] : null,
       isset($b['shipping_fee']) && $b['shipping_fee'] !== '' ? (float)$b['shipping_fee'] : null,
       array_key_exists('digital_delivery_content', $b) ? (trim($b['digital_delivery_content']) !== '' ? trim($b['digital_delivery_content']) : null) : $p['digital_delivery_content'],
       $optionsJson, isset($b['low_stock_threshold']) && $b['low_stock_threshold'] !== '' ? (int)$b['low_stock_threshold'] : $p['low_stock_threshold'],
       $relatedJson, $categoryId, $p['id']]);
    // Remplacement complet des variantes si le champ est fourni (le
    // generateur d'options cote tableau de bord envoie toujours la liste
    // complete a jour, y compris les variantes inchangees).
    if (array_key_exists('variants', $b)) {
        q("DELETE FROM product_variants WHERE product_id=?", [$p['id']]);
        foreach (($b['variants'] ?? []) as $v) {
            if (trim($v['name'] ?? '') === '') continue;
            q("INSERT INTO product_variants (id,product_id,name,price,stock_qty) VALUES (?,?,?,?,?)",
              [uid(), $p['id'], trim($v['name']), isset($v['price']) && $v['price']!=='' ? (float)$v['price'] : null, (int)($v['stock_qty'] ?? 0)]);
        }
    }
    ok(q("SELECT * FROM products WHERE id=?", [$p['id']])->fetch(), 'Produit mis a jour');
}

// Galerie photos : chaque produit peut avoir plusieurs images. La premiere
// ajoutee devient automatiquement l'image principale (is_primary=1) et
// products.image_url est maintenu en synchro avec elle, pour que tout le
// code deja ecrit (liste produits, vitrine, panier) continue de fonctionner
// sans modification.
function product_image_add($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($b['product_id'] ?? '', $bt['id']);
    $data = trim($b['data'] ?? '');
    if ($data === '') fail('Image manquante');
    $count = (int)q("SELECT COUNT(*) c FROM product_images WHERE product_id=?", [$p['id']])->fetch()['c'];
    if ($count >= 5) fail('5 images maximum par produit', 400);
    $isPrimary = $count === 0 ? 1 : 0;
    $id = uid();
    q("INSERT INTO product_images (id,product_id,boutique_id,data,position,is_primary) VALUES (?,?,?,?,?,?)",
      [$id, $p['id'], $bt['id'], $data, $count, $isPrimary]);
    if ($isPrimary) q("UPDATE products SET image_url=? WHERE id=?", [$data, $p['id']]);
    ok(['id'=>$id, 'is_primary'=>$isPrimary], 'Image ajoutee', 201);
}
function product_image_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM product_images WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Image introuvable', 404);
    return $row;
}
function product_image_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $img = product_image_owned($b['id'] ?? '', $bt['id']);
    q("DELETE FROM product_images WHERE id=?", [$img['id']]);
    if ($img['is_primary']) {
        $next = q("SELECT * FROM product_images WHERE product_id=? ORDER BY position LIMIT 1", [$img['product_id']])->fetch();
        if ($next) {
            q("UPDATE product_images SET is_primary=1 WHERE id=?", [$next['id']]);
            q("UPDATE products SET image_url=? WHERE id=?", [$next['data'], $img['product_id']]);
        } else {
            q("UPDATE products SET image_url='' WHERE id=?", [$img['product_id']]);
        }
    }
    ok(null, 'Image supprimee');
}
function product_image_set_primary($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $img = product_image_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE product_images SET is_primary=0 WHERE product_id=?", [$img['product_id']]);
    q("UPDATE product_images SET is_primary=1 WHERE id=?", [$img['id']]);
    q("UPDATE products SET image_url=? WHERE id=?", [$img['data'], $img['product_id']]);
    ok(null, 'Image principale mise a jour');
}

function products_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($b['id'] ?? '', $bt['id']);
    q("DELETE FROM product_variants WHERE product_id=?", [$p['id']]);
    q("DELETE FROM product_images WHERE product_id=?", [$p['id']]);
    q("DELETE FROM product_digital_codes WHERE product_id=?", [$p['id']]);
    q("DELETE FROM product_reviews WHERE product_id=?", [$p['id']]);
    q("DELETE FROM products WHERE id=?", [$p['id']]);
    ok(null, 'Produit supprime');
}

function products_stock_adjust($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($b['id'] ?? '', $bt['id']);
    $delta = (int)($b['delta'] ?? 0);
    q("UPDATE products SET stock_qty = stock_qty + ? WHERE id=?", [$delta, $p['id']]);
    ok(q("SELECT * FROM products WHERE id=?", [$p['id']])->fetch(), 'Stock ajuste');
}

// ============================================================
// CODES NUMERIQUES A USAGE UNIQUE (licences, codes cadeaux...)
// Alternative a digital_delivery_content pour un produit numerique quand
// chaque acheteur doit recevoir un code DIFFERENT plutot que le meme
// contenu partage - voir maybe_send_digital_delivery() qui pioche dedans.
// ============================================================
function digital_codes_list($pl) {
    $b = body() ?: [];
    $boutiqueId = $_GET['boutique_id'] ?? ($b['boutique_id'] ?? '');
    $productId = $_GET['product_id'] ?? ($b['product_id'] ?? '');
    $bt = require_boutique_owned($boutiqueId, $pl['sub']);
    $p = product_owned($productId, $bt['id']);
    $rows = q("SELECT id, code, status, used_at, created_at FROM product_digital_codes
               WHERE product_id=? ORDER BY (status='available') DESC, created_at DESC LIMIT 500", [$p['id']])->fetchAll();
    $available = q("SELECT COUNT(*) c FROM product_digital_codes WHERE product_id=? AND status='available'", [$p['id']])->fetch()['c'];
    ok(['codes' => $rows, 'available_count' => (int)$available]);
}
// Un code par ligne - les lignes vides sont ignorees, les doublons exacts
// deja presents dans le pool de ce produit ne sont pas rajoutes (evite les
// codes en double si le marchand colle deux fois la meme liste par erreur).
function digital_codes_add($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($b['product_id'] ?? '', $bt['id']);
    $raw = (string)($b['codes'] ?? '');
    $lines = array_values(array_unique(array_filter(array_map('trim', explode("\n", $raw)))));
    if (!$lines) fail('Le code est requis');
    $existing = q("SELECT code FROM product_digital_codes WHERE product_id=?", [$p['id']])->fetchAll(PDO::FETCH_COLUMN);
    $existingSet = array_flip($existing);
    $added = 0;
    foreach ($lines as $code) {
        if (isset($existingSet[$code])) continue;
        q("INSERT INTO product_digital_codes (id,product_id,code) VALUES (?,?,?)", [uid(), $p['id'], $code]);
        $existingSet[$code] = true;
        $added++;
    }
    ok(['added' => $added], $added.' code(s) ajoute(s)');
}
function digital_codes_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $p = product_owned($b['product_id'] ?? '', $bt['id']);
    // Un code deja utilise reste rattache a sa commande (support client) -
    // seul un code encore disponible peut etre retire du pool.
    q("DELETE FROM product_digital_codes WHERE id=? AND product_id=? AND status='available'", [$b['id'] ?? '', $p['id']]);
    ok(null, 'Code supprime');
}

function suppliers_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT * FROM suppliers WHERE boutique_id=? ORDER BY name", [$bt['id']])->fetchAll());
}
function supplier_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom du fournisseur est requis');
    $id = uid();
    q("INSERT INTO suppliers (id,boutique_id,name,phone,notes) VALUES (?,?,?,?,?)",
      [$id, $bt['id'], $name, trim($b['phone'] ?? ''), trim($b['notes'] ?? '')]);
    ok(q("SELECT * FROM suppliers WHERE id=?", [$id])->fetch(), 'Fournisseur ajoute', 201);
}
function supplier_owned($id, $boutiqueId) {
    $s = q("SELECT * FROM suppliers WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$s) fail('Fournisseur introuvable', 404);
    return $s;
}
function supplier_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $s = supplier_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE suppliers SET name=?, phone=?, notes=? WHERE id=?",
      [trim($b['name'] ?? $s['name']), trim($b['phone'] ?? $s['phone']), trim($b['notes'] ?? $s['notes']), $s['id']]);
    ok(q("SELECT * FROM suppliers WHERE id=?", [$s['id']])->fetch(), 'Fournisseur mis a jour');
}
function supplier_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $s = supplier_owned($b['id'] ?? '', $bt['id']);
    q("DELETE FROM suppliers WHERE id=?", [$s['id']]);
    ok(null, 'Fournisseur supprime');
}

function supplier_orders_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $rows = q("SELECT so.*, s.name AS supplier_name, p.name AS product_name
               FROM supplier_orders so
               LEFT JOIN suppliers s ON s.id = so.supplier_id
               LEFT JOIN products p ON p.id = so.product_id
               WHERE so.boutique_id=? ORDER BY so.created_at DESC", [$bt['id']])->fetchAll();
    ok($rows);
}
function supplier_order_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    supplier_owned($b['supplier_id'] ?? '', $bt['id']);
    $id = uid();
    q("INSERT INTO supplier_orders (id,boutique_id,supplier_id,product_id,qty,unit_cost,status)
       VALUES (?,?,?,?,?,?,?)",
      [$id, $bt['id'], $b['supplier_id'], $b['product_id'] ?? null, (int)($b['qty'] ?? 1),
       (float)($b['unit_cost'] ?? 0), 'pending']);
    ok(q("SELECT * FROM supplier_orders WHERE id=?", [$id])->fetch(), 'Commande fournisseur creee', 201);
}
function supplier_order_update_status($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $so = q("SELECT * FROM supplier_orders WHERE id=? AND boutique_id=?", [$b['id'] ?? '', $bt['id']])->fetch();
    if (!$so) fail('Commande fournisseur introuvable', 404);
    $status = $b['status'] ?? $so['status'];
    q("UPDATE supplier_orders SET status=? WHERE id=?", [$status, $so['id']]);
    // Une commande fournisseur marquee "received" reapprovisionne le stock.
    if ($status === 'received' && $so['status'] !== 'received' && $so['product_id']) {
        q("UPDATE products SET stock_qty = stock_qty + ? WHERE id=?", [$so['qty'], $so['product_id']]);
    }
    ok(q("SELECT * FROM supplier_orders WHERE id=?", [$so['id']])->fetch(), 'Statut mis a jour');
}

// ============================================================
// VITRINE PUBLIQUE (module "shop") — aucune authentification, utilisee par
// store/index.html : catalogue, fiche produit, commande COD, capture des
// paniers abandonnes, newsletter, formulaire de contact.
// ============================================================
function route_shop($action) {
    switch ($action) {
        case 'boutique':         shop_boutique(); break;
        case 'products':         shop_products(); break;
        case 'product':          shop_product(); break;
        case 'checkout':         shop_checkout(); break;
        case 'track_visit':      shop_track_visit(); break;
        case 'track_abandoned':  shop_track_abandoned(); break;
        case 'newsletter':       shop_newsletter(); break;
        case 'contact_message':  shop_contact_message(); break;
        case 'validate_promo':   shop_validate_promo(); break;
        case 'active_promos':    shop_active_promos(); break;
        case 'categories':       shop_categories(); break;
        case 'promo_for_phone':  shop_promo_for_phone(); break;
        case 'track_order':      shop_track_order(); break;
        case 'orders_for_phone': shop_orders_for_phone(); break;
        case 'reviews':          shop_reviews(); break;
        case 'review_add':       shop_review_add(); break;
        default: fail('Action inconnue', 404);
    }
}

// ============================================================
// ANNUAIRE PUBLIC (directory/index.html) - navigation/recherche parmi les
// boutiques qui ont explicitement choisi d'y apparaitre (public_listed=1,
// jamais automatique - voir boutiques_update()). Aucune authentification :
// c'est une vitrine de decouverte, comme store/index.html mais a l'echelle
// de toute la plateforme plutot que d'une seule boutique.
// ============================================================
function route_directory($action) {
    switch ($action) {
        case 'boutiques': directory_boutiques(); break;
        case 'products':  directory_products(); break;
        case 'filters':   directory_filters(); break;
        default: fail('Action inconnue', 404);
    }
}
function directory_boutiques() {
    rate_limit_check('directory_boutiques', 60, 300);
    $q = trim($_GET['q'] ?? '');
    $category = trim($_GET['category'] ?? '');
    $city = trim($_GET['city'] ?? '');
    $country = trim($_GET['country'] ?? '');
    // Note moyenne calculee a la volee a partir des avis produits deja
    // approuves par le marchand (product_reviews.status='approved') -
    // aucune nouvelle table, juste une agregation par boutique.
    $sql = "SELECT b.slug, b.name, b.description, b.logo_url, b.currency, b.category, b.city, b.country,
              (SELECT ROUND(AVG(r.rating)::numeric, 1) FROM product_reviews r WHERE r.boutique_id=b.id AND r.status='approved') AS avg_rating,
              (SELECT COUNT(*) FROM product_reviews r WHERE r.boutique_id=b.id AND r.status='approved') AS review_count
            FROM boutiques b WHERE b.status='active' AND b.public_listed=1";
    $params = [];
    if ($q !== '') { $sql .= " AND b.name ILIKE ?"; $params[] = '%'.$q.'%'; }
    if ($category !== '') { $sql .= " AND b.category=?"; $params[] = $category; }
    if ($city !== '') { $sql .= " AND b.city ILIKE ?"; $params[] = '%'.$city.'%'; }
    if ($country !== '') { $sql .= " AND b.country=?"; $params[] = $country; }
    $sql .= " ORDER BY b.created_at DESC LIMIT 60";
    ok(q($sql, $params)->fetchAll());
}
// Categories et villes distinctes parmi les boutiques listees - alimente
// les listes deroulantes de filtre de l'annuaire (evite de proposer un
// filtre sur une valeur qu'aucune boutique n'utilise).
function directory_filters() {
    rate_limit_check('directory_filters', 60, 300);
    $categories = q("SELECT DISTINCT category FROM boutiques WHERE status='active' AND public_listed=1 AND category IS NOT NULL AND category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    $cities = q("SELECT DISTINCT city FROM boutiques WHERE status='active' AND public_listed=1 AND city IS NOT NULL AND city<>'' ORDER BY city")->fetchAll(PDO::FETCH_COLUMN);
    $countries = q("SELECT DISTINCT country FROM boutiques WHERE status='active' AND public_listed=1 AND country IS NOT NULL AND country<>'' ORDER BY country")->fetchAll(PDO::FETCH_COLUMN);
    ok(['categories' => $categories, 'cities' => $cities, 'countries' => $countries]);
}
// Recherche d'articles a travers TOUTES les boutiques listees publiquement -
// le nom de la boutique est renvoye avec chaque article pour que le client
// sache chez qui il achete avant de cliquer (le lien pointe directement vers
// la fiche produit dans cette boutique).
function directory_products() {
    rate_limit_check('directory_products', 60, 300);
    $q = trim($_GET['q'] ?? '');
    if ($q === '') ok([]);
    $rows = q("SELECT p.id AS product_id, p.name, p.price, p.slug AS product_slug,
                      b.slug AS boutique_slug, b.name AS boutique_name, b.currency
               FROM products p JOIN boutiques b ON b.id = p.boutique_id
               WHERE b.status='active' AND b.public_listed=1 AND p.status='active' AND p.name ILIKE ?
               ORDER BY p.created_at DESC LIMIT 60", ['%'.$q.'%'])->fetchAll();
    ok($rows);
}

// ============================================================
// IMAGES SERVIES DEPUIS UNE URL STABLE (image?action=...) - les photos sont
// stockees en base64 directement en base (jamais comme fichiers a part),
// ce qui les rend inutilisables telles quelles pour un og:image : les
// robots WhatsApp/Facebook qui generisent les apercus de lien exigent une
// vraie URL http(s) a recuperer, pas une data: URI. Cette route decode et
// resert l'image existante sous une URL classique, sans dupliquer le
// stockage.
// ============================================================
function route_image($action) {
    switch ($action) {
        case 'boutique_logo': image_boutique_logo(); break;
        case 'product_photo': image_product_photo(); break;
        default: fail('Action inconnue', 404);
    }
}
function output_data_uri_image($dataUri) {
    if (!$dataUri || !preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#', $dataUri, $m)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Image introuvable';
        exit;
    }
    header('Content-Type: '.$m[1]);
    header('Cache-Control: public, max-age=3600');
    echo base64_decode($m[2]);
    exit;
}
function image_boutique_logo() {
    $slug = trim($_GET['slug'] ?? '');
    $bt = q("SELECT logo_url FROM boutiques WHERE slug=? AND status='active'", [$slug])->fetch();
    output_data_uri_image($bt['logo_url'] ?? null);
}
function image_product_photo() {
    $slug = trim($_GET['slug'] ?? '');
    $productSlug = trim($_GET['p'] ?? '');
    $row = q("SELECT p.image_url FROM products p JOIN boutiques b ON b.id=p.boutique_id
              WHERE b.slug=? AND p.slug=? AND b.status='active' AND p.status='active'", [$slug, $productSlug])->fetch();
    output_data_uri_image($row['image_url'] ?? null);
}

// ============================================================
// PAGES D'APERCU POUR LE PARTAGE (preview?action=...) - le frontend est un
// site 100% statique dont le contenu se charge en JavaScript ; les robots
// qui generent les apercus de lien WhatsApp/Facebook ne l'executent
// generalement pas et ne verraient donc que le HTML generique, identique
// pour toutes les boutiques. Cette route, servie par le backend PHP, genere
// a la volee une page HTML minimale avec les vraies balises Open Graph
// (nom, description, image) puis redirige immediatement le visiteur humain
// vers la vraie page interactive - le robot lit les balises sans suivre la
// redirection, l'humain ne voit quasiment rien (redirection instantanee).
// C'est cette URL de "preview", pas le lien direct, qui doit etre partagee.
// ============================================================
function route_preview($action) {
    switch ($action) {
        case 'shop':    preview_shop(); break;
        case 'product': preview_product(); break;
        default: fail('Action inconnue', 404);
    }
}
function preview_not_found() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Introuvable';
    exit;
}
function preview_html($title, $description, $imageUrl, $redirectUrl) {
    header('Content-Type: text/html; charset=utf-8');
    $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    $imageAttr = $imageUrl ? '<meta property="og:image" content="'.htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8').'">' : '';
    $redirectUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        .'<title>'.$title.'</title>'
        .'<meta property="og:title" content="'.$title.'">'
        .'<meta property="og:description" content="'.$description.'">'
        .$imageAttr
        .'<meta property="og:type" content="website">'
        .'<meta name="twitter:card" content="summary_large_image">'
        .'<meta http-equiv="refresh" content="0; url='.$redirectUrl.'">'
        .'<style>body{font-family:Arial,sans-serif;padding:40px;text-align:center;color:#0f172a}a{color:#ea580c;font-weight:700}</style>'
        .'</head><body>'
        .'<p>Redirection vers '.$title.'…</p>'
        .'<p><a href="'.$redirectUrl.'">Cliquez ici si la redirection ne fonctionne pas</a></p>'
        .'<script>window.location.replace('.json_encode($redirectUrl).');</script>'
        .'</body></html>';
    exit;
}
function preview_self_base() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme.'://'.$_SERVER['HTTP_HOST'];
}
function preview_shop() {
    $slug = trim($_GET['slug'] ?? '');
    $bt = q("SELECT name, description, logo_url FROM boutiques WHERE slug=? AND status='active'", [$slug])->fetch();
    if (!$bt) preview_not_found();
    $title = $bt['name'].' — MYBOUTIK';
    $desc = trim($bt['description'] ?? '') !== '' ? $bt['description'] : 'Decouvrez '.$bt['name'].' sur MYBOUTIK - paiement a la livraison.';
    $image = $bt['logo_url'] ? preview_self_base().'/image?action=boutique_logo&slug='.urlencode($slug) : null;
    $redirect = FRONTEND_BASE_URL.'/store/index.html?b='.urlencode($slug);
    preview_html($title, $desc, $image, $redirect);
}
function preview_product() {
    $slug = trim($_GET['slug'] ?? '');
    $productSlug = trim($_GET['p'] ?? '');
    $row = q("SELECT p.name, p.description, p.price, p.image_url, b.name AS boutique_name, b.currency
              FROM products p JOIN boutiques b ON b.id=p.boutique_id
              WHERE b.slug=? AND p.slug=? AND b.status='active' AND p.status='active'", [$slug, $productSlug])->fetch();
    if (!$row) preview_not_found();
    $title = $row['name'].' — '.$row['boutique_name'];
    $price = number_format((float)$row['price'], 0, ',', ' ').' '.($row['currency'] ?: 'XOF');
    $desc = trim($row['description'] ?? '') !== '' ? $row['description'] : ($row['name'].' a '.$price.' chez '.$row['boutique_name'].' sur MYBOUTIK.');
    $image = $row['image_url'] ? preview_self_base().'/image?action=product_photo&slug='.urlencode($slug).'&p='.urlencode($productSlug) : null;
    $redirect = FRONTEND_BASE_URL.'/store/index.html?b='.urlencode($slug).'&p='.urlencode($productSlug);
    preview_html($title, $desc, $image, $redirect);
}

function public_boutique_by_slug($slug) {
    $row = q("SELECT id,slug,name,description,logo_url,currency,cod_enabled,default_delivery_fee,default_shipping_fee,status,city,country FROM boutiques WHERE slug=?", [$slug])->fetch();
    if (!$row || $row['status'] !== 'active') fail('Boutique introuvable', 404);
    return $row;
}

function shop_boutique() {
    ok(public_boutique_by_slug($_GET['slug'] ?? ''));
}

// Promotion "produit" active (independante des codes promo - visible
// directement sur le prix affiche, rien a saisir cote client). Au plus une
// promotion appliquee par produit ; en cas de chevauchement, la plus
// recemment creee gagne plutot que de cumuler les reductions.
function active_product_promotion($productId) {
    return q("SELECT pp.type, pp.value FROM product_promotions pp
              JOIN product_promotion_items ppi ON ppi.promotion_id = pp.id
              WHERE ppi.product_id=? AND pp.active=1
              AND (pp.starts_at IS NULL OR pp.starts_at <= NOW())
              AND (pp.expires_at IS NULL OR pp.expires_at > NOW())
              ORDER BY pp.created_at DESC LIMIT 1", [$productId])->fetch();
}
function effective_unit_price($productId, $basePrice) {
    $promo = active_product_promotion($productId);
    if (!$promo) return (float)$basePrice;
    $discount = $promo['type'] === 'amount' ? (float)$promo['value'] : round($basePrice * ((float)$promo['value'] / 100), 2);
    return max(0, (float)$basePrice - min($discount, (float)$basePrice));
}
// Applique la promotion (si active) directement sur le tableau produit tel
// que renvoye a la vitrine : compare_at_price devient le prix catalogue
// d'origine, price devient le prix reduit - reutilise donc l'affichage
// "prix barre" deja code cote store/index.html sans rien y changer la-bas.
// Ne touche jamais products.price en base - seulement ce qui est renvoye
// ici, le catalogue du marchand garde son vrai prix.
function apply_active_promotion(&$p) {
    $effective = effective_unit_price($p['id'], (float)$p['price']);
    if ($effective < (float)$p['price']) {
        $p['compare_at_price'] = $p['price'];
        $p['price'] = $effective;
    }
}

// Categories visibles publiquement (uniquement celles ayant au moins un
// produit actif, pour ne pas afficher un filtre menant a une liste vide).
function shop_categories() {
    $bt = public_boutique_by_slug($_GET['slug'] ?? '');
    ok(q("SELECT DISTINCT c.id, c.name FROM product_categories c
          JOIN products p ON p.category_id = c.id
          WHERE c.boutique_id=? AND p.status='active' ORDER BY c.name", [$bt['id']])->fetchAll());
}

function shop_products() {
    $bt = public_boutique_by_slug($_GET['slug'] ?? '');
    $sql = "SELECT id,name,description,price,compare_at_price,stock_qty,image_url,slug,track_inventory,allow_backorder,is_physical,is_digital,delivery_fee,shipping_fee,options_json,category_id
            FROM products WHERE boutique_id=? AND status='active'";
    $params = [$bt['id']];
    if (!empty($_GET['category_id'])) { $sql .= " AND category_id=?"; $params[] = $_GET['category_id']; }
    $sql .= " ORDER BY created_at DESC";
    $rows = q($sql, $params)->fetchAll();
    foreach ($rows as &$p) {
        $p['variants'] = q("SELECT id,name,price,stock_qty FROM product_variants WHERE product_id=? ORDER BY name", [$p['id']])->fetchAll();
        $p['images'] = q("SELECT id,data FROM product_images WHERE product_id=? ORDER BY position", [$p['id']])->fetchAll();
        apply_active_promotion($p);
    }
    ok($rows);
}

function shop_product() {
    $bt = public_boutique_by_slug($_GET['slug'] ?? '');
    $p = q("SELECT id,name,description,price,compare_at_price,stock_qty,image_url,slug,track_inventory,allow_backorder,is_physical,is_digital,delivery_fee,shipping_fee,options_json,related_product_ids
            FROM products WHERE id=? AND boutique_id=? AND status='active'", [$_GET['id'] ?? '', $bt['id']])->fetch();
    if (!$p) fail('Produit introuvable', 404);
    $p['variants'] = q("SELECT id,name,price,stock_qty FROM product_variants WHERE product_id=? ORDER BY name", [$p['id']])->fetchAll();
    $p['images'] = q("SELECT id,data FROM product_images WHERE product_id=? ORDER BY position", [$p['id']])->fetchAll();
    apply_active_promotion($p);
    // Produits associes (upsell) choisis a la main par le marchand sur la
    // fiche produit du tableau de bord - seuls les produits toujours actifs
    // sont proposes a l'achat, un produit retire du catalogue disparait donc
    // silencieusement de cette liste.
    $relatedIds = json_decode($p['related_product_ids'] ?: '[]', true);
    $p['related'] = [];
    if (is_array($relatedIds) && $relatedIds) {
        $placeholders = implode(',', array_fill(0, count($relatedIds), '?'));
        $p['related'] = q("SELECT id,name,slug,price,compare_at_price,image_url
                            FROM products WHERE boutique_id=? AND status='active' AND id IN ($placeholders)",
                           array_merge([$bt['id']], $relatedIds))->fetchAll();
    }
    unset($p['related_product_ids']);
    $ratingRow = q("SELECT COUNT(*) c, COALESCE(AVG(rating),0) a FROM product_reviews WHERE product_id=? AND status='approved'", [$p['id']])->fetch();
    $p['review_count'] = (int)$ratingRow['c'];
    $p['review_avg'] = round((float)$ratingRow['a'], 1);
    ok($p);
}

// $code potentiellement invalide/expire -> null (jamais d'exception ici,
// c'est a l'appelant de decider si c'est bloquant). La casse est ignoree
// (voir l'index UPPER(code) dans route_install()). Un code SANS aucune
// ligne dans promo_code_customers reste ouvert a tous (comportement
// d'origine) ; des qu'au moins un numero lui est associe, seul ce(s)
// numero(s) peuvent l'utiliser - meme si quelqu'un d'autre obtient le code
// par un tiers, $phone absent/non liste le fait echouer ici.
function find_active_promo($boutiqueId, $code, $phone = null) {
    $code = trim($code ?? '');
    if ($code === '') return null;
    $promo = q("SELECT * FROM promo_codes WHERE boutique_id=? AND UPPER(code)=UPPER(?) AND active=1", [$boutiqueId, $code])->fetch();
    if (!$promo) return null;
    if ($promo['expires_at'] && strtotime($promo['expires_at']) < time()) return null;
    if ($promo['max_uses'] !== null && (int)$promo['used_count'] >= (int)$promo['max_uses']) return null;
    $targetCount = (int)q("SELECT COUNT(*) c FROM promo_code_customers WHERE promo_code_id=?", [$promo['id']])->fetch()['c'];
    if ($targetCount > 0) {
        $phone = trim($phone ?? '');
        if ($phone === '') return null;
        if (!q("SELECT 1 FROM promo_code_customers WHERE promo_code_id=? AND phone=?", [$promo['id'], $phone])->fetch()) return null;
    }
    return $promo;
}
function promo_discount_amount($promo, $subtotal) {
    $discount = $promo['type'] === 'amount' ? (float)$promo['value'] : round($subtotal * ((float)$promo['value'] / 100), 2);
    return max(0, min($discount, $subtotal));
}
// Apercu du rabais avant de valider la commande (affiche au checkout, une
// fois que le client a saisi son telephone - necessaire pour verifier un
// code cible sur un client precis) - ne consomme pas le code (used_count
// n'est incremente qu'au checkout reel).
function shop_validate_promo() {
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $subtotal = max(0, (float)($b['subtotal'] ?? 0));
    $promo = find_active_promo($bt['id'], $b['code'] ?? '', $b['phone'] ?? '');
    if (!$promo) fail('Code promo invalide, expire, ou reserve a un autre client', 404);
    ok(['code'=>$promo['code'], 'type'=>$promo['type'], 'value'=>(float)$promo['value'], 'discount'=>promo_discount_amount($promo, $subtotal)]);
}
// Codes promo ouverts a tous (jamais les codes cibles sur un client precis -
// ceux-la restent invisibles, communiques par le marchand lui-meme) -
// affiches en bandeau public sur la vitrine (voir renderShop() cote store).
function shop_active_promos() {
    $bt = public_boutique_by_slug($_GET['slug'] ?? '');
    ok(q("SELECT code, type, value FROM promo_codes pc
          WHERE boutique_id=? AND active=1
          AND (expires_at IS NULL OR expires_at > NOW())
          AND (max_uses IS NULL OR used_count < max_uses)
          AND NOT EXISTS (SELECT 1 FROM promo_code_customers WHERE promo_code_id=pc.id)
          ORDER BY created_at DESC LIMIT 5", [$bt['id']])->fetchAll());
}
// Des qu'un client tape son telephone au checkout, on lui revele lui-meme
// tout code qui lui a ete personnellement reserve - le marchand n'a donc
// pas besoin de le contacter un par un pour le prevenir. Rate-limite : sans
// ca, un tiers pourrait essayer des numeros en serie pour decouvrir qui a
// un code (peu grave en soi, mais autant limiter le bruit).
function shop_promo_for_phone() {
    rate_limit_check('shop_promo_for_phone', 30, 300);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $phone = trim($b['phone'] ?? '');
    if ($phone === '') ok([]);
    ok(q("SELECT pc.code, pc.type, pc.value FROM promo_codes pc
          JOIN promo_code_customers pcc ON pcc.promo_code_id = pc.id
          WHERE pc.boutique_id=? AND RIGHT(regexp_replace(pcc.phone,'\D','','g'),8)=? AND pc.active=1
          AND (pc.expires_at IS NULL OR pc.expires_at > NOW())
          AND (pc.max_uses IS NULL OR pc.used_count < pc.max_uses)
          ORDER BY pc.created_at DESC", [$bt['id'], phone_key($phone)])->fetchAll());
}

// Commande a la livraison : cree/retrouve le client par telephone, cree la
// commande + ses lignes, decremente le stock. Tout dans une transaction
// pour ne jamais laisser une commande a moitie ecrite en cas d'erreur.
function shop_checkout() {
    rate_limit_check('shop_checkout', 20, 300);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $customer = $b['customer'] ?? [];
    $name = trim($customer['name'] ?? '');
    $phone = trim($customer['phone'] ?? '');
    $address = trim($customer['address'] ?? '');
    $items = $b['items'] ?? [];
    if ($name === '' || $phone === '') fail('Nom et telephone requis');
    if (!is_array($items) || count($items) === 0) fail('Le panier est vide');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $customerEmail = trim($customer['email'] ?? '');
        $customerRow = q("SELECT id FROM customers WHERE boutique_id=? AND phone=?", [$bt['id'], $phone])->fetch();
        if ($customerRow) {
            $customerId = $customerRow['id'];
            q("UPDATE customers SET name=?, address=?, email=COALESCE(NULLIF(?,''), email) WHERE id=?", [$name, $address, $customerEmail, $customerId]);
        } else {
            $customerId = uid();
            q("INSERT INTO customers (id,boutique_id,name,phone,email,address) VALUES (?,?,?,?,?,?)",
              [$customerId, $bt['id'], $name, $phone, $customerEmail, $address]);
        }

        $subtotal = 0;
        $lineData = [];
        foreach ($items as $it) {
            $productId = $it['product_id'] ?? '';
            $qty = max(1, (int)($it['qty'] ?? 1));
            $product = q("SELECT * FROM products WHERE id=? AND boutique_id=? AND status='active'", [$productId, $bt['id']])->fetch();
            if (!$product) throw new Exception('Produit indisponible');
            $variant = null;
            if (!empty($it['variant_id'])) {
                $variant = q("SELECT * FROM product_variants WHERE id=? AND product_id=?", [$it['variant_id'], $productId])->fetch();
            }
            $unitPrice = $variant && $variant['price'] !== null ? (float)$variant['price'] : (float)$product['price'];
            // Meme reduction "promotion produit" que celle affichee sur la
            // vitrine (voir apply_active_promotion()) - recalculee ici a
            // partir de la base, jamais a partir d'un prix envoye par le
            // client, pour ne jamais faire confiance a un montant client.
            $unitPrice = effective_unit_price($productId, $unitPrice);
            // Une commande n'est jamais bloquee par manque de stock - c'est
            // au marchand de s'organiser une fois la commande recue, pas a
            // l'acheteur de le decouvrir au moment de payer. Le stock est
            // simplement decompte (et peut devenir negatif, ce qui signale
            // au marchand qu'il a vendu plus que ce qu'il avait) tant que
            // le produit suit sa quantite (track_inventory).
            $subtotal += $unitPrice * $qty;
            $lineData[] = [
                'product' => $product, 'variant' => $variant, 'qty' => $qty, 'unit_price' => $unitPrice,
            ];
        }
        // "Paiement a la livraison" n'a de sens que pour un panier contenant
        // au moins un produit physique - un panier 100% numerique n'a rien a
        // livrer, donc ne doit pas etre bloque si le marchand a desactive le
        // COD (un marchand qui ne vend que du numerique n'a aucune raison de
        // l'activer).
        $hasPhysical = false;
        foreach ($lineData as $l) { if (!empty($l['product']['is_physical'])) { $hasPhysical = true; break; } }
        if ($hasPhysical && !$bt['cod_enabled']) {
            throw new Exception('Le paiement a la livraison n\'est pas active pour cette boutique');
        }
        // L'email n'est obligatoire que si le panier contient au moins un
        // produit numerique (is_digital) - c'est le seul moyen de lui faire
        // parvenir sa livraison numerique plus tard (voir
        // maybe_send_digital_delivery()). Independant de is_physical : un
        // produit peut etre physique ET numerique a la fois.
        foreach ($lineData as $l) {
            if (!empty($l['product']['is_digital']) && $customerEmail === '') {
                throw new Exception('Un email est requis pour recevoir un produit numerique');
            }
        }
        // Le frais de livraison/expedition vient de la boutique (reglage
        // marchand), pas du client - jamais du corps de la requete publique,
        // pour eviter qu'un acheteur ne le mette a 0 lui-meme. Chaque produit
        // peut definir son propre frais (sinon celui de la boutique
        // s'applique) ; une commande n'est livree/expediee qu'une fois, donc
        // on retient le plus eleve des frais concernes plutot que de les
        // additionner - et les produits non physiques (service/numerique)
        // n'en ajoutent aucun. Le client choisit un SEUL des deux modes
        // (jamais les deux a la fois) - deliveryMethod par defaut 'delivery'
        // si absent/invalide, pour ne jamais rejeter une vieille requete qui
        // n'envoie pas encore ce champ.
        $deliveryMethod = in_array($b['delivery_method'] ?? '', ['delivery', 'shipping'], true) ? $b['delivery_method'] : 'delivery';
        $feeField = $deliveryMethod === 'shipping' ? 'shipping_fee' : 'delivery_fee';
        $defaultFeeField = $deliveryMethod === 'shipping' ? 'default_shipping_fee' : 'default_delivery_fee';
        $deliveryFee = 0;
        foreach ($lineData as $l) {
            if (!$l['product']['is_physical']) continue;
            $productFee = $l['product'][$feeField];
            $fee = ($productFee !== null) ? (float)$productFee : (float)$bt[$defaultFeeField];
            $deliveryFee = max($deliveryFee, $fee);
        }
        // Code promo revalide ici (dans la transaction, sur le vrai
        // sous-total serveur) plutot que de faire confiance a un montant de
        // reduction envoye par le client - shop_validate_promo() ne sert
        // qu'a l'affichage anticipe au panier.
        $discountAmount = 0;
        $promoCodeUsed = null;
        $promoInput = trim($b['promo_code'] ?? '');
        if ($promoInput !== '') {
            $promo = find_active_promo($bt['id'], $promoInput, $phone);
            if (!$promo) throw new Exception('Code promo invalide ou expire');
            $discountAmount = promo_discount_amount($promo, $subtotal);
            $promoCodeUsed = $promo['code'];
            q("UPDATE promo_codes SET used_count = used_count + 1 WHERE id=?", [$promo['id']]);
        }
        $total = $subtotal + $deliveryFee - $discountAmount;

        $orderId = uid();
        $ref = order_ref();
        q("INSERT INTO orders (id,boutique_id,customer_id,ref,status,payment_method,subtotal,delivery_fee_charged,delivery_method,total,
           customer_name,customer_phone,customer_address,utm_source,utm_campaign,promo_code,discount_amount)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [$orderId, $bt['id'], $customerId, $ref, 'pending', 'cod', $subtotal, $deliveryFee, $deliveryMethod, $total,
           $name, $phone, $address, trim($b['utm_source'] ?? ''), trim($b['utm_campaign'] ?? ''), $promoCodeUsed, $discountAmount]);

        foreach ($lineData as $l) {
            q("INSERT INTO order_items (id,order_id,product_id,product_name,variant_id,unit_price,unit_cost,qty)
               VALUES (?,?,?,?,?,?,?,?)",
              [uid(), $orderId, $l['product']['id'],
               $l['product']['name'].($l['variant'] ? ' - '.$l['variant']['name'] : ''),
               $l['variant']['id'] ?? null, $l['unit_price'], $l['product']['cost_price'] ?? 0, $l['qty']]);
            if (!$l['product']['track_inventory']) {
                // Stock illimite pour ce produit : rien a decompter.
            } elseif ($l['variant']) {
                q("UPDATE product_variants SET stock_qty = stock_qty - ? WHERE id=?", [$l['qty'], $l['variant']['id']]);
            } else {
                q("UPDATE products SET stock_qty = stock_qty - ? WHERE id=?", [$l['qty'], $l['product']['id']]);
            }
        }
        q("INSERT INTO delivery_assignments (id,order_id,boutique_id,status) VALUES (?,?,?,?)",
          [uid(), $orderId, $bt['id'], 'to_assign']);

        if (!empty($b['session_id'])) {
            q("UPDATE abandoned_carts SET converted=1 WHERE boutique_id=? AND session_id=? AND converted=0",
              [$bt['id'], $b['session_id']]);
        }

        $pdo->commit();
        log_activity($bt['id'], 'Nouvelle commande '.$ref.' ('.$name.')');
        notify_new_order($bt, $ref, $name, $total);
        if ($customerEmail !== '') {
            notify_customer_order_confirmation($bt, $ref, $customerEmail, $name, $lineData, $subtotal, $deliveryFee, $discountAmount, $total, $address, $deliveryMethod);
        }
        ok(['ref'=>$ref, 'order_id'=>$orderId, 'total'=>$total], 'Commande enregistree', 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        fail($e->getMessage(), 400);
    }
}

function notify_new_order($bt, $ref, $customerName, $total) {
    $settings = q("SELECT notify_order_email, notify_email FROM boutiques WHERE id=?", [$bt['id']])->fetch();
    if (!$settings) return;
    $summary = "Client: $customerName\nTotal: $total ".($bt['currency'] ?: 'XOF')."\nReference: $ref\n\nOuvrez votre tableau de bord MYBOUTIK pour la traiter.";
    if ($settings['notify_order_email']) {
        $to = $settings['notify_email'] ?: null;
        if (!$to) {
            $owner = q("SELECT u.email FROM users u JOIN boutiques b ON b.owner_user_id=u.id WHERE b.id=?", [$bt['id']])->fetch();
            $to = $owner['email'] ?? null;
        }
        if ($to) send_email($to, 'Nouvelle commande '.$ref.' - '.$bt['name'], $summary);
    }
}

// Recu envoye au CLIENT (par opposition a notify_new_order() qui previent le
// MARCHAND) - uniquement si une adresse email a ete renseignee au checkout
// (le champ est optionnel, voir store/index.html). N'est jamais bloquant :
// appele apres le commit de la transaction, une erreur d'envoi ne doit
// jamais faire echouer une commande deja enregistree.
function notify_customer_order_confirmation($bt, $ref, $email, $customerName, $lineData, $subtotal, $deliveryFee, $discountAmount, $total, $address, $deliveryMethod = 'delivery') {
    $currency = $bt['currency'] ?: 'XOF';
    $lines = array_map(function($l) use ($currency) {
        $label = $l['product']['name'].($l['variant'] ? ' - '.$l['variant']['name'] : '');
        return '- '.$l['qty'].' x '.$label.' ('.number_format($l['unit_price'],0,',',' ').' '.$currency.')';
    }, $lineData);
    // Le mot "livraison"/"expedition" (adresse, "vous serez contacte pour...")
    // n'a de sens que si la commande contient au moins un produit physique -
    // pour un panier 100% numerique, rien n'est jamais livre/expedie.
    $isShipping = $deliveryMethod === 'shipping';
    $modeWord = $isShipping ? 'expedition' : 'livraison';
    $hasPhysical = false; $hasDigital = false;
    foreach ($lineData as $l) {
        if (!empty($l['product']['is_physical'])) $hasPhysical = true;
        if (!empty($l['product']['is_digital'])) $hasDigital = true;
    }
    $totalLabel = $hasPhysical ? 'Total a payer a la '.$modeWord : 'Total';
    $footer = '';
    if ($hasPhysical) {
        $footer .= ($isShipping ? "Adresse d'expedition : $address\n\n" : "Adresse de livraison : $address\n\n").
            "Vous serez contacte(e) pour la ".$modeWord.". Paiement a la reception (paiement a la ".$modeWord.").\n";
    }
    if ($hasDigital) {
        $footer .= "Votre produit numerique vous sera envoye par email des que ".$bt['name']." aura confirme votre paiement.\n";
    }
    $body = "Bonjour $customerName,\n\n".
        "Merci pour votre commande chez ".$bt['name']." !\n\n".
        "Reference : $ref\n\n".
        implode("\n", $lines)."\n\n".
        "Sous-total : ".number_format($subtotal,0,',',' ')." $currency\n".
        ($deliveryFee > 0 ? "Frais de ".$modeWord." : ".number_format($deliveryFee,0,',',' ')." $currency\n" : '').
        ($discountAmount > 0 ? "Remise : -".number_format($discountAmount,0,',',' ')." $currency\n" : '').
        "$totalLabel : ".number_format($total,0,',',' ')." $currency\n\n".
        $footer.
        "Pour suivre votre commande, retournez sur la boutique et utilisez \"Suivre ma commande\" avec cette reference et votre telephone.";
    send_email($email, 'Confirmation de votre commande '.$ref.' - '.$bt['name'], $body);
}

function shop_track_visit() {
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    q("INSERT INTO visits (boutique_id,session_id,path,referrer) VALUES (?,?,?,?)",
      [$bt['id'], substr(trim($b['session_id'] ?? ''),0,64), substr(trim($b['path'] ?? ''),0,255), substr(trim($b['referrer'] ?? ''),0,255)]);
    ok(null);
}

function shop_track_abandoned() {
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $settings = q("SELECT * FROM abandoned_settings WHERE boutique_id=?", [$bt['id']])->fetch();
    if ($settings && !$settings['capture_enabled']) ok(null);
    $sessionId = trim($b['session_id'] ?? '');
    $phone = trim($b['phone'] ?? '');
    $email = trim($b['email'] ?? '');
    if ($phone === '' && $email === '') fail('Aucune information a capturer');
    $existing = $sessionId ? q("SELECT id FROM abandoned_carts WHERE boutique_id=? AND session_id=? AND converted=0",
        [$bt['id'], $sessionId])->fetch() : null;
    $cartJson = json_encode($b['cart'] ?? [], JSON_UNESCAPED_UNICODE);
    $total = (float)($b['total'] ?? 0);
    if ($existing) {
        q("UPDATE abandoned_carts SET phone=?, email=?, cart_snapshot=?, total=?, captured_at=NOW() WHERE id=?",
          [$phone, $email, $cartJson, $total, $existing['id']]);
    } else {
        q("INSERT INTO abandoned_carts (id,boutique_id,session_id,phone,email,cart_snapshot,total)
           VALUES (?,?,?,?,?,?,?)", [uid(), $bt['id'], $sessionId, $phone, $email, $cartJson, $total]);
    }
    ok(null);
}

function shop_newsletter() {
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $email = strtolower(trim($b['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse email invalide');
    q("INSERT INTO newsletter_subscribers (id,boutique_id,email) VALUES (?,?,?) ON CONFLICT (boutique_id,email) DO NOTHING",
      [uid(), $bt['id'], $email]);
    ok(null, 'Inscription confirmee');
}

function shop_contact_message() {
    rate_limit_check('shop_contact', 10, 300);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $message = trim($b['message'] ?? '');
    if ($message === '') fail('Le message ne peut pas etre vide');
    q("INSERT INTO contact_messages (id,boutique_id,name,email,message) VALUES (?,?,?,?,?)",
      [uid(), $bt['id'], trim($b['name'] ?? ''), trim($b['email'] ?? ''), $message]);
    ok(null, 'Message envoye');
}

// Suivi de commande public : le client doit connaitre a la fois la
// reference ET le telephone utilise a la commande - empeche quiconque
// devinant/enumerant des references de voir les commandes des autres
// clients de la boutique.
function shop_track_order() {
    rate_limit_check('shop_track_order', 20, 300);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $ref = trim($b['ref'] ?? '');
    $phone = trim($b['phone'] ?? '');
    if ($ref === '' || $phone === '') fail('Numero de commande et telephone requis');
    $o = q("SELECT id,ref,status,total,subtotal,delivery_fee_charged,delivery_method,discount_amount,customer_name,created_at,delivered_at
            FROM orders WHERE boutique_id=? AND ref=? AND RIGHT(regexp_replace(customer_phone,'\D','','g'),8)=?",
           [$bt['id'], $ref, phone_key($phone)])->fetch();
    if (!$o) fail('Aucune commande trouvee avec ces informations', 404);
    $o['items'] = q("SELECT product_name,qty,unit_price FROM order_items WHERE order_id=?", [$o['id']])->fetchAll();
    $delivery = q("SELECT da.status AS delivery_status, dp.name AS delivery_person_name, dp.phone AS delivery_person_phone
                   FROM delivery_assignments da LEFT JOIN delivery_persons dp ON dp.id = da.delivery_person_id
                   WHERE da.order_id=?", [$o['id']])->fetch();
    $o['delivery_status'] = $delivery['delivery_status'] ?? null;
    $o['delivery_person_name'] = $delivery['delivery_person_name'] ?? null;
    $o['delivery_person_phone'] = $delivery['delivery_person_phone'] ?? null;
    ok($o);
}

// Liste des commandes d'un numero (sans reference) - permet au client qui a
// oublie/perdu sa reference de la retrouver lui-meme plutot que de devoir
// vous contacter. Le telephone seul suffit ici (comme pour
// shop_promo_for_phone()) : c'est sa propre historique, pas celle de
// quelqu'un d'autre - il ne peut deviner que son propre numero.
function shop_orders_for_phone() {
    rate_limit_check('shop_orders_for_phone', 20, 300);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $phone = trim($b['phone'] ?? '');
    if ($phone === '') ok([]);
    ok(q("SELECT ref, status, total, created_at FROM orders
          WHERE boutique_id=? AND RIGHT(regexp_replace(customer_phone,'\D','','g'),8)=?
          ORDER BY created_at DESC LIMIT 20", [$bt['id'], phone_key($phone)])->fetchAll());
}

function shop_reviews() {
    $bt = public_boutique_by_slug($_GET['slug'] ?? '');
    $productId = $_GET['product_id'] ?? '';
    $rows = q("SELECT customer_name,rating,comment,created_at FROM product_reviews
               WHERE boutique_id=? AND product_id=? AND status='approved' ORDER BY created_at DESC LIMIT 100",
              [$bt['id'], $productId])->fetchAll();
    ok($rows);
}

function shop_review_add() {
    rate_limit_check('shop_review_add', 10, 600);
    $b = body();
    $bt = public_boutique_by_slug($b['slug'] ?? '');
    $product = product_owned($b['product_id'] ?? '', $bt['id']);
    $name = trim($b['customer_name'] ?? '');
    $rating = (int)($b['rating'] ?? 0);
    if ($name === '') fail('Votre nom est requis');
    if ($rating < 1 || $rating > 5) fail('Note invalide (1 a 5)');
    // Publie immediatement (status='approved') : le marchand n'a plus a valider
    // avant affichage, mais garde la main pour supprimer un avis apres coup
    // depuis Marketing > Avis (voir marketing_review_delete()).
    q("INSERT INTO product_reviews (id,boutique_id,product_id,customer_name,rating,comment,status) VALUES (?,?,?,?,?,?,'approved')",
      [uid(), $bt['id'], $product['id'], $name, $rating, trim($b['comment'] ?? '')]);
    ok(null, 'Merci pour votre avis !');
}

// ============================================================
// COMMANDES — gestion cote marchand (liste, statut, creation manuelle) +
// commandes abandonnees
// ============================================================
function route_orders($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'orders');
    switch ($action) {
        case 'list':               orders_list($pl); break;
        case 'get':                orders_get($pl); break;
        case 'create':              orders_create_manual($pl); break;
        case 'update_status':       orders_update_status($pl); break;
        case 'resend_digital':      orders_resend_digital($pl); break;
        case 'abandoned_list':      abandoned_list($pl); break;
        case 'abandoned_mark':      abandoned_mark($pl); break;
        case 'abandoned_settings_get':  abandoned_settings_get($pl); break;
        case 'abandoned_settings_save': abandoned_settings_save($pl); break;
        default: fail('Action inconnue', 404);
    }
}

function order_owned($id, $boutiqueId) {
    $o = q("SELECT * FROM orders WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$o) fail('Commande introuvable', 404);
    return $o;
}

function orders_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $status = $_GET['status'] ?? '';
    $qStr = trim($_GET['q'] ?? '');
    // has_physical/has_digital : permettent d'afficher un badge par commande
    // (une commande peut melanger les deux si le panier avait un produit de
    // chaque sorte) sans avoir a rappeler orders_get() pour chaque ligne.
    $sql = "SELECT o.*,
              CAST(EXISTS(SELECT 1 FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=o.id AND p.is_physical=1) AS INT) AS has_physical,
              CAST(EXISTS(SELECT 1 FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=o.id AND p.is_digital=1) AS INT) AS has_digital
            FROM orders o WHERE o.boutique_id=?";
    $params = [$bt['id']];
    if ($status !== '' && $status !== 'all') { $sql .= " AND o.status=?"; $params[] = $status; }
    if ($qStr !== '') {
        $sql .= " AND (o.ref ILIKE ? OR o.customer_name ILIKE ? OR o.customer_phone ILIKE ?)";
        $like = '%'.$qStr.'%'; array_push($params, $like, $like, $like);
    }
    $sql .= " ORDER BY o.created_at DESC LIMIT 500";
    $rows = q($sql, $params)->fetchAll();
    ok($rows);
}

function orders_get($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $o = order_owned($_GET['id'] ?? '', $bt['id']);
    $o['items'] = q("SELECT * FROM order_items WHERE order_id=?", [$o['id']])->fetchAll();
    $o['delivery'] = q("SELECT da.*, dp.name AS delivery_person_name, dp.phone AS delivery_person_phone
                         FROM delivery_assignments da LEFT JOIN delivery_persons dp ON dp.id = da.delivery_person_id
                         WHERE da.order_id=?", [$o['id']])->fetch();
    ok($o);
}

function orders_create_manual($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['customer_name'] ?? '');
    $phone = trim($b['customer_phone'] ?? '');
    $items = $b['items'] ?? [];
    if ($name === '' || $phone === '') fail('Nom et telephone du client requis');
    if (!is_array($items) || count($items) === 0) fail('Ajoutez au moins un produit');

    $customerRow = q("SELECT id FROM customers WHERE boutique_id=? AND phone=?", [$bt['id'], $phone])->fetch();
    if ($customerRow) {
        $customerId = $customerRow['id'];
        q("UPDATE customers SET name=?, address=? WHERE id=?", [$name, trim($b['customer_address'] ?? ''), $customerId]);
    } else {
        $customerId = uid();
        q("INSERT INTO customers (id,boutique_id,name,phone,address) VALUES (?,?,?,?,?)",
          [$customerId, $bt['id'], $name, $phone, trim($b['customer_address'] ?? '')]);
    }

    $subtotal = 0; $lineData = [];
    foreach ($items as $it) {
        $product = product_owned($it['product_id'] ?? '', $bt['id']);
        $qty = max(1, (int)($it['qty'] ?? 1));
        $unitPrice = isset($it['unit_price']) ? (float)$it['unit_price'] : (float)$product['price'];
        $subtotal += $unitPrice * $qty;
        $lineData[] = ['product'=>$product, 'qty'=>$qty, 'unit_price'=>$unitPrice];
    }
    $deliveryFee = max(0, (float)($b['delivery_fee'] ?? 0));
    $total = $subtotal + $deliveryFee;
    $orderId = uid(); $ref = order_ref();
    q("INSERT INTO orders (id,boutique_id,customer_id,ref,status,payment_method,subtotal,delivery_fee_charged,total,
       customer_name,customer_phone,customer_address) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
      [$orderId, $bt['id'], $customerId, $ref, 'pending', 'cod', $subtotal, $deliveryFee, $total,
       $name, $phone, trim($b['customer_address'] ?? '')]);
    foreach ($lineData as $l) {
        q("INSERT INTO order_items (id,order_id,product_id,product_name,unit_price,unit_cost,qty) VALUES (?,?,?,?,?,?,?)",
          [uid(), $orderId, $l['product']['id'], $l['product']['name'], $l['unit_price'], $l['product']['cost_price'] ?? 0, $l['qty']]);
        q("UPDATE products SET stock_qty = stock_qty - ? WHERE id=?", [$l['qty'], $l['product']['id']]);
    }
    q("INSERT INTO delivery_assignments (id,order_id,boutique_id,status) VALUES (?,?,?,?)", [uid(), $orderId, $bt['id'], 'to_assign']);
    log_activity($bt['id'], 'Commande manuelle creee '.$ref, $pl['sub']);
    ok(q("SELECT * FROM orders WHERE id=?", [$orderId])->fetch(), 'Commande creee', 201);
}

function orders_update_status($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $o = order_owned($b['id'] ?? '', $bt['id']);
    $status = $b['status'] ?? '';
    $allowed = ['pending','processing','shipped','delivered','refused','cancelled'];
    if (!in_array($status, $allowed, true)) fail('Statut invalide');
    if ($status === 'delivered') {
        q("UPDATE orders SET status=?, delivered_at=NOW() WHERE id=?", [$status, $o['id']]);
        maybe_send_digital_delivery($bt, $o);
    } else {
        q("UPDATE orders SET status=? WHERE id=?", [$status, $o['id']]);
    }
    log_activity($bt['id'], 'Commande '.$o['ref'].' -> '.$status, $pl['sub']);
    ok(q("SELECT * FROM orders WHERE id=?", [$o['id']])->fetch(), 'Statut mis a jour');
}

// Envoie automatiquement, par email, le contenu numerique (lien, code,
// instructions...) des produits non physiques de la commande, des qu'elle
// passe au statut "livree" - c'est ce statut qui represente pour un produit
// numerique le moment ou le client recoit vraiment ce qu'il a paye. Ne fait
// rien si : deja envoye pour cette commande (digital_delivery_sent_at),
// aucun email client connu, ou aucun article non physique n'a de contenu
// renseigne par le marchand.
// $claimNewCodes=true (premier envoi) : pioche de nouveaux codes dans le
// pool. $claimNewCodes=false (renvoi manuel, voir orders_resend_digital()) :
// reutilise les codes DEJA attribues a cette commande plutot que d'en piocher
// de nouveaux - un renvoi doit redonner exactement ce que le client a deja
// recu, jamais un code different ni consommer le pool une deuxieme fois.
function digital_delivery_parts($bt, $o, $claimNewCodes) {
    $items = q("SELECT oi.product_id, oi.product_name, oi.qty, p.is_digital, p.digital_delivery_content
                FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id=?", [$o['id']])->fetchAll();
    $parts = [];
    foreach ($items as $it) {
        if (empty($it['is_digital'])) continue;
        // Les deux sources sont independantes et se cumulent : le contenu
        // partage (meme lien/instructions pour tout le monde) ET un code
        // unique pioche dans le pool, si le produit en a un - utile pour un
        // logiciel ou le lien de telechargement est le meme pour tous mais
        // la cle de licence doit etre differente a chaque vente.
        $pieces = [];
        $content = trim((string)($it['digital_delivery_content'] ?? ''));
        if ($content !== '') $pieces[] = $content;
        if ($claimNewCodes) {
            $poolSize = (int)q("SELECT COUNT(*) c FROM product_digital_codes WHERE product_id=?", [$it['product_id']])->fetch()['c'];
            if ($poolSize > 0) {
                // Pioche atomique d'un code disponible par unite commandee :
                // le SELECT ... FOR UPDATE SKIP LOCKED evite que deux
                // commandes du meme produit livrees en meme temps ne
                // recuperent le meme code.
                $codes = [];
                for ($i = 0; $i < (int)$it['qty']; $i++) {
                    $code = q("UPDATE product_digital_codes SET status='used', used_by_order_id=?, used_at=NOW()
                               WHERE id = (SELECT id FROM product_digital_codes WHERE product_id=? AND status='available'
                                           ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED)
                               RETURNING code", [$o['id'], $it['product_id']])->fetchColumn();
                    if ($code === false) break;
                    $codes[] = $code;
                }
                if ($codes) $pieces[] = (count($codes) > 1 ? 'Vos codes' : 'Votre code').' : '.implode(', ', $codes);
                if (count($codes) < (int)$it['qty']) {
                    log_activity($bt['id'], 'Stock de codes numeriques epuise pour '.$it['product_name'].' (commande '.$o['ref'].')');
                }
            }
        } else {
            $codes = q("SELECT code FROM product_digital_codes WHERE product_id=? AND used_by_order_id=?",
                       [$it['product_id'], $o['id']])->fetchAll(PDO::FETCH_COLUMN);
            if ($codes) $pieces[] = (count($codes) > 1 ? 'Vos codes' : 'Votre code').' : '.implode(', ', $codes);
        }
        if ($pieces) $parts[] = $it['product_name'].":\n".implode("\n", $pieces);
    }
    return $parts;
}

function maybe_send_digital_delivery($bt, $o) {
    if (!empty($o['digital_delivery_sent_at'])) return;
    if (empty($o['customer_id'])) return;
    $email = trim((string)(q("SELECT email FROM customers WHERE id=?", [$o['customer_id']])->fetchColumn() ?: ''));
    if ($email === '') return;
    $parts = digital_delivery_parts($bt, $o, true);
    if (!$parts) return;
    $body = "Bonjour,\n\nMerci pour votre commande ".$o['ref']." chez ".$bt['name'].
        " ! Voici votre/vos produit(s) numerique(s) :\n\n".implode("\n\n", $parts).
        "\n\nBonne utilisation !";
    send_email($email, 'Votre produit numerique - commande '.$o['ref'].' - '.$bt['name'], $body);
    q("UPDATE orders SET digital_delivery_sent_at=NOW() WHERE id=?", [$o['id']]);
}

// Renvoi manuel a la demande du marchand (bouton "Renvoyer" sur une commande
// numerique deja livree) - typiquement quand le client dit ne rien avoir
// recu (spam, adresse mal tapee corrigee entre temps...). Contrairement au
// premier envoi, jamais bloque par digital_delivery_sent_at : c'est prevu
// pour etre redeclenchable autant de fois que necessaire.
function orders_resend_digital($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $o = order_owned($b['id'] ?? '', $bt['id']);
    if (empty($o['customer_id'])) fail('Client introuvable');
    $email = trim((string)(q("SELECT email FROM customers WHERE id=?", [$o['customer_id']])->fetchColumn() ?: ''));
    if ($email === '') fail('Ce client n\'a pas d\'email enregistre');
    $parts = digital_delivery_parts($bt, $o, false);
    if (!$parts) fail('Aucun contenu numerique a renvoyer pour cette commande');
    $body = "Bonjour,\n\nVoici a nouveau votre/vos produit(s) numerique(s) pour la commande ".$o['ref']." chez ".$bt['name'].
        " :\n\n".implode("\n\n", $parts)."\n\nBonne utilisation !";
    send_email($email, 'Votre produit numerique - commande '.$o['ref'].' - '.$bt['name'], $body);
    q("UPDATE orders SET digital_delivery_sent_at=NOW() WHERE id=?", [$o['id']]);
    log_activity($bt['id'], 'Livraison numerique renvoyee pour la commande '.$o['ref'], $pl['sub']);
    ok(null, 'Email renvoye a '.$email);
}

function abandoned_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $filter = $_GET['filter'] ?? 'abandoned';
    $sql = "SELECT * FROM abandoned_carts WHERE boutique_id=?";
    $params = [$bt['id']];
    if ($filter === 'abandoned') { $sql .= " AND converted=0"; }
    elseif ($filter === 'converted') { $sql .= " AND converted=1"; }
    $sql .= " ORDER BY captured_at DESC LIMIT 500";
    ok(q($sql, $params)->fetchAll());
}
function abandoned_mark($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $row = q("SELECT id FROM abandoned_carts WHERE id=? AND boutique_id=?", [$b['id'] ?? '', $bt['id']])->fetch();
    if (!$row) fail('Introuvable', 404);
    q("UPDATE abandoned_carts SET converted=? WHERE id=?", [(int)!!($b['converted'] ?? true), $row['id']]);
    ok(null, 'Mis a jour');
}
function abandoned_settings_get($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $row = q("SELECT * FROM abandoned_settings WHERE boutique_id=?", [$bt['id']])->fetch();
    ok($row ?: ['boutique_id'=>$bt['id'],'capture_enabled'=>1,'timeout_minutes'=>15,'email_alert'=>0,'customer_reminder_enabled'=>0]);
}
function abandoned_settings_save($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    q("INSERT INTO abandoned_settings (boutique_id,capture_enabled,timeout_minutes,email_alert,customer_reminder_enabled) VALUES (?,?,?,?,?)
       ON CONFLICT (boutique_id) DO UPDATE SET capture_enabled=EXCLUDED.capture_enabled,
       timeout_minutes=EXCLUDED.timeout_minutes, email_alert=EXCLUDED.email_alert,
       customer_reminder_enabled=EXCLUDED.customer_reminder_enabled",
      [$bt['id'], (int)!!($b['capture_enabled'] ?? 1), (int)($b['timeout_minutes'] ?? 15), (int)!!($b['email_alert'] ?? 0),
       (int)!!($b['customer_reminder_enabled'] ?? 0)]);
    ok(null, 'Reglages enregistres');
}

// ============================================================
// LIVRAISONS — livreurs et suivi de la livraison de chaque commande
// ============================================================
function route_deliveries($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'deliveries');
    switch ($action) {
        case 'list':           deliveries_list($pl); break;
        case 'persons':        delivery_persons_list($pl); break;
        case 'person_create':  delivery_person_create($pl); break;
        case 'person_update':  delivery_person_update($pl); break;
        case 'person_delete':  delivery_person_delete($pl); break;
        case 'assign':         delivery_assign($pl); break;
        case 'update_status':  delivery_update_status($pl); break;
        default: fail('Action inconnue', 404);
    }
}

function deliveries_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $status = $_GET['status'] ?? '';
    $sql = "SELECT da.*, o.ref, o.customer_name, o.customer_phone, o.customer_address, o.total,
                   dp.name AS delivery_person_name, dp.phone AS delivery_person_phone
            FROM delivery_assignments da
            JOIN orders o ON o.id = da.order_id
            LEFT JOIN delivery_persons dp ON dp.id = da.delivery_person_id
            WHERE da.boutique_id=?";
    $params = [$bt['id']];
    if ($status !== '' && $status !== 'all') { $sql .= " AND da.status=?"; $params[] = $status; }
    // Un membre 'livreur' ne voit que les livraisons qui lui sont assignees
    // (via son lien delivery_person_id) - pas encore lie : ne voit rien
    // plutot que tout, en attendant que l'admin fasse le lien.
    if (($bt['_member_role'] ?? '') === 'livreur') {
        $dpId = q("SELECT delivery_person_id FROM boutique_members WHERE boutique_id=? AND user_id=? AND status='active'", [$bt['id'], $pl['sub']])->fetchColumn();
        if ($dpId) { $sql .= " AND da.delivery_person_id=?"; $params[] = $dpId; }
        else { $sql .= " AND 1=0"; }
    }
    $sql .= " ORDER BY da.created_at DESC LIMIT 500";
    ok(q($sql, $params)->fetchAll());
}

function delivery_persons_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT * FROM delivery_persons WHERE boutique_id=? ORDER BY active DESC, name", [$bt['id']])->fetchAll());
}
function delivery_person_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    deny_roles($bt, ['livreur']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom du livreur est requis');
    $id = uid();
    q("INSERT INTO delivery_persons (id,boutique_id,name,phone,email,vehicle_type,plate_number,photo_url,notes)
       VALUES (?,?,?,?,?,?,?,?,?)",
      [$id, $bt['id'], $name, trim($b['phone'] ?? ''), trim($b['email'] ?? ''), trim($b['vehicle_type'] ?? ''),
       trim($b['plate_number'] ?? ''), trim($b['photo_url'] ?? ''), trim($b['notes'] ?? '')]);
    ok(q("SELECT * FROM delivery_persons WHERE id=?", [$id])->fetch(), 'Livreur ajoute', 201);
}
function delivery_person_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM delivery_persons WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Livreur introuvable', 404);
    return $row;
}
function delivery_person_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    deny_roles($bt, ['livreur']);
    $row = delivery_person_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE delivery_persons SET name=?, phone=?, active=?, email=?, vehicle_type=?, plate_number=?, photo_url=?, notes=? WHERE id=?",
      [trim($b['name'] ?? $row['name']), trim($b['phone'] ?? $row['phone']),
       isset($b['active']) ? (int)!!$b['active'] : $row['active'],
       trim($b['email'] ?? $row['email']), trim($b['vehicle_type'] ?? $row['vehicle_type']),
       trim($b['plate_number'] ?? $row['plate_number']), trim($b['photo_url'] ?? $row['photo_url']),
       trim($b['notes'] ?? $row['notes']), $row['id']]);
    ok(q("SELECT * FROM delivery_persons WHERE id=?", [$row['id']])->fetch(), 'Livreur mis a jour');
}
function delivery_person_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    deny_roles($bt, ['livreur']);
    $row = delivery_person_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE delivery_persons SET active=0 WHERE id=?", [$row['id']]);
    ok(null, 'Livreur desactive');
}

function delivery_assign($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    deny_roles($bt, ['livreur']);
    $o = order_owned($b['order_id'] ?? '', $bt['id']);
    delivery_person_owned($b['delivery_person_id'] ?? '', $bt['id']);
    q("UPDATE delivery_assignments SET delivery_person_id=?, status='assigned', assigned_at=NOW() WHERE order_id=?",
      [$b['delivery_person_id'], $o['id']]);
    q("UPDATE orders SET status='processing' WHERE id=? AND status='pending'", [$o['id']]);
    log_activity($bt['id'], 'Commande '.$o['ref'].' assignee a un livreur', $pl['sub']);
    ok(q("SELECT * FROM delivery_assignments WHERE order_id=?", [$o['id']])->fetch(), 'Commande assignee');
}

function delivery_update_status($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $o = order_owned($b['order_id'] ?? '', $bt['id']);
    // Un livreur ne peut faire evoluer que ses propres livraisons assignees,
    // pas n'importe quelle commande de la boutique.
    if (($bt['_member_role'] ?? '') === 'livreur') {
        $dpId = q("SELECT delivery_person_id FROM boutique_members WHERE boutique_id=? AND user_id=? AND status='active'", [$bt['id'], $pl['sub']])->fetchColumn();
        $assignment = q("SELECT delivery_person_id FROM delivery_assignments WHERE order_id=?", [$o['id']])->fetch();
        if (!$dpId || !$assignment || $assignment['delivery_person_id'] !== $dpId) {
            fail('Cette livraison ne vous est pas assignee', 403);
        }
    }
    $status = $b['status'] ?? '';
    $allowed = ['to_assign','assigned','in_delivery','delivered','refused'];
    if (!in_array($status, $allowed, true)) fail('Statut invalide');
    if ($status === 'delivered') {
        q("UPDATE delivery_assignments SET status=?, delivered_at=NOW() WHERE order_id=?", [$status, $o['id']]);
        q("UPDATE orders SET status='delivered', delivered_at=NOW() WHERE id=?", [$o['id']]);
    } else {
        q("UPDATE delivery_assignments SET status=? WHERE order_id=?", [$status, $o['id']]);
        if ($status === 'refused') q("UPDATE orders SET status='refused' WHERE id=?", [$o['id']]);
        if ($status === 'in_delivery') q("UPDATE orders SET status='shipped' WHERE id=?", [$o['id']]);
    }
    log_activity($bt['id'], 'Livraison '.$o['ref'].' -> '.$status, $pl['sub']);
    ok(q("SELECT * FROM delivery_assignments WHERE order_id=?", [$o['id']])->fetch(), 'Statut mis a jour');
}

// ============================================================
// CLIENTS (derives des commandes) + CARNET D'ADRESSES (contacts internes)
// ============================================================
function route_customers($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'customers');
    switch ($action) {
        case 'list':     customers_list($pl); break;
        case 'get':      customers_get($pl); break;
        case 'inactive_list': customers_inactive_list($pl); break;
        default: fail('Action inconnue', 404);
    }
}
function customers_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $qStr = trim($_GET['q'] ?? '');
    $sql = "SELECT c.*, COUNT(o.id) AS orders_count, COALESCE(SUM(CASE WHEN o.status IN ".ENCAISSE_STATUSES." THEN o.total ELSE 0 END),0) AS total_spent,
            MAX(o.created_at) AS last_order_at
            FROM customers c LEFT JOIN orders o ON o.customer_id = c.id
            WHERE c.boutique_id=?";
    $params = [$bt['id']];
    if ($qStr !== '') { $sql .= " AND (c.name ILIKE ? OR c.phone ILIKE ? OR c.email ILIKE ?)"; $like='%'.$qStr.'%'; array_push($params,$like,$like,$like); }
    $sql .= " GROUP BY c.id ORDER BY c.created_at DESC LIMIT 500";
    ok(q($sql, $params)->fetchAll());
}
// Clients ayant deja commande au moins une fois mais plus depuis un moment -
// candidats a une relance (ex: code promo cible, voir Marketing). "Plus
// jamais commande" n'existe pas comme notion a part : c'est juste la meme
// liste avec un delai tres long.
function customers_inactive_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $days = max(1, (int)($_GET['days'] ?? 60));
    ok(q("SELECT c.*, COUNT(o.id) AS orders_count,
          COALESCE(SUM(CASE WHEN o.status IN ".ENCAISSE_STATUSES." THEN o.total ELSE 0 END),0) AS total_spent,
          MAX(o.created_at) AS last_order_at
          FROM customers c JOIN orders o ON o.customer_id = c.id
          WHERE c.boutique_id=?
          GROUP BY c.id
          HAVING MAX(o.created_at) < NOW() - (?::text || ' days')::interval
          ORDER BY MAX(o.created_at) ASC LIMIT 200", [$bt['id'], $days])->fetchAll());
}
function customers_get($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $c = q("SELECT * FROM customers WHERE id=? AND boutique_id=?", [$_GET['id'] ?? '', $bt['id']])->fetch();
    if (!$c) fail('Client introuvable', 404);
    $c['orders'] = q("SELECT * FROM orders WHERE customer_id=? ORDER BY created_at DESC", [$c['id']])->fetchAll();
    ok($c);
}

function route_contacts($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'contacts');
    switch ($action) {
        case 'roles':        contact_roles_list($pl); break;
        case 'role_create':  contact_role_create($pl); break;
        case 'list':         contacts_list($pl); break;
        case 'create':       contacts_create($pl); break;
        case 'update':       contacts_update($pl); break;
        case 'delete':       contacts_delete($pl); break;
        default: fail('Action inconnue', 404);
    }
}
function contact_roles_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT * FROM contact_roles WHERE boutique_id=? ORDER BY name", [$bt['id']])->fetchAll());
}
function contact_role_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom du role est requis');
    $id = uid();
    q("INSERT INTO contact_roles (id,boutique_id,name) VALUES (?,?,?)", [$id, $bt['id'], $name]);
    ok(q("SELECT * FROM contact_roles WHERE id=?", [$id])->fetch(), 'Role cree', 201);
}
function contacts_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $sql = "SELECT c.*, r.name AS role_name FROM contacts c LEFT JOIN contact_roles r ON r.id=c.role_id WHERE c.boutique_id=?";
    $params = [$bt['id']];
    if (!empty($_GET['role_id'])) { $sql .= " AND c.role_id=?"; $params[] = $_GET['role_id']; }
    if (!empty($_GET['q'])) { $sql .= " AND c.name ILIKE ?"; $params[] = '%'.$_GET['q'].'%'; }
    $sql .= " ORDER BY c.name";
    ok(q($sql, $params)->fetchAll());
}
function contacts_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom du contact est requis');
    $id = uid();
    q("INSERT INTO contacts (id,boutique_id,role_id,name,phone,email) VALUES (?,?,?,?,?,?)",
      [$id, $bt['id'], $b['role_id'] ?? null, $name, trim($b['phone'] ?? ''), trim($b['email'] ?? '')]);
    ok(q("SELECT * FROM contacts WHERE id=?", [$id])->fetch(), 'Contact ajoute', 201);
}
function contact_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM contacts WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Contact introuvable', 404);
    return $row;
}
function contacts_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $row = contact_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE contacts SET role_id=?, name=?, phone=?, email=? WHERE id=?",
      [$b['role_id'] ?? $row['role_id'], trim($b['name'] ?? $row['name']), trim($b['phone'] ?? $row['phone']), trim($b['email'] ?? $row['email']), $row['id']]);
    ok(q("SELECT * FROM contacts WHERE id=?", [$row['id']])->fetch(), 'Contact mis a jour');
}
function contacts_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $row = contact_owned($b['id'] ?? '', $bt['id']);
    q("DELETE FROM contacts WHERE id=?", [$row['id']]);
    ok(null, 'Contact supprime');
}

// ============================================================
// FINANCES — Vue Globale, Livre de Compte, Frais de livraison,
// Finance Detaillee, Publicites & ROAS
// ============================================================
function route_finance($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'finance');
    switch ($action) {
        case 'overview':          finance_overview($pl); break;
        case 'accounts':          finance_accounts($pl); break;
        case 'account_create':    finance_account_create($pl); break;
        case 'account_transaction': finance_account_transaction($pl); break;
        case 'account_transfer':  finance_account_transfer($pl); break;
        case 'delivery_fees':        finance_delivery_fees($pl); break;
        case 'delivery_fee_create':  finance_delivery_fee_create($pl); break;
        case 'expenses':          finance_expenses($pl); break;
        case 'expense_create':    finance_expense_create($pl); break;
        case 'detail':             finance_detail($pl); break;
        case 'ads':                finance_ads($pl); break;
        case 'ad_expense_create':  finance_ad_expense_create($pl); break;
        case 'ad_expense_detail':  finance_ad_expense_detail($pl); break;
        case 'export':             finance_export($pl); break;
        case 'export_excel':       finance_export_excel($pl); break;
        default: fail('Action inconnue', 404);
    }
}
// Sort un CSV (ouvrable directement dans Excel/LibreOffice/Google Sheets)
// au lieu du JSON habituel - seule route de l'API qui ne repond pas via
// ok()/fail(), donc le Content-Type JSON pose en tete de fichier est
// volontairement ecrase ici avant le premier echo.
function csv_output($filename, $headers, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 : Excel affiche correctement les accents avec.
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}
// Un tableau HTML avec le bon Content-Type/extension .xls s'ouvre
// directement dans Excel/LibreOffice avec sa mise en forme (gras, bordures,
// couleurs) deja appliquee - evite d'ajouter une bibliotheque PHP (type
// PhpSpreadsheet) et son installation Composer juste pour produire un
// vrai .xlsx.
function excel_output($filename, $title, $headers, $rows) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:12px">';
    echo '<tr><td colspan="'.count($headers).'" style="background:#1d4ed8;color:#ffffff;font-size:15px;font-weight:bold;padding:10px">'.htmlspecialchars($title).'</td></tr>';
    echo '<tr>';
    foreach ($headers as $h) echo '<th style="background:#eff6ff;font-weight:bold;text-align:left;padding:6px;border:1px solid #cbd5e1">'.htmlspecialchars($h).'</th>';
    echo '</tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) echo '<td style="padding:6px;border:1px solid #cbd5e1">'.htmlspecialchars((string)$cell).'</td>';
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}
function finance_export_rows($bt, $period) {
    $pc = period_clause($period, 'delivered_at');
    $orders = q("SELECT * FROM orders WHERE boutique_id=? AND status='delivered' AND $pc ORDER BY delivered_at DESC", [$bt['id']])->fetchAll();
    $rows = [];
    foreach ($orders as $o) {
        $cost = (float)q("SELECT COALESCE(SUM(unit_cost*qty),0) s FROM order_items WHERE order_id=?", [$o['id']])->fetch()['s'];
        $rows[] = [$o['ref'], $o['delivered_at'], $o['customer_name'], $o['customer_phone'], $o['subtotal'],
                   $o['delivery_fee_charged'], $o['discount_amount'], $o['total'], $cost, (float)$o['total']-$cost];
    }
    return [['Reference','Livree le','Client','Telephone','Sous-total','Frais livraison','Remise','Total','Cout produits','Marge'], $rows];
}
function finance_export($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    [$headers, $rows] = finance_export_rows($bt, $_GET['period'] ?? '30d');
    csv_output('finance-detaillee-'.$bt['slug'].'.csv', $headers, $rows);
}
function finance_export_excel($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    [$headers, $rows] = finance_export_rows($bt, $_GET['period'] ?? '30d');
    excel_output('finance-detaillee-'.$bt['slug'].'.xls', 'Finance detaillee - '.$bt['name'], $headers, $rows);
}

function finance_overview($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $period = $_GET['period'] ?? '30d';
    $pc = period_clause($period);

    $revenue = q("SELECT COALESCE(SUM(total),0) s, COUNT(*) c FROM orders
                  WHERE boutique_id=? AND status IN ".ENCAISSE_STATUSES." AND $pc", [$bt['id']])->fetch();
    $revenusEncaisses = (float)$revenue['s'];
    $nbEncaisse = (int)$revenue['c'];

    $coutProduits = (float)q("SELECT COALESCE(SUM(oi.unit_cost*oi.qty),0) s
                               FROM order_items oi JOIN orders o ON o.id=oi.order_id
                               WHERE o.boutique_id=? AND o.status='delivered' AND $pc", [$bt['id']])
                     ->fetch()['s'];
    $fraisLivraison = (float)q("SELECT COALESCE(SUM(amount),0) s FROM delivery_fees_paid
                                 WHERE boutique_id=? AND ".period_clause($period,'created_at'), [$bt['id']])->fetch()['s'];
    $fraisPub = (float)q("SELECT COALESCE(SUM(amount),0) s FROM ad_expenses
                           WHERE boutique_id=? AND ".period_clause($period,'created_at'), [$bt['id']])->fetch()['s'];
    $autresDepenses = (float)q("SELECT COALESCE(SUM(amount),0) s FROM expenses
                                 WHERE boutique_id=? AND ".period_clause($period,'created_at'), [$bt['id']])->fetch()['s'];

    $resultatNet = $revenusEncaisses - $coutProduits - $fraisLivraison - $fraisPub - $autresDepenses;

    $livraisonsFaites = (int)q("SELECT COUNT(*) c FROM orders WHERE boutique_id=? AND status='delivered' AND $pc", [$bt['id']])->fetch()['c'];

    ok([
        'revenus_encaisses'=>$revenusEncaisses, 'nb_commande_encaissee'=>$nbEncaisse,
        'livraisons_faites'=>$livraisonsFaites,
        'cout_produits'=>$coutProduits, 'frais_livraison'=>$fraisLivraison,
        'frais_pub'=>$fraisPub, 'autres_depenses'=>$autresDepenses,
        'resultat_net'=>$resultatNet,
    ]);
}

function finance_accounts($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $rows = q("SELECT * FROM accounts WHERE boutique_id=? ORDER BY created_at", [$bt['id']])->fetchAll();
    foreach ($rows as &$a) {
        $a['history'] = q("SELECT * FROM account_transactions WHERE account_id=? ORDER BY created_at DESC LIMIT 50", [$a['id']])->fetchAll();
    }
    ok($rows);
}
function finance_account_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $name = trim($b['name'] ?? '');
    if ($name === '') fail('Le nom du compte est requis');
    $id = uid();
    q("INSERT INTO accounts (id,boutique_id,name,type,balance) VALUES (?,?,?,?,?)",
      [$id, $bt['id'], $name, $b['type'] ?? 'caisse', (float)($b['balance'] ?? 0)]);
    ok(q("SELECT * FROM accounts WHERE id=?", [$id])->fetch(), 'Compte cree', 201);
}
function account_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM accounts WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Compte introuvable', 404);
    return $row;
}
function finance_account_transaction($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $acc = account_owned($b['account_id'] ?? '', $bt['id']);
    $type = $b['type'] ?? '';
    if (!in_array($type, ['in','out'], true)) fail('Type invalide');
    $amount = (float)($b['amount'] ?? 0);
    if ($amount <= 0) fail('Montant invalide');
    $delta = $type === 'in' ? $amount : -$amount;
    q("UPDATE accounts SET balance = balance + ? WHERE id=?", [$delta, $acc['id']]);
    q("INSERT INTO account_transactions (id,account_id,boutique_id,type,amount,note) VALUES (?,?,?,?,?,?)",
      [uid(), $acc['id'], $bt['id'], $type, $amount, trim($b['note'] ?? '')]);
    ok(q("SELECT * FROM accounts WHERE id=?", [$acc['id']])->fetch(), 'Mouvement enregistre');
}
function finance_account_transfer($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $from = account_owned($b['from_account_id'] ?? '', $bt['id']);
    $to = account_owned($b['to_account_id'] ?? '', $bt['id']);
    $amount = (float)($b['amount'] ?? 0);
    if ($amount <= 0) fail('Montant invalide');
    if ($from['id'] === $to['id']) fail('Choisissez deux comptes differents');
    $note = trim($b['note'] ?? '').' (transfert '.$from['name'].' -> '.$to['name'].')';
    $pdo = db(); $pdo->beginTransaction();
    try {
        q("UPDATE accounts SET balance = balance - ? WHERE id=?", [$amount, $from['id']]);
        q("UPDATE accounts SET balance = balance + ? WHERE id=?", [$amount, $to['id']]);
        q("INSERT INTO account_transactions (id,account_id,boutique_id,type,amount,note) VALUES (?,?,?,?,?,?)",
          [uid(), $from['id'], $bt['id'], 'out', $amount, $note]);
        q("INSERT INTO account_transactions (id,account_id,boutique_id,type,amount,note) VALUES (?,?,?,?,?,?)",
          [uid(), $to['id'], $bt['id'], 'in', $amount, $note]);
        $pdo->commit();
    } catch (Exception $e) { $pdo->rollBack(); fail('Transfert impossible', 500); }
    ok(null, 'Transfert effectue');
}

function finance_delivery_fees($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $period = $_GET['period'] ?? '30d';
    $rows = q("SELECT dfp.*, o.ref AS order_ref, dp.name AS delivery_person_name
               FROM delivery_fees_paid dfp
               LEFT JOIN orders o ON o.id = dfp.order_id
               LEFT JOIN delivery_persons dp ON dp.id = dfp.delivery_person_id
               WHERE dfp.boutique_id=? AND ".period_clause($period,'dfp.created_at')."
               ORDER BY dfp.created_at DESC", [$bt['id']])->fetchAll();
    $totalMonth = (float)q("SELECT COALESCE(SUM(amount),0) s FROM delivery_fees_paid
                             WHERE boutique_id=? AND created_at >= date_trunc('month', NOW())", [$bt['id']])->fetch()['s'];
    $total = (float)q("SELECT COALESCE(SUM(amount),0) s FROM delivery_fees_paid WHERE boutique_id=?", [$bt['id']])->fetch()['s'];
    ok(['rows'=>$rows, 'total_month'=>$totalMonth, 'total_all'=>$total, 'count'=>count($rows)]);
}
function finance_delivery_fee_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $amount = (float)($b['amount'] ?? 0);
    if ($amount <= 0) fail('Montant invalide');
    if (!empty($b['order_id'])) order_owned($b['order_id'], $bt['id']);
    if (!empty($b['delivery_person_id'])) {
        $dp = q("SELECT id FROM delivery_persons WHERE id=? AND boutique_id=?", [$b['delivery_person_id'], $bt['id']])->fetch();
        if (!$dp) fail('Livreur introuvable', 404);
    }
    $id = uid();
    q("INSERT INTO delivery_fees_paid (id,boutique_id,order_id,delivery_person_id,amount,note,account_id,paid_at)
       VALUES (?,?,?,?,?,?,?,COALESCE(?,CURRENT_DATE))",
      [$id, $bt['id'], $b['order_id'] ?? null, $b['delivery_person_id'] ?? null, $amount,
       trim($b['note'] ?? ''), $b['account_id'] ?? null, $b['paid_at'] ?? null]);
    if (!empty($b['account_id'])) {
        account_owned($b['account_id'], $bt['id']);
        q("UPDATE accounts SET balance = balance - ? WHERE id=?", [$amount, $b['account_id']]);
        q("INSERT INTO account_transactions (id,account_id,boutique_id,type,amount,note) VALUES (?,?,?,?,?,?)",
          [uid(), $b['account_id'], $bt['id'], 'out', $amount, 'Frais de livraison']);
    }
    ok(q("SELECT * FROM delivery_fees_paid WHERE id=?", [$id])->fetch(), 'Frais enregistre', 201);
}

function finance_expenses($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $period = $_GET['period'] ?? '30d';
    ok(q("SELECT * FROM expenses WHERE boutique_id=? AND ".period_clause($period,'created_at')."
          ORDER BY created_at DESC", [$bt['id']])->fetchAll());
}
function finance_expense_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $label = trim($b['label'] ?? '');
    $amount = (float)($b['amount'] ?? 0);
    if ($label === '' || $amount <= 0) fail('Libelle et montant requis');
    $id = uid();
    q("INSERT INTO expenses (id,boutique_id,label,category,amount,account_id,expense_date)
       VALUES (?,?,?,?,?,?,COALESCE(?,CURRENT_DATE))",
      [$id, $bt['id'], $label, trim($b['category'] ?? ''), $amount, $b['account_id'] ?? null, $b['expense_date'] ?? null]);
    if (!empty($b['account_id'])) {
        account_owned($b['account_id'], $bt['id']);
        q("UPDATE accounts SET balance = balance - ? WHERE id=?", [$amount, $b['account_id']]);
        q("INSERT INTO account_transactions (id,account_id,boutique_id,type,amount,note) VALUES (?,?,?,?,?,?)",
          [uid(), $b['account_id'], $bt['id'], 'out', $amount, 'Depense: '.$label]);
    }
    ok(q("SELECT * FROM expenses WHERE id=?", [$id])->fetch(), 'Depense enregistree', 201);
}

function finance_detail($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $period = $_GET['period'] ?? '30d';
    $pc = period_clause($period);
    $orders = q("SELECT * FROM orders WHERE boutique_id=? AND status='delivered' AND $pc ORDER BY delivered_at DESC", [$bt['id']])->fetchAll();
    $rows = [];
    $totalRevenue = 0; $totalCost = 0;
    foreach ($orders as $o) {
        $cost = (float)q("SELECT COALESCE(SUM(unit_cost*qty),0) s FROM order_items WHERE order_id=?", [$o['id']])->fetch()['s'];
        $margin = (float)$o['total'] - $cost;
        $totalRevenue += (float)$o['total']; $totalCost += $cost;
        $rows[] = ['ref'=>$o['ref'], 'customer_name'=>$o['customer_name'], 'total'=>$o['total'], 'cost'=>$cost, 'margin'=>$margin, 'delivered_at'=>$o['delivered_at']];
    }
    ok(['rows'=>$rows, 'total_revenue'=>$totalRevenue, 'total_cost'=>$totalCost, 'total_margin'=>$totalRevenue-$totalCost]);
}

function finance_ads($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $period = $_GET['period'] ?? '30d';
    $pcAd = period_clause($period, 'created_at');
    $pcOrder = period_clause($period);
    $depenseTotale = (float)q("SELECT COALESCE(SUM(amount),0) s FROM ad_expenses WHERE boutique_id=? AND $pcAd", [$bt['id']])->fetch()['s'];

    // Ici $pcAd (non prefixe) serait ambigu : products a aussi une colonne
    // created_at, donc on qualifie explicitement celle de ad_expenses.
    $pcAdJoined = period_clause($period, 'ae.created_at');
    // Quand une meme depense couvre plusieurs produits (carrousel, promo
    // groupee), son montant est reparti a parts egales entre eux plutot que
    // compte en entier pour chacun - sinon la somme des depenses "par
    // produit" depasserait la depense totale reellement payee.
    $byProduct = q("SELECT aep.product_id, p.name AS product_name, COALESCE(SUM(ae.amount / cnt.n), 0) AS spend
                    FROM ad_expense_products aep
                    JOIN ad_expenses ae ON ae.id = aep.ad_expense_id
                    JOIN (SELECT ad_expense_id, COUNT(*)::float AS n FROM ad_expense_products GROUP BY ad_expense_id) cnt
                         ON cnt.ad_expense_id = aep.ad_expense_id
                    LEFT JOIN products p ON p.id = aep.product_id
                    WHERE ae.boutique_id=? AND $pcAdJoined
                    GROUP BY aep.product_id, p.name", [$bt['id']])->fetchAll();
    foreach ($byProduct as &$row) {
        $revenu = (float)q("SELECT COALESCE(SUM(oi.unit_price*oi.qty),0) s FROM order_items oi
                             JOIN orders o ON o.id = oi.order_id
                             WHERE o.boutique_id=? AND o.status='delivered' AND oi.product_id=? AND $pcOrder",
                            [$bt['id'], $row['product_id']])->fetch()['s'];
        $row['revenue'] = $revenu;
        $row['roas'] = $row['spend'] > 0 ? round($revenu / $row['spend'], 2) : null;
    }

    $byCampaign = q("SELECT campaign_name, COALESCE(SUM(amount),0) AS spend
                     FROM ad_expenses WHERE boutique_id=? AND campaign_name IS NOT NULL AND campaign_name<>'' AND $pcAd
                     GROUP BY campaign_name", [$bt['id']])->fetchAll();
    foreach ($byCampaign as &$row) {
        $revenu = (float)q("SELECT COALESCE(SUM(total),0) s FROM orders
                             WHERE boutique_id=? AND status='delivered' AND utm_campaign=? AND $pcOrder",
                            [$bt['id'], $row['campaign_name']])->fetch()['s'];
        $row['revenue'] = $revenu;
        $row['roas'] = $row['spend'] > 0 ? round($revenu / $row['spend'], 2) : null;
    }

    $revenuAttribueTotal = array_sum(array_column($byProduct, 'revenue')) + array_sum(array_column($byCampaign, 'revenue'));
    $roasGlobal = $depenseTotale > 0 ? round($revenuAttribueTotal / $depenseTotale, 2) : null;

    // product_slug permet au tableau de bord de reconstituer le lien exact
    // de la campagne (store/index.html?...&p=slug&utm_campaign=...) sans
    // avoir a le retaper - voir "Dernieres depenses" cote frontend.
    $recent = q("SELECT ae.*, p.slug AS product_slug,
                 (SELECT COUNT(*) FROM ad_expense_products WHERE ad_expense_id=ae.id) AS product_count
                 FROM ad_expenses ae LEFT JOIN products p ON p.id = ae.product_id
                 WHERE ae.boutique_id=? AND $pcAdJoined ORDER BY ae.created_at DESC LIMIT 50", [$bt['id']])->fetchAll();

    ok([
        'depense_totale'=>$depenseTotale, 'revenu_attribue'=>$revenuAttribueTotal, 'roas_global'=>$roasGlobal,
        'by_product'=>$byProduct, 'by_campaign'=>$byCampaign, 'recent'=>$recent,
    ]);
}
function finance_ad_expense_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $amount = (float)($b['amount'] ?? 0);
    if ($amount <= 0) fail('Montant invalide');
    // Accepte plusieurs produits (carrousel, promo groupee) ; product_id
    // reste rempli seulement quand il n'y en a qu'un, par compatibilite
    // avec l'ancien affichage - ad_expense_products est la vraie source
    // pour le ROAS par produit des qu'il y en a plusieurs.
    $productIds = array_values(array_unique(array_filter((array)($b['product_ids'] ?? []))));
    if (!$productIds && !empty($b['product_id'])) $productIds = [$b['product_id']];
    foreach ($productIds as $pid) { product_owned($pid, $bt['id']); }
    $id = uid();
    $singleProductId = count($productIds) === 1 ? $productIds[0] : null;
    q("INSERT INTO ad_expenses (id,boutique_id,campaign_name,product_id,amount,spend_date)
       VALUES (?,?,?,?,?,COALESCE(?,CURRENT_DATE))",
      [$id, $bt['id'], trim($b['campaign_name'] ?? ''), $singleProductId, $amount, $b['spend_date'] ?? null]);
    foreach ($productIds as $pid) {
        q("INSERT INTO ad_expense_products (ad_expense_id, product_id) VALUES (?,?) ON CONFLICT DO NOTHING", [$id, $pid]);
    }
    ok(q("SELECT * FROM ad_expenses WHERE id=?", [$id])->fetch(), 'Depense publicitaire enregistree', 201);
}

// Detail par produit pour UNE depense precise (ex: un carrousel a 3
// articles) - contrairement a finance_ads()?action=ads (qui agrege par
// produit sur TOUTES les depenses de la periode), on isole ici uniquement
// les produits lies a ce groupe, avec sa propre part du budget (partagee a
// parts egales) et son propre revenu/ROAS.
function finance_ad_expense_detail($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $expense = q("SELECT * FROM ad_expenses WHERE id=? AND boutique_id=?", [$_GET['id'] ?? '', $bt['id']])->fetch();
    if (!$expense) fail('Depense introuvable', 404);
    $products = q("SELECT p.id, p.name FROM ad_expense_products aep JOIN products p ON p.id=aep.product_id
                   WHERE aep.ad_expense_id=? ORDER BY p.name", [$expense['id']])->fetchAll();
    $n = max(1, count($products));
    $sharePerProduct = (float)$expense['amount'] / $n;
    $period = $_GET['period'] ?? '30d';
    $pcOrder = period_clause($period);
    $rows = [];
    foreach ($products as $p) {
        $revenu = (float)q("SELECT COALESCE(SUM(oi.unit_price*oi.qty),0) s FROM order_items oi
                             JOIN orders o ON o.id = oi.order_id
                             WHERE o.boutique_id=? AND o.status='delivered' AND oi.product_id=? AND $pcOrder",
                            [$bt['id'], $p['id']])->fetch()['s'];
        $rows[] = [
            'product_id' => $p['id'], 'product_name' => $p['name'], 'spend_share' => $sharePerProduct,
            'revenue' => $revenu, 'roas' => $sharePerProduct > 0 ? round($revenu / $sharePerProduct, 2) : null,
        ];
    }
    ok(['campaign_name' => $expense['campaign_name'], 'total_amount' => (float)$expense['amount'], 'products' => $rows]);
}

// ============================================================
// ANALYTIQUE — rapports de ventes et activite en direct
// ============================================================
function route_analytics($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'analytics');
    switch ($action) {
        case 'report':   analytics_report($pl); break;
        case 'live':     analytics_live($pl); break;
        case 'activity_log': analytics_activity_log($pl); break;
        case 'export':       analytics_export($pl); break;
        case 'export_excel': analytics_export_excel($pl); break;
        default: fail('Action inconnue', 404);
    }
}
function analytics_export_rows($bt, $period) {
    $pc = period_clause($period);
    $sales = q("SELECT DATE(created_at) d, COUNT(*) commandes, COALESCE(SUM(total),0) ventes
                FROM orders WHERE boutique_id=? AND status<>'cancelled' AND $pc
                GROUP BY DATE(created_at) ORDER BY d", [$bt['id']])->fetchAll();
    return [['Date','Commandes','Ventes'], array_map(fn($r) => [$r['d'], $r['commandes'], $r['ventes']], $sales)];
}
function analytics_export($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    [$headers, $rows] = analytics_export_rows($bt, $_GET['period'] ?? '30d');
    csv_output('rapport-ventes-'.$bt['slug'].'.csv', $headers, $rows);
}
function analytics_export_excel($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    [$headers, $rows] = analytics_export_rows($bt, $_GET['period'] ?? '30d');
    excel_output('rapport-ventes-'.$bt['slug'].'.xls', 'Rapport de ventes - '.$bt['name'], $headers, $rows);
}

function analytics_report($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $period = $_GET['period'] ?? '30d';
    $pc = period_clause($period);

    $kpi = q("SELECT COALESCE(SUM(total),0) ventes_brutes, COUNT(*) commandes
              FROM orders WHERE boutique_id=? AND status<>'cancelled' AND $pc", [$bt['id']])->fetch();
    $ventesBrutes = (float)$kpi['ventes_brutes'];
    $commandes = (int)$kpi['commandes'];
    $panierMoyen = $commandes > 0 ? round($ventesBrutes / $commandes, 2) : 0;
    $livrees = (int)q("SELECT COUNT(*) c FROM orders WHERE boutique_id=? AND status='delivered' AND $pc", [$bt['id']])->fetch()['c'];
    $retours = (float)q("SELECT COALESCE(SUM(total),0) s FROM orders WHERE boutique_id=? AND status='refused' AND $pc", [$bt['id']])->fetch()['s'];
    $fraisLivraisonClients = (float)q("SELECT COALESCE(SUM(delivery_fee_charged),0) s FROM orders WHERE boutique_id=? AND status<>'cancelled' AND $pc", [$bt['id']])->fetch()['s'];
    $ventesNettes = $ventesBrutes - $retours;

    $salesSeries = q("SELECT DATE(created_at) d, COALESCE(SUM(total),0) ventes,
                       COALESCE(SUM(CASE WHEN status IN ".ENCAISSE_STATUSES." THEN total ELSE 0 END),0) encaissements
                       FROM orders WHERE boutique_id=? AND status<>'cancelled' AND $pc
                       GROUP BY DATE(created_at) ORDER BY d", [$bt['id']])->fetchAll();

    $visitSeries = q("SELECT DATE(created_at) d, COUNT(*) vues, COUNT(DISTINCT session_id) uniques
                       FROM visits WHERE boutique_id=? AND ".period_clause($period,'created_at')."
                       GROUP BY DATE(created_at) ORDER BY d", [$bt['id']])->fetchAll();

    $bestSellers = q("SELECT oi.product_id, oi.product_name, SUM(oi.qty) qty, SUM(oi.unit_price*oi.qty) revenue
                       FROM order_items oi JOIN orders o ON o.id=oi.order_id
                       WHERE o.boutique_id=? AND o.status<>'cancelled' AND $pc
                       GROUP BY oi.product_id, oi.product_name ORDER BY qty DESC LIMIT 10", [$bt['id']])->fetchAll();

    $utm = q("SELECT utm_source, utm_campaign, COUNT(*) commandes, COALESCE(SUM(total),0) ventes
              FROM orders WHERE boutique_id=? AND (utm_source<>'' OR utm_campaign<>'') AND $pc
              GROUP BY utm_source, utm_campaign", [$bt['id']])->fetchAll();

    ok([
        'ventes_brutes'=>$ventesBrutes, 'commandes'=>$commandes, 'panier_moyen'=>$panierMoyen, 'livrees'=>$livrees,
        'decomposition'=>[
            'ventes_brutes'=>$ventesBrutes, 'remises'=>0, 'retours'=>$retours, 'ventes_nettes'=>$ventesNettes,
            'frais_livraison'=>$fraisLivraisonClients, 'taxes'=>0, 'total'=>$ventesNettes+$fraisLivraisonClients,
        ],
        'sales_series'=>$salesSeries, 'visit_series'=>$visitSeries, 'best_sellers'=>$bestSellers, 'utm'=>$utm,
    ]);
}

function analytics_live($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $today = "DATE(created_at) = CURRENT_DATE";
    $cmdToday = (int)q("SELECT COUNT(*) c FROM orders WHERE boutique_id=? AND $today", [$bt['id']])->fetch()['c'];
    $revToday = (float)q("SELECT COALESCE(SUM(total),0) s FROM orders WHERE boutique_id=? AND status IN ".ENCAISSE_STATUSES." AND $today", [$bt['id']])->fetch()['s'];
    $panierMoyen = $cmdToday > 0 ? round($revToday / $cmdToday, 2) : 0;
    $recent = q("SELECT * FROM orders WHERE boutique_id=? ORDER BY created_at DESC LIMIT 10", [$bt['id']])->fetchAll();
    $activity = q("SELECT * FROM activity_log WHERE boutique_id=? ORDER BY created_at DESC LIMIT 20", [$bt['id']])->fetchAll();
    ok(['commandes_aujourdhui'=>$cmdToday, 'revenus_aujourdhui'=>$revToday, 'panier_moyen'=>$panierMoyen,
        'commandes_recentes'=>$recent, 'activite'=>$activity, 'server_time'=>date('c')]);
}

// Historique complet (qui a fait quoi, quand) - reserve au proprietaire et
// aux membres 'admin' de la boutique, comme l'equipe et les parametres :
// ca revele l'activite de chaque collegue, pas seulement des chiffres.
function analytics_activity_log($pl) {
    $bt = require_boutique_admin($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT * FROM activity_log WHERE boutique_id=? ORDER BY created_at DESC LIMIT 300", [$bt['id']])->fetchAll());
}

// ============================================================
// MARKETING — abonnes newsletter et messages de contact captes sur la
// vitrine publique
// ============================================================
function route_marketing($action) {
    $pl = owner_auth();
    require_module_access(require_boutique_owned(bg('boutique_id'), $pl['sub']), 'marketing');
    switch ($action) {
        case 'newsletter':     marketing_newsletter($pl); break;
        case 'messages':       marketing_messages($pl); break;
        case 'message_mark_read': marketing_message_mark_read($pl); break;
        case 'promo_list':     marketing_promo_list($pl); break;
        case 'promo_create':   marketing_promo_create($pl); break;
        case 'promo_update':   marketing_promo_update($pl); break;
        case 'promo_delete':   marketing_promo_delete($pl); break;
        case 'review_list':    marketing_review_list($pl); break;
        case 'review_moderate':marketing_review_moderate($pl); break;
        case 'review_delete':  marketing_review_delete($pl); break;
        case 'promotion_list':   marketing_promotion_list($pl); break;
        case 'promotion_create': marketing_promotion_create($pl); break;
        case 'promotion_update': marketing_promotion_update($pl); break;
        case 'promotion_delete': marketing_promotion_delete($pl); break;
        default: fail('Action inconnue', 404);
    }
}
function marketing_promo_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT pc.*, COALESCE((SELECT string_agg(phone, ', ' ORDER BY phone) FROM promo_code_customers WHERE promo_code_id=pc.id), '') AS target_phones
          FROM promo_codes pc WHERE pc.boutique_id=? ORDER BY pc.created_at DESC", [$bt['id']])->fetchAll());
}
// Remplace entierement la liste des numeros cibles par un code - une liste
// vide rend le code ouvert a tous (voir find_active_promo()).
function set_promo_targets($promoId, $phones) {
    $phones = array_values(array_unique(array_filter(array_map('trim', (array)$phones))));
    q("DELETE FROM promo_code_customers WHERE promo_code_id=?", [$promoId]);
    foreach ($phones as $phone) {
        q("INSERT INTO promo_code_customers (promo_code_id, phone) VALUES (?,?) ON CONFLICT DO NOTHING", [$promoId, $phone]);
    }
}
// Limite le nombre de codes ACTIFS simultanement selon le plan du
// proprietaire (voir PLANS.promo_limit) - un code desactive ou expire ne
// compte plus dans la limite.
function assert_promo_limit_not_reached($bt, $userId) {
    $plan = q("SELECT plan FROM users WHERE id=?", [$userId])->fetchColumn() ?: 'starter';
    $limit = PLANS[$plan]['promo_limit'] ?? 1;
    $count = (int)q("SELECT COUNT(*) c FROM promo_codes WHERE boutique_id=? AND active=1
                      AND (expires_at IS NULL OR expires_at > NOW())", [$bt['id']])->fetch()['c'];
    if ($count >= $limit) {
        fail('Limite de codes promo actifs atteinte pour votre plan ('.$limit.'). Passez a un plan superieur ou desactivez un code existant.', 403);
    }
}
function marketing_promo_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    assert_promo_limit_not_reached($bt, $pl['sub']);
    $code = trim($b['code'] ?? '');
    $type = in_array($b['type'] ?? '', ['percent','amount'], true) ? $b['type'] : 'percent';
    $value = (float)($b['value'] ?? 0);
    if ($code === '') fail('Le code est requis');
    if ($value <= 0) fail('La valeur doit etre superieure a 0');
    if ($type === 'percent' && $value > 100) fail('Un pourcentage ne peut pas depasser 100');
    if (q("SELECT 1 FROM promo_codes WHERE boutique_id=? AND UPPER(code)=UPPER(?)", [$bt['id'], $code])->fetch()) {
        fail('Ce code existe deja pour cette boutique');
    }
    $id = uid();
    q("INSERT INTO promo_codes (id,boutique_id,code,type,value,max_uses,expires_at,active) VALUES (?,?,?,?,?,?,?,1)",
      [$id, $bt['id'], strtoupper($code), $type, $value,
       isset($b['max_uses']) && $b['max_uses'] !== '' ? (int)$b['max_uses'] : null,
       isset($b['expires_at']) && $b['expires_at'] !== '' ? $b['expires_at'] : null]);
    set_promo_targets($id, $b['target_phones'] ?? []);
    log_activity($bt['id'], 'Code promo cree: '.strtoupper($code), $pl['sub']);
    ok(q("SELECT * FROM promo_codes WHERE id=?", [$id])->fetch(), 'Code promo cree', 201);
}
function promo_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM promo_codes WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Code promo introuvable', 404);
    return $row;
}
function marketing_promo_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $promo = promo_owned($b['id'] ?? '', $bt['id']);
    $wasActive = (bool)$promo['active'];
    $nowActive = isset($b['active']) ? (bool)!!$b['active'] : $wasActive;
    if (!$wasActive && $nowActive) assert_promo_limit_not_reached($bt, $pl['sub']);
    q("UPDATE promo_codes SET type=?, value=?, max_uses=?, expires_at=?, active=? WHERE id=?",
      [in_array($b['type'] ?? '', ['percent','amount'], true) ? $b['type'] : $promo['type'],
       isset($b['value']) && $b['value'] !== '' ? (float)$b['value'] : $promo['value'],
       isset($b['max_uses']) && $b['max_uses'] !== '' ? (int)$b['max_uses'] : null,
       isset($b['expires_at']) && $b['expires_at'] !== '' ? $b['expires_at'] : null,
       (int)$nowActive, $promo['id']]);
    if (array_key_exists('target_phones', $b)) set_promo_targets($promo['id'], $b['target_phones']);
    ok(q("SELECT * FROM promo_codes WHERE id=?", [$promo['id']])->fetch(), 'Code promo mis a jour');
}
function marketing_promo_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $promo = promo_owned($b['id'] ?? '', $bt['id']);
    q("DELETE FROM promo_code_customers WHERE promo_code_id=?", [$promo['id']]);
    q("DELETE FROM promo_codes WHERE id=?", [$promo['id']]);
    ok(null, 'Code promo supprime');
}
function marketing_review_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $rows = q("SELECT r.*, p.name AS product_name FROM product_reviews r
               LEFT JOIN products p ON p.id = r.product_id
               WHERE r.boutique_id=? ORDER BY r.created_at DESC LIMIT 300", [$bt['id']])->fetchAll();
    ok($rows);
}
function marketing_review_moderate($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $row = q("SELECT id FROM product_reviews WHERE id=? AND boutique_id=?", [$b['id'] ?? '', $bt['id']])->fetch();
    if (!$row) fail('Avis introuvable', 404);
    $status = $b['status'] ?? '';
    if (!in_array($status, ['approved','rejected','pending'], true)) fail('Statut invalide');
    q("UPDATE product_reviews SET status=? WHERE id=?", [$status, $row['id']]);
    ok(null, 'Avis mis a jour');
}
function marketing_review_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $row = q("SELECT id FROM product_reviews WHERE id=? AND boutique_id=?", [$b['id'] ?? '', $bt['id']])->fetch();
    if (!$row) fail('Avis introuvable', 404);
    q("DELETE FROM product_reviews WHERE id=?", [$row['id']]);
    ok(null, 'Avis supprime');
}

// ============================================================
// PROMOTIONS PRODUIT — reduction automatiquement visible sur le prix des
// produits choisis (contrairement aux codes promo, aucune saisie du client
// n'est necessaire). Voir apply_active_promotion()/effective_unit_price()
// dans le module shop pour la partie affichage/calcul cote vitrine.
// ============================================================
function marketing_promotion_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $rows = q("SELECT * FROM product_promotions WHERE boutique_id=? ORDER BY created_at DESC", [$bt['id']])->fetchAll();
    foreach ($rows as &$pr) {
        $pr['products'] = q("SELECT p.id, p.name FROM product_promotion_items ppi
                              JOIN products p ON p.id = ppi.product_id
                              WHERE ppi.promotion_id=? ORDER BY p.name", [$pr['id']])->fetchAll();
    }
    ok($rows);
}
function set_promotion_products($promotionId, $productIds, $boutiqueId) {
    $productIds = array_values(array_unique(array_filter((array)$productIds)));
    q("DELETE FROM product_promotion_items WHERE promotion_id=?", [$promotionId]);
    foreach ($productIds as $pid) {
        product_owned($pid, $boutiqueId); // 404 si le produit n'appartient pas a cette boutique
        q("INSERT INTO product_promotion_items (promotion_id, product_id) VALUES (?,?) ON CONFLICT DO NOTHING", [$promotionId, $pid]);
    }
}
function marketing_promotion_create($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $type = in_array($b['type'] ?? '', ['percent','amount'], true) ? $b['type'] : 'percent';
    $value = (float)($b['value'] ?? 0);
    $productIds = (array)($b['product_ids'] ?? []);
    if ($value <= 0) fail('La valeur doit etre superieure a 0');
    if ($type === 'percent' && $value > 100) fail('Un pourcentage ne peut pas depasser 100');
    if (!$productIds) fail('Selectionnez au moins un produit');
    $id = uid();
    q("INSERT INTO product_promotions (id,boutique_id,type,value,starts_at,expires_at,active) VALUES (?,?,?,?,?,?,1)",
      [$id, $bt['id'], $type, $value,
       isset($b['starts_at']) && $b['starts_at'] !== '' ? $b['starts_at'] : null,
       isset($b['expires_at']) && $b['expires_at'] !== '' ? $b['expires_at'] : null]);
    set_promotion_products($id, $productIds, $bt['id']);
    log_activity($bt['id'], 'Promotion produit creee ('.count($productIds).' produit(s))', $pl['sub']);
    ok(q("SELECT * FROM product_promotions WHERE id=?", [$id])->fetch(), 'Promotion creee', 201);
}
function promotion_owned($id, $boutiqueId) {
    $row = q("SELECT * FROM product_promotions WHERE id=? AND boutique_id=?", [$id, $boutiqueId])->fetch();
    if (!$row) fail('Promotion introuvable', 404);
    return $row;
}
function marketing_promotion_update($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $promo = promotion_owned($b['id'] ?? '', $bt['id']);
    q("UPDATE product_promotions SET type=?, value=?, starts_at=?, expires_at=?, active=? WHERE id=?",
      [in_array($b['type'] ?? '', ['percent','amount'], true) ? $b['type'] : $promo['type'],
       isset($b['value']) && $b['value'] !== '' ? (float)$b['value'] : $promo['value'],
       isset($b['starts_at']) && $b['starts_at'] !== '' ? $b['starts_at'] : null,
       isset($b['expires_at']) && $b['expires_at'] !== '' ? $b['expires_at'] : null,
       isset($b['active']) ? (int)!!$b['active'] : $promo['active'], $promo['id']]);
    if (array_key_exists('product_ids', $b)) set_promotion_products($promo['id'], $b['product_ids'], $bt['id']);
    ok(q("SELECT * FROM product_promotions WHERE id=?", [$promo['id']])->fetch(), 'Promotion mise a jour');
}
function marketing_promotion_delete($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $promo = promotion_owned($b['id'] ?? '', $bt['id']);
    q("DELETE FROM product_promotion_items WHERE promotion_id=?", [$promo['id']]);
    q("DELETE FROM product_promotions WHERE id=?", [$promo['id']]);
    ok(null, 'Promotion supprimee');
}
function marketing_newsletter($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT * FROM newsletter_subscribers WHERE boutique_id=? ORDER BY created_at DESC", [$bt['id']])->fetchAll());
}
function marketing_messages($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(q("SELECT * FROM contact_messages WHERE boutique_id=? ORDER BY created_at DESC", [$bt['id']])->fetchAll());
}
function marketing_message_mark_read($pl) {
    $b = body();
    $bt = require_boutique_owned($b['boutique_id'] ?? '', $pl['sub']);
    $row = q("SELECT id FROM contact_messages WHERE id=? AND boutique_id=?", [$b['id'] ?? '', $bt['id']])->fetch();
    if (!$row) fail('Introuvable', 404);
    q("UPDATE contact_messages SET is_read=1 WHERE id=?", [$row['id']]);
    ok(null, 'Marque comme lu');
}

// ============================================================
// EQUIPE — inviter des collegues sur une boutique (roles : admin, manager,
// livreur, closeuse, comptable). Seuls le proprietaire et les membres
// 'admin' peuvent gerer l'equipe (require_boutique_admin) ; les autres
// roles ont pour l'instant le meme acces au reste de la boutique que
// l'equipe existante - aucune restriction fine par page n'est encore
// appliquee cote serveur au-dela des parametres/de l'equipe elle-meme.
// ============================================================
function route_team($action) {
    $pl = owner_auth();
    switch ($action) {
        case 'list':   team_list($pl); break;
        case 'invite': team_invite($pl); break;
        case 'remove': team_remove($pl); break;
        case 'accept_invite': team_accept_invite($pl); break;
        default: fail('Action inconnue', 404);
    }
}

function team_invite_link($token, $pageUrl) {
    $pageUrl = trim($pageUrl);
    if ($pageUrl !== '' && preg_match('#^https?://#i', $pageUrl)) {
        $base = rtrim(strtok($pageUrl, '?#'), '/');
    } else {
        $base = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');
    }
    return $base.'?team_invite='.$token; // voir le commentaire dans auth_verify_link()
}

function team_list($pl) {
    $bt = require_boutique_owned($_GET['boutique_id'] ?? '', $pl['sub']);
    $rows = q("SELECT bm.id, bm.email, bm.role, bm.status, bm.created_at, u.full_name
               FROM boutique_members bm LEFT JOIN users u ON u.id=bm.user_id
               WHERE bm.boutique_id=? ORDER BY bm.created_at", [$bt['id']])->fetchAll();
    $owner = q("SELECT email, full_name FROM users WHERE id=?", [$bt['owner_user_id']])->fetch();
    array_unshift($rows, ['id'=>null, 'email'=>$owner['email'] ?? '', 'full_name'=>$owner['full_name'] ?? '', 'role'=>'owner', 'status'=>'active', 'created_at'=>null]);
    ok($rows);
}

function team_invite($pl) {
    $b = body();
    $bt = require_boutique_admin($b['boutique_id'] ?? '', $pl['sub']);
    $email = strtolower(trim($b['email'] ?? ''));
    $role = $b['role'] ?? 'manager';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse email invalide');
    if (!in_array($role, TEAM_ROLES, true)) fail('Role invalide');
    $existing = q("SELECT id FROM boutique_members WHERE boutique_id=? AND email=?", [$bt['id'], $email])->fetch();
    if ($existing) fail('Ce membre est deja invite sur cette boutique', 409);
    // Pour un livreur, on peut le lier des l'invitation a une fiche de la
    // page Livraisons (voir delivery_persons) : c'est ce lien qui lui
    // permettra de ne voir que ses propres livraisons assignees.
    $deliveryPersonId = null;
    if ($role === 'livreur' && !empty($b['delivery_person_id'])) {
        $dp = q("SELECT id FROM delivery_persons WHERE id=? AND boutique_id=?", [$b['delivery_person_id'], $bt['id']])->fetch();
        if ($dp) $deliveryPersonId = $dp['id'];
    }
    $token = bin2hex(random_bytes(24));
    $id = uid();
    q("INSERT INTO boutique_members (id,boutique_id,email,role,invite_token,status,delivery_person_id) VALUES (?,?,?,?,?,'pending',?)",
      [$id, $bt['id'], $email, $role, $token, $deliveryPersonId]);
    $link = team_invite_link($token, $b['page_url'] ?? '');
    send_email($email, 'Invitation a rejoindre '.$bt['name'].' sur MYBOUTIK',
        "Vous avez ete invite(e) a gerer la boutique ".$bt['name']." avec le role \"$role\".\n".
        "Connectez-vous (ou creez un compte avec cette meme adresse email) puis ouvrez ce lien :\n".$link);
    $data = ['id'=>$id];
    if (APP_ENV === 'development') $data['invite_link_dev_only'] = $link;
    ok($data, 'Invitation envoyee', 201);
}

function team_remove($pl) {
    $b = body();
    $bt = require_boutique_admin($b['boutique_id'] ?? '', $pl['sub']);
    q("DELETE FROM boutique_members WHERE id=? AND boutique_id=?", [$b['id'] ?? '', $bt['id']]);
    ok(null, 'Membre retire');
}

function team_accept_invite($pl) {
    $token = bg('token');
    if (!$token) fail('Token manquant');
    $member = q("SELECT * FROM boutique_members WHERE invite_token=? AND status='pending'", [$token])->fetch();
    if (!$member) fail('Invitation invalide ou deja utilisee', 404);
    $user = q("SELECT email FROM users WHERE id=?", [$pl['sub']])->fetch();
    if (strtolower($user['email']) !== strtolower($member['email'])) {
        fail('Cette invitation est destinee a une autre adresse email', 403);
    }
    q("UPDATE boutique_members SET user_id=?, status='active', invite_token=NULL WHERE id=?", [$pl['sub'], $member['id']]);
    ok(null, 'Invitation acceptee');
}

// ============================================================
// ABONNEMENT — plans/limites de boutiques. Aucune passerelle de paiement
// automatique : choisir un plan cree une demande verifiee manuellement par
// l'operateur de MYBOUTIK (voir route_admin()).
// ============================================================
function route_billing($action) {
    $pl = owner_auth();
    switch ($action) {
        case 'plans':     billing_plans($pl); break;
        case 'subscribe': billing_subscribe($pl); break;
        case 'affiliate_info':          billing_affiliate_info($pl); break;
        case 'affiliate_request_payout':billing_affiliate_request_payout($pl); break;
        default: fail('Action inconnue', 404);
    }
}

function billing_plans($pl) {
    $user = q("SELECT plan, plan_status, plan_valid_until FROM users WHERE id=?", [$pl['sub']])->fetch();
    $pending = q("SELECT * FROM subscription_requests WHERE user_id=? AND status='pending' ORDER BY created_at DESC LIMIT 1", [$pl['sub']])->fetch();
    ok([
        'plans' => PLANS, 'current_plan' => $user['plan'], 'plan_status' => $user['plan_status'],
        'plan_valid_until' => $user['plan_valid_until'],
        'pending_request' => $pending ?: null,
        'payment_instructions' => 'Envoyez le montant du plan choisi via Orange Money, Wave ou Djomo au +225 07 78 79 83 19 (MYBOUTIK). Votre plan sera active des verification manuelle du paiement par l\'equipe MYBOUTIK (generalement sous 24h).',
    ]);
}

function billing_subscribe($pl) {
    $b = body();
    $plan = $b['plan'] ?? '';
    if (!isset(PLANS[$plan])) fail('Plan invalide');
    $existing = q("SELECT id FROM subscription_requests WHERE user_id=? AND plan=? AND status='pending'", [$pl['sub'], $plan])->fetch();
    if ($existing) { ok(null, 'Demande deja en attente de verification'); }
    $id = uid();
    q("INSERT INTO subscription_requests (id,user_id,plan) VALUES (?,?,?)", [$id, $pl['sub'], $plan]);
    ok(null, 'Demande enregistree. Envoyez le montant via Orange Money, Wave ou Djomo au +225 07 78 79 83 19 (MYBOUTIK) - votre plan sera active des verification du paiement par l\'equipe MYBOUTIK (generalement sous 24h).', 201);
}

function billing_affiliate_info($pl) {
    $user = q("SELECT referral_code, referral_clicks FROM users WHERE id=?", [$pl['sub']])->fetch();
    if (!$user['referral_code']) {
        $code = generate_unique_referral_code();
        q("UPDATE users SET referral_code=? WHERE id=?", [$code, $pl['sub']]);
        $user['referral_code'] = $code;
    }
    $referred = q("SELECT email, full_name, plan_status, created_at FROM users WHERE referred_by=? ORDER BY created_at DESC", [$pl['sub']])->fetchAll();
    $payingCount = count(array_filter($referred, fn($r) => $r['plan_status'] === 'active'));

    $commissions = q("SELECT * FROM referral_commissions WHERE referrer_user_id=? ORDER BY created_at DESC", [$pl['sub']])->fetchAll();
    $pending = 0; $available = 0; $paid = 0;
    $cutoff = time() - REFERRAL_VALIDATION_DAYS * 86400;
    foreach ($commissions as $c) {
        $amount = (float)$c['amount'];
        if ($c['status'] === 'paid') { $paid += $amount; }
        elseif ($c['status'] === 'requested') { /* deja compte comme "disponible" au moment de la demande, en cours de versement */ $available += 0; }
        elseif ($c['status'] === 'pending' && strtotime($c['created_at']) <= $cutoff) { $available += $amount; }
        else { $pending += $amount; }
    }
    $payouts = q("SELECT * FROM referral_payouts WHERE user_id=? ORDER BY created_at DESC", [$pl['sub']])->fetchAll();

    ok([
        'referral_code' => $user['referral_code'],
        'clicks' => (int)$user['referral_clicks'],
        'signups' => count($referred),
        'paying_referrals' => $payingCount,
        'total_earned' => $pending + $available + $paid,
        'pending_validation' => $pending,
        'available' => $available,
        'paid' => $paid,
        'min_payout' => REFERRAL_MIN_PAYOUT,
        'validation_days' => REFERRAL_VALIDATION_DAYS,
        'referred_users' => $referred,
        'payouts' => $payouts,
    ]);
}

function billing_affiliate_request_payout($pl) {
    $b = body();
    $method = trim($b['method'] ?? '');
    $phone = trim($b['phone'] ?? '');
    if ($phone === '') fail('Numero Mobile Money requis');
    $cutoff = date('Y-m-d H:i:s', time() - REFERRAL_VALIDATION_DAYS * 86400);
    $available = q("SELECT * FROM referral_commissions WHERE referrer_user_id=? AND status='pending' AND created_at <= ?", [$pl['sub'], $cutoff])->fetchAll();
    $total = array_sum(array_column($available, 'amount'));
    if ($total < REFERRAL_MIN_PAYOUT) {
        fail('Le solde disponible doit atteindre au moins '.REFERRAL_MIN_PAYOUT.' FCFA pour demander un retrait');
    }
    $id = uid();
    q("INSERT INTO referral_payouts (id,user_id,amount,method,phone,status) VALUES (?,?,?,?,?,'requested')",
      [$id, $pl['sub'], $total, $method, $phone]);
    foreach ($available as $c) { q("UPDATE referral_commissions SET status='requested' WHERE id=?", [$c['id']]); }
    ok(null, 'Demande de retrait envoyee. Vous serez paye(e) apres verification.', 201);
}

// ============================================================
// AVIS DES PROPRIETAIRES SUR MYBOUTIK — envoyes depuis le tableau de bord
// marchand (page Profil), lus et traites depuis le panneau admin (bouton
// "Repondre" -> mailto:, voir admin.html).
// ============================================================
function route_feedback($action) {
    // submit_public : ouvert a tout client acheteur, sans compte (identifie
    // par nom/telephone saisis a la volee, comme au checkout) - toutes les
    // autres actions restent reservees aux marchands connectes.
    if ($action === 'submit_public') { feedback_submit_public(); return; }
    $pl = owner_auth();
    switch ($action) {
        case 'submit': feedback_submit($pl); break;
        default: fail('Action inconnue', 404);
    }
}
function feedback_submit($pl) {
    $b = body();
    $rating = (int)($b['rating'] ?? 0);
    $message = trim($b['message'] ?? '');
    if ($rating < 1 || $rating > 5) fail('Note invalide (1 a 5)');
    if ($message === '') fail('Le message ne peut pas etre vide');
    q("INSERT INTO platform_feedback (id,user_id,rating,message) VALUES (?,?,?,?)", [uid(), $pl['sub'], $rating, $message]);
    ok(null, 'Avis envoye. Merci pour votre retour !', 201);
}
function feedback_submit_public() {
    rate_limit_check('feedback_submit_public', 5, 1800);
    $b = body();
    $name = trim($b['customer_name'] ?? '');
    $phone = trim($b['customer_phone'] ?? '');
    $email = trim($b['customer_email'] ?? '');
    $rating = (int)($b['rating'] ?? 0);
    $message = trim($b['message'] ?? '');
    if ($name === '') fail('Votre nom est requis');
    if ($phone === '') fail('Votre telephone est requis');
    if ($rating < 1 || $rating > 5) fail('Note invalide (1 a 5)');
    if ($message === '') fail('Le message ne peut pas etre vide');
    q("INSERT INTO platform_feedback (id,customer_name,customer_phone,customer_email,rating,message) VALUES (?,?,?,?,?,?)",
      [uid(), $name, $phone, $email, $rating, $message]);
    ok(null, 'Avis envoye. Merci pour votre retour !', 201);
}

// ============================================================
// ADMIN — panneau reserve a l'operateur de MYBOUTIK (mot de passe distinct
// des comptes marchands), pour valider les demandes d'abonnement. Pas de
// JWT ici : le mot de passe est verifie a chaque appel, comme le panel
// admin de ROM_MONEY.
// ============================================================
function route_admin($action) {
    if (!ADMIN_PASSWORD) fail('Panneau admin non configure (variable ADMIN_PASSWORD absente)', 503);
    $password = bg('password', '');
    // Le compteur ne s'incremente que sur un MAUVAIS mot de passe - le
    // panneau admin declenche 7 appels par chargement de page (un par
    // carte), donc compter aussi les appels avec le bon mot de passe
    // epuisait le quota au bout de 4-5 rechargements en 5 minutes, meme
    // pour l'operateur legitime. La protection anti-bruteforce reste
    // intacte : un mot de passe errone continue de compter.
    if (!hash_equals(ADMIN_PASSWORD, (string)$password)) {
        rate_limit_check('admin_auth', 30, 300);
        fail('Mot de passe incorrect', 403);
    }
    switch ($action) {
        case 'subscription_requests': admin_subscription_requests(); break;
        case 'subscription_approve':  admin_subscription_approve(); break;
        case 'subscription_reject':   admin_subscription_reject(); break;
        case 'payouts_pending':       admin_payouts_pending(); break;
        case 'payout_mark_paid':      admin_payout_mark_paid(); break;
        case 'clients_list':          admin_clients_list(); break;
        case 'subscription_history':  admin_subscription_history(); break;
        case 'revenue_by_month':      admin_revenue_by_month(); break;
        case 'feedback_list':         admin_feedback_list(); break;
        case 'feedback_mark_replied': admin_feedback_mark_replied(); break;
        case 'boutiques_list':        admin_boutiques_list(); break;
        case 'boutique_set_status':   admin_boutique_set_status(); break;
        default: fail('Action inconnue', 404);
    }
}

function admin_subscription_requests() {
    ok(q("SELECT sr.*, u.email FROM subscription_requests sr JOIN users u ON u.id=sr.user_id
          WHERE sr.status='pending' ORDER BY sr.created_at ASC")->fetchAll());
}

// Vue d'ensemble de tous les clients (proprietaires de compte) - plan,
// statut d'acces, nombre de boutiques, revenu genere a vie (base sur les
// demandes approuvees x prix du plan au moment de l'approbation - PLANS
// peut changer de prix ensuite, mais l'historique reste calcule sur le prix
// ACTUEL du plan concerne, faute de stocker un prix fige par demande).
function admin_clients_list() {
    $users = q("SELECT id, email, full_name, plan, plan_status, plan_valid_until, created_at FROM users ORDER BY created_at DESC")->fetchAll();
    foreach ($users as &$u) {
        $owned = q("SELECT name FROM boutiques WHERE owner_user_id=? ORDER BY created_at", [$u['id']])->fetchAll();
        $u['boutique_count'] = count($owned);
        $u['boutique_names'] = array_column($owned, 'name');
        $member = q("SELECT b.name FROM boutique_members bm JOIN boutiques b ON b.id=bm.boutique_id
                     WHERE bm.user_id=? AND bm.status='active'", [$u['id']])->fetchAll();
        $u['member_boutique_count'] = count($member);
        $u['member_boutique_names'] = array_column($member, 'name');
        $approved = q("SELECT plan FROM subscription_requests WHERE user_id=? AND status='approved'", [$u['id']])->fetchAll();
        $u['payments_count'] = count($approved);
        $u['total_paid'] = array_sum(array_map(fn($r) => PLANS[$r['plan']]['price'] ?? 0, $approved));
    }
    ok($users);
}

// Historique complet (toutes demandes, tous statuts) - contrairement a
// admin_subscription_requests() qui ne montre que les demandes en attente
// d'action, celle-ci sert a retracer l'activite de paiement passee.
function admin_subscription_history() {
    ok(q("SELECT sr.*, u.email FROM subscription_requests sr JOIN users u ON u.id=sr.user_id
          ORDER BY sr.created_at DESC LIMIT 300")->fetchAll());
}

// Revenu regroupe par mois (annee-mois), base sur la date d'APPROBATION
// (reviewed_at) - c'est le moment ou l'argent a reellement ete verifie
// recu, pas la date de la demande initiale. Prix pris au tarif ACTUEL du
// plan (PLANS), comme admin_clients_list() - aucun prix historique fige par
// demande n'est stocke.
function admin_revenue_by_month() {
    $rows = q("SELECT plan, COALESCE(reviewed_at, created_at) AS paid_at FROM subscription_requests WHERE status='approved'")->fetchAll();
    $byMonth = [];
    foreach ($rows as $r) {
        $key = date('Y-m', strtotime($r['paid_at']));
        $byMonth[$key] = ($byMonth[$key] ?? 0) + (PLANS[$r['plan']]['price'] ?? 0);
    }
    ksort($byMonth);
    $result = [];
    foreach ($byMonth as $month => $amount) $result[] = ['month' => $month, 'revenue' => $amount];
    ok($result);
}

function admin_feedback_list() {
    // LEFT JOIN : un avis client anonyme (user_id NULL) n'a pas de compte
    // marchand associe - on retombe alors sur ses customer_* saisis a la volee.
    ok(q("SELECT pf.*, COALESCE(u.full_name, pf.customer_name) AS full_name,
                 COALESCE(u.email, pf.customer_email) AS email,
                 pf.customer_phone, (pf.user_id IS NULL) AS is_customer
          FROM platform_feedback pf
          LEFT JOIN users u ON u.id = pf.user_id
          ORDER BY pf.replied_at IS NOT NULL ASC, pf.created_at DESC LIMIT 300")->fetchAll());
}
function admin_feedback_mark_replied() {
    $b = body();
    $id = $b['id'] ?? '';
    if (!$id) fail('Introuvable', 404);
    q("UPDATE platform_feedback SET replied_at=NOW() WHERE id=?", [$id]);
    ok(null, 'Marque comme repondu');
}

// Vue d'ensemble de toutes les boutiques de la plateforme (pas seulement
// celles d'un marchand connecte) - permet a l'operateur de retrouver une
// boutique signalee et de la suspendre (voir admin_boutique_set_status()).
function admin_boutiques_list() {
    ok(q("SELECT b.id, b.name, b.slug, b.status, b.public_listed, b.category, b.city, b.created_at,
                 u.email AS owner_email, u.full_name AS owner_name
          FROM boutiques b JOIN users u ON u.id = b.owner_user_id
          ORDER BY b.created_at DESC")->fetchAll());
}
// Suspendre = status different de 'active' : toutes les routes publiques
// (vitrine, apercus OG, annuaire, alertes de stock) filtrent deja sur
// status='active', donc ca coupe immediatement la boutique du public sans
// toucher aux donnees ni empecher le marchand de se connecter a son
// tableau de bord pour voir ce qui se passe.
function admin_boutique_set_status() {
    $b = body();
    $id = $b['id'] ?? '';
    $status = $b['status'] ?? '';
    if (!in_array($status, ['active', 'suspended'], true)) fail('Statut invalide');
    $row = q("SELECT id FROM boutiques WHERE id=?", [$id])->fetch();
    if (!$row) fail('Boutique introuvable', 404);
    q("UPDATE boutiques SET status=? WHERE id=?", [$status, $id]);
    ok(null, $status === 'suspended' ? 'Boutique suspendue' : 'Boutique reactivee');
}

function admin_subscription_approve() {
    $b = body();
    $req = q("SELECT * FROM subscription_requests WHERE id=?", [$b['id'] ?? ''])->fetch();
    if (!$req) fail('Demande introuvable', 404);
    // Prolonge de 30 jours a partir de MAINTENANT (pas cumule sur l'ancienne
    // date) - si un compte est deja expire depuis longtemps, le paiement
    // repart d'un mois plein a partir d'aujourd'hui plutot que de rester
    // bloque a cause d'un cumul depuis une tres vieille date.
    q("UPDATE users SET plan=?, plan_status='active', plan_valid_until=NOW() + INTERVAL '30 days' WHERE id=?", [$req['plan'], $req['user_id']]);
    q("UPDATE subscription_requests SET status='approved', reviewed_at=NOW() WHERE id=?", [$req['id']]);
    // Commission de parrainage (10% du prix du plan) si ce compte a ete
    // recrute via un lien d'affiliation - une seule fois par abonnement
    // approuve (subscription_request_id), jamais recalculee si le meme
    // plan est de nouveau approuve plus tard.
    $referredUser = q("SELECT referred_by FROM users WHERE id=?", [$req['user_id']])->fetch();
    if ($referredUser && $referredUser['referred_by']) {
        $amount = round((PLANS[$req['plan']]['price'] ?? 0) * 0.10, 2);
        if ($amount > 0) {
            q("INSERT INTO referral_commissions (id,referrer_user_id,referred_user_id,subscription_request_id,plan,amount) VALUES (?,?,?,?,?,?)",
              [uid(), $referredUser['referred_by'], $req['user_id'], $req['id'], $req['plan'], $amount]);
        }
    }
    ok(null, 'Plan active');
}

function admin_subscription_reject() {
    $b = body();
    q("UPDATE subscription_requests SET status='rejected', reviewed_at=NOW() WHERE id=?", [$b['id'] ?? '']);
    ok(null, 'Demande rejetee');
}

function admin_payouts_pending() {
    ok(q("SELECT rp.*, u.email FROM referral_payouts rp JOIN users u ON u.id=rp.user_id
          WHERE rp.status='requested' ORDER BY rp.created_at ASC")->fetchAll());
}

function admin_payout_mark_paid() {
    $b = body();
    $payout = q("SELECT * FROM referral_payouts WHERE id=?", [$b['id'] ?? ''])->fetch();
    if (!$payout) fail('Retrait introuvable', 404);
    q("UPDATE referral_payouts SET status='paid', paid_at=NOW() WHERE id=?", [$payout['id']]);
    q("UPDATE referral_commissions SET status='paid' WHERE referrer_user_id=? AND status='requested'", [$payout['user_id']]);
    ok(null, 'Retrait marque paye');
}

// ============================================================
// INTEGRATIONS — import de commandes depuis une feuille Google Sheets
// publiee en CSV. Reserve au proprietaire/admin de la boutique (memes
// donnees sensibles qu'un parametre). Pas de synchronisation automatique en
// arriere-plan (aucun worker planifie sur cet hebergement) : seul le bouton
// "Importer maintenant" declenche une lecture, a la demande.
// ============================================================
function route_integrations($action) {
    $pl = owner_auth();
    switch ($action) {
        case 'sheet_get':    integrations_sheet_get($pl); break;
        case 'sheet_save':   integrations_sheet_save($pl); break;
        case 'sheet_import': integrations_sheet_import($pl); break;
        default: fail('Action inconnue', 404);
    }
}

function integrations_sheet_get($pl) {
    $bt = require_boutique_admin($_GET['boutique_id'] ?? '', $pl['sub']);
    ok(['sheet_url' => $bt['sheet_url'], 'sheet_sync_enabled' => (bool)$bt['sheet_sync_enabled']]);
}

function integrations_sheet_save($pl) {
    $b = body();
    $bt = require_boutique_admin($b['boutique_id'] ?? '', $pl['sub']);
    q("UPDATE boutiques SET sheet_url=?, sheet_sync_enabled=? WHERE id=?",
      [trim($b['sheet_url'] ?? ''), (int)!!($b['sheet_sync_enabled'] ?? 0), $bt['id']]);
    ok(null, 'Parametres enregistres');
}

// Lit une valeur de ligne CSV en essayant plusieurs noms de colonne possibles
// (les variantes usuelles - "tel"/"telephone", "qty"/"quantite" - sont
// acceptees sans que l'ordre des colonnes compte).
function csv_alias($row, $aliases) {
    foreach ($aliases as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') return trim((string)$row[$key]);
    }
    return '';
}

function integrations_sheet_import($pl) {
    $bt = require_boutique_admin(bg('boutique_id'), $pl['sub']);
    $url = trim($bt['sheet_url'] ?? '');
    if ($url === '') fail('Aucun lien de feuille configure');
    $context = stream_context_create(['http' => ['timeout' => 15], 'https' => ['timeout' => 15]]);
    $csvRaw = @file_get_contents($url, false, $context);
    if ($csvRaw === false || trim($csvRaw) === '') {
        fail('Impossible de recuperer la feuille (verifiez que le lien est bien publie en CSV et accessible publiquement)');
    }
    $lines = preg_split('/\r\n|\r|\n/', trim($csvRaw));
    if (count($lines) < 2) fail('La feuille est vide (juste l\'entete ou aucune ligne)');
    $header = array_map(fn($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));

    $groups = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $cells = str_getcsv($line);
        $row = [];
        foreach ($header as $i => $h) { $row[$h] = $cells[$i] ?? ''; }
        $extRef = csv_alias($row, ['id_commande', 'order id', 'commande', 'id']);
        if ($extRef === '') continue; // colonne obligatoire manquante : ligne ignoree silencieusement
        $groups[$extRef][] = $row;
    }

    $imported = 0; $skipped = [];
    foreach ($groups as $extRef => $groupRows) {
        $already = q("SELECT id FROM orders WHERE boutique_id=? AND external_ref=?", [$bt['id'], $extRef])->fetch();
        if ($already) continue; // deja importee - jamais deux fois la meme ligne
        $first = $groupRows[0];
        $client = csv_alias($first, ['client', 'nom', 'name']);
        $phone = csv_alias($first, ['telephone', 'tel', 'phone']);
        if ($client === '' || $phone === '') { $skipped[] = "$extRef: client ou telephone manquant"; continue; }

        $items = []; $subtotal = 0; $lineOk = true; $reason = '';
        foreach ($groupRows as $row) {
            $sku = csv_alias($row, ['sku', 'reference']);
            if ($sku === '') { $lineOk = false; $reason = 'SKU manquant'; break; }
            $product = q("SELECT * FROM products WHERE boutique_id=? AND sku=?", [$bt['id'], $sku])->fetch();
            if (!$product) { $lineOk = false; $reason = "SKU '$sku' introuvable dans le catalogue"; break; }
            $qty = max(1, (int)(csv_alias($row, ['quantite', 'qty', 'quantity']) ?: 1));
            $unitPriceRaw = csv_alias($row, ['prix_unitaire', 'prix', 'price']);
            $unitPrice = $unitPriceRaw !== '' ? (float)$unitPriceRaw : (float)$product['price'];
            $items[] = ['product' => $product, 'qty' => $qty, 'unit_price' => $unitPrice];
            $subtotal += $unitPrice * $qty;
        }
        if (!$lineOk) { $skipped[] = "$extRef: $reason"; continue; }

        $deliveryFee = (float)(csv_alias($first, ['frais_livraison', 'frais de livraison']) ?: 0);
        $address = trim(csv_alias($first, ['adresse']).' '.csv_alias($first, ['quartier']).' '.csv_alias($first, ['ville']));
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $customerRow = q("SELECT id FROM customers WHERE boutique_id=? AND phone=?", [$bt['id'], $phone])->fetch();
            if ($customerRow) {
                $customerId = $customerRow['id'];
                q("UPDATE customers SET name=?, address=? WHERE id=?", [$client, $address, $customerId]);
            } else {
                $customerId = uid();
                q("INSERT INTO customers (id,boutique_id,name,phone,email,address) VALUES (?,?,?,?,?,?)",
                  [$customerId, $bt['id'], $client, $phone, csv_alias($first, ['email']), $address]);
            }
            $orderId = uid(); $ref = order_ref();
            $total = $subtotal + $deliveryFee;
            q("INSERT INTO orders (id,boutique_id,customer_id,ref,external_ref,status,payment_method,subtotal,delivery_fee_charged,total,
               customer_name,customer_phone,customer_address) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [$orderId, $bt['id'], $customerId, $ref, $extRef, 'pending', 'cod', $subtotal, $deliveryFee, $total, $client, $phone, $address]);
            foreach ($items as $it) {
                q("INSERT INTO order_items (id,order_id,product_id,product_name,unit_price,unit_cost,qty) VALUES (?,?,?,?,?,?,?)",
                  [uid(), $orderId, $it['product']['id'], $it['product']['name'], $it['unit_price'], $it['product']['cost_price'] ?? 0, $it['qty']]);
                if ($it['product']['track_inventory']) {
                    q("UPDATE products SET stock_qty = stock_qty - ? WHERE id=?", [$it['qty'], $it['product']['id']]);
                }
            }
            q("INSERT INTO delivery_assignments (id,order_id,boutique_id,status) VALUES (?,?,?,?)", [uid(), $orderId, $bt['id'], 'to_assign']);
            $pdo->commit();
            $imported++;
            log_activity($bt['id'], 'Commande importee depuis Google Sheets: '.$ref, $pl['sub']);
        } catch (Exception $e) {
            $pdo->rollBack();
            $skipped[] = "$extRef: erreur d'import";
        }
    }
    ok(['imported' => $imported, 'skipped' => $skipped], $imported.' commande(s) importee(s), '.count($skipped).' ligne(s) ignoree(s)');
}

// ============================================================
// CRON — taches planifiees declenchees par un service externe (cron-job.org
// ou equivalent), aucun worker en arriere-plan n'existe sur cet hebergement.
// Protegee par CRON_KEY (jamais un token utilisateur : personne n'est
// connecte quand le service de cron appelle cette route).
// ============================================================
function route_cron($action) {
    $key = $_GET['key'] ?? '';
    if (!hash_equals((string)CRON_KEY, (string)$key)) fail('Non autorise', 403);
    switch ($action) {
        case 'abandoned_reminders': cron_abandoned_reminders(); break;
        case 'stock_alerts':        cron_stock_alerts(); break;
        default: fail('Action inconnue', 404);
    }
}

// Une seule relance par panier (reminded_at), envoyee entre 30 minutes et 48h
// apres capture - au-dela le client a probablement deja renonce ou commande
// ailleurs, en dessous on le derange trop tot.
function cron_abandoned_reminders() {
    $carts = q("SELECT ac.*, b.slug, b.name AS boutique_name, b.currency
                FROM abandoned_carts ac
                JOIN boutiques b ON b.id = ac.boutique_id
                JOIN abandoned_settings s ON s.boutique_id = ac.boutique_id
                WHERE ac.converted=0 AND ac.reminded_at IS NULL AND s.customer_reminder_enabled=1
                AND ac.captured_at <= NOW() - INTERVAL '30 minutes'
                AND ac.captured_at >= NOW() - INTERVAL '48 hours'")->fetchAll();
    $sent = 0;
    foreach ($carts as $c) {
        $total = number_format((float)$c['total'], 0, ',', ' ').' '.($c['currency'] ?: 'XOF');
        $message = "Bonjour, vous avez laisse des articles dans votre panier chez ".$c['boutique_name']." (".$total."). Revenez finaliser votre commande !";
        if ($c['email']) send_email($c['email'], 'Votre panier vous attend - '.$c['boutique_name'], $message);
        q("UPDATE abandoned_carts SET reminded_at=NOW() WHERE id=?", [$c['id']]);
        $sent++;
    }
    ok(['reminders_sent' => $sent]);
}

// Au plus une alerte par boutique toutes les 20h (voir last_stock_alert_at) -
// evite de spammer le marchand a chaque appel du cron (typiquement toutes
// les heures) tant que le stock reste bas.
function cron_stock_alerts() {
    $boutiques = q("SELECT * FROM boutiques WHERE status='active' AND stock_alert_enabled=1
                     AND (last_stock_alert_at IS NULL OR last_stock_alert_at <= NOW() - INTERVAL '20 hours')")->fetchAll();
    $sent = 0;
    foreach ($boutiques as $bt) {
        $low = q("SELECT name, stock_qty, low_stock_threshold FROM products
                   WHERE boutique_id=? AND status='active' AND track_inventory=1 AND stock_qty <= low_stock_threshold
                   ORDER BY stock_qty ASC LIMIT 30", [$bt['id']])->fetchAll();
        // Meme seuil (low_stock_threshold) reutilise pour le pool de codes
        // numeriques - seuls les produits ayant deja au moins un code dans
        // leur pool sont concernes (poolSize=0 = le marchand n'utilise pas
        // les codes pour ce produit, rien a signaler).
        $lowCodes = q("SELECT p.name,
                         (SELECT COUNT(*) FROM product_digital_codes c WHERE c.product_id=p.id AND c.status='available') AS available_codes
                       FROM products p
                       WHERE p.boutique_id=? AND p.status='active' AND p.is_digital=1
                         AND EXISTS (SELECT 1 FROM product_digital_codes c WHERE c.product_id=p.id)
                         AND (SELECT COUNT(*) FROM product_digital_codes c WHERE c.product_id=p.id AND c.status='available') <= p.low_stock_threshold
                       ORDER BY available_codes ASC LIMIT 30", [$bt['id']])->fetchAll();
        if (!$low && !$lowCodes) continue;
        $messageParts = [];
        if ($low) {
            $lines = array_map(fn($p) => '- '.$p['name'].' : '.$p['stock_qty'].' restant(s)', $low);
            $messageParts[] = "Stock limite sur ".count($low)." produit(s) physique(s) :\n".implode("\n", $lines);
        }
        if ($lowCodes) {
            $lines = array_map(fn($p) => '- '.$p['name'].' : '.$p['available_codes'].' code(s) disponible(s)', $lowCodes);
            $messageParts[] = "Stock de codes numeriques limite sur ".count($lowCodes)." produit(s) :\n".implode("\n", $lines);
        }
        $message = implode("\n\n", $messageParts)."\n\nBoutique : ".$bt['name'];
        $settings = q("SELECT notify_order_email, notify_email FROM boutiques WHERE id=?", [$bt['id']])->fetch();
        if ($settings['notify_order_email']) {
            $to = $settings['notify_email'] ?: (q("SELECT email FROM users WHERE id=?", [$bt['owner_user_id']])->fetchColumn() ?: null);
            if ($to) send_email($to, 'Alerte stock limite - '.$bt['name'], $message);
        }
        q("UPDATE boutiques SET last_stock_alert_at=NOW() WHERE id=?", [$bt['id']]);
        $sent++;
    }
    ok(['boutiques_alerted' => $sent]);
}
